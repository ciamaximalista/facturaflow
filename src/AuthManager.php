<?php
/**
 * src/AuthManager.php
 * Gestiona la autenticación, registro y sesión del usuario único de la aplicación.
 * Adaptado para trabajar con data/config.json en lugar de un sistema multi-usuario.
 */
 
class AuthManager
{
    private $configFile;
    private $dataDir;
    private $certsDir;

    public function __construct()
    {
        $this->dataDir = __DIR__ . '/../data';
        $this->configFile = $this->dataDir . '/config.json';
        $this->certsDir = $this->dataDir . '/certs';

        if (!is_dir($this->dataDir)) mkdir($this->dataDir, 0775, true);
        if (!is_dir($this->certsDir)) mkdir($this->certsDir, 0775, true);
    }

    /**
     * Verifica si el usuario principal ha sido registrado (si config.json existe).
     * @return bool
     */
    public function isUserRegistered(): bool
{
    if (!file_exists($this->configFile)) {
        return false;
    }
    $cfg = json_decode((string)@file_get_contents($this->configFile), true);
    if (!is_array($cfg)) {
        return false;
    }

    // El usuario es "válido" sólo si existen NIF y password_hash
    $nif  = isset($cfg['nif']) ? strtoupper(preg_replace('/[\s-]+/', '', (string)$cfg['nif'])) : '';
    $hash = (string)($cfg['password_hash'] ?? '');

    // Evita contar como usuario si sólo hay configuración técnica (faceb2b, aeat, etc.)
    return ($nif !== '') && ($hash !== '') && strlen($hash) > 20;
}

