<?php
// src/FacturaeGenerator.php
declare(strict_types=1);

use josemmo\Facturae\Facturae;
use josemmo\Facturae\FacturaeParty;
use josemmo\Facturae\FacturaeItem;
use josemmo\Facturae\FacturaeCentre;
use josemmo\Facturae\ReimbursableExpense;

final class FacturaeGenerator {
    /** @var array<string,mixed> */
    private array $cfg;
    private string $exportDir;
    private string $logFile;
    private string $baseDir;

    public function __construct(array $cfg = []) {
        $this->cfg = $cfg;
        $base = realpath(__DIR__ . '/../') ?: dirname(__DIR__);
        $this->baseDir   = $base;
        $this->exportDir = $base . '/data/facturae_exports';
        $this->logFile   = $base . '/data/logs/facturae_generator.log';
        @mkdir($this->exportDir, 0775, true);
        @mkdir(dirname($this->logFile), 0775, true);
    }

    /**
     * Genera y firma Facturae 3.2.1 (XAdES) y devuelve la ruta del .xsig
     * Compatible con index.php: $xmlPath = (new FacturaeGenerator())->generate($invoiceArray, $outputDir);
     */
    public function generate(array $input, ?string $outputDir = null): string {
        $meta = $this->doGenerate($input, $outputDir, wantMeta:false);
        return (string)$meta['path'];
    }

    /**
     * Variante que devuelve metadatos útiles para diagnósticos automáticos.
     * @return array{success:bool,path:string,size:int,sha256:string,hasSig:bool}
     */
    public function generateWithMeta(array $input, ?string $outputDir = null): array {
        return $this->doGenerate($input, $outputDir, wantMeta:true);
    }

