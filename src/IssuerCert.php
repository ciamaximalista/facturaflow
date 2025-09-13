<?php
declare(strict_types=1);

/**
 * IssuerCert — punto único para el certificado de firma del EMISOR (Facturae).
 *
 * Reglas:
 *  - El P12 del emisor se guarda (por convención) como data/certs/issuer.cert
 *  - La contraseña, si se quiere persistir, se guarda en data/certs/issuer.pass (texto plano)
 *    o en config (JSON) en alguna de estas claves: certPassword | issuer_pass | signer_p12_pass | signer_pass | p12_pass.
 *  - Si la contraseña empieza por enc:v1:... se intentará descifrar primero con SecureConfig::decrypt()
 *    y, si falla, con compat local (secret.key + AES-256-CBC).
 *
 * Uso desde el generador:
 *    [$p12Path, $p12Pass] = \IssuerCert::getPathAndPass();
 *
 * Uso desde settings/register (subida):
 *    \IssuerCert::saveUploaded($_FILES['issuer_cert'] ?? null, $_POST['issuer_pass'] ?? null);
 */
final class IssuerCert
{
    /** Ruta absoluta al P12 del emisor (convención) */
    public const CERT_PATH = __DIR__ . '/../data/certs/issuer.cert';
    /** Ruta absoluta al fichero de contraseña (opcional) */
    public const PASS_PATH = __DIR__ . '/../data/certs/issuer.pass';
    /** Posibles configs JSON a consultar (por compat) */
    private const CFG_FILES_LEGACY = [
        __DIR__ . '/../data/config.json',
        __DIR__ . '/../data/local.config.json',
    ];

    /** Log del generador (mantenemos el mismo usado por tu app) */
    private const GEN_LOG = __DIR__ . '/../data/logs/facturae_generator.log';

    /** Devuelve [rutaP12, contraseña] o lanza RuntimeException con mensaje claro. */
    public static function getPathAndPass(): array {
        $p12  = self::resolvePath();
        $pass = self::resolvePass();

        self::log('sign_prelude', [
            'p12Path'             => $p12,
            'p12_exists'          => is_file($p12),
            'pass_len'            => strlen($pass),
            'pass_was_enc'        => self::looksEncrypted($pass),
            'openssl_pkcs12_read' => null,
            'openssl_sign'        => true
        ]);

        if (!is_file($p12)) {
            throw new \RuntimeException('No se encuentra el P12 del emisor en ' . $p12);
        }
        return [$p12, $pass];
    }

    /** Prioriza certificatePath de config.json; si no, fallback a rutas conocidas. */
    private static function resolvePath(): string {
        // 1) Configs declaradas (incluyendo plataforma si existe)
        $cfgFiles = self::CFG_FILES_LEGACY;
        if (!function_exists('ff_platform_dir')) { @require_once __DIR__ . '/helpers.php'; }
        $plat = function_exists('ff_platform_dir') ? ff_platform_dir() : null;
        if ($plat && is_file($plat . '/faceb2b.json')) $cfgFiles[] = $plat . '/faceb2b.json';
        foreach ($cfgFiles as $cfg) {
            if (!is_file($cfg)) continue;
            $j = json_decode((string)@file_get_contents($cfg), true);
            if (!is_array($j)) continue;
            $path = isset($j['certificatePath']) ? (string)$j['certificatePath'] : '';
            if ($path !== '' && is_file($path) && filesize($path) > 0) {
                return $path;
            }
        }
        // 2) Fallbacks razonables en tu árbol
        $cands = [
            self::CERT_PATH,
            __DIR__ . '/../data/certs/issuer.p12',
            __DIR__ . '/../data/issuer.cert',
            __DIR__ . '/../data/issuer.p12',
        ];
        foreach ($cands as $p) {
            if (is_file($p) && filesize($p) > 0) return $p;
        }
        // 3) Por defecto, convención
        return self::CERT_PATH;
    }

