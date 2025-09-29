<?php
declare(strict_types=1);

use josemmo\Facturae\Facturae;
use josemmo\Facturae\FacturaeParty;
use josemmo\Facturae\FacturaeItem;
use josemmo\Facturae\FacturaeCentre;
use josemmo\Facturae\ReimbursableExpense;

require_once __DIR__ . '/InvoiceManager.php';

class SignatureManager {

    public function generateUnsignedXml(array $invoiceData): string {
        $fac = $this->buildFacturaeObject($invoiceData);
        return $fac->export();
    }

    private function buildFacturaeObject(array $input): Facturae {
        $series     = trim((string)($input['series']    ?? ''));
        $number     = trim((string)($input['number']    ?? ''));
        $issueDate  = trim((string)($input['issueDate'] ?? date('Y-m-d')));

        $fac = new Facturae();
        $fac->setNumber($series, $number);
        $fac->setIssueDate($issueDate);

        $seller = $this->buildPartyFromInput($input['seller'] ?? null, 'seller');
        $buyer  = $this->buildPartyFromInput($input['buyer']  ?? null, 'buyer');
        $fac->setSeller($seller);
        $fac->setBuyer($buyer);

        $oc = strtoupper(trim((string)($input['buyer']['face_dir3_oc'] ?? $input['buyer']['centres']['OC'] ?? '')));
        $og = strtoupper(trim((string)($input['buyer']['face_dir3_og'] ?? $input['buyer']['centres']['OG'] ?? '')));
        $ut = strtoupper(trim((string)($input['buyer']['face_dir3_ut'] ?? $input['buyer']['centres']['UT'] ?? '')));
        if ($oc !== '' || $og !== '' || $ut !== '') {
            $centres = [];
            if ($oc !== '') $centres[] = new FacturaeCentre(['code'=>$oc, 'role'=>FacturaeCentre::ROLE_CONTABLE,   'name'=>'OC']);
            if ($og !== '') $centres[] = new FacturaeCentre(['code'=>$og, 'role'=>FacturaeCentre::ROLE_GESTOR,     'name'=>'OG']);
            if ($ut !== '') $centres[] = new FacturaeCentre(['code'=>$ut, 'role'=>FacturaeCentre::ROLE_TRAMITADOR, 'name'=>'UT']);
            $buyer->centres = array_merge($buyer->centres ?? [], $centres);
        }

        if (is_string($input['receivingUnit'] ?? null) && ($input['receivingUnit'] !== '')) {
            try {
                $fb2b = $fac->getExtension('Fb2b');
                $fb2b->setReceiver(new FacturaeCentre(['code'=>(string)$input['receivingUnit'], 'role'=>FacturaeCentre::ROLE_B2B_BUYER]));
                if (!empty($contractRef = (string)($input['receiverContractReference'] ?? ''))) {
                    $fb2b->setContractReference($contractRef);
                }
            } catch (\Throwable $e) {}
        }

        if (empty($input['items'])) {
            throw new \InvalidArgumentException('La factura debe contener al menos una línea de concepto.');
        }

        $irpfMax = 0.0;
        foreach (($input['items'] ?? []) as $i) {
            $irpfLine = (float)($i['irpfRate'] ?? 0.0);
            if ($irpfLine > $irpfMax) $irpfMax = $irpfLine;
        }
        $irpfRate = isset($input['irpfRate']) ? (float)$input['irpfRate'] : $irpfMax;

        foreach (($input['items'] ?? []) as $i) {
            $taxes = [Facturae::TAX_IVA => (float)($i['vat'] ?? $i['vatRate'] ?? $i['taxRate'] ?? 0.0)];
            $effIrpf  = ($irpfLine = (float)($i['irpfRate'] ?? 0.0)) > 0 ? $irpfLine : $irpfRate;
            if ($effIrpf > 0) {
                $taxes[Facturae::TAX_IRPF] = ['rate' => $effIrpf];
            }

            $item = new FacturaeItem([
                'name'           => (string)($i['description'] ?? ''),
                'quantity'       => (float)($i['quantity'] ?? 0.0),
                'unitPrice'      => (float)($i['unitPrice'] ?? 0.0),
                'unitOfMeasure'  => (string)($i['unitOfMeasure'] ?? Facturae::UNIT_DEFAULT),
                'taxes'          => $taxes,
            ]);
            $fac->addItem($item);
        }

        if (!empty($input['reimbursables']) && is_array($input['reimbursables'])) {
            foreach ($input['reimbursables'] as $r) {
                if (($amt = (float)($r['amount'] ?? 0.0)) != 0.0) {
                    $fac->addReimbursableExpense(new ReimbursableExpense(['amount' => $amt]));
                }
            }
        }

        $this->attachExtras($fac, $input['attachments'] ?? []);

        $fileRef     = trim((string)($input['fileReference'] ?? ''));
        $contractRef = trim((string)($input['receiverContractReference'] ?? ''));
        if ($fileRef !== '' || $contractRef !== '') {
            $fac->setReferences($fileRef ?: null, null, $contractRef ?: null);
        }

        return $fac;
    }

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