    // ============================ NÚCLEO ===============================
    /**
     * Hace todo el trabajo. Si wantMeta=false, retorna array pero el caller usa sólo 'path'.
     * @return array{success:bool,path:string,size:int,sha256:string,hasSig:bool}
     */
    private function doGenerate(array $input, ?string $outputDir, bool $wantMeta): array {
        // ====== 0) Normalización básica entrada ======
        $series     = trim((string)($input['series']    ?? ''));
        $number     = trim((string)($input['number']    ?? ''));
        $issueDate  = trim((string)($input['issueDate'] ?? date('Y-m-d')));
        // Nota: En Facturae 1.8.3 no existe setCurrency; se asume EUR
        $currency   = trim((string)($input['currency']  ?? 'EUR'));
        $fileSuffix = trim((string)($input['fileSuffix'] ?? 'FACE')); // index usa 'FACE' o 'EXPORT'

        // ====== 1) LOG gen_start ======
        $itemsCnt = is_array($input['items'] ?? null) ? count($input['items']) : 0;
        $hasReimb = !empty($input['reimbursables']) || !empty($input['reimbursable']);
        $hasRU    = is_string($input['receivingUnit'] ?? null) && ($input['receivingUnit'] !== '');
        $this->log('gen_start', [
            'series'            => $series,
            'number'            => $number,
            'issueDate'         => $issueDate,
            'items_count'       => $itemsCnt,
            'has_reimbursable'  => $hasReimb,
            'has_faceb2b_ru'    => $hasRU
        ]);

        // ====== 2) Construcción de Facturae ======
        $fac = new Facturae();                // La librería pone 3.2.1 por defecto en 1.8.3
        $fac->setNumber($series, $number);    // Serie y número
        $fac->setIssueDate($issueDate);       // 1.8.3 acepta string o timestamp

        // 2.1 Seller / Buyer
        $seller = $this->buildPartyFromInput($input['seller'] ?? null, 'seller');
        $buyer  = $this->buildPartyFromInput($input['buyer']  ?? null, 'buyer');

        $fac->setSeller($seller);
        $fac->setBuyer($buyer);

        // 2.2 (Opcional) marcas de DIR3 / DIRe para diagnóstico
        $oc = strtoupper(trim((string)($input['buyer']['face_dir3_oc'] ?? $input['buyer']['centres']['OC'] ?? '')));
        $og = strtoupper(trim((string)($input['buyer']['face_dir3_og'] ?? $input['buyer']['centres']['OG'] ?? '')));
        $ut = strtoupper(trim((string)($input['buyer']['face_dir3_ut'] ?? $input['buyer']['centres']['UT'] ?? '')));
        if ($oc !== '' || $og !== '' || $ut !== '') {
            // Mapear DIR3 a centros administrativos del comprador
            $centres = [];
            if ($oc !== '') $centres[] = new FacturaeCentre(['code'=>$oc, 'role'=>FacturaeCentre::ROLE_CONTABLE,   'name'=>'OC']);
            if ($og !== '') $centres[] = new FacturaeCentre(['code'=>$og, 'role'=>FacturaeCentre::ROLE_GESTOR,     'name'=>'OG']);
            if ($ut !== '') $centres[] = new FacturaeCentre(['code'=>$ut, 'role'=>FacturaeCentre::ROLE_TRAMITADOR, 'name'=>'UT']);
            $buyer->centres = array_merge($buyer->centres ?? [], $centres);
        }
        if ($hasRU && is_string($input['receivingUnit'])) {
            // DIRe (FACeB2B) vía extensión Fb2b si está disponible
            try {
                $fb2b = $fac->getExtension('Fb2b');
                $fb2b->setReceiver(new FacturaeCentre(['code'=>(string)$input['receivingUnit'], 'role'=>FacturaeCentre::ROLE_B2B_BUYER]));
                if (!empty($contractRef = (string)($input['receiverContractReference'] ?? ''))) {
                    $fb2b->setContractReference($contractRef);
                }
            } catch (\Throwable $e) {
                // Si la extensión no existe, continuamos sin romper el flujo
            }
        }

        // 2.3 Líneas
        if ($itemsCnt < 1) {
            throw new \InvalidArgumentException('La factura debe contener al menos una línea de concepto.');
        }

        // Precalcular IRPF global (si aplica) y luego construir las líneas con FacturaeItem
        $irpfMax = 0.0;
        foreach (($input['items'] ?? []) as $i) {
            $irpfLine = (float)($i['irpfRate'] ?? 0.0);
            if ($irpfLine > $irpfMax) $irpfMax = $irpfLine;
        }
        $irpfRate = isset($input['irpfRate']) ? (float)$input['irpfRate'] : $irpfMax;

        foreach (($input['items'] ?? []) as $i) {
            $desc = (string)($i['description'] ?? '');
            $qty  = (float)($i['quantity'] ?? 0.0);
            $ppu  = (float)($i['unitPrice'] ?? 0.0);

            // IVA
            $vatRate = (float)($i['vat'] ?? $i['vatRate'] ?? $i['taxRate'] ?? 0.0);

            // Unidad de medida
            $uom = isset($i['unitOfMeasure']) ? (string)$i['unitOfMeasure'] : null;

            // Descuentos / Cargos a nivel de línea
            $discounts = [];
            if (!empty($i['discountPercent'])) {
                $discounts[] = ['rate' => (float)$i['discountPercent']];
            } elseif (!empty($i['discountAmount'])) {
                $discounts[] = ['amount' => (float)$i['discountAmount']];
            }
            $charges = [];
            if (!empty($i['surchargePercent'])) {
                $charges[] = ['rate' => (float)$i['surchargePercent']];
            } elseif (!empty($i['surchargeAmount'])) {
                $charges[] = ['amount' => (float)$i['surchargeAmount']];
            }

            // Impuestos por línea (IVA + IRPF retenido si aplica)
            $taxes = [Facturae::TAX_IVA => $vatRate];
            $irpfLine = (float)($i['irpfRate'] ?? 0.0);
            $effIrpf  = $irpfLine > 0 ? $irpfLine : $irpfRate;
            if ($effIrpf > 0) {
                $taxes[Facturae::TAX_IRPF] = ['rate' => $effIrpf];
            }

            $itemProps = [
                'name'           => $desc,
                'quantity'       => $qty,
                'unitPrice'      => $ppu,
                'unitOfMeasure'  => $uom ?? Facturae::UNIT_DEFAULT,
                'discounts'      => $discounts,
                'charges'        => $charges,
                'taxes'          => $taxes,
            ];
            $item = new FacturaeItem($itemProps);
            $fac->addItem($item);
        }

        // 2.5 Suplidos / Reembolsables
        $reimbTotal = 0.0;
        if (!empty($input['reimbursables']) && is_array($input['reimbursables'])) {
            foreach ($input['reimbursables'] as $r) {
                $amt  = (float)($r['amount'] ?? 0.0);
                if ($amt != 0.0) {
                    $fac->addReimbursableExpense(new ReimbursableExpense(['amount' => $amt]));
                    $reimbTotal += $amt;
                }
            }
        } elseif (!empty($input['reimbursable']) && is_array($input['reimbursable'])) {
            $amt  = (float)($input['reimbursable']['amount'] ?? 0.0);
            if ($amt != 0.0) {
                $fac->addReimbursableExpense(new ReimbursableExpense(['amount' => $amt]));
                $reimbTotal += $amt;
            }
        }

        // 2.6 Propiedades auxiliares/adjuntos/QR (opcionales)
        $this->attachExtras($fac, $input['attachments'] ?? []);

        // 2.7 Referencias administrativas de FACe (opcional; no todas las versiones tienen setter)
        $fileRef     = trim((string)($input['fileReference'] ?? ''));
        $contractRef = trim((string)($input['receiverContractReference'] ?? ''));
        if ($fileRef !== '' || $contractRef !== '') {
            $fac->setReferences($fileRef ?: null, null, $contractRef ?: null);
        }

        // ====== 3) Firma y exportación ======
        // 3.1 Certificado desde $input['certificate'] (index.php ya lo pasa)
        $certPath = (string)($input['certificate']['path']     ?? '');
        $certPass = (string)($input['certificate']['password'] ?? '');

        // Descifra si viniera cifrada por error (index ya descifra, pero por robustez)
        if (class_exists('SecureConfig') && strncmp($certPass, 'enc:v1:', 7) === 0) {
            $dec = \SecureConfig::decrypt($certPass);
            if (is_string($dec) && $dec !== '') $certPass = $dec;
        }

        if ($certPath === '' || !is_file($certPath)) {
            throw new \RuntimeException('No se encontró el P12/PFX del emisor en certificate.path');
        }

        // 3.2 Ruta de salida
        $outDir = $outputDir ? rtrim($outputDir, '/\\') : $this->exportDir;
        @mkdir($outDir, 0775, true);
        $outName = $this->defaultOutName($input, $fileSuffix);
        $outPath = $outDir . '/' . $outName . '.xsig';

        // 3.3 Firmado + export
        try {
            // XAdES-BES con SHA-256: usar PKCS#12 (segundo parámetro null, tercero passphrase)
            $fac->sign($certPath, null, $certPass);
            $ok = $fac->export($outPath);

            if (!$ok || !is_file($outPath)) {
                throw new \RuntimeException('Factura exportada sin firma XMLDSIG. Revisa certificate/privkey contenidos en el PKCS#12.');
            }
        } catch (\Throwable $e) {
            // Log de intento de firma
            $this->log('sign_error', [
                'p12'         => basename($certPath),
                'pass_len'    => strlen($certPass),
                'message'     => $e->getMessage(),
            ]);
            throw $e;
        }

        // 3.4 Meta + verificaciones ligeras
        $xmlData = (string)@file_get_contents($outPath);
        $hasSig  = (bool)preg_match('/<([a-z0-9._-]+:)?Signature\b/i', $xmlData);
        if (!$hasSig) {
            throw new \RuntimeException('El XML Facturae se generó sin firma. Revisa el P12 y su contraseña.');
        }
        $size    = (int)@filesize($outPath);
        $sha256  = is_file($outPath) ? (string)hash_file('sha256', $outPath) : '';

        // Chequeo simple de DIR3 y DIRe marcados
        $hasOC = ($oc !== '' && strpos($xmlData, $oc) !== false);
        $hasOG = ($og !== '' && strpos($xmlData, $og) !== false);
        $hasUT = ($ut !== '' && strpos($xmlData, $ut) !== false);
        $hasRU = $hasRU || (strpos($xmlData, 'FaceB2BExtension') !== false);

        $this->log('export_done', [
            'path'         => $outPath,
            'size'         => $size,
            'hasSignature' => $hasSig ? 1 : 0,
            'sha256'       => $sha256,
            'dir3'         => ['OC'=>$oc,'OG'=>$og,'UT'=>$ut,'hasOC'=>$hasOC,'hasOG'=>$hasOG,'hasUT'=>$hasUT],
            'hasRU'        => $hasRU ? 1 : 0,
            'reimb_total'  => number_format($reimbTotal, 2, '.', '')
        ]);

        return [
            'success' => true,
            'path'    => $outPath,
            'size'    => $size,
            'sha256'  => $sha256,
            'hasSig'  => $hasSig
        ];
    }

