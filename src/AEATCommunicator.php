<?php
// src/AEATCommunicator.php
declare(strict_types=1);

/**
 * AEATCommunicator
 *
 * - Camino principal (preferente): usa josemmo/Verifactu-PHP para construir el RegistroAlta
 *   y enviarlo a Veri*Factu con mTLS, calculando huella con calculateHash() y encadenamiento.
 * - Camino de compatibilidad: si la librería no está disponible o falla la inicialización,
 *   recurre al cliente SOAP interno (implementación anterior).
 */

require_once __DIR__ . '/InvoiceManager.php';
require_once __DIR__ . '/SecureConfig.php';

// Clases de la librería josemmo/Verifactu-PHP (si está instalada vía Composer)
use josemmo\Verifactu\Models\Taxpayer;
use josemmo\Verifactu\Models\ComputerSystem;
use josemmo\Verifactu\Models\RegistrationRecord;
use josemmo\Verifactu\Models\InvoiceIdentification;
use josemmo\Verifactu\Models\BreakdownDetail;
use josemmo\Verifactu\Models\BreakdownDetails;
use josemmo\Verifactu\Models\FiscalIdentifier;
use josemmo\Verifactu\Enums\TaxType;
use josemmo\Verifactu\Enums\RegimeType;
use josemmo\Verifactu\Enums\OperationType;
use josemmo\Verifactu\Enums\InvoiceType;
use josemmo\Verifactu\Services\AeatClient;

final class AEATCommunicator {

    /** WSDL Veri*Factu (fallback SOAP) */
    private const WSDL_TEST = 'https://prewww2.aeat.es/static_files/common/internet/dep/aplicaciones/es/aeat/tikeV1.0/cont/ws/SistemaFacturacion.wsdl';
    private const WSDL_PROD = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tikeV1.0/cont/ws/SistemaFacturacion.wsdl';

    private string $configFile;
    private array $cfg;
    private string $tmpDir;

    public function __construct() {
        $this->configFile = __DIR__ . '/../data/config.json';
        $this->cfg = is_file($this->configFile)
            ? (array)json_decode((string)file_get_contents($this->configFile), true)
            : [];
        $this->tmpDir = __DIR__ . '/../data/certs/tmp/';
        if (!is_dir($this->tmpDir)) {
            @mkdir($this->tmpDir, 0775, true);
        }
    }

    // ===================== API PÚBLICA =====================

    /** Envío de una factura/rectificativa; guarda estado en el XML de la factura. */
    public function sendInvoice(string $invoiceId): array {
        // 1) Intento con la librería Verifactu-PHP
        $libAvailable = class_exists(AeatClient::class)
            && class_exists(RegistrationRecord::class);

        if ($libAvailable) {
            try {
                return $this->sendWithJosemmoLibrary($invoiceId);
            } catch (\Throwable $e) {
                // caemos a SOAP legacy si algo se tuerce en el lado de la librería
                $this->logDebug("verifactu_lib_fallback", $e->getMessage());
            }
        }

        // 2) Fallback: método legado con SoapClient propio
        return $this->sendWithLegacySoap($invoiceId);
    }

