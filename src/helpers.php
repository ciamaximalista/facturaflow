<?php
// src/helpers.php

declare(strict_types=1);


// Localiza la carpeta data/clients de forma robusta, aunque esta vista cambie de ruta
if (!function_exists('ff_data_clients_dir')) {
    function ff_data_clients_dir(): string {
        // puntos de partida razonables
        $candidates = [
            __DIR__ . '/../data/clients',  // si helpers.php está en src/
            __DIR__ . '/data/clients',     // si helpers.php está en raíz (por si acaso)
            dirname(__DIR__) . '/data/clients',
            dirname(__DIR__, 2) . '/data/clients',
        ];
        foreach ($candidates as $dir) {
            if (is_dir($dir)) return $dir;
        }
        // último recurso: probar a subir hasta 4 niveles buscando data/clients
        $base = __DIR__;
        for ($i=0; $i<4; $i++) {
            $try = $base . '/data/clients';
            if (is_dir($try)) return $try;
            $base = dirname($base);
        }
        return __DIR__ . '/../data/clients'; // fallback
    }
}

/**
 * Devuelve el DIRe del cliente buscando por:
 * 1) ID (clients_<id>.xml o <id>.xml con prefijo)
 * 2) NIF (escaneo de fichas)
 * 3) Nombre (escaneo, último recurso)
 * Admite <dire> o <DIRe>. Cachea resultados por rendimiento.
 */