    // ============================ HELPERS ===============================

    /**
     * Construye FacturaeParty a partir de la estructura normalizada que le pasamos desde index.php
     * Valida mínimos (NIF + dirección básica para evitar errores en entornos públicos/FACe).
     *
     * @param array<string,mixed>|null $raw
     */
    private function buildPartyFromInput($raw, string $who): FacturaeParty {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException("Faltan datos de {$who}");
        }

        $tax = (string)($raw['taxNumber'] ?? $raw['taxId'] ?? $raw['nif'] ?? $raw['vat'] ?? '');
        $tax = strtoupper(preg_replace('/[\s-]+/', '', $tax));

        if (strlen($tax) < 3) {
            throw new \InvalidArgumentException("Falta NIF/CIF válido para '{$who}' (min 3 caracteres).");
        }

        $name      = (string)($raw['name'] ?? $raw['razon'] ?? $raw['company'] ?? '');
        $isLegal   = array_key_exists('isLegalEntity', $raw) ? (bool)$raw['isLegalEntity'] : true;

        // Dirección: evitamos errores comunes (FACe suele exigir estos campos)
        $address   = (string)($raw['address']   ?? '');
        $postCode  = (string)($raw['postCode']  ?? '');
        $town      = (string)($raw['town']      ?? '');
        $province  = (string)($raw['province']  ?? '');
        $country   = (string)($raw['countryCode'] ?? $raw['country'] ?? 'ESP');

