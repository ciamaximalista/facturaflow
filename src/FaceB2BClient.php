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
        // Merge configuración externa (global/local) para REST/SOAP sin depender de data/config.json
        $this->mergeExternalConfig();
    }

    /**
     * Actualiza el estado vía API REST (si está configurada) o cae a SOAP si es posible.
     * $status: códigos oficiales (1100 registrada, 1300 confirmada, 2500 pagada, 2600 rechazada, 3100 anulada)
     * $reason: obligatorio según API REST; se usa como comentario si el canal es SOAP.
     */
    public function updateInvoiceStatus(string $registryNumber, string $status, string $reason, ?string $comment = null): array {
        $status = trim($status);
        $reason = trim($reason);
        $comment = $comment !== null ? trim($comment) : null;

        // 1) Intentar REST si está configurado
        $base = (string)($this->cfg['rest_base'] ?? $this->cfg['rest_url'] ?? '');
        if ($base !== '') {
            $url = rtrim($base, "/") . '/v1/invoices/' . rawurlencode($registryNumber);
            $payload = [ 'status' => $status, 'reason' => ($reason !== '' ? $reason : 'estado actualizado'), 'comment' => $comment ];
            $res = $this->doRestPatch($url, $payload);
            if (!empty($res['success'])) return $res;
            $this->log('rest_patch_failed', ['url'=>$url, 'err'=>$res['error'] ?? null, 'http'=>$res['status'] ?? null, 'resp'=>$res['response'] ?? null]);
            // sigue a SOAP si falla
        }

        // 2) Fallback SOAP (limitado a pagada/rechazada/confirmación de descarga)
        try {
            if ($status === '2500') { // pagada
                return $this->markAsPaid($registryNumber);
            }
            if ($status === '2600') { // rechazada
                $code = preg_match('/^[A-Z]\d{3}$/', $reason) ? $reason : 'R001';
                return $this->reject($registryNumber, $code, ($reason !== $code ? $reason : $comment));
            }
            if ($status === '1300') { // confirmada → no existe en SOAP: confirmDownload
                $this->confirmDownload($registryNumber);
                return ['success'=>true, 'message'=>'Descarga confirmada (fallback SOAP)'];
            }
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }

        return ['success'=>false, 'message'=>'No hay canal disponible para este estado'];
    }

    /** HTTP PATCH sencillo para el API REST; soporta Bearer con JWT RS256 o Basic */
    private function doRestPatch(string $url, array $payload): array {
        // Asegura config actualizada (rest_base/rest_token/etc.)
        $this->mergeExternalConfig();
        $ch = curl_init($url);
        $headers = ['Content-Type: application/json'];
        // Auth: Bearer token fijo, Bearer JWT (RS256 + x5c) o Basic user:pass
        if (!empty($this->cfg['rest_token'])) {
            $headers[] = 'Authorization: Bearer ' . $this->cfg['rest_token'];
        } elseif (!empty($this->cfg['rest_user']) && isset($this->cfg['rest_pass'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $this->cfg['rest_user'] . ':' . $this->cfg['rest_pass']);
        } else {
            $jwt = $this->buildJwtToken();
            if ($jwt !== '') $headers[] = 'Authorization: Bearer ' . $jwt;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => (int)($this->cfg['timeout'] ?? 30),
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $resp = null;
        if (is_string($body) && $body !== '') {
            $tmp = json_decode($body, true);
            if (is_array($tmp)) $resp = $tmp; else $resp = ['raw'=>$body];
        }
        $ok = ($http >= 200 && $http < 300);
        return [ 'success'=>$ok, 'status'=>$http, 'response'=>$resp, 'error'=>$err ?: null ];
    }

    /** HTTP GET para API REST (con Bearer JWT/Token o Basic) */
    private function doRestGet(string $url, array $query = []): array {
        $this->mergeExternalConfig();
        if ($query) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        $ch = curl_init($url);
        $headers = [];
        if (!empty($this->cfg['rest_token'])) {
            $headers[] = 'Authorization: Bearer ' . $this->cfg['rest_token'];
        } elseif (!empty($this->cfg['rest_user']) && isset($this->cfg['rest_pass'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $this->cfg['rest_user'] . ':' . $this->cfg['rest_pass']);
        } else {
            $jwt = $this->buildJwtToken();
            if ($jwt !== '') $headers[] = 'Authorization: Bearer ' . $jwt;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => (int)($this->cfg['timeout'] ?? 30),
        ]);
        $body = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        $resp = null; if (is_string($body) && $body !== '') { $tmp = json_decode($body, true); $resp = is_array($tmp) ? $tmp : ['raw'=>$body]; }
        $ok = ($http >= 200 && $http < 300);
        return ['success'=>$ok, 'status'=>$http, 'response'=>$resp, 'error'=>$err ?: null ];
    }

    /** HTTP POST para API REST (con Bearer JWT/Token o Basic) */
    private function doRestPost(string $url, array $payload): array {
        $this->mergeExternalConfig();
        $ch = curl_init($url);
        $headers = ['Content-Type: application/json'];
        if (!empty($this->cfg['rest_token'])) {
            $headers[] = 'Authorization: Bearer ' . $this->cfg['rest_token'];
        } elseif (!empty($this->cfg['rest_user']) && isset($this->cfg['rest_pass'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $this->cfg['rest_user'] . ':' . $this->cfg['rest_pass']);
        } else {
            $jwt = $this->buildJwtToken();
            if ($jwt !== '') $headers[] = 'Authorization: Bearer ' . $jwt;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => (int)($this->cfg['timeout'] ?? 30),
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
        $body = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        $resp = null; if (is_string($body) && $body !== '') { $tmp = json_decode($body, true); $resp = is_array($tmp) ? $tmp : ['raw'=>$body]; }
        $ok = ($http >= 200 && $http < 300);
        return ['success'=>$ok, 'status'=>$http, 'response'=>$resp, 'error'=>$err ?: null ];
    }

    // -------- Cancelación (REST) --------
    public function listCancellationRequests(?string $direCode = null): array {
        $base = (string)($this->cfg['rest_base'] ?? ''); if ($base === '') return ['success'=>false,'message'=>'REST no configurado'];
        $url = rtrim($base,'/') . '/v1/invoices/cancellation-request';
        return $this->doRestGet($url, $direCode ? ['code'=>$direCode] : []);
    }

    public function requestCancellation(string $registry, string $reasonCode, ?string $comment=null): array {
        $base = (string)($this->cfg['rest_base'] ?? ''); if ($base === '') return ['success'=>false,'message'=>'REST no configurado'];
        $url = rtrim($base,'/') . '/v1/invoices/' . rawurlencode($registry) . '/cancellation-request';
        $payload = ['reason'=>$reasonCode]; if ($comment) $payload['comment'] = $comment;
        return $this->doRestPost($url, $payload);
    }

    public function decideCancellation(string $registry, string $cancelRequestId, string $action, ?string $comment=null): array {
        $base = (string)($this->cfg['rest_base'] ?? ''); if ($base === '') return ['success'=>false,'message'=>'REST no configurado'];
        $url = rtrim($base,'/') . '/v1/invoices/' . rawurlencode($registry) . '/cancellation-request/' . rawurlencode($cancelRequestId);
        $payload = ['action' => ($action === 'accept' ? 'accept' : 'reject')]; if ($comment) $payload['comment'] = $comment;
        return $this->doRestPatch($url, $payload);
    }

    /** Construye un JWT RS256 con x5c según el manual FACeB2B (caducidad ~5 minutos) */
    private function buildJwtToken(): string {
        // Asegura config actualizada (p12_path/p12_pass)
        $this->mergeExternalConfig();
        $p12 = (string)($this->cfg['p12_path'] ?? '');
        $pass = (string)($this->cfg['p12_pass'] ?? '');
        if ($p12 === '' && is_file('/var/www/html/cifra/max.p12')) $p12 = '/var/www/html/cifra/max.p12';
        if ($p12 === '' || !is_file($p12)) return '';
        if ($pass !== '' && strncmp($pass, 'enc:v1:', 7) === 0 && class_exists('SecureConfig')) {
            $dec = \SecureConfig::decrypt($pass); if (is_string($dec) && $dec !== '') $pass = $dec;
        }
        $raw = @file_get_contents($p12); if ($raw === false) return '';
        $certs = [];
        if (!openssl_pkcs12_read($raw, $certs, $pass)) return '';
        $privKey = $certs['pkey'] ?? null;
        $certPem = $certs['cert'] ?? null;
        if (!$privKey || !$certPem) return '';
        // Extrae cuerpo del cert PEM (base64 DER)
        $b64der = $this->pemToBase64Der($certPem);
        if ($b64der === '') return '';
        $header = [ 'typ'=>'JWT', 'alg'=>'RS256', 'x5c'=> [ $b64der ] ];
        $now = time(); $exp = $now + 300; // 5 minutos
        $payload = [ 'iat'=>$now, 'exp'=>$exp ];
        $enc = fn($a)=>rtrim(strtr(base64_encode(json_encode($a, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
        $signingInput = $enc($header) . '.' . $enc($payload);
        $signature = '';
        if (!openssl_sign($signingInput, $signature, $privKey, OPENSSL_ALGO_SHA256)) return '';
        $sigB64 = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        return $signingInput . '.' . $sigB64;
    }

    private function pemToBase64Der(string $pem): string {
        $pem = trim($pem);
        if (preg_match('~-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----~s', $pem, $m)) {
            $data = preg_replace('~\s+~', '', $m[1]);
            return $data ?: '';
        }
        return '';
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
            $code = (string)($arr['resultStatus']['code'] ?? $arr['result']['status']['code'] ?? '');
            $msg  = (string)($arr['resultStatus']['message'] ?? $arr['result']['status']['message'] ?? '');

            if ($reg) {
                $this->log('sent_ok', ['id'=>$invId, 'registrationCode'=>$reg]);
                return ['success'=>true, 'message'=>'OK', 'registrationCode'=>(string)$reg, 'result'=>$arr];
            }

            // Fallback: si hay error de firma (FS001) reintenta con EXC-C14N=true
            $codeU = strtoupper($code);
            if ($codeU === 'FS001' || stripos($msg, 'firma') !== false) {
                $this->log('retry_exc_c14n', ['id'=>$invId]);
                $cli2 = $this->buildClient(true);
                $res2 = $cli2->sendInvoice($ff);
                $arr2 = json_decode(json_encode($res2, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?? [];
                $reg2 = $arr2['invoiceDetail']['registryNumber'] ?? $arr2['registrationCode'] ?? null;
                if ($reg2) {
                    $this->log('sent_ok', ['id'=>$invId, 'registrationCode'=>$reg2, 'mode'=>'exc_c14n']);
                    return ['success'=>true, 'message'=>'OK', 'registrationCode'=>(string)$reg2, 'result'=>$arr2];
                }
                $msg2 = (string)($arr2['resultStatus']['message'] ?? $arr2['result']['status']['message'] ?? $msg);
                $this->log('sent_nok', ['id'=>$invId, 'msg'=>$msg2 ?: 'Rechazado por FACeB2B', 'raw'=>$arr2]);
                return ['success'=>false, 'message'=>$msg2 ?: 'Rechazado por FACeB2B', 'response'=>$arr2];
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
            $dire = !empty($params['dire']) ? (string)$params['dire'] : null;
            $res = $cli->getRegisteredInvoices($dire);
            $arr = json_decode(json_encode($res, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?: [];
            $this->log('debug_list', ['dire'=>$dire, 'raw_keys'=>array_keys($arr)]);
            return $arr ?: ['ok'=>false,'message'=>'sin datos'];
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
                    'name' => $arr['invoiceFile']['name'] ?? null,
                    'size' => isset($arr['invoiceFile']['content']) ? strlen((string)base64_decode((string)$arr['invoiceFile']['content'], true)) : null
                ],
                'reportFile' => [
                    'name' => $arr['reportFile']['name'] ?? null,
                    'size' => isset($arr['reportFile']['content']) ? strlen((string)base64_decode((string)$arr['reportFile']['content'], true)) : null
                ],
                'raw' => $arr
            ];
            return $out;
        } catch (\Throwable $e) {
            return ['ok'=>false, 'message'=>$e->getMessage()];
        }
    }

    /** Devuelve los códigos de negocio (invoiceStatus, rejectionReason, cancellationReason) en array */
    public function getCodes(string $type = ''): array {
        try {
            $cli = $this->getClient();
            $res = $cli->getCodes($type);
            $arr = json_decode(json_encode($res, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?: [];
            $items = [];
            $codes = $arr['codes']['item'] ?? [];
            if (isset($codes['code'])) $codes = [$codes]; // normaliza si viene uno solo
            foreach ((array)$codes as $it) {
                $items[] = [
                    'code' => (string)($it['code'] ?? ''),
                    'name' => (string)($it['name'] ?? ''),
                    'description' => (string)($it['description'] ?? ''),
                ];
            }
            return ['success'=>true, 'items'=>$items];
        } catch (\Throwable $e) {
            return ['success'=>false, 'message'=>$e->getMessage()];
        }
    }

    /** Lista recibidas para filtros (nif/dire), normalizado a array simple. */
    public function listReceived(array $filters = []): array {
        $cli = $this->getClient();
        $dire = !empty($filters['dire']) ? (string)$filters['dire'] : null;

        $fetch = function($d) use ($cli) {
            $raw = $cli->getRegisteredInvoices($d);
            $arr = json_decode(json_encode($raw, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?: [];
            $nums = [];
            $node = $arr['newRegisteredInvoices']['registryNumber'] ?? null;
            if (is_string($node) && $node !== '') {
                $nums[] = $node;
            } elseif (is_array($node)) {
                foreach ($node as $n) {
                    if (is_string($n) && $n !== '') $nums[] = $n;
                    elseif (is_array($n) && isset($n['value']) && is_string($n['value'])) $nums[] = $n['value'];
                }
            }
            $this->log('debug_list', ['dire'=>$d, 'count'=>count($nums)]);
            return $nums;
        };

        $nums = $fetch($dire);
        if (!$nums) {
            // Reintento sin filtrar por DIRe (por si el broker no filtra por unidad)
            $nums = $fetch(null);
            if ($nums) $this->log('debug_list_retry_all', ['found'=>count($nums)]);
        }

        $out = [];
        foreach ($nums as $reg) {
            $out[] = [
                'id'        => (string)$reg,
                'number'    => '',
                'issueDate' => '',
                'status'    => '',
            ];
        }
        return $out;
    }

    /** Descarga la factura y devuelve el XML (string). Valida la firma en origen según config. */
    public function downloadInvoiceXml(string $registryNumber): string {
        $bundle = $this->downloadInvoiceBundle($registryNumber);
        return $bundle['invoiceData'];
    }

    /** Descarga invoice y report (si existe), decodificados. */
    public function downloadInvoiceBundle(string $registryNumber): array {
        $cli = $this->getClient();
        $validate = (bool)($this->cfg['validate_signature'] ?? true);
        $res = $cli->downloadInvoice($registryNumber, $validate);
        $arr = json_decode(json_encode($res, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?: [];
        if (empty($arr['invoiceFile']) || empty($arr['invoiceFile']['content'])) {
            throw new \RuntimeException('FACeB2B no devolvió invoiceFile');
        }
        $invData = base64_decode((string)$arr['invoiceFile']['content'], true);
        if (!is_string($invData) || $invData === '' || $invData === false) {
            throw new \RuntimeException('invoiceFile vacío');
        }
        $repData = null;
        if (!empty($arr['reportFile']['content'])) {
            $tmp = base64_decode((string)$arr['reportFile']['content'], true);
            if (is_string($tmp) && $tmp !== '') $repData = $tmp;
        }
        return ['invoiceData'=>$invData, 'reportData'=>$repData];
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

    /** Devuelve detalles normalizados de una factura registrada. */
    public function getDetails(string $registryNumber): array {
        try {
            $cli = $this->getClient();
            $res = $cli->getInvoiceDetails($registryNumber);
            return json_decode(json_encode($res, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?: [];
        } catch (\Throwable $e) {
            return ['ok'=>false, 'message'=>$e->getMessage()];
        }
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

    public function accept(string $registryNumber): array {
        try {
            $cli = $this->getClient();
            if (method_exists($cli, 'acceptInvoice')) {
                $cli->acceptInvoice($registryNumber);
                return ['success'=>true];
            }
            if (method_exists($cli, 'acceptRegisteredInvoice')) {
                $cli->acceptRegisteredInvoice($registryNumber);
                return ['success'=>true];
            }
            if (method_exists($cli, 'setInvoiceAccepted')) {
                $cli->setInvoiceAccepted($registryNumber);
                return ['success'=>true];
            }
            return ['success'=>false,'message'=>'El cliente FACeB2B no soporta aceptación explícita'];
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
        $this->client = $this->buildClient(false);
        return $this->client;
    }

    /** Construye un cliente nuevo con C14N exclusiva según parámetro. */
    private function buildClient(bool $exclusiveC14n): JosemmoFaceb2bClient {
        // Fuente global (compartida) en /var/www/html/cifra/faceb2b.json
        $globalCfgPath = '/var/www/html/cifra/faceb2b.json';
        if (is_file($globalCfgPath)) {
            $gj = json_decode((string)@file_get_contents($globalCfgPath), true);
            if (is_array($gj)) {
                foreach ($gj as $k => $v) {
                    if (!array_key_exists($k, $this->cfg)) $this->cfg[$k] = $v;
                }
            }
        }
        // Config local por instalación (opcional) en data/faceb2b.json
        $localCfgPath = __DIR__ . '/../data/faceb2b.json';
        if (is_file($localCfgPath)) {
            $lj = json_decode((string)@file_get_contents($localCfgPath), true);
            if (is_array($lj)) {
                foreach ($lj as $k => $v) {
                    $this->cfg[$k] = $v; // local puede sobreescribir global si se desea
                }
            }
        }

        // Usar SIEMPRE el certificado de plataforma para SOAP (no el del emisor)
        $p12Path = (string)($this->cfg['p12_path'] ?? '');
        $p12Pass = (string)($this->cfg['p12_pass'] ?? '');
        if ($p12Pass !== '' && strncmp($p12Pass, 'enc:v1:', 7) === 0) {
            // Descifra con clave global primero (/var/www/html/cifra/secret.key), luego local data/secret.key o SecureConfig
            $dec = $this->tryCompatLocalDecrypt($p12Pass);
            if (($dec === '' || $dec === null) && class_exists('SecureConfig')) $dec = \SecureConfig::decrypt($p12Pass);
            if (is_string($dec) && $dec !== '') $p12Pass = $dec;
        }
        // Forzar p12 de plataforma si existe
        if (is_file('/var/www/html/cifra/max.p12')) {
            $p12Path = '/var/www/html/cifra/max.p12';
        }
        // Si no tenemos pass aún, intenta localizarlo en ficheros conocidos
        if ($p12Pass === '') {
            $passFiles = [
                '/var/www/html/cifra/max.pass',
                '/var/www/html/cifra/faceb2b.pass',
                '/var/www/html/cifra/p12.pass',
                __DIR__ . '/../data/faceb2b.pass',
            ];
            foreach ($passFiles as $pf) {
                if (!is_file($pf)) continue;
                $raw = trim((string)@file_get_contents($pf));
                if ($raw === '') continue;
                if (strncmp($raw, 'enc:v1:', 7) === 0) {
                    $dec = $this->tryCompatLocalDecrypt($raw);
                    if (($dec === '' || $dec === null) && class_exists('SecureConfig')) $dec = \SecureConfig::decrypt($raw);
                    if (is_string($dec) && $dec !== '') { $p12Pass = $dec; break; }
                } else {
                    $p12Pass = $raw; break;
                }
            }
        }
        if ($p12Path === '' || !is_file($p12Path)) {
            throw new \RuntimeException("No se encuentra el P12 para FACeB2B: {$p12Path}");
        }
        if ($p12Pass === '') {
            // Evita intentar firmar SOAP sin pass: da FS001. Mensaje claro para el usuario.
            throw new \RuntimeException('Falta la contraseña del P12 de plataforma para FACeB2B. Define "p12_pass" en config o coloca /var/www/html/cifra/max.pass');
        }
        $this->log('client_init', ['p12'=> $p12Path, 'pass_len'=>strlen((string)$p12Pass), 'exc_c14n'=>$exclusiveC14n?1:0]);
        $cli = new JosemmoFaceb2bClient($p12Path, null, $p12Pass);
        if (method_exists($cli, 'setExclusiveC14n')) $cli->setExclusiveC14n($exclusiveC14n);
        $usePre = (bool)($this->cfg['use_pre'] ?? false);
        if (method_exists($cli, 'setProduction')) $cli->setProduction(!$usePre);
        $this->log('env', ['production' => (!$usePre?1:0)]);
        if (!empty($this->cfg['timeout']) && method_exists($cli, 'setTimeout')) $cli->setTimeout((int)$this->cfg['timeout']);
        if (!empty($this->cfg['proxy']) && method_exists($cli, 'setProxy')) $cli->setProxy((string)$this->cfg['proxy']);
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
            '/var/www/html/cifra/key.secret',
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

    /** Valida “suavemente” que el DIRe está presente en el XML (Fb2bExtension). */
    private function softCheckDireInXml(string $xml, string $dire): void {
        if ($dire === '') return;
        $present = (strpos($xml, $dire) !== false) || (bool)preg_match('~FaceB2BExtension~i', $xml);
        $this->log('receiving_unit_check', ['dire'=>$dire, 'present'=>$present]);
    }

    /** Mezcla configuración REST/SOAP desde ubicaciones estándar si están disponibles */
    private function mergeExternalConfig(): void {
        // Global
        $globalCfgPath = '/var/www/html/cifra/faceb2b.json';
        if (is_file($globalCfgPath)) {
            $gj = json_decode((string)@file_get_contents($globalCfgPath), true);
            if (is_array($gj)) {
                foreach ($gj as $k => $v) {
                    if (!array_key_exists($k, $this->cfg) || $this->cfg[$k] === null || $this->cfg[$k] === '') {
                        $this->cfg[$k] = $v;
                    }
                }
            }
        }
        // Local override
        $localCfgPath = __DIR__ . '/../data/faceb2b.json';
        if (is_file($localCfgPath)) {
            $lj = json_decode((string)@file_get_contents($localCfgPath), true);
            if (is_array($lj)) {
                foreach ($lj as $k => $v) {
                    $this->cfg[$k] = $v;
                }
            }
        }
        // Defaults
        if (empty($this->cfg['p12_path']) && is_file('/var/www/html/cifra/max.p12')) {
            $this->cfg['p12_path'] = '/var/www/html/cifra/max.p12';
        }
    }
}