if (!function_exists('resolveClientDire')) {
    function resolveClientDire(?string $clientId, ?string $clientNif, ?string $clientName = null): string {
        static $cacheById = [];
        static $cacheByNif = [];
        static $cacheByName = [];

        $dir = ff_data_clients_dir();
        $readDire = function(string $file): string {
            $xml = @simplexml_load_file($file);
            if ($xml === false) return '';
            // dire o DIRe
            $v = '';
            if (isset($xml->dire)) $v = (string)$xml->dire;
            elseif (isset($xml->DIRe)) $v = (string)$xml->DIRe;
            return trim($v);
        };
        $readField = function($xml, string $field): string {
            return isset($xml->{$field}) ? trim((string)$xml->{$field}) : '';
        };

        // 1) Por ID
        if ($clientId) {
            $norm = (strpos($clientId, 'clients_') === 0) ? $clientId : ('clients_' . $clientId);
            if (isset($cacheById[$norm])) return $cacheById[$norm];
            $file = $dir . '/' . $norm . '.xml';
            if (is_file($file)) {
                $dire = $readDire($file);
                $cacheById[$norm] = $dire;
                if ($dire !== '') return $dire;
            }
        }

        // 2) Por NIF
        $nif = $clientNif ? strtoupper(str_replace([' ', "\t", "\n", "\r"], '', $clientNif)) : '';
        if ($nif) {
            if (isset($cacheByNif[$nif])) return $cacheByNif[$nif];
            foreach (glob($dir . '/clients_*.xml') as $f) {
                $xml = @simplexml_load_file($f);
                if ($xml === false) continue;
                $xmlNif = isset($xml->nif) ? strtoupper(str_replace(' ', '', (string)$xml->nif)) : '';
                if ($xmlNif !== '' && $xmlNif === $nif) {
                    $dire = $readDire($f);
                    $cacheByNif[$nif] = $dire;
                    return $dire;
                }
            }
        }

        // 3) Por nombre (último recurso)
        $name = $clientName ? trim(mb_strtolower($clientName)) : '';
        if ($name !== '') {
            if (isset($cacheByName[$name])) return $cacheByName[$name];
            foreach (glob($dir . '/clients_*.xml') as $f) {
                $xml = @simplexml_load_file($f);
                if ($xml === false) continue;
                $xmlName = isset($xml->name) ? trim(mb_strtolower((string)$xml->name)) : '';
                if ($xmlName !== '' && $xmlName === $name) {
                    $dire = $readDire($f);
                    $cacheByName[$name] = $dire;
                    return $dire;
                }
            }
        }

        return '';
    }
}

 function auth_is_authenticated($auth): bool {
    if (is_object($auth) && method_exists($auth, 'isAuthenticated')) {
        return (bool)$auth->isAuthenticated();
    }
    return isset($_SESSION['user_authenticated']) && $_SESSION['user_authenticated'] === true;
}
 
 

    function generate_default_series(array $issuer): string
		    {
		    // Regla: PF -> 3 iniciales; Sociedad -> iniciales + forma jurídica (SA/SL/SCOOP/...)
		    $type = strtolower((string)($issuer['entityType'] ?? 'company'));

		    // --- Utilidades locales ---
		    $onlyAZ = function(string $s): string {
			return strtoupper(preg_replace('/[^A-Z]/u', '', $s));
		    };
		    $firstLetter = function(string $w): string {
			$w = preg_replace('/[^A-ZÁÉÍÓÚÜÑ]/iu', '', $w);
			return $w === '' ? '' : strtoupper(mb_substr($w, 0, 1, 'UTF-8'));
		    };
		    $tokenize = function(string $s): array {
			$u = mb_strtoupper($s, 'UTF-8');
			return preg_split('/\s+/u', $u, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		    };
		    $detectLegal = function(string $nameU) use ($onlyAZ): string {
			// Busca formas jurídicas en la cadena normalizada sin separadores
			$joined = $onlyAZ($nameU); // quita espacios y puntos
			if (preg_match('/S.Coop./u', $joined) || preg_match('/COOP/u', $joined)) return 'SCOOP';
			if (preg_match('/S.L.L./u',   $joined)) return 'SLL';
			if (preg_match('/S.L.U./u',   $joined)) return 'SLU';
			if (preg_match('/S.L./u',    $joined)) return 'SL';
			if (preg_match('/S.A.U./u',   $joined)) return 'SAU';
			if (preg_match('/S.A./u',    $joined)) return 'SA';
			if (preg_match('/S.C./u',    $joined)) return 'SC';
			if (preg_match('/S.A.L./u',    $joined)) return 'SAL';
			return '';
		    };

		    // --- PF: tres iniciales ---
		    if (in_array($type, ['freelancer','pf','persona_fisica'], true)) {
			$fn  = (string)($issuer['firstName'] ?? '');
			$ln1 = (string)($issuer['lastName'] ?? '');
			$ln2 = (string)($issuer['secondSurname'] ?? '');

			$ini = '';
			$ini .= $firstLetter($fn);
			$ini .= $firstLetter($ln1);
			$ini .= $firstLetter($ln2);

			// Completa si faltan con el campo 'name'
			if (mb_strlen($ini, 'UTF-8') < 3) {
			    $fallback = trim((string)($issuer['name'] ?? ($fn.' '.$ln1.' '.$ln2)));
			    foreach ($tokenize($fallback) as $w) {
				if (mb_strlen($ini, 'UTF-8') >= 3) break;
				$ini .= $firstLetter($w);
			    }
			}
			// Exactamente 3 letras A-Z
			$ini = $onlyAZ($ini);
			if (strlen($ini) < 3) {
			    $seed = $onlyAZ(($issuer['name'] ?? '') . $fn . $ln1 . $ln2);
			    $ini  = substr($ini . $seed, 0, 3);
			} else {
			    $ini = substr($ini, 0, 3);
			}
			return $ini !== '' ? $ini : 'FAC';
		    }

		    // --- Sociedades: iniciales (máx. 3) + forma jurídica ---
		    $name = (string)($issuer['name'] ?? ($issuer['companyName'] ?? ''));

		    $nameU = mb_strtoupper($name, 'UTF-8');

		    $stop = ['DE','DEL','LA','LAS','LOS','Y','THE','DA','DO','DOS','DAS','OF'];
		    $legal = $detectLegal($nameU);

		    $core = '';
		    foreach ($tokenize($nameU) as $t) {
			$tClean = preg_replace('/[^A-ZÁÉÍÓÚÜÑ]/u', '', $t);
			if ($tClean === '' || in_array($tClean, $stop, true)) continue;

			// Salta tokens de forma jurídica evidentes
			$tAZ = $onlyAZ($tClean);
			if (in_array($tAZ, ['S','SA','SL','SLL','SAU','SLU','SC','COOP','SCOOP'], true)) continue;

			$core .= $firstLetter($tClean);
			if (mb_strlen($core, 'UTF-8') >= 3) break;
		    }
		    $core = $onlyAZ($core);
		    if ($core === '') $core = 'FAC'; // fallback si el nombre es raro

		    $series = $core . ($legal ? $legal : '');
		    $series = $onlyAZ($series);

		    return $series !== '' ? $series : 'FAC';
		}


		/**
		 * Crea un directorio si no existe.
		 */
		if (!function_exists('ensure_dir')) {
		    function ensure_dir(string $dir): void
		    {
			if (!is_dir($dir)) {
			    @mkdir($dir, 0775, true);
			}
		    }
		}

		/**
		 * Carga JSON (array) de un archivo. Si no existe o falla, devuelve [].
		 */
		if (!function_exists('load_json')) {
		    function load_json(string $file): array
		    {
			if (!is_file($file)) return [];
			$raw = @file_get_contents($file);
			if ($raw === false || $raw === '') return [];
			$data = json_decode($raw, true);
			return is_array($data) ? $data : [];
		    }
		}

		/**
		 * Guarda un array como JSON (pretty + unicode).
		 */
		if (!function_exists('save_json')) {
		    function save_json(string $file, array $data): void
		    {
			@file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		    }
		}

		/**
		 * Carga config.json y garantiza estructura mínima para evitar notices.
		 * Estructura sugerida:
		 * {
		 *   "issuer": {"nif":"", "dire":"", ...},
		 *   "faceb2b": {"endpoint":"", "user":"", "password":"", "timeout": 30}
		 * }
		 */
		if (!function_exists('read_config')) {
		    function read_config(string $file): array
		    {
			$cfg = load_json($file);
			if (!isset($cfg['issuer']) || !is_array($cfg['issuer'])) $cfg['issuer'] = [];
			if (!isset($cfg['faceb2b']) || !is_array($cfg['faceb2b'])) $cfg['faceb2b'] = [];
			// Compatibilidad: si vienen nif/dire en raíz, refléjalos en issuer.*
			if (empty($cfg['issuer'])) $cfg['issuer'] = [];
			if (!isset($cfg['issuer']['nif']) && isset($cfg['nif']))   $cfg['issuer']['nif']  = $cfg['nif'];
			if (!isset($cfg['issuer']['dire']) && isset($cfg['dire'])) $cfg['issuer']['dire'] = $cfg['dire'];

			return $cfg;
		    }
		}

		/**
		 * Convierte a nombre de archivo seguro.
		 */
		if (!function_exists('safe_filename')) {
		    function safe_filename(string $name): string
		    {
			$name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name);
			return trim((string)$name, '_');
		    }
		}

		/**
		 * Helpers para respuestas uniformes (útiles si respondes JSON desde index.php)
		 */
		if (!function_exists('json_success')) {
		    /**
		     * @param string $message
		     * @param array<string,mixed> $extra
		     * @return array<string,mixed>
		     */
		    function json_success(string $message = 'OK', array $extra = []): array
		    {
			return array_merge(['success' => true, 'message' => $message], $extra);
		    }
		}

		if (!function_exists('json_error')) {
		    /**
		     * @param string $message
		     * @param array<string,mixed> $extra
		     * @return array<string,mixed>
     */
    function json_error(string $message = 'Error', array $extra = []): array
    {
        return array_merge(['success' => false, 'message' => $message], $extra);
    }

/**
 * Localiza el XML/X-SIG de Facturae correspondiente a una factura.
 * - Acepta también una ruta directa y la devuelve si existe.
 * - Busca por nombre exacto en carpetas canónicas.
 * - Fallback: barrido por patrones "*ID*.{xsig,xml}" (incluye 1 nivel de subcarpetas)
 *             y devuelve el fichero más reciente.
 *
 * @param string $invoiceId  ID o número de la factura, o una ruta directa.
 * @param array<string> $extraDirs  Directorios adicionales donde buscar.
 * @return string|null Ruta al fichero si se encuentra, o null si no existe.
 */
function find_facturae_xml(string $lookup, ?string $dataBase = null): ?string {
    $lookup = trim($lookup);
    if ($lookup === '') return null;

    // Si ya te pasan una ruta válida, devuélvela
    if (is_file($lookup) && filesize($lookup) > 0) {
        $real = realpath($lookup);
        return $real !== false ? $real : $lookup;
    }

    // Base data/
    $dataBase = $dataBase ?: (realpath(__DIR__.'/../data') ?: (__DIR__.'/../data'));

    // Directorios donde puede estar el Facturae firmado
    $dirs = [];
    foreach ([
        'facturae_exports',
        'facturae',
        'faceb2b',
        'faceb2b/outbox',
        'faceb2b/sent',
        'outbox',
        'sent',
        'invoices',   // por si algún generador lo deja junto a la factura
        'tmp',
    ] as $d) {
        $p = rtrim($dataBase, '/').'/'.$d;
        if (is_dir($p)) $dirs[] = $p;
    }

    // Agujas y extensiones
    $basename = basename($lookup);
    $noext    = preg_replace('/\.(xml|xsig)$/i', '', $basename);
    $needles  = array_values(array_unique(array_filter([$lookup, $basename, (string)$noext])));
    $exts     = ['xsig','xml'];

    // Recolector de candidatos (mapea ruta -> mtime)
    $candidates = [];

    $scanOne = function (string $dir) use (&$candidates, $needles, $exts) {
        foreach ($needles as $n) {
            foreach ($exts as $ext) {
                // patrón laxo: *AGUJA*.{xsig,xml}
                foreach (glob($dir.'/*'.$n.'*.'.$ext) ?: [] as $p) {
                    if (is_file($p) && filesize($p) > 0) {
                        $candidates[$p] = @filemtime($p) ?: 0;
                    }
                }
            }
        }
    };

    // Escanear directorios y 1 nivel de subcarpetas (años, etc.)
    foreach ($dirs as $d) {
        $scanOne($d);
        foreach (glob($d.'/*', GLOB_ONLYDIR) ?: [] as $sub) {
            $scanOne($sub);
        }
    }

    if (!empty($candidates)) {
        arsort($candidates, SORT_NUMERIC); // el más reciente primero
        $best = array_key_first($candidates);
        if ($best !== null) return $best;
    }

    // Fallback: si parece un ID de factura (SERIE-YYYY-NNNN), intenta generarla
    if (preg_match('/^.+-\d{4}-\d{1,6}$/', $lookup)) {
        try {
            require_once __DIR__.'/InvoiceManager.php';
            $im = new \InvoiceManager();
            if (method_exists($im, 'generateFacturae')) {
                $p = $im->generateFacturae($lookup);
                if ($p && is_file($p)) return $p;
            }
        } catch (\Throwable $e) {
            // silencioso
        }
    }

    return null;
}


/**
 * Sube/actualiza el certificado del emisor y/o su contraseña.
 * - Si viene fichero en $_FILES['certificate'] => se guarda SIEMPRE como data/certs/issuer.cert
 * - Si no hay fichero pero hay contraseña => actualiza data/certs/issuer.pass
 * Devuelve ['ok'=>bool,'message'?:string]
 */
function ff_handle_issuer_cert_upload(array $files, array $post): array {
    $fieldCert = 'certificate';
    $fieldPass = 'certPassword';

    $hasFile = isset($files[$fieldCert]) 
        && is_array($files[$fieldCert]) 
        && ($files[$fieldCert]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        && !empty($files[$fieldCert]['tmp_name']);

    $pass = isset($post[$fieldPass]) ? trim((string)$post[$fieldPass]) : '';

    // 1) Si hay fichero, delegamos en IssuerCert::saveUploaded (guardar como issuer.cert)
    if ($hasFile) {
        $res = \IssuerCert::saveUploaded($files[$fieldCert], ($pass !== '' ? $pass : null));
        if (!$res['ok']) return ['ok'=>false, 'message'=>$res['message'] ?? 'No se pudo guardar el certificado.'];
        return ['ok'=>true];
    }

    // 2) Solo contraseña (sin fichero)
    if ($pass !== '') {
        if (class_exists('SecureConfig')) {
            $enc = \SecureConfig::encrypt($pass); // debe devolver 'enc:v1:...'
            if (!is_string($enc) || $enc === '') {
                return ['ok'=>false, 'message'=>'No se pudo cifrar la contraseña del certificado.'];
            }
            // Escribe/actualiza en config JSON
            $cfgPath = __DIR__ . '/repoblacion/data/config.json';
            $cfg = [];
            if (is_file($cfgPath)) {
                $tmp = json_decode((string)@file_get_contents($cfgPath), true);
                if (is_array($tmp)) $cfg = $tmp;
            }
            $cfg['issuer_pass'] = $enc;
            @mkdir(dirname($cfgPath), 0775, true);
            if (@file_put_contents($cfgPath, json_encode($cfg, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) === false) {
                return ['ok'=>false, 'message'=>'No se pudo guardar la pass cifrada en config.json'];
            }
            // si existía issuer.pass en claro, lo eliminamos
            if (is_file(\IssuerCert::PASS_PATH)) @unlink(\IssuerCert::PASS_PATH);
        } else {
            // fallback: issuer.pass en claro
            @mkdir(dirname(\IssuerCert::PASS_PATH), 0775, true);
            if (@file_put_contents(\IssuerCert::PASS_PATH, $pass) === false) {
                return ['ok'=>false, 'message'=>'No se pudo actualizar la contraseña del certificado.'];
            }
            @chmod(\IssuerCert::PASS_PATH, 0600);
        }
    }

    return ['ok'=>true];
}



}

