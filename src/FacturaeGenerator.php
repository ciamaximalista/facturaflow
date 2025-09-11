<?php
// src/FacturaeGenerator.php
declare(strict_types=1);

use josemmo\Facturae\Facturae;
use josemmo\Facturae\FacturaeParty;

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
        $fac = new Facturae();                // La librería ya pone 3.2.1 por defecto en versiones recientes
        $fac->setNumber($series, $number);    // Serie y número
        $fac->setIssueDate(new \DateTime($issueDate));
        $fac->setCurrency($currency);

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
            // No hay API específica para centros en la lib; dejamos huella en AdditionalData
            $fac->addAdditionalProperty('Buyer-DIR3-OC', $oc);
            $fac->addAdditionalProperty('Buyer-DIR3-OG', $og);
            $fac->addAdditionalProperty('Buyer-DIR3-UT', $ut);
        }
        if ($hasRU) {
            $fac->addAdditionalProperty('FACeB2B-ReceivingUnit', (string)$input['receivingUnit']);
        }

        // 2.3 Líneas
        if ($itemsCnt < 1) {
            throw new \InvalidArgumentException('La factura debe contener al menos una línea de concepto.');
        }

        $irpfMax = 0.0;
        foreach (($input['items'] ?? []) as $i) {
            $desc = (string)($i['description'] ?? '');
            $qty  = (float)($i['quantity'] ?? 0.0);
            $ppu  = (float)($i['unitPrice'] ?? 0.0);

            // IVA
            $vatRate = (float)($i['vat'] ?? $i['vatRate'] ?? $i['taxRate'] ?? 0.0);
            $vatType = Facturae::TAX_IVA;

            // Unidad de medida (Facturae 3.2.1 admite códigos) — opcional
            $uom = isset($i['unitOfMeasure']) ? (string)$i['unitOfMeasure'] : null;

            // Descuentos / Cargos a nivel de línea (opcional, estructura de la lib)
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

            // Añadir item
            // Firma: addItem(string $desc, float $qty, float $price, string $taxType, float $taxRate, array $discounts=[], array $charges=[], ?string $unitOfMeasure=null)
            $fac->addItem($desc, $qty, $ppu, $vatType, $vatRate, $discounts, $charges, $uom);

            // IRPF máximo (si vienes por línea)
            $irpfLine = (float)($i['irpfRate'] ?? 0.0);
            if ($irpfLine > $irpfMax) $irpfMax = $irpfLine;
        }

        // 2.4 IRPF global (si aplica)
        $irpfRate = isset($input['irpfRate']) ? (float)$input['irpfRate'] : $irpfMax;
        if ($irpfRate > 0) {
            $fac->setIRPF($irpfRate);
        }

        // 2.5 Suplidos / Reembolsables
        $reimbTotal = 0.0;
        if (!empty($input['reimbursables']) && is_array($input['reimbursables'])) {
            foreach ($input['reimbursables'] as $r) {
                $desc = (string)($r['description'] ?? '');
                $amt  = (float)($r['amount'] ?? 0.0);
                $this->tryAddReimbursable($fac, $desc, $amt);
                $reimbTotal += $amt;
            }
        } elseif (!empty($input['reimbursable']) && is_array($input['reimbursable'])) {
            // Forma corta: ['reimbursable' => ['amount'=>X, 'description'=>Y]]
            $desc = (string)($input['reimbursable']['description'] ?? 'Reembolsable');
            $amt  = (float)($input['reimbursable']['amount'] ?? 0.0);
            $this->tryAddReimbursable($fac, $desc, $amt);
            $reimbTotal += $amt;
        }

        // 2.6 Propiedades auxiliares/adjuntos/QR (opcionales)
        $this->attachExtras($fac, $input['attachments'] ?? []);

        // 2.7 Referencias administrativas de FACe (opcional; no todas las versiones tienen setter)
        $fileRef = trim((string)($input['fileReference'] ?? ''));
        if ($fileRef !== '') {
            // Si no existe método específico, lo dejamos como propiedad adicional
            $fac->addAdditionalProperty('FileReference', $fileRef);
        }
        $contractRef = trim((string)($input['receiverContractReference'] ?? ''));
        if ($contractRef !== '') {
            $fac->addAdditionalProperty('ReceiverContractReference', $contractRef);
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
            // XAdES-BES con SHA-256: la librería gestiona internamente
            $fac->sign($certPath, $certPass);
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
        $size    = (int)@filesize($outPath);
        $sha256  = is_file($outPath) ? (string)hash_file('sha256', $outPath) : '';

        // Chequeo simple de DIR3 y DIRe marcados
        $hasOC = ($oc !== '' && strpos($xmlData, $oc) !== false);
        $hasOG = ($og !== '' && strpos($xmlData, $og) !== false);
        $hasUT = ($ut !== '' && strpos($xmlData, $ut) !== false);
        $hasRU = $hasRU || (strpos($xmlData, 'FACeB2B-ReceivingUnit') !== false);

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
        if ($amount == 0.0) return;
        if (method_exists($fac, 'addReimbursable')) {
            // Disponible en versiones recientes (>=1.7.x/1.8.x)
            $fac->addReimbursable($description, $amount);
        } else {
            $fac->addAdditionalProperty('Reimbursable: ' . $description, number_format($amount, 2, '.', ''));
        }
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

        // Hashes
        if (!empty($attachments['hashes']) && is_array($attachments['hashes'])) {
            foreach ($attachments['hashes'] as $h) {
                $h = (string)$h;
                if ($h !== '') $fac->addAdditionalProperty('Hash', $h);
            }
        }

        // Texto QR
        if (!empty($attachments['qr_text']) && is_string($attachments['qr_text'])) {
            $fac->addAdditionalProperty('QR-TEXT', (string)$attachments['qr_text']);
        }

        // PNG QR como base64 (opcional)
        if (!empty($attachments['qr_png']) && is_string($attachments['qr_png']) && is_file($attachments['qr_png'])) {
            $b = @file_get_contents($attachments['qr_png']);
            if ($b !== false && $b !== '') {
                $fac->addAdditionalProperty('QR-PNG', base64_encode($b));
            }
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