    /** Listing de operaciones (útil para diagnóstico). Con fallback si WSDL necesita mTLS. */
    public function listOperations(): array {
        // Si la librería está disponible, devolvemos un set “canónico”
        if (class_exists(AeatClient::class)) {
            return [
                'success' => true,
                // Operación principal usada por la lib:
                'operations' => ['RegFactuSistemaFacturacion (RegistroAlta)']
            ];
        }

        // Fallback: intenta leer funciones del WSDL sin mTLS (solo diagnóstico)
        $env  = strtolower((string)($this->cfg['aeatEnv'] ?? 'test'));
        $wsdl = ($env === 'prod') ? self::WSDL_PROD : self::WSDL_TEST;

        try {
            $plain = new \SoapClient($wsdl, [
                'cache_wsdl' => WSDL_CACHE_NONE,
                'trace'      => 1,
                'exceptions' => true,
            ]);
            $funcs = $plain->__getFunctions();
            return ['success' => true, 'operations' => $funcs ?: []];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'AEAT WSDL error: '.$e->getMessage()];
        }
    }

    /** Extras electrónicos (para FACeB2B/xsig; no se envían a AEAT salvo flag) */
    public function getElectronicInvoiceExtras(string $invoiceId): array {
        $im  = new InvoiceManager();
        $inv = $im->getInvoiceById($invoiceId);
        if (!$inv) return [];
        return $this->buildEInvoiceExtras($inv);
    }

    // ===================== NUEVO: Envío con josemmo/Verifactu-PHP =====================

    private function sendWithJosemmoLibrary(string $invoiceId): array {
        $im  = new InvoiceManager();
        $inv = $im->getInvoiceById($invoiceId);
        if (!$inv) {
            return ['success' => false, 'message' => 'Factura no encontrada'];
        }

        // ---- Certificado PFX/P12 del EMISOR ----
        $p12Path = (string)($this->cfg['certificatePath'] ?? '');
        $p12Pass = (string)($this->cfg['certPassword'] ?? '');
        if ($p12Pass !== '' && class_exists('SecureConfig') && strncmp($p12Pass, 'enc:v1:', 7) === 0) {
            $dec = SecureConfig::decrypt($p12Pass);
            if (is_string($dec) && $dec !== '') $p12Pass = $dec;
        }
        if ($p12Path === '' || !is_file($p12Path)) {
            $im->setAeatStatus($invoiceId, 'Failed', 'No se encontró el certificado del emisor', null);
            return ['success' => false, 'message' => 'Certificado no encontrado'];
        }

        // ---- Emisor / Sistema Informático ----
        $issuerNif  = (string)($this->cfg['nif'] ?? ($this->cfg['issuer']['nif'] ?? ''));
        $issuerName = $this->resolveIssuerName();

        $taxpayer = new Taxpayer($issuerNif);

        $sysName = 'FacturaFlow';
        $sysId   = substr(preg_replace('/[^0-9A-Za-z]/', '', (string)($this->cfg['aeatIdSistema'] ?? 'FF01')), 0, 4) ?: 'FF01';
        $sysVer  = (string)($this->cfg['aeatVersion'] ?? '1.0.0');
        $sysInst = (int)($this->cfg['aeatNumeroInstalacion'] ?? 1);

        $system = new ComputerSystem($sysName, $sysId, $sysVer, $sysInst);
        // Nota: otros flags del sistema (solo verifactu, multiOT) vienen con defaults razonables en la lib

        // ---- Cliente AEAT ----
        $client = new AeatClient($system, $taxpayer, $p12Path, $p12Pass);
        $env  = strtolower((string)($this->cfg['aeatEnv'] ?? 'test'));
        $client->setEnvironmentProduction($env === 'prod');

        // ---- Construcción del RegistrationRecord ----
        [$importeTotal, $cuotaTotal, $details] = $this->buildBreakdownDetails($inv);

        $issueIso  = (string)($inv->issueDate ?? date('Y-m-d'));
        $issueDate = new \DateTimeImmutable($issueIso);

        // Tipo de factura: si marcaste isRectificative=true tratamos como rectificativa
        $isRect = (string)($inv->isRectificative ?? 'false') === 'true';
        $invoiceType = $isRect ? InvoiceType::Rectificativa : InvoiceType::Completa;

        $identification = new InvoiceIdentification(
            $issuerNif,
            (string)$inv->id,   // usamos tu id completo (SERIE-YYYY-NNNN)
            $issueDate
        );

        $record = new RegistrationRecord();
        $record->invoiceType           = $invoiceType;
        $record->invoiceIdentification = $identification;
        $record->totalTaxAmount        = round((float)$cuotaTotal, 2);
        $record->totalAmount           = round((float)$importeTotal, 2);
        $record->breakdownDetails      = $details;
        $record->description           = (string)($inv->concept ?? $inv->description ?? '');

        // Destinatario (si existe)
        $buyerName = $this->resolveBuyerName($inv);
        $buyerNif  = (string)($inv->client->nif ?? '');
        if ($buyerName !== '' || $buyerNif !== '') {
            $record->recipient = new FiscalIdentifier($buyerNif, $buyerName !== '' ? $buyerName : (string)($inv->client->name ?? ''));
        }

        // Encadenamiento: tomamos última entrada del log Verifactu que NO sea esta misma
        $prev = $this->readLastVerifactuEntryExcluding((string)$inv->id);
        if (!empty($prev['invoiceId']) && !empty($prev['hash'])) {
            $record->previousInvoiceId = (string)$prev['invoiceId'];
            $record->previousHash      = (string)$prev['hash'];
        }

        // Huella actual y marca temporal
        $record->hashedAt = new \DateTimeImmutable();          // cuando calculamos la huella
        $hash = $record->calculateHash();                      // HEX UPPER, según librería

        // Enviamos
        try {
            $result = $client->sendRegistrationRecords([$record]);

            // Intento de extraer CSV/estado de una forma tolerante (la lib suele envolver la respuesta)
            $arr = json_decode(json_encode($result, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?: [];
            $csv = $this->findFirstScalar($arr, ['csv','CSV','Csv']);
            $estado = $this->findFirstScalar($arr, ['estadoEnvio','EstadoEnvio','sendingStatus','status']);
            $mensaje = $this->findFirstScalar($arr, ['mensaje','Mensaje','message','descripcion','descripcionResultado']);

            $ok = !is_string($estado) || (stripos((string)$estado, 'incorrect') === false && stripos((string)$estado, 'error') === false);

            // Persistimos estado en el XML de la factura
            $im->setAeatStatus($invoiceId, $ok ? 'Success' : 'Failed', $mensaje ?? ($ok ? 'Envío correcto' : 'Envío fallido'), $csv);

            return [
                'success'  => (bool)$ok,
                'message'  => $mensaje ?? ($ok ? 'Envío correcto' : 'Envío fallido'),
                'receipt'  => $csv,
                'hash'     => $hash,
                'response' => $arr,
            ];
        } catch (\Throwable $e) {
            $im->setAeatStatus($invoiceId, 'Failed', 'Error: '.$e->getMessage(), null);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ===================== LEGACY SOAP (compatibilidad) =====================

    private function sendWithLegacySoap(string $invoiceId): array {
        $im  = new InvoiceManager();
        $inv = $im->getInvoiceById($invoiceId);
        if (!$inv) {
            return ['success' => false, 'message' => 'Factura no encontrada'];
        }

        // === Certificado del emisor ===
        $p12Path = (string)($this->cfg['certificatePath'] ?? '');
        $p12Pass = (string)($this->cfg['certPassword'] ?? '');
        if (class_exists('SecureConfig')) {
            $dec = SecureConfig::decrypt($p12Pass);
            if (is_string($dec) && $dec !== '') $p12Pass = $dec;
        }
        if ($p12Path === '' || !is_file($p12Path)) {
            $im->setAeatStatus($invoiceId, 'Failed', 'No se encontró el certificado del emisor', null);
            return ['success' => false, 'message' => 'Certificado no encontrado'];
        }

        $pem = $this->ensurePemFromP12($p12Path, $p12Pass);
        if (!$pem['ok']) {
            $im->setAeatStatus($invoiceId, 'Failed', 'No se pudo convertir el PKCS#12 a PEM', null);
            return ['success' => false, 'message' => $pem['error'] ?? 'Error convirtiendo el PKCS#12'];
        }

        // === Cliente SOAP (preproducción/producción) ===
        [$wsdl, $soapOpts] = $this->buildSoapOptions($pem['pem'], $p12Pass);
        try {
            $client = new \SoapClient($wsdl, $soapOpts);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'AEAT WSDL error: '.$e->getMessage()];
        }

        // === Payload EXACTO (Cabecera + RegistroFactura[RegistroAlta]) ===
        $payload   = $this->buildAeatPayloadAlta($inv); // mantiene tu payload legacy
        $operation = 'RegFactuSistemaFacturacion';

        try {
            $resp = $client->__soapCall($operation, [ new \SoapParam($payload, $operation) ]);

            if (!empty($this->cfg['aeatLogXml'])) {
                $this->debugSoap($client);
            }

            $parsed = $this->parseAeatResponse($resp);
            $status = $parsed['ok'] ? 'Success' : 'Failed';
            $im->setAeatStatus($invoiceId, $status, $parsed['message'] ?? '', $parsed['csv'] ?? null);

            $this->cleanup([$pem['pem']]);
            return [
                'success'  => $parsed['ok'],
                'message'  => $parsed['message'] ?? ($parsed['ok'] ? 'Envío correcto' : 'Envío fallido'),
                'receipt'  => $parsed['csv'] ?? null,
                'response' => $resp
            ];
        } catch (\SoapFault $sf) {
            if (!empty($this->cfg['aeatLogXml'])) { $this->debugSoap($client); }
            $im->setAeatStatus($invoiceId, 'Failed', 'SoapFault: '.$sf->getMessage(), null);
            $this->cleanup([$pem['pem']]);
            return ['success' => false, 'message' => 'SoapFault: '.$sf->getMessage()];
        } catch (\Throwable $e) {
            if (!empty($this->cfg['aeatLogXml'])) { $this->debugSoap($client); }
            $im->setAeatStatus($invoiceId, 'Failed', 'Error: '.$e->getMessage(), null);
            $this->cleanup([$pem['pem']]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ===================== Helpers NUEVOS (librería) =====================

    /**
     * Agrupa líneas por tipo de IVA y devuelve [importeTotal, cuotaTotal, BreakdownDetails]
     */
    private function buildBreakdownDetails(\SimpleXMLElement $inv): array {
        $groups = []; // rate => [base, cuota]
        if (isset($inv->items) && isset($inv->items->item)) {
            foreach ($inv->items->item as $it) {
                $qty   = (float)($it->quantity ?? 1);
                $price = (float)($it->unitPrice ?? 0);
                $rate  = (float)($it->vatRate ?? 0);
                $base  = $qty * $price;
                $cuota = $base * ($rate / 100.0);
                if (!isset($groups[$rate])) $groups[$rate] = [0.0, 0.0];
                $groups[$rate][0] += $base;
                $groups[$rate][1] += $cuota;
            }
        }

        $details = new BreakdownDetails();
        $cuotaTotal = 0.0;
        $baseTotal  = 0.0;

        foreach ($groups as $rate => [$base, $cuota]) {
            $details->add(new BreakdownDetail(
                TaxType::IVA,
                RegimeType::C01,              // Régimen general
                OperationType::S1,            // Sujeta y no exenta
                round((float)$rate, 2),
                round((float)$base, 2),
                round((float)$cuota, 2)
            ));
            $baseTotal  += $base;
            $cuotaTotal += $cuota;
        }

        // Si no hay líneas, añade un bloque 0% para cumplir esquema
        if ($details->count() === 0) {
            $details->add(new BreakdownDetail(
                TaxType::IVA,
                RegimeType::C01,
                OperationType::S1,
                0.0, 0.0, 0.0
            ));
        }

        $importeTotal = $baseTotal + $cuotaTotal;
        if (isset($inv->irpfRate) && (float)$inv->irpfRate > 0) {
            $importeTotal -= $baseTotal * ((float)$inv->irpfRate / 100.0);
        }
        if (isset($inv->totalSuplidos) && (float)$inv->totalSuplidos > 0) {
            $importeTotal += (float)$inv->totalSuplidos;
        }

        return [$importeTotal, $cuotaTotal, $details];
    }

    private function findFirstScalar(array $arr, array $keys) {
        foreach ($keys as $k) {
            if (isset($arr[$k]) && !is_array($arr[$k])) return $arr[$k];
            // busca en profundidad
            $stack = [$arr];
            while ($stack) {
                $node = array_pop($stack);
                foreach ($node as $kk => $vv) {
                    if ($kk === $k && !is_array($vv)) return $vv;
                    if (is_array($vv)) $stack[] = $vv;
                }
            }
        }
        return null;
    }

    // ===================== Helpers LEGACY (payload/soap) =====================

    /** WSDL + opciones de SoapClient (cert PEM) según entorno */
    private function buildSoapOptions(string $pemPath, ?string $pass): array {
        $env  = strtolower((string)($this->cfg['aeatEnv'] ?? 'test'));
        $wsdl = ($env === 'prod') ? self::WSDL_PROD : self::WSDL_TEST;

        $opts = [
            'trace'        => 1,
            'exceptions'   => true,
            'cache_wsdl'   => WSDL_CACHE_NONE,
            'local_cert'   => $pemPath,      // PEM con cert+key (concatenados)
            'passphrase'   => (string)$pass, // pass de la key privada
            'stream_context' => stream_context_create([
                'ssl' => [
                    'verify_peer'       => true,
                    'verify_peer_name'  => true,
                    'allow_self_signed' => false,
                ],
            ]),
        ];

        return [$wsdl, $opts];
    }

    /**
     * Convierte PKCS#12 a PEM (cert + key concatenados) en un único archivo temporal.
     * Devuelve ['ok'=>bool, 'pem'=>ruta] o ['ok'=>false,'error'=>...]
     */
    private function ensurePemFromP12(string $p12Path, string $password): array {
        $raw = @file_get_contents($p12Path);
        if ($raw === false) {
            return ['ok' => false, 'error' => 'No se pudo leer el PKCS#12'];
        }
        $out = [];
        if (!@openssl_pkcs12_read($raw, $out, $password)) {
            return ['ok' => false, 'error' => 'PKCS#12 inválido o contraseña incorrecta'];
        }
        $cert = (string)($out['cert'] ?? '');
        $key  = (string)($out['pkey'] ?? '');
        if ($cert === '' || $key === '') {
            return ['ok' => false, 'error' => 'El PKCS#12 no contiene cert o clave privada'];
        }
        if (!empty($out['extracerts']) && is_array($out['extracerts'])) {
            foreach ($out['extracerts'] as $c) {
                $cert .= "\n" . trim($c) . "\n";
            }
        }
        if (!is_dir($this->tmpDir)) {
            @mkdir($this->tmpDir, 0775, true);
        }
        $pemPath = $this->tmpDir . 'vf_' . uniqid('', true) . '.pem';
        $pemData = $cert . "\n" . $key . "\n";
        if (@file_put_contents($pemPath, $pemData) === false) {
            return ['ok' => false, 'error' => 'No se pudo escribir PEM temporal'];
        }
        @chmod($pemPath, 0600);
        return ['ok' => true, 'pem' => $pemPath];
    }

    private function cleanup(array $files): void {
        foreach ($files as $f) {
            if ($f && is_file($f)) @unlink($f);
        }
    }

    /** Guarda el último request/response SOAP en /data/aeat_debug/ si aeatLogXml=true */
    private function debugSoap(\SoapClient $client): void {
        try {
            $dir = __DIR__ . '/../data/aeat_debug/';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            @file_put_contents($dir.'last_request.xml',  $client->__getLastRequest());
            @file_put_contents($dir.'last_response.xml', $client->__getLastResponse());
        } catch (\Throwable $e) {
            // silencioso
        }
    }

    // ---------- Helpers de construcción (compartidos con legacy) ----------

    private function resolveIssuerName(): string {
        $issuer = (isset($this->cfg['issuer']) && is_array($this->cfg['issuer'])) ? $this->cfg['issuer'] : $this->cfg;

        $type = strtolower((string)($issuer['entityType'] ?? 'company'));
        $name = ($type === 'freelancer')
            ? trim(($issuer['firstName'] ?? '') . ' ' . ($issuer['lastName'] ?? '') . (empty($issuer['secondSurname']) ? '' : ' ' . $issuer['secondSurname']))
            : (string)($issuer['companyName'] ?? '');

        // saneo básico para AEAT
        $name = preg_replace('/[\r\n\t\x00-\x1F\x7F]/', ' ', $name); // quita control chars
        $name = trim($name);
        if ($name === '') { $name = 'SIN NOMBRE'; }
        if (mb_strlen($name) > 120) { $name = mb_substr($name, 0, 120); }

        return $name;
    }

    private function resolveBuyerName(\SimpleXMLElement $inv): string {
        if (!isset($inv->client)) return '';
        $client = $inv->client;
        $type = strtolower((string)($client->entityType ?? 'company'));
        if ($type === 'freelancer') {
            $fn = trim((string)($client->firstName ?? ''));
            $ln = trim((string)($client->lastName ?? ''));
            $sn = trim((string)($client->secondSurname ?? ''));
            return trim($fn . ' ' . $ln . ($sn ? ' ' . $sn : ''));
        }
        return (string)($client->name ?? '');
    }

    /** 'YYYY-mm-dd' -> 'dd-mm-YYYY' */
    private function fmtDateDMY(string $iso): string {
        $t = strtotime($iso ?: 'now');
        return date('d-m-Y', $t);
    }

    /** Email del emisor desde config.json (issuer.* o raíz por compat) */
    private function resolveIssuerEmail(): string {
        $issuer = (isset($this->cfg['issuer']) && is_array($this->cfg['issuer'])) ? $this->cfg['issuer'] : $this->cfg;
        $email  = (string)($issuer['email'] ?? ($this->cfg['email'] ?? ''));
        return trim($email);
    }

    /** IBAN del emisor desde config.json (issuer.* o raíz por compat), sin espacios */
    private function resolveIssuerIban(): string {
        $issuer = (isset($this->cfg['issuer']) && is_array($this->cfg['issuer'])) ? $this->cfg['issuer'] : $this->cfg;
        $iban   = (string)($issuer['iban'] ?? ($this->cfg['iban'] ?? ''));
        $iban   = preg_replace('/\s+/', '', $iban);
        return strtoupper(trim($iban));
    }

    /** Lee vencimiento de <paymentTerms><dueType/dueDate> del XML y devuelve array normalizado */
    private function readPaymentTerms(\SimpleXMLElement $inv): array {
        $out = ['dueType' => null, 'dueDateIso' => null, 'dueDateDMY' => null];
        if (isset($inv->paymentTerms)) {
            $dt = (string)($inv->paymentTerms->dueType ?? '');
            $dd = (string)($inv->paymentTerms->dueDate ?? '');
            $dt = $dt !== '' ? $dt : null;
            $dd = $dd !== '' ? $dd : null;
            $out['dueType']    = $dt;
            $out['dueDateIso'] = $dd;
            $out['dueDateDMY'] = $dd ? $this->fmtDateDMY($dd) : null;
        }
        return $out;
    }

    /** Construye el bloque de “extras” para factura electrónica (FACeB2B / xsig / opcional AEAT) */
    private function buildEInvoiceExtras(\SimpleXMLElement $inv): array {
        $email = $this->resolveIssuerEmail();
        $iban  = $this->resolveIssuerIban();
        $pt    = $this->readPaymentTerms($inv);

        $extras = [
            'IssuerEmail'  => $email ?: null,
            'IssuerIBAN'   => $iban  ?: null,
            'PaymentTerms' => [
                'DueType'    => $pt['dueType']    ?? null,   // on_receipt | plus60 | custom
                'DueDateDMY' => $pt['dueDateDMY'] ?? null,   // dd-mm-YYYY (humano/firma)
                'DueDateISO' => $pt['dueDateIso'] ?? null,   // YYYY-mm-dd (máquina)
            ],
        ];
        return $this->arrayFilterNulls($extras);
    }

    /** Flag: incluir extras dentro del payload AEAT (desactivado por defecto para no romper esquema) */
    private function includeExtrasInAeat(): bool {
        return (bool)($this->cfg['aeatIncludeExtras'] ?? false);
    }


    /** Almacén de encadenamiento */
    private function chainFile(): string {
        $dir = __DIR__ . '/../data/verifactu/';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . 'aeat_chain.json';
    }
    private function readChain(): array {
        $f = $this->chainFile();
        if (is_file($f)) {
            $j = json_decode((string)file_get_contents($f), true);
            if (is_array($j)) return $j;
        }
        return [];
    }
    private function writeChain(array $chain): void {
        @file_put_contents($this->chainFile(), json_encode($chain, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** Lee la última entrada del verifactu_log.xml excluyendo la actual */
    private function readLastVerifactuEntryExcluding(string $currentId): array {
        $vfFile = __DIR__ . '/../data/verifactu/verifactu_log.xml';
        if (!is_file($vfFile)) return [];

        $xml = @simplexml_load_file($vfFile);
        if (!$xml) return [];

        $entries = $xml->xpath('//entry');
        if (!is_array($entries) || !$entries) return [];
        for ($i = count($entries) - 1; $i >= 0; $i--) {
            $e = $entries[$i];
            $id = (string)($e->invoiceId ?? '');
            if ($id === '' || $id === $currentId) continue;
            return [
                'invoiceId' => $id,
                'issueDate' => (string)($e->issueDate ?? ''),
                'hash'      => (string)($e->hash ?? ''),
            ];
        }
        return [];
    }

    // ===================== Payload legacy y parsing (se mantienen) =====================

    /** Cuerpo para RegFactuSistemaFacturacion (ALTA normal, con encadenamiento+huella) */
    private function buildAeatPayloadAlta(\SimpleXMLElement $inv): array {
        // 1) Cabecera -> ObligadoEmision
        $issuerNif  = (string)($this->cfg['nif'] ?? '');
        $issuerName = $this->resolveIssuerName();

        $issuerName = trim(preg_replace('/\s+/', ' ', (string)$issuerName));
        if ($issuerName === '') {
            $im = new InvoiceManager();
            $im->setAeatStatus((string)$inv->id, 'Failed', 'Falta Nombre/Razón Social del emisor en config.json', null);
            return ['success' => false, 'message' => 'Config incompleta: Nombre/Razón Social vacío'];
        }
        $issuerName = mb_substr($issuerName, 0, 120);

        $cabecera = [
            'ObligadoEmision' => [
                'NombreRazon' => $issuerName,
                'NIF'         => $issuerNif,
            ],
        ];

        // 2) Totales + desglose por IVA
        [$importeTotal, $cuotaTotal, $desglose] = $this->buildDesgloseLegacy($inv);

        // 3) IDFactura (fechas DMY)
        $numSerie = (string)$inv->id;
        $fechaExp = $this->fmtDateDMY((string)$inv->issueDate);
        $tipoFac = ((string)($inv->isRectificative ?? 'false') === 'true') ? 'R1' : 'F1';

        $idFactura = [
            'IDEmisorFactura'        => $issuerNif,
            'NumSerieFactura'        => $numSerie,
            'FechaExpedicionFactura' => $fechaExp,
        ];

        // 4) Destinatarios
        $buyerName = $this->resolveBuyerName($inv);
        $destinatarios = [];
        if ($buyerName !== '' || !empty($inv->client->nif)) {
            $destinatarios['IDDestinatario'] = [
                'NombreRazon' => $buyerName !== '' ? $buyerName : (string)($inv->client->name ?? ''),
                'NIF'         => (string)($inv->client->nif ?? ''),
            ];
        }

        // 5) Encadenamiento (PrimerRegistro / RegistroAnterior)
        $chain      = $this->readChain();
        $byNif      = $chain[$issuerNif] ?? null;
        $isFirst    = !$byNif;
        $fechaHora  = date('c');
        $huellaPrev = $isFirst ? '' : (string)($byNif['huella'] ?? '');

        if (!$isFirst && (($byNif['numSerie'] ?? '') === $numSerie)) {
            $prev = $this->readLastVerifactuEntryExcluding($numSerie);
            if (!empty($prev['invoiceId']) && !empty($prev['hash'])) {
                $huellaPrev = (string)$prev['hash'];
                $prevDateDMY = $this->fmtDateDMY((string)($prev['issueDate'] ?? ''));
                $byNif = [
                    'numSerie' => (string)$prev['invoiceId'],
                    'fechaExp' => $prevDateDMY,
                    'huella'   => $huellaPrev,
                ];
                $isFirst = false;
            } else {
                $isFirst = true;
                $huellaPrev = '';
            }
        }

        $encadenamiento = $isFirst
            ? ['PrimerRegistro' => 'S']
            : ['RegistroAnterior' => [
                'IDEmisorFactura'        => $issuerNif,
                'NumSerieFactura'        => (string)($byNif['numSerie'] ?? ''),
                'FechaExpedicionFactura' => (string)($byNif['fechaExp'] ?? ''),
                'Huella'                 => $huellaPrev,
            ]];

        // 6) SistemaInformatico
        $sistemaInf = $this->buildSistemaInformaticoLegacy();

        // 7) Hash actual
        $huellaActual = $this->computeHuellaAlta([
            'IDEmisorFactura'          => $issuerNif,
            'NumSerieFactura'          => $numSerie,
            'FechaExpedicionFactura'   => $fechaExp,
            'TipoFactura'              => $tipoFac,
            'CuotaTotal'               => $this->n2((float)$cuotaTotal),
            'ImporteTotal'             => $this->n2((float)$importeTotal),
            'HuellaAnterior'           => $huellaPrev,
            'FechaHoraHusoGenRegistro' => $fechaHora,
        ]);

        // 8) RegistroAlta completo
        $registroAlta = [
            'IDVersion'                => '1.0',
            'IDFactura'                => $idFactura,
            'NombreRazonEmisor'        => $issuerName,
            'TipoFactura'              => $tipoFac,
            'DescripcionOperacion'     => (string)($inv->concept ?? $inv->description ?? ''),
            'Destinatarios'            => $destinatarios ?: null,
            'Desglose'                 => $desglose,
            'CuotaTotal'               => $this->n2((float)$cuotaTotal),
            'ImporteTotal'             => $this->n2((float)$importeTotal),
            'Encadenamiento'           => $encadenamiento,
            'SistemaInformatico'       => $sistemaInf,
            'FechaHoraHusoGenRegistro' => $fechaHora,
            'TipoHuella'               => '01',
            'Huella'                   => $huellaActual,
        ];

        if ($this->includeExtrasInAeat()) {
            $extras = $this->buildEInvoiceExtras($inv);
            if (!empty($extras)) {
                $registroAlta['DatosAdicionales'] = $extras;
            }
        }

        $registroAlta = $this->arrayFilterNulls($registroAlta);

        // 9) Persistir cadena para el siguiente envío
        $chain[$issuerNif] = [
            'huella'   => $huellaActual,
            'numSerie' => $numSerie,
            'fechaExp' => $fechaExp,
            'ts'       => $fechaHora,
        ];
        $this->writeChain($chain);

        // 10) Estructura final: RegistroFactura como LISTA (1..N)
        return [
            'Cabecera' => $cabecera,
            'RegistroFactura' => [
                [ 'RegistroAlta' => $registroAlta ]
            ],
        ];
    }

    /** Calcula desglose legado por tipos de IVA y devuelve [importeTotal, cuotaTotal, Desglose] */
    private function buildDesgloseLegacy(\SimpleXMLElement $inv): array {
        $groups = []; // rate => [base, cuota]
        if (isset($inv->items) && isset($inv->items->item)) {
            foreach ($inv->items->item as $it) {
                $qty   = (float)($it->quantity ?? 1);
                $price = (float)($it->unitPrice ?? 0);
                $rate  = (float)($it->vatRate ?? 0);
                $base  = $qty * $price;
                $cuota = $base * ($rate / 100.0);
                if (!isset($groups[$rate])) $groups[$rate] = [0.0, 0.0];
                $groups[$rate][0] += $base;
                $groups[$rate][1] += $cuota;
            }
        }

        $detalleRows = [];
        $cuotaTotal = 0.0;
        $baseTotal  = 0.0;

        foreach ($groups as $rate => [$base, $cuota]) {
            $detalleRows[] = [
                'ClaveRegimen'                  => '01',            // régimen general
                'CalificacionOperacion'         => 'S1',            // sujeta y no exenta
                'TipoImpositivo'                => $this->n2((float)$rate),
                'BaseImponibleOimporteNoSujeto' => $this->n2((float)$base),
                'CuotaRepercutida'              => $this->n2((float)$cuota),
            ];
            $baseTotal  += $base;
            $cuotaTotal += $cuota;
        }

         if (empty($detalleRows)) {
            $detalleRows[] = [
                'ClaveRegimen'                  => '01',
                'CalificacionOperacion'         => 'S1',
                'TipoImpositivo'                => $this->n2(0),
                'BaseImponibleOimporteNoSujeto' => $this->n2(0),
                'CuotaRepercutida'              => $this->n2(0),
            ];
        }

        $importeTotal = $baseTotal + $cuotaTotal;
        if (isset($inv->irpfRate) && (float)$inv->irpfRate > 0) {
            $importeTotal -= $baseTotal * ((float)$inv->irpfRate / 100.0);
        }
        if (isset($inv->totalSuplidos) && (float)$inv->totalSuplidos > 0) {
            $importeTotal += (float)$inv->totalSuplidos;
        }

        $desglose = [
            'DetalleDesglose' => $detalleRows
        ];

        return [$importeTotal, $cuotaTotal, $desglose];
    }

    /** Datos mínimos de SistemaInformatico (legacy payload) */
    private function buildSistemaInformaticoLegacy(): array {
        $issuerName = $this->resolveIssuerName();
        $issuerNif  = (string)($this->cfg['nif'] ?? '');

        $idSif = (string)($this->cfg['aeatIdSistema'] ?? '01');
        $idSif = substr(preg_replace('/[^0-9A-Za-z]/', '', $idSif), 0, 2);
        if ($idSif === '') { $idSif = '01'; }

        return [
            'NombreRazon'                 => $issuerName !== '' ? $issuerName : 'FacturaFlow',
            'NIF'                         => $issuerNif !== '' ? $issuerNif : '00000000T',
            'NombreSistemaInformatico'    => 'FacturaFlow',
            'IdSistemaInformatico'        => $idSif, // máx 2 chars
            'Version'                     => (string)($this->cfg['aeatVersion'] ?? '1.0.0'),
            'NumeroInstalacion'           => (string)($this->cfg['aeatNumeroInstalacion'] ?? '1'),
            'TipoUsoPosibleSoloVerifactu' => 'N',
            'TipoUsoPosibleMultiOT'       => 'S',
            'IndicadorMultiplesOT'        => 'N',
        ];
    }

    /** Cadena de hash ALTA + SHA-256 HEX uppercase (según AEAT, legacy) */
    private function computeHuellaAlta(array $p): string {
        $pairs = [
            'IDEmisorFactura'          => trim((string)($p['IDEmisorFactura'] ?? '')),
            'NumSerieFactura'          => trim((string)($p['NumSerieFactura'] ?? '')),
            'FechaExpedicionFactura'   => trim((string)($p['FechaExpedicionFactura'] ?? '')),
            'TipoFactura'              => trim((string)($p['TipoFactura'] ?? '')),
            'CuotaTotal'               => trim((string)($p['CuotaTotal'] ?? '')),
            'ImporteTotal'             => trim((string)($p['ImporteTotal'] ?? '')),
            'Huella'                   => trim((string)($p['HuellaAnterior'] ?? '')),
            'FechaHoraHusoGenRegistro' => trim((string)($p['FechaHoraHusoGenRegistro'] ?? '')),
        ];
        $str = implode('&', array_map(static fn($k,$v)=>$k.'='.$v, array_keys($pairs), $pairs));
        return strtoupper(hash('sha256', $str));
    }

    /** Interpreta respuesta AEAT (estado, CSV, mensaje) — legacy */
    private function parseAeatResponse($resp): array {
        $arr = json_decode(json_encode($resp), true);

        $estado = $arr['EstadoEnvio'] ?? $arr['estadoEnvio'] ?? null;
        $ok = (is_string($estado) && strtolower($estado) !== 'incorrecto');
        $msg = $arr['Mensaje'] ?? $arr['mensaje'] ?? null;

        // Detalles por registro si vienen
        $detalles = [];
        $paths = [
            ['RespuestaRegFactuSistemaFacturacion','RegistroEstado'],
            ['RegistroEstado'],
            ['DetalleErrores'],
            ['Respuestas','Respuesta']
        ];
        foreach ($paths as $p) {
            $node = $arr;
            $found = true;
            foreach ($p as $k) {
                if (!isset($node[$k])) { $found = false; break; }
                $node = $node[$k];
            }
            if ($found) { $detalles = $node; break; }
        }
        if ($detalles) {
            if (isset($detalles['Codigo']) || isset($detalles['Descripcion'])) {
                $detalles = [$detalles];
            }
            $parts = [];
            foreach ($detalles as $d) {
                $c = $d['Codigo'] ?? $d['codigo'] ?? null;
                $t = $d['Descripcion'] ?? $d['descripcion'] ?? null;
                if ($c || $t) $parts[] = trim(($c?("[".$c."] "):"").(string)$t);
            }
            if ($parts) {
                $msg = $msg ? ($msg.' | '.implode(' ; ', $parts)) : implode(' ; ', $parts);
            }
        }

        $csv = $arr['CSV'] ?? $arr['csv'] ?? null;
        if (!$msg && $estado) $msg = 'Estado: '.$estado;
        return ['ok' => (bool)$ok, 'csv' => $csv, 'message' => $msg];
    }

    // ===================== Utilidades =====================

    private function n2(float $n): string {
        return number_format($n, 2, '.', '');
    }

    private function logDebug(string $tag, string $msg): void {
        $dir = __DIR__ . '/../data/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir.'/aeat_debug.log', '['.date('c')."] {$tag}: {$msg}\n", FILE_APPEND);
    }
}