    /**
     * Guarda el P12 subido SIEMPRE como issuer.cert.
     * Si llega $pass, lo persiste como issuer.pass (0600) y actualiza config.json (certificatePath).
     * Devuelve array con meta (ok, size, pass_len).
     *
     * @param array<string,mixed>|null $file  Entrada $_FILES['issuer_cert']
     * @param null|string $pass
     * @return array{ok:bool,message?:string,size?:int,pass_len?:int}
     */
    public static function saveUploaded(?array $file, ?string $pass = null): array
    {
        try {
            if (!$file || !isset($file['tmp_name'], $file['error'])) {
                return ['ok' => false, 'message' => 'No se recibió el fichero (issuer_cert).'];
            }
            if ((int)$file['error'] !== UPLOAD_ERR_OK) {
                return ['ok' => false, 'message' => 'Fallo en subida (código '.$file['error'].').'];
            }
            $tmp  = (string)$file['tmp_name'];
            $size = (int)filesize($tmp);
            @mkdir(dirname(self::CERT_PATH), 0775, true);
            if (!@move_uploaded_file($tmp, self::CERT_PATH)) {
                // si no es subida HTTP (p.ej. CLI), intentar rename()
                if (!@rename($tmp, self::CERT_PATH)) {
                    return ['ok' => false, 'message' => 'No se pudo mover el P12 a issuer.cert'];
                }
            }
            @chmod(self::CERT_PATH, 0640);

            if (is_string($pass) && $pass !== '') {
                @file_put_contents(self::PASS_PATH, $pass);
                @chmod(self::PASS_PATH, 0600);
            }

            self::log('issuer_saved', [
                'dest'     => self::CERT_PATH,
                'size'     => $size,
                'pass_len' => is_string($pass) ? strlen($pass) : 0
            ]);

            // Alinea config.json para que certificatePath apunte a lo que realmente usamos
            self::writeConfigCertificatePath(self::CERT_PATH);

            return ['ok' => true, 'size' => $size, 'pass_len' => is_string($pass) ? strlen($pass) : 0];

        } catch (\Throwable $e) {
            self::log('issuer_save_error', $e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private static function writeConfigCertificatePath(string $path): void {
        $cfg = __DIR__ . '/../data/config.json';
        $j   = is_file($cfg) ? json_decode((string)@file_get_contents($cfg), true) : [];
        if (!is_array($j)) $j = [];
        $j['certificatePath'] = $path;
        @file_put_contents($cfg, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** Intenta resolver la contraseña: issuer.pass → config json (enc/plano) → '' */
    private static function resolvePass(): string
    {
        // 1) issuer.pass (texto plano)
        if (is_file(self::PASS_PATH)) {
            $raw  = (string)@file_get_contents(self::PASS_PATH);
            $pass = trim($raw);
            if ($pass !== '') {
                self::log('p12_pass_info', [
                    'source'      => 'pass_file',
                    'path'        => self::PASS_PATH,
                    'path_exists' => true,
                    'was_enc'     => self::looksEncrypted($pass),
                    'dec_ok'      => !self::looksEncrypted($pass),
                    'pass_len'    => strlen($pass),
                ]);
                return $pass;
            }
        }

        // 2) config JSON (varias claves por compat)
        $keys = [
            'certPassword',       // preferida en el config.json actual
            'issuer_pass',
            'signer_p12_pass',
            'signer_pass',
            'p12_pass'
        ];
        foreach (self::CFG_FILES as $cfg) {
            if (!is_file($cfg)) continue;
            $j = json_decode((string)@file_get_contents($cfg), true);
            if (!is_array($j)) continue;

            // ignoramos 'password_hash' (no es la pass usable)
            foreach ($keys as $k) {
                if (!isset($j[$k])) continue;
                $val = (string)$j[$k];
                if ($val === '') continue;

                // enc:v1:… ?
                if (self::looksEncrypted($val)) {
                    $dec = self::tryDecrypt($val);
                    if ($dec !== '') {
                        self::log('p12_pass_info', [
                            'source'   => 'enc_secure_or_compat',
                            'cfg'      => basename($cfg),
                            'key'      => $k,
                            'was_enc'  => true,
                            'dec_ok'   => true,
                            'pass_len' => strlen($dec),
                        ]);
                        return $dec;
                    }
                    self::log('p12_pass_info', [
                        'source'   => 'enc_unresolved',
                        'cfg'      => basename($cfg),
                        'key'      => $k,
                        'was_enc'  => true,
                        'dec_ok'   => false,
                        'pass_len' => 0,
                    ]);
                    continue;
                }

                // plano
                self::log('p12_pass_info', [
                    'source'   => 'cfg_plain',
                    'cfg'      => basename($cfg),
                    'key'      => $k,
                    'was_enc'  => false,
                    'dec_ok'   => true,
                    'pass_len' => strlen($val),
                ]);
                return $val;
            }
        }

        // 3) vacío
        self::log('p12_pass_info', [
            'source'   => 'not_found',
            'was_enc'  => false,
            'dec_ok'   => false,
            'pass_len' => 0,
        ]);
        return '';
    }

    /** ¿Tiene pinta de enc:v1:? */
    private static function looksEncrypted(string $s): bool
    {
        return strncmp($s, 'enc:v1:', 7) === 0;
    }

    /** Descifra enc:v1:… con SecureConfig o compat local (secret.key). */
    private static function tryDecrypt(string $enc): string
    {
        // A) SecureConfig::decrypt si existe
        if (class_exists('SecureConfig')) {
            try {
                $dec = \SecureConfig::decrypt($enc);
                if (is_string($dec) && $dec !== '') return $dec;
            } catch (\Throwable $e) { /* sigue */ }
        }

        // B) Compat local con secret.key (AES-256-CBC, iv||ct base64 en enc:v1:)
        $b64 = substr($enc, 7);
        $b64 = preg_replace('~[^A-Za-z0-9+/=]~', '', (string)$b64);
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < 17) return '';

        $iv = substr($raw, 0, 16);
        $ct = substr($raw, 16);
        $candidates = [
            '/var/www/html/cifra/secret.key',
            __DIR__ . '/../data/secret.key',
        ];
        foreach ($candidates as $kfile) {
            if (!is_file($kfile)) continue;
            $k = (string)@file_get_contents($kfile);
            if ($k === '') continue;
            $key   = hash('sha256', $k, true);
            $plain = openssl_decrypt($ct, 'aes-256-cbc', $key, OPENSSL_ZERO_PADDING, $iv);
            if (!is_string($plain) || $plain === '') continue;
            $pad = ord(substr($plain, -1));
            if ($pad > 0 && $pad <= 16) $plain = substr($plain, 0, -$pad);
            return $plain;
        }
        return '';
    }

    /** Logging compacto al log del generador (para mantener trazabilidad). */
    private static function log(string $msg, $ctx = null): void
    {
        $line = '[' . date('c') . "] " . $msg;
        if ($ctx !== null) {
            if (!is_string($ctx)) $ctx = json_encode($ctx, JSON_UNESCAPED_UNICODE);
            $line .= ' ' . $ctx;
        }
        @mkdir(dirname(self::GEN_LOG), 0775, true);
        @file_put_contents(self::GEN_LOG, $line . "\n", FILE_APPEND);
    }
}