    /**
     * Registra al usuario principal guardando sus datos en config.json.
     * @param array $data Los datos del formulario de registro.
     * @param array|null $certFile El archivo del certificado.
     * @param array|null $logoFile El archivo del logo.
     * @return array
     */
    public function registerUser(array $data, ?array $certFile, ?array $logoFile): array {
	    if ($this->isUserRegistered()) {
		return ['success' => false, 'message' => 'El usuario ya está registrado.'];
	    }
	    // Aceptación de condiciones de uso obligatoria
	    $accepted = false;
	    if (isset($data['accept_terms'])) {
	        $v = strtolower(trim((string)$data['accept_terms']));
	        $accepted = in_array($v, ['1','true','on','yes','si','sí'], true);
	    }
	    if (!$accepted) {
	        return ['success' => false, 'message' => 'Debes aceptar las Condiciones de Uso para registrarte.'];
	    }
	    if (empty($data['nif']) || empty($data['password'])) {
		return ['success' => false, 'message' => 'El NIF y la contraseña son obligatorios.'];
	    }
	    if ($data['password'] !== ($data['password_confirm'] ?? null)) {
		return ['success' => false, 'message' => 'Las contraseñas no coinciden.'];
	    }

	    // Limpia NIF (sin espacios ni guiones)
	    $data['nif'] = strtoupper(preg_replace('/[\s-]+/', '', (string)$data['nif']));

	    // Hashear la contraseña de acceso
	    $data['password_hash'] = password_hash((string)$data['password'], PASSWORD_DEFAULT);
	    unset($data['password'], $data['password_confirm'], $data['action']);

	    // Gestionar subida de logo
	    if ($logoFile && $logoFile['error'] === UPLOAD_ERR_OK) {
		$uploadDir = $this->dataDir . '/uploads/';
		if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
		$logoName = 'logo_' . uniqid() . '_' . basename($logoFile['name']);
		if (move_uploaded_file($logoFile['tmp_name'], $uploadDir . $logoName)) {
		    $data['logoPath'] = 'data/uploads/' . $logoName;
		}
	    }

	    // Gestionar subida de certificado
	    if ($certFile && $certFile['error'] === UPLOAD_ERR_OK) {
		$certName = 'cert_' . uniqid() . '_' . basename($certFile['name']);
		if (move_uploaded_file($certFile['tmp_name'], $this->certsDir . '/' . $certName)) {
		    $data['certificatePath'] = $this->certsDir . '/' . $certName;
		}
	    }

	    // >>> Evitar DOBLE CIFRADO de la contraseña del certificado <<<
	    if (isset($data['certPassword']) && $data['certPassword'] !== '') {
		$pwd = (string)$data['certPassword'];
		// Si ya viene cifrada (p.ej. desde index.php), respeta el valor
		if (strpos($pwd, 'enc:v1:') !== 0 && class_exists('SecureConfig')) {
		    $pwd = SecureConfig::encrypt($pwd);
		}
		$data['certPassword'] = $pwd;
	    } else {
		// No guardes campo vacío
		unset($data['certPassword']);
	    }
	    
	    // --- Valores por defecto AEAT en primera creación de config.json ---
		$data += [
		    'aeatLogXml'            => true,      // booleano
		    'aeatIdSistema'         => '01',
		    'aeatVersion'           => '1.0.0',
		    'aeatNumeroInstalacion' => '1',
		    // Metadatos de aceptación de condiciones
		    'termsAcceptedAt'       => gmdate('c'),
		    'termsAcceptedIp'       => $_SERVER['REMOTE_ADDR'] ?? '',
		    'termsAcceptedUA'       => $_SERVER['HTTP_USER_AGENT'] ?? '',
		    'termsVersion'          => 'v1.0',
		];


	    if (file_put_contents($this->configFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
		// Crear índices iniciales vacíos relacionados con recibidas (proveedores)
		try {
		    $baseData = realpath(__DIR__ . '/../data') ?: (__DIR__ . '/../data');
		    if (!is_dir($baseData)) @mkdir($baseData, 0775, true);
		    $provFile = $baseData . '/providers_last5y.json';
		    if (!is_file($provFile)) {
		        @file_put_contents($provFile, json_encode(['updatedAt'=>date('c'),'providers'=>new \stdClass()], JSON_UNESCAPED_UNICODE));
		    }
		} catch (\Throwable $e) { /* noop */ }
		return ['success' => true];
	    }

	    return ['success' => false, 'message' => 'No se pudo guardar la configuración.'];
	}


    /**
     * Valida las credenciales del usuario contra config.json.
     * @param string $nif
     * @param string $password
     * @return bool
     */
    public function loginUser(string $nif, string $password): bool
{
    if (!$this->isUserRegistered()) {
        return false;
    }
    $config = json_decode(file_get_contents($this->configFile), true);

    // Normaliza NIF de entrada y de fichero para comparación robusta
    $inNif = strtoupper(preg_replace('/[\s-]+/', '', (string)$nif));
    $cfgNif = isset($config['nif']) ? strtoupper(preg_replace('/[\s-]+/', '', (string)$config['nif'])) : null;

    if ($cfgNif && isset($config['password_hash'])) {
        if ($cfgNif === $inNif && password_verify($password, (string)$config['password_hash'])) {
            $_SESSION['user_authenticated'] = true;
            return true;
        }
    }
    return false;
}


    /**
     * Cierra la sesión del usuario.
     */
    public function logoutUser(): void
    {
        session_unset();
        session_destroy();
    }
    
    
    /**
 * Cifra un texto usando una clave externa guardada en un fichero.
 */
public static function encryptWithKeyFile(string $plaintext, string $keyFilePath): ?string
{
    if (!file_exists($keyFilePath)) {
        throw new \RuntimeException("Fichero de clave no encontrado: $keyFilePath");
    }
    $key = file_get_contents($keyFilePath);
    if ($key === false || trim($key) === '') {
        throw new \RuntimeException("La clave en $keyFilePath está vacía o no se pudo leer.");
    }
    
    $derivedKey = hash('sha256', $key, true);
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', $derivedKey, 0, $iv);

    if ($encrypted === false) {
        return null;
    }
    return 'enc:v1:' . base64_encode($iv . $encrypted);
}

/**
 * Descifra un texto usando una clave externa guardada en un fichero.
 */
public static function decryptWithKeyFile(string $encrypted, string $keyFilePath): ?string
{
    if (strncmp($encrypted, 'enc:v1:', 7) !== 0) {
        return $encrypted; // No está cifrado con nuestro formato
    }
    if (!file_exists($keyFilePath)) {
        // No lanzamos excepción para no romper el flujo si el fichero es opcional
        error_log("Fichero de clave no encontrado al intentar descifrar: $keyFilePath");
        return null;
    }
    $key = file_get_contents($keyFilePath);
    if ($key === false || trim($key) === '') {
        return null;
    }

    $derivedKey = hash('sha256', $key, true);
    $data = base64_decode(substr($encrypted, 7));
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($data, 0, $ivLength);
    $encryptedText = substr($data, $ivLength);
    
    $decrypted = openssl_decrypt($encryptedText, 'aes-256-cbc', $derivedKey, 0, $iv);
    return ($decrypted === false) ? null : $decrypted;
}
    
}