        if ($who === 'buyer') {
            $missing = [];
            foreach (['address','postCode','town','province'] as $k) {
                if (trim((string)($raw[$k] ?? '')) === '') $missing[] = $k;
            }
            if (!empty($missing)) {
                throw new \InvalidArgumentException("buyer con campos de dirección vacíos: " . implode(', ', $missing));
            }
        }

        // Personas físicas
        if (!$isLegal) {
            $firstName     = (string)($raw['name'] ?? $raw['firstName'] ?? '');
            $firstSurname  = (string)($raw['firstSurname'] ?? $raw['lastName'] ?? '');
            $lastSurname   = (string)($raw['lastSurname'] ?? $raw['secondSurname'] ?? '');

            return new FacturaeParty([
                'isLegalEntity' => false,
                'taxNumber'     => $tax,
                'name'          => $firstName,
                'firstSurname'  => $firstSurname,
                'lastSurname'   => $lastSurname,
                'address'       => $address,
                'postCode'      => $postCode,
                'town'          => $town,
                'province'      => $province,
                'countryCode'   => $country,
            ]);
        }

        // Persona jurídica
        return new FacturaeParty([
            'isLegalEntity' => true,
            'taxNumber'     => $tax,
            'name'          => $name,
            'address'       => $address,
            'postCode'      => $postCode,
            'town'          => $town,
            'province'      => $province,
            'countryCode'   => $country,
        ]);
    }

    /**
     * Añade suplidos si la versión de la librería lo soporta; si no, deja una marca.
     */
    private function tryAddReimbursable(Facturae $fac, string $description, float $amount): void {
        // Compat: en 1.8.3 se usa ReimbursableExpense. Mantengo método para no romper llamadas internas.
        if ($amount == 0.0) return;
        $fac->addReimbursableExpense(new ReimbursableExpense(['amount' => $amount]));
    }

    /**
     * Adjunta hashes/QR/otros como propiedades adicionales (diagnóstico).
     * Estructura esperada:
     *   attachments: {
     *     hashes: [ 'sha256:...', '...'],
     *     qr_text: '...',
     *     qr_png: '/ruta/qr.png'   // opcional, se inserta como base64
     *   }
     */
    private function attachExtras(Facturae $fac, $attachments): void {
        if (!is_array($attachments)) return;

        $info = [];
        if (!empty($attachments['hashes']) && is_array($attachments['hashes'])) {
            foreach ($attachments['hashes'] as $h) {
                $h = trim((string)$h);
                if ($h !== '') $info[] = 'Hash: ' . $h;
            }
        }
        if (!empty($attachments['qr_text']) && is_string($attachments['qr_text'])) {
            $info[] = 'QR: ' . (string)$attachments['qr_text'];
        }
        if (!empty($info)) {
            $fac->setAdditionalInformation(implode("\n", $info));
        }

        if (!empty($attachments['qr_png']) && is_string($attachments['qr_png']) && is_file($attachments['qr_png'])) {
            // Adjuntamos el PNG como anexo
            $fac->addAttachment($attachments['qr_png'], 'QR');
        }
    }

    /**
     * Nombre de salida: <NIF>_<SERIE>-<NUM>_<YYYY>_<SUFIJO>
     */
    private function defaultOutName(array $in, string $suffix): string {
        $sellerCif = strtoupper((string)($in['seller']['taxNumber'] ?? $in['seller']['taxId'] ?? $in['seller']['nif'] ?? 'EMISOR'));
        $sellerCif = preg_replace('/[\s-]+/', '', $sellerCif);
        $serie     = (string)($in['series'] ?? '');
        $num       = (string)($in['number'] ?? '');
        $year      = substr((string)($in['issueDate'] ?? date('Y-m-d')), 0, 4);
        $suffix    = $suffix !== '' ? $suffix : 'FACE';
        return "{$sellerCif}_{$serie}-{$num}_{$year}_{$suffix}";
    }

    /**
     * Logging muy simple a data/logs/facturae_generator.log
     * @param mixed $ctx
     */
    private function log(string $msg, $ctx = null): void {
        $line = '['.date('c')."] {$msg}";
        if ($ctx !== null) {
            if (!is_string($ctx)) $ctx = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $line .= ' ' . $ctx;
        }
        @file_put_contents($this->logFile, $line . "\n", FILE_APPEND);
    }
}
