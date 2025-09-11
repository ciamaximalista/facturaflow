<?php
declare(strict_types=1);

/**
 * FACeB2B client “drop-in” sobre josemmo/facturae-php.
 * - Mantiene nombre/clase y métodos usados por la app (sendInvoice, debugList, debugDownloadPeek, ...).
 * - Usa el certificado del EMISOR o el específico de FACeB2B para WS-Security (no mTLS).
 * - El XML/XSIG llega YA firmado XAdES por FacturaeGenerator (no se re-firma).
 */

use josemmo\Facturae\FacturaeFile;
use josemmo\Facturae\Face\Faceb2bClient as JosemmoFaceb2bClient;

final class FaceB2BClient {
    /** @var array<string,mixed> */
    private array $cfg;
    private string $logFile;
    /** @var JosemmoFaceb2bClient|null */
    private ?JosemmoFaceb2bClient $client = null;

    public function __construct(array $cfg = []) {
        $this->cfg = $cfg;
        $this->logFile = __DIR__ . '/../data/logs/faceb2b.log';
        @mkdir(dirname($this->logFile), 0775, true);
    }

    /**
     * Envía una factura (XML/XSIG ya firmado) a FACeB2B.
     * @param string $xmlPath Ruta al XML/XSIG firmado.
     * @param string $receivingDire DIRe del receptor.
     * @return array{success:bool,message?:string,registrationCode?:string,response?:mixed,result?:mixed}
     */
    public function sendInvoice(string $xmlPath, string $receivingDire): array {
        $invId = basename((string)preg_replace('~\.\w+$~', '', $xmlPath));
        $this->log('send_start', ['xml'=>basename($xmlPath), 'dire'=>$receivingDire]);

        if (!is_file($xmlPath)) return $this->fail('No se encuentra el fichero XML a enviar.');
        $xml = (string)@file_get_contents($xmlPath);
        if ($xml === '')        return $this->fail('El fichero XML está vacío.');
        if (!preg_match('/<([a-z0-9._-]+:)?Signature\b/i', $xml)) {
            return $this->fail('El Facturae no está firmado (falta Signature).');
        }

        // Validación suave: presencia de DIRe en el XML (como marca diagnóstica)
        $this->softCheckDireInXml($xml, $receivingDire);

        try {
            $cli = $this->getClient();
            $ff = new FacturaeFile();
            $ff->loadData($xml, basename($xmlPath)); // NO re-firma

            $res = $cli->sendInvoice($ff);

            // Normaliza respuesta (mantén shape ya usado por index.php)
            $arr = json_decode(json_encode($res, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?? [];
            $reg  = $arr['invoiceDetail']['registryNumber'] ?? $arr['registrationCode'] ?? null;
            $code = (int)($arr['resultStatus']['code'] ?? $arr['result']['status']['code'] ?? 1);
            $msg  = (string)($arr['resultStatus']['message'] ?? $arr['result']['status']['message'] ?? '');

            if ($code === 0 && $reg) {
                $this->log('sent_ok', ['id'=>$invId, 'registrationCode'=>$reg]);
                return ['success'=>true, 'message'=>'OK', 'registrationCode'=>(string)$reg, 'result'=>$arr];
            }
            $this->log('sent_nok', ['id'=>$invId, 'msg'=>$msg ?: 'Rechazado por FACeB2B', 'raw'=>$arr]);
            return ['success'=>false, 'message'=>$msg ?: 'Rechazado por FACeB2B', 'response'=>$arr];
        } catch (\Throwable $e) {
            $this->log('send_err', ['id'=>$invId, 'msg'=>$e->getMessage()]);
            return $this->fail($e->getMessage() ?: 'Error enviando a FACeB2B');
        }
    }

    // -------------------- debug helpers (compat con tu index.php) --------------------

    /**
     * Listado (filtros opcionales nif/dire). Devuelve array simple para inspección.
     * @param array{nif?:string,dire?:string} $params
     */
    public function debugList(array $params = []): array {
        try {
            $cli = $this->getClient();
            $filters = [];
            if (!empty($params['nif']))  $filters['nif']  = (string)$params['nif'];
            if (!empty($params['dire'])) $filters['dire'] = (string)$params['dire'];
            $res = $cli->listInvoices($filters, 0, 50);
            $this->log('debug_list', ['filters'=>$filters, 'count'=>is_countable($res)?count($res):null]);
            return json_decode(json_encode($res, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?: ['ok'=>false,'message'=>'sin datos'];
        } catch (\Throwable $e) {
            return ['ok'=>false, 'message'=>$e->getMessage()];
        }
    }

    /**
     * Descarga factura + informe (si procede) pero sólo “peek” para debug.
     */
    public function debugDownloadPeek(string $registryNumber): array {
        try {
            $cli = $this->getClient();
            $validate = (bool)($this->cfg['validate_signature'] ?? true);
            $res = $cli->downloadInvoice($registryNumber, $validate);
            $this->log('debug_download', ['reg'=>$registryNumber, 'validate'=>$validate]);

            $arr = json_decode(json_encode($res, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?: [];
            $out = [
                'ok' => true,
                'invoiceFile' => [
                    'name' => $res->invoiceFile->name ?? null,
                    'size' => isset($res->invoiceFile) ? strlen($res->invoiceFile->getData()) : null
                ],
                'reportFile' => [
                    'name' => $res->reportFile->name ?? null,
                    'size' => isset($res->reportFile) ? strlen($res->reportFile->getData()) : null
                ],
                'raw' => $arr
            ];
            return $out;
        } catch (\Throwable $e) {
            return ['ok'=>false, 'message'=>$e->getMessage()];
        }
    }

    /** Lista recibidas para filtros (nif/dire), normalizado a array simple. */
    public function listReceived(array $filters = []): array {
        $cli = $this->getClient();
        $apiFilters = [];
        if (!empty($filters['nif']))  $apiFilters['nif']  = (string)$filters['nif'];
        if (!empty($filters['dire'])) $apiFilters['dire'] = (string)$filters['dire'];

        $raw = $cli->listInvoices($apiFilters, 0, 200);
        $arr = json_decode(json_encode($raw, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?: [];

        $out = [];
        foreach ($arr as $it) {
            $out[] = [
                'id'        => (string)($it['registryNumber'] ?? $it['id'] ?? ''),
                'number'    => (string)($it['invoiceNumber'] ?? $it['number'] ?? ''),
                'issueDate' => (string)($it['issueDate'] ?? ''),
                'status'    => (string)($it['status'] ?? $it['state'] ?? ''),
            ];
        }
        return $out;
    }

    /** Descarga la factura y devuelve el XML (string). Valida la firma en origen según config. */
    public function downloadInvoiceXml(string $registryNumber): string {
        $cli = $this->getClient();
        $validate = (bool)($this->cfg['validate_signature'] ?? true);
        $res = $cli->downloadInvoice($registryNumber, $validate);
        if (!isset($res->invoiceFile)) {
            throw new \RuntimeException('FACeB2B no devolvió invoiceFile');
        }
        $data = $res->invoiceFile->getData();
        if (!is_string($data) || $data === '') {
            throw new \RuntimeException('invoiceFile vacío');
        }
        return $data;
    }

    // -------------------- extras (compat) --------------------

    public function getStatus(string $registryNumber): array {
        try {
            $res = $this->getClient()->getInvoiceStatus($registryNumber);
            return ['success'=>true, 'response'=>json_decode(json_encode($res), true)];
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }

    public function confirmDownload(string $registryNumber): array {
        try {
            $this->getClient()->confirmInvoiceDownload($registryNumber);
            return ['success'=>true];
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }

    public function markAsPaid(string $registryNumber): array {
        try {
            $this->getClient()->markInvoiceAsPaid($registryNumber);
            return ['success'=>true];
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }

    public function reject(string $registryNumber, string $reason, ?string $comment=null): array {
        try {
            $this->getClient()->rejectInvoice($registryNumber, $reason, $comment);
            return ['success'=>true];
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }

    public function getChangesSince(string $sinceRfc3339, int $limit=100, int $offset=0): array {
        try {
            $res = $this->getClient()->getInvoicesSince($sinceRfc3339, $limit, $offset);
            return ['success'=>true, 'items'=>json_decode(json_encode($res), true)];
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }

    // -------------------- internals --------------------

    /**
     * Construye/recupera el cliente josemmo con el certificado (prioriza FACeB2B.p12).
     * `index.php` le pasa $faceCfg = $cfg['faceb2b'].
     */
    private function getClient(): JosemmoFaceb2bClient {
        if ($this->client instanceof JosemmoFaceb2bClient) return $this->client;

        // 1) Preferencia: P12 específico de FACeB2B guardado en config.json
        $p12Path = (string)($this->cfg['p12_path'] ?? '');
        $p12Pass = (string)($this->cfg['p12_pass'] ?? '');

        // 2) Descifrar si viene enc:v1: con SecureConfig
        if ($p12Pass !== '' && strncmp($p12Pass, 'enc:v1:', 7) === 0) {
            if (class_exists('SecureConfig')) {
                $dec = \SecureConfig::decrypt($p12Pass);
                if (is_string($dec) && $dec !== '') $p12Pass = $dec;
            } else {
                // Fallback compat si no hay SecureConfig
                $dec = $this->tryCompatLocalDecrypt($p12Pass);
                if ($dec !== '') $p12Pass = $dec;
            }
        }

        // 3) Si no hay P12 específico, intentar fallback al certificado general del emisor
        if ($p12Path === '' || !is_file($p12Path)) {
            $cfgPath = __DIR__ . '/../data/config.json';
            $cfgFull = is_file($cfgPath) ? (array)json_decode((string)@file_get_contents($cfgPath), true) : [];
            $issuer  = (array)($cfgFull['issuer'] ?? $cfgFull);
            $altPath = (string)($issuer['certificatePath'] ?? '');
            $altPass = (string)($issuer['certPassword']   ?? '');
            if ($altPass !== '' && strncmp($altPass, 'enc:v1:', 7) === 0 && class_exists('SecureConfig')) {
                $dec = \SecureConfig::decrypt($altPass);
                if (is_string($dec) && $dec !== '') $altPass = $dec;
            }
            if ($p12Path === '' && $altPath !== '') $p12Path = $altPath;
            if ($p12Pass === '' && $altPass !== '') $p12Pass = $altPass;
        }

        // 4) Acepta también claves legacy si alguien las rellena
        if (($this->cfg['issuer_p12'] ?? '') !== '' && $p12Path === '') {
            $p12Path = (string)$this->cfg['issuer_p12'];
        }
        if (($this->cfg['issuer_pass'] ?? '') !== '' && $p12Pass === '') {
            $p12Pass = (string)$this->cfg['issuer_pass'];
        }

        if ($p12Path === '' || !is_file($p12Path)) {
            throw new \RuntimeException("No se encuentra el P12 para FACeB2B: {$p12Path}");
        }

        $cli = new JosemmoFaceb2bClient($p12Path, null, $p12Pass);

        // PRE/PROD (true=pre): usa setProduction(false) cuando use_pre=true
        $usePre = (bool)($this->cfg['use_pre'] ?? false);
        if (method_exists($cli, 'setProduction')) {
            $cli->setProduction(!$usePre);
        }

        if (!empty($this->cfg['timeout']) && method_exists($cli, 'setTimeout')) {
            $cli->setTimeout((int)$this->cfg['timeout']);
        }
        if (!empty($this->cfg['proxy']) && method_exists($cli, 'setProxy')) {
            $cli->setProxy((string)$this->cfg['proxy']);
        }

        // NOTA: algunos despliegues permitían cambiar WSDLs; si tu vendor soporta setters de endpoint,
        // podrías mapear aquí wsdl_pre/wsdl_prod. Por defecto usamos las URLs de la librería.

        $this->client = $cli;
        return $cli;
    }

    private function readPassFile(string $path): string {
        return is_file($path) ? trim((string)@file_get_contents($path)) : '';
    }

    /** Compat: AES-256-CBC con enc:v1:BASE64(iv||ct) + secret.key (histórico). */
    private function tryCompatLocalDecrypt(string $enc): string {
        $b64 = substr($enc, 7);
        $b64 = preg_replace('~[^A-Za-z0-9+/=]~', '', $b64 ?? '');
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < 17) return '';
        $iv  = substr($raw, 0, 16);
        $ct  = substr($raw, 16);

        $candidates = [
            '/var/www/html/cifra/secret.key',
            __DIR__ . '/../data/secret.key',
        ];
        foreach ($candidates as $kfile) {
            if (!is_file($kfile)) continue;
            $k = (string)@file_get_contents($kfile);
            if ($k === '') continue;
            $key = hash('sha256', $k, true);
            $plain = openssl_decrypt($ct, 'aes-256-cbc', $key, OPENSSL_ZERO_PADDING, $iv);
            if (is_string($plain) && $plain !== '') {
                $pad = ord(substr($plain, -1));
                if ($pad > 0 && $pad <= 16) $plain = substr($plain, 0, -$pad);
                return $plain;
            }
        }
        return '';
    }

    private function log(string $msg, $ctx = null): void {
        $line = '['.date('c')."] {$msg}";
        if ($ctx !== null) {
            if (!is_string($ctx)) $ctx = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $line .= ' ' . $ctx;
        }
        @file_put_contents($this->logFile, $line . "\n", FILE_APPEND);
    }

    private function fail(string $message): array {
        return ['success'=>false,'message'=>$message];
    }

    /** Valida “suavemente” que el DIRe está en alguna propiedad adicional del XML. */
    private function softCheckDireInXml(string $xml, string $dire): void {
        if ($dire === '') return;
        $pat = preg_quote($dire, '~');
        $ok = (bool)preg_match('~FACeB2B-ReceivingUnit~i', $xml) && (strpos($xml, $dire) !== false);
        $this->log('receiving_unit_check', ['dire'=>$dire, 'present'=>$ok]);
    }
}

