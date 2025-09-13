<?php
declare(strict_types=1);

/**
 * Cliente mínimo para el API de Proveedores de FACe (REST v1).
 * - Autenticación: JWT RS256 con cabecera x5c y claim username=SHA1(PEM limpio).
 * - Endpoints usados: GET /v1/invoices/{registryCode}
 */

final class FaceProvidersClient {
    /** @var array<string,mixed> */
    private array $cfg;

    public function __construct(array $cfg = []) {
        $this->cfg = $cfg;
        $this->mergeExternalConfig();
    }

    /** Obtiene la factura por número de registro. */
    public function getInvoice(string $registryCode): array {
        $base = (string)($this->cfg['rest_base'] ?? 'https://api.face.gob.es/providers');
        $url  = rtrim($base, '/') . '/v1/invoices/' . rawurlencode($registryCode);
        return $this->doRestGet($url, []);
    }

    /** HTTP GET con Bearer (token fijo/JWT) o Basic si se configuró. */
    private function doRestGet(string $url, array $query): array {
        $this->mergeExternalConfig();
        if (!empty($query)) {
            $sep = (strpos($url, '?') === false) ? '?' : '&';
            $url .= $sep . http_build_query($query);
        }
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        // Auth: token fijo, Basic, o JWT generado con RS256 + x5c + username
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
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $resp = null;
        if (is_string($body) && $body !== '') {
            $tmp = json_decode($body, true);
            $resp = is_array($tmp) ? $tmp : ['raw'=>$body];
        }
        $ok = ($http >= 200 && $http < 300);
        return [ 'success'=>$ok, 'status'=>$http, 'response'=>$resp, 'error'=>$err ?: null ];
    }

    /** Construye JWT RS256 con x5c y username=SHA1(PEM limpio). */
    private function buildJwtToken(): string {
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

        // PEM limpio (DER en base64, una línea)
        $b64der = $this->pemToBase64Der((string)$certPem);
        if ($b64der === '') return '';

        $header = [ 'typ' => 'JWT', 'alg' => 'RS256', 'x5c' => [ $b64der ] ];
        $now = time(); $exp = $now + 300; // 5 minutos
        $payload = [
            'username' => sha1($b64der), // SHA1 del PEM limpio
            'iat' => $now,
            'exp' => $exp,
        ];

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
     * Mezcla configuración desde ubicaciones estándar si están disponibles.
     * Admite: /var/www/html/cifra/face.json y data/face.json
     */
    private function mergeExternalConfig(): void {
        $globalCfgPath = '/var/www/html/cifra/face.json';
        if (is_file($globalCfgPath)) {
            $gj = json_decode((string)@file_get_contents($globalCfgPath), true);
            if (is_array($gj)) {
                foreach ($gj as $k => $v) {
                    if (!array_key_exists($k, $this->cfg)) $this->cfg[$k] = $v;
                }
            }
        }
        $localCfgPath = __DIR__ . '/../data/face.json';
        if (is_file($localCfgPath)) {
            $lj = json_decode((string)@file_get_contents($localCfgPath), true);
            if (is_array($lj)) {
                foreach ($lj as $k => $v) {
                    $this->cfg[$k] = $v;
                }
            }
        }
        if (empty($this->cfg['p12_path']) && is_file('/var/www/html/cifra/max.p12')) {
            $this->cfg['p12_path'] = '/var/www/html/cifra/max.p12';
        }
        if (empty($this->cfg['rest_base'])) {
            $this->cfg['rest_base'] = 'https://api.face.gob.es/providers';
        }
    }
}