    private function attachExtras(Facturae $fac, $attachments): void {
        if (!is_array($attachments)) return;
        $info = [];
        if (!empty($attachments['hashes']) && is_array($attachments['hashes'])) {
            foreach ($attachments['hashes'] as $h) {
                if (($h = trim((string)$h)) !== '') $info[] = 'Hash: ' . $h;
            }
        }
        if (!empty($attachments['qr_text']) && is_string($attachments['qr_text'])) {
            $info[] = 'QR: ' . (string)$attachments['qr_text'];
        }
        if (!empty($info)) {
            $fac->setAdditionalInformation(implode("\n", $info));
        }
        if (!empty($attachments['qr_png']) && is_string($attachments['qr_png']) && is_file($attachments['qr_png'])) {
            $fac->addAttachment($attachments['qr_png'], 'QR');
        }
    }

    public function saveSignedXml(string $invoiceId, string $signedXmlB64, array $invoiceData): array {
        // PHP convierte los '+' en espacios cuando llega como application/x-www-form-urlencoded.
        $this->log('raw_signed_input', [
            'id' => $invoiceId,
            'raw_prefix' => substr($signedXmlB64, 0, 16),
            'raw_suffix' => substr($signedXmlB64, -16),
            'raw_len' => strlen($signedXmlB64)
        ]);

        $cleanB64 = preg_replace('/\s+/', '', $signedXmlB64);
        // AutoFirma normaliza Base64 a variante URL-safe ('-' y '_'); revertimos a la versión estándar.
        $cleanB64 = strtr($cleanB64, ['-' => '+', '_' => '/']);
        $pad = strlen($cleanB64) % 4;
        if ($pad > 0) {
            $cleanB64 .= str_repeat('=', 4 - $pad);
        }

        $signedXml = base64_decode($cleanB64, true);
        if ($signedXml === false || $signedXml === '') {
            return ['success' => false, 'message' => 'El XML firmado está vacío o no es un Base64 válido.'];
        }

        // Validamos el XML antes de persistirlo para detectar corrupciones de transporte.
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        $prevLibxml = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($signedXml);
        if (!$loaded) {
            $rawTail = substr($signedXml, -16);
            $patched = false;
            $trimmed = rtrim($signedXml);
            if (!str_ends_with($trimmed, '</fe:Facturae>') && str_ends_with($trimmed, '</fe:F')) {
                $signedXml = $trimmed . 'acturae>';
                $dom = new \DOMDocument();
                $dom->preserveWhiteSpace = true;
                $dom->formatOutput = false;
                libxml_clear_errors();
                $loaded = $dom->loadXML($signedXml);
                $patched = $loaded;
            }

            if (!$loaded) {
                $errors = array_map(static function($err) {
                    return trim($err->message ?? '');
                }, libxml_get_errors() ?: []);
                libxml_clear_errors();
                libxml_use_internal_errors($prevLibxml);
                $this->log('invalid_signed_xml', [
                    'id' => $invoiceId,
                    'errors' => $errors,
                    'base64_len' => strlen($signedXmlB64),
                    'base64_len_clean' => strlen($cleanB64),
                    'decoded_prefix' => substr($signedXml, 0, 80),
                    'decoded_tail' => substr($signedXml, -80)
                ]);
                return ['success' => false, 'message' => 'La firma devuelta por AutoFirma está corrupta. Repite la operación.'];
            }

            if ($patched) {
                $this->log('patched_autofirma_signature', [
                    'id' => $invoiceId,
                    'base64_len' => strlen($signedXmlB64),
                    'raw_tail' => $rawTail
                ]);
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prevLibxml);

        // DOMDocument normaliza el XML; usamos el string resultante para garantizar cierre correcto.
        $signedXml = $dom->saveXML();
        $this->log('signed_xml_stats', [
            'id'          => $invoiceId,
            'base64_len'  => strlen($signedXmlB64),
            'base64_len_clean' => strlen($cleanB64),
            'decoded_len' => strlen($signedXml),
            'tail'        => substr($signedXml, -60)
        ]);

        $fileSuffix = 'AUTOFIRMA';
        $outName = $this->defaultOutName($invoiceData, $fileSuffix);
        $outDir = realpath(__DIR__ . '/../data/facturae_exports') ?: (__DIR__ . '/../data/facturae_exports');
        $this->ensureDir($outDir);
        $outPath = $outDir . '/' . $outName . '.xsig';

        if (file_put_contents($outPath, $signedXml) === false) {
            return ['success' => false, 'message' => 'No se pudo escribir el fichero de la factura firmada en el disco.'];
        }

        $sha256 = hash('sha256', $signedXml) ?: '';
        $size   = strlen($signedXml);
        $signedInfo = null;
        try {
            $im = new InvoiceManager();
            $im->recordSignedFacturae($invoiceId, $outPath, [
                'sha256'   => $sha256,
                'size'     => $size,
                'signedAt' => date('c'),
                'source'   => 'autofirma',
            ]);
            $signedInfo = $im->getSignedFacturaeInfo($invoiceId);
        } catch (\Throwable $e) {
            $this->log('record_signed_facturae_error', ['id' => $invoiceId, 'error' => $e->getMessage()]);
        }

        $logData = ['id' => $invoiceId, 'path' => $outPath, 'sha256' => $sha256, 'size' => $size];
        if ($signedInfo && isset($signedInfo['path'])) {
            $logData['relative'] = $signedInfo['path'];
        }
        $this->log('save_signed_facturae', $logData);

        $response = [
            'success' => true,
            'message' => 'Factura firmada y guardada correctamente.',
            'path'    => $outPath,
            'sha256'  => $sha256,
            'size'    => $size,
        ];
        if ($signedInfo) {
            $response['meta'] = $signedInfo;
        }
        return $response;
    }

    private function defaultOutName(array $in, string $suffix): string {
        $sellerCif = strtoupper((string)($in['seller']['taxNumber'] ?? $in['seller']['taxId'] ?? $in['seller']['nif'] ?? 'EMISOR'));
        $sellerCif = preg_replace('/[\s-]+/', '', $sellerCif);
        $serie     = (string)($in['series'] ?? '');
        $num       = (string)($in['number'] ?? '');
        $year      = substr((string)($in['issueDate'] ?? date('Y-m-d')), 0, 4);
        $suffix    = $suffix !== '' ? $suffix : 'FACE';
        return "{$sellerCif}_{$serie}-{$num}_{$year}_{$suffix}";
    }

    private function ensureDir(string $dir) : void {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
    }

    private function log(string $tag, array $data = []) : void {
        $log = __DIR__ . '/../data/logs/signature_manager.log';
        @mkdir(dirname($log), 0775, true);
        @file_put_contents($log, '['.date('c')."] {$tag} ".json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
    }

}
