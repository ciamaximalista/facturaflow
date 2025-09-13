<?php
// src/SecureConfig.php
declare(strict_types=1);

final class SecureConfig
{
    /**
     * Devuelve la ruta al fichero de clave (prioriza plataforma/cifra si existe).
     */
    private static function getLocalKeyPath(): string
    {
        // Intentar plataforma (config_plataforma.json)
        $plat = null;
        $helpers = __DIR__ . '/helpers.php';
        if (is_file($helpers)) { @require_once $helpers; }
        if (function_exists('ff_platform_dir')) {
            $plat = \ff_platform_dir();
        }
        if (is_string($plat) && $plat !== '') {
            $pf = rtrim($plat, '/');
            foreach (['/secret.key','/key.secret'] as $suf) {
                $cand = $pf . $suf;
                if (is_file($cand) && is_readable($cand)) return $cand;
            }
        }
        // Fallback: clave local de la instalación
        return __DIR__ . '/../data/secret.key';
    }

    /**
     * Obtiene la clave de cifrado LOCAL.
     * Esta versión solo lee la clave, no intenta crearla.
     */
    private static function getLocalKey(): string
    {
        $keyFile = self::getLocalKeyPath();
        if (!is_file($keyFile) || !is_readable($keyFile)) {
            // Este error indica un problema de instalación/configuración del usuario.
            throw new \RuntimeException("Falta la clave de cifrado local o no se puede leer: " . $keyFile);
        }
        $key = file_get_contents($keyFile);
        if ($key === false || trim($key) === '') {
            throw new \RuntimeException("La clave de cifrado local en $keyFile está vacía.");
        }
        return $key;
    }

    /**
     * Cifra un texto usando la clave LOCAL del usuario.
     */
    public static function encrypt(string $plaintext): ?string
    {
        $key = self::getLocalKey();
        $derivedKey = hash('sha256', $key, true);
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', $derivedKey, 0, $iv);

        if ($encrypted === false) return null;
        return 'enc:v1:' . base64_encode($iv . $encrypted);
    }
    
    /**
     * Descifra un texto usando la clave LOCAL del usuario.
     */
    public static function decrypt(?string $encrypted): ?string
    {
        if ($encrypted === null || strncmp($encrypted, 'enc:v1:', 7) !== 0) {
            return $encrypted;
        }
        
        $key = self::getLocalKey();
        $derivedKey = hash('sha256', $key, true);
        $data = base64_decode(substr($encrypted, 7));
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $ivLength);
        $encryptedText = substr($data, $ivLength);
        
        $decrypted = openssl_decrypt($encryptedText, 'aes-256-cbc', $derivedKey, 0, $iv);
        return ($decrypted === false) ? null : $decrypted;
    }

    /**
     * Cifra un texto usando una clave GLOBAL externa guardada en un fichero.
     */
    public static function encryptWithKeyFile(string $plaintext, string $keyFilePath): ?string
    {
        if (!file_exists($keyFilePath)) throw new \RuntimeException("Fichero de clave no encontrado: $keyFilePath");
        $key = file_get_contents($keyFilePath);
        if ($key === false || trim($key) === '') throw new \RuntimeException("La clave en $keyFilePath está vacía.");
        
        $derivedKey = hash('sha256', $key, true);
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', $derivedKey, 0, $iv);

        if ($encrypted === false) return null;
        return 'enc:v1:' . base64_encode($iv . $encrypted);
    }

    /**
     * Descifra un texto usando una clave GLOBAL externa guardada en un fichero.
     */
    public static function decryptWithKeyFile(string $encrypted, string $keyFilePath): ?string
    {
        if (strncmp($encrypted, 'enc:v1:', 7) !== 0) return $encrypted;
        if (!file_exists($keyFilePath)) {
            error_log("Fichero de clave global no encontrado: $keyFilePath");
            return null;
        }
        $key = file_get_contents($keyFilePath);
        if ($key === false || trim($key) === '') return null;

        $derivedKey = hash('sha256', $key, true);
        $data = base64_decode(substr($encrypted, 7));
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $ivLength);
        $encryptedText = substr($data, $ivLength);
        
        $decrypted = openssl_decrypt($encryptedText, 'aes-256-cbc', $derivedKey, 0, $iv);
        return ($decrypted === false) ? null : $decrypted;
    }
}
