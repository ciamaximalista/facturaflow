<?php
// index.php — con layout + dashboard y rutas para Clientes/Productos/Mis datos
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// --- INCLUDES ---
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/DataManager.php';
require_once __DIR__ . '/src/InvoiceManager.php';
require_once __DIR__ . '/src/VeriFactu.php';
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/SecureConfig.php';
require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/AEATCommunicator.php';
require_once __DIR__ . '/src/ReceivedManager.php';
require_once __DIR__ . '/src/FaceB2BClient.php';
require_once __DIR__ . '/src/Normalizers.php';
require_once __DIR__ . '/src/SignatureManager.php';

// --- INICIALIZACIÓN DE CONFIG.JSON ---
$cfgPath = __DIR__ . '/data/config.json';
if (!is_dir(dirname($cfgPath))) { @mkdir(dirname($cfgPath), 0775, true); }

// ----------- INICIO DE LA SESIÓN -----------
session_start();

// ----------------- AUTENTICACIÓN: HANDLERS TEMPRANOS -----------------
$auth = new AuthManager();

// ----------------- PÁGINAS PÚBLICAS (antes de registro/login) -----------------
if (isset($_GET['page'])) {
    $public = strtolower((string)$_GET['page']);
    if (in_array($public, ['terms','condiciones','terminos','tos'], true)) {
        include __DIR__ . '/templates/terms.php';
        exit;
    }
}

function json_response(array $payload, int $code = 200): void {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    if ($action === 'register_user') {
        $localKeyPath = __DIR__ . '/data/secret.key';
        if (!file_exists($localKeyPath)) {
            try {
                $key = bin2hex(random_bytes(32));
                if (file_put_contents($localKeyPath, $key) === false) {
                    json_response(['success' => false, 'message' => 'Error: No se pudo crear la clave de cifrado local.'], 500);
                }
                @chmod($localKeyPath, 0640);
            } catch (Exception $e) {
                json_response(['success' => false, 'message' => 'Error: No se pudo generar la clave de cifrado: ' . $e->getMessage()], 500);
            }
        }
        $data = $_POST;
        try {
            $res = $auth->registerUser($data, $_FILES['logo'] ?? null);
            json_response($res['success'] ? ['success' => true, 'redirect' => 'index.php?page=dashboard'] : ['success' => false, 'message' => $res['message'] ?? 'Error en registro.']);
        } catch (Throwable $e) {
            json_response(['success' => false, 'message' => 'Error: '.$e->getMessage()]);
        }
    }

    if ($action === 'login_user') {
        if ($auth->loginUser((string)($_POST['nif'] ?? ''), (string)($_POST['password'] ?? ''))) {
            $_SESSION['user_authenticated'] = true;
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            session_regenerate_id(true);
            json_response(['success' => true, 'redirect' => 'index.php?page=dashboard']);
        } else {
            json_response(['success' => false, 'message' => 'Credenciales inválidas.']);
        }
    }

    if ($action === 'logout_user') {
        $auth->logoutUser();
        header('Location: index.php');
        exit;
    }

    if ($action === 'faceb2b_codes') {
        $type = (string)($_POST['type'] ?? '');
        $cfgLocal = file_exists($cfgPath) ? (json_decode((string)@file_get_contents($cfgPath), true) ?: []) : [];
        $cacheDir = __DIR__ . '/data';
        $cacheFile = $cacheDir . '/faceb2b_codes_' . preg_replace('~[^a-z0-9._-]+~i','_', $type ?: 'all') . '.json';
        try {
            $fb = new FaceB2BClient((array)($cfgLocal['faceb2b'] ?? []));
            $res = $fb->getCodes($type);
            $items = (array)($res['items'] ?? []);
            if (!empty($res['success']) && !empty($items)) {
                if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
                @file_put_contents($cacheFile, json_encode(['items'=>$items], JSON_UNESCAPED_UNICODE));
                json_response(['success'=>true, 'items'=>$items], 200);
            }
            // Fallback a caché local si existe
            if (is_file($cacheFile)) {
                $cached = json_decode((string)@file_get_contents($cacheFile), true) ?: [];
                $cItems = (array)($cached['items'] ?? []);
                if (!empty($cItems)) {
                    json_response(['success'=>true, 'items'=>$cItems, 'cached'=>true], 200);
                }
            }
            $msg = (string)($res['message'] ?? 'No se pudo cargar el catálogo de motivos');
            json_response(['success'=>false,'message'=>$msg], 200);
        } catch (Throwable $e) {
            // Fallback a caché si hay error
            if (is_file($cacheFile)) {
                $cached = json_decode((string)@file_get_contents($cacheFile), true) ?: [];
                $cItems = (array)($cached['items'] ?? []);
                if (!empty($cItems)) {
                    json_response(['success'=>true, 'items'=>$cItems, 'cached'=>true], 200);
                }
            }
            json_response(['success'=>false,'message'=>'Error: '.$e->getMessage()], 200);
        }
    }

    // Eliminado: endpoints de anulación (solicitar/listar/decidir) deshabilitados por petición
}

// ----------------- AUTENTICACIÓN: GATE DE ENTRADA -----------------
if (!$auth->isUserRegistered()) {
    include __DIR__ . '/templates/register.php';
    exit;
}
if (empty($_SESSION['user_authenticated'])) {
    include __DIR__ . '/templates/login.php';
    exit;
}

// ----------------- NORMALIZACIÓN DE RUTAS (PÁGINAS) -----------------
$rawPage = $_GET['page'] ?? 'dashboard';
$map = [
    'dashboard' => 'dashboard', 'panel' => 'dashboard', 'home' => 'dashboard',
    'invoice_list' => 'invoice_list', 'invoices' => 'invoice_list', 'mis_facturas' => 'invoice_list', 'facturas' => 'invoice_list',
    'view_invoice' => 'view_invoice', 'ver_factura' => 'view_invoice',
    'create_invoice' => 'create_invoice', 'new_invoice' => 'create_invoice', 'nueva_factura' => 'create_invoice', 'emitir_factura' => 'create_invoice',
    'rectify_prompt' => 'rectify_prompt', 'rectificar' => 'rectify_prompt',
    'export_facturae' => 'export_facturae', 'export_xsig' => 'export_facturae', 'export_factura_e' => 'export_facturae',
    'print_invoice' => 'print_invoice', 'pdf_invoice' => 'print_invoice', 'pdf_factura' => 'print_invoice',
    'print_received' => 'print_received', 'pdf_received' => 'print_received',
    'clients' => 'clients', 'clientes' => 'clients',
    'products' => 'products', 'productos' => 'products', 'product_list' => 'products',
    'settings' => 'settings', 'ajustes' => 'settings', 'configuracion' => 'settings', 'mis_datos' => 'settings', 'my_data' => 'settings',
    'edit_product' => 'edit_product', 'editar_producto' => 'edit_product',
    'edit_client' => 'edit_client',  'editar_cliente' => 'edit_client',
    'received' => 'received', 'recibidas' => 'received',
    'received_view' => 'received_view', 'ver_recibida' => 'received_view',
    // Página pública de condiciones
    'terms' => 'terms', 'condiciones' => 'terms', 'terminos' => 'terms', 'tos' => 'terms',
];
$page = $map[strtolower((string)$rawPage)] ?? strtolower((string)$rawPage);

// ----------------- MANEJADOR DE ACCIONES POST (AJAX) -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ((string)$_POST['action']) {
            case 'get_unsigned_facturae': {
                $invoiceId = trim((string)($_POST['id'] ?? ''));
                if ($invoiceId === '') {
                    json_response(['success' => false, 'message' => 'Falta el identificador de la factura'], 400);
                }

                try {
                    $im = new InvoiceManager();
                    $payload = $im->getFacturaePayload($invoiceId, []);
                    $sm = new SignatureManager();
                    $xml = $sm->generateUnsignedXml($payload);
                    $hash = hash('sha256', $xml) ?: '';
                    json_response([
                        'success'   => true,
                        'invoiceId' => $invoiceId,
                        'xml'       => base64_encode($xml),
                        'sha256'    => $hash,
                        'meta'      => [
                            'series' => $payload['series'] ?? null,
                            'number' => $payload['number'] ?? null,
                            'issueDate' => $payload['issueDate'] ?? null,
                        ],
                    ]);
                } catch (Throwable $e) {
                    json_response(['success' => false, 'message' => 'No se pudo preparar la factura para firmar: ' . $e->getMessage()], 500);
                }
                break;
            }

            case 'save_signed_facturae': {
                $invoiceId = trim((string)($_POST['id'] ?? ''));
                $signedB64 = trim((string)($_POST['signedXml'] ?? ''));
                if ($invoiceId === '' || $signedB64 === '') {
                    json_response(['success' => false, 'message' => 'Faltan datos de la factura firmada.'], 400);
                }

                try {
                    $im = new InvoiceManager();
                    $payload = $im->getFacturaePayload($invoiceId, []);
                    $payload['fileSuffix'] = 'AUTOFIRMA';
                    $sm = new SignatureManager();
                    $result = $sm->saveSignedXml($invoiceId, $signedB64, $payload);
                    json_response($result, $result['success'] ? 200 : 400);
                } catch (Throwable $e) {
                    json_response(['success' => false, 'message' => 'No se pudo guardar la factura firmada: ' . $e->getMessage()], 500);
                }
                break;
            }

            // --- Facturación ---
            case 'create_invoice': {
                $im = new InvoiceManager();
                $result = $im->createInvoice($_POST);
                json_response($result);
                break;
            }

            case 'rectify_create': {
                $originalId = (string)($_POST['id'] ?? '');
                $reason = trim((string)($_POST['reason'] ?? ''));

                $flag = strtolower(trim((string)($_POST['openSecond'] ?? 'no')));
                $openSecond = in_array($flag, ['yes','true','on','1','si','sí'], true);

                if ($originalId === '') {
                    json_response(['success' => false, 'message' => 'Falta el ID de la factura a rectificar'], 400);
                }

                $im  = new InvoiceManager();
                $res = $im->createRectificative($originalId, $reason, 'R');
                if (empty($res['success'])) {
                    json_response(['success' => false, 'message' => ($res['message'] ?? 'No se pudo crear la rectificativa')], 400);
                }

                $payload = ['success' => true, 'rectificativeId' => $res['invoiceId']];
                if ($openSecond) {
                    $payload['redirect'] =
                        'index.php?page=create_invoice'
                        . '&series=R'
                        . '&rectifies=' . rawurlencode($originalId)
                        . '&motive='    . rawurlencode($reason);
                }
                json_response($payload);
                break;
            }

            case 'send_faceb2b':
            case 'faceb2b_send':
            case 'send_faceb2b_to_client': {
                $invoiceId = trim((string)($_POST['invoice_id'] ?? $_POST['id'] ?? ''));
                if ($invoiceId === '') {
                    json_response(['success'=>false,'message'=>'Falta invoice_id.'], 400);
                }

                $im  = new InvoiceManager();
                $inv = $im->getInvoiceById($invoiceId);
                if (!$inv) {
                    json_response(['success'=>false, 'message'=>'Factura no encontrada.'], 404);
                }

                $clientId = (string)($inv->client->id ?? '');
                $dm = new DataManager('clients');
                $client = $dm->getItemById($clientId);
                if (!$client) {
                    json_response(['success'=>false, 'message'=>'Ficha de cliente no encontrada.'], 400);
                }

                $dire = strtoupper(trim((string)($client->dire ?? '')));
                if ($dire === '') {
                    json_response(['success'=>false, 'message'=>'El cliente no tiene DIRe configurado.'], 400);
                }

                $signedInfo = $im->getSignedFacturaeInfo($invoiceId);
                if (!$signedInfo || empty($signedInfo['absolutePath'])) {
                    json_response(['success'=>false, 'message'=>'La factura no está firmada todavía. Firma con AutoFirma antes de enviarla.'], 400);
                }

                $xmlPath = $signedInfo['absolutePath'];
                $xmlData = (string)@file_get_contents($xmlPath);
                if ($xmlData === '') {
                    json_response(['success'=>false,'message'=>'No se pudo leer el fichero Facturae firmado.'], 500);
                }
                if (!preg_match('/<([a-z0-9._-]+:)?Signature\b/i', $xmlData)) {
                    json_response(['success'=>false,'message'=>'El fichero Facturae no contiene firma digital.'], 400);
                }

                // Verificación suave de DIR3 presentes
                $dir3Oc = strtoupper(trim((string)($client->face_dir3_oc ?? $client->dir3_oc ?? '')));
                $dir3Og = strtoupper(trim((string)($client->face_dir3_og ?? $client->dir3_og ?? '')));
                $dir3Ut = strtoupper(trim((string)($client->face_dir3_ut ?? $client->dir3_ut ?? '')));
                if ($dir3Oc !== '' && strpos($xmlData, $dir3Oc) === false) {
                    json_response(['success'=>false,'message'=>'El XML firmado no contiene el DIR3 OC del cliente.'], 400);
                }
                if ($dir3Og !== '' && strpos($xmlData, $dir3Og) === false) {
                    json_response(['success'=>false,'message'=>'El XML firmado no contiene el DIR3 OG del cliente.'], 400);
                }
                if ($dir3Ut !== '' && strpos($xmlData, $dir3Ut) === false) {
                    json_response(['success'=>false,'message'=>'El XML firmado no contiene el DIR3 UT del cliente.'], 400);
                }

                $cfg = json_decode((string)@file_get_contents($cfgPath), true) ?: [];
                $faceCfg = (array)($cfg['faceb2b'] ?? []);
                if (!empty($faceCfg['p12_pass']) && is_string($faceCfg['p12_pass']) && strncmp($faceCfg['p12_pass'], 'enc:v1:', 7) === 0) {
                    $dec = SecureConfig::decrypt((string)$faceCfg['p12_pass']);
                    if (is_string($dec) && $dec !== '') {
                        $faceCfg['p12_pass'] = $dec;
                    }
                }

                @file_put_contents(__DIR__.'/data/logs/faceb2b.log',
                    '['.date('c')."] will_send xml=".basename($xmlPath)." size=".strlen($xmlData)." dire={$dire}\n",
                    FILE_APPEND
                );

                $fb2b = new FaceB2BClient($faceCfg);
                $result = $fb2b->sendInvoice($xmlPath, $dire);

                $registrationCode = $result['registrationCode'] ?? null;
                if (!empty($registrationCode)) {
                    $im->setFaceb2bCode($invoiceId, (string)$registrationCode);
                    $flagDir = __DIR__ . '/data/faceb2b/sent';
                    if (!is_dir($flagDir)) @mkdir($flagDir, 0775, true);
                    @file_put_contents($flagDir . '/' . basename($invoiceId) . '.flag', (string)$registrationCode);

                    json_response(['success' => true, 'message' => 'Factura enviada a FACeB2B.', 'registrationCode' => $registrationCode]);
                }

                $raw = $result['response'] ?? [];
                $msg = $result['message']
                    ?? ($raw['resultStatus']['message']
                        ?? ($raw['result']['status']['message']
                            ?? 'FACeB2B respondió sin código de registro.'));
                json_response([
                    'success' => false,
                    'message' => $msg,
                    'raw'     => $raw
                ], 502);
                break;
            }

            case 'send_face': {
                $invoiceId = trim((string)($_POST['id'] ?? $_POST['invoice_id'] ?? ''));
                if ($invoiceId === '') { json_response(['success'=>false,'message'=>'Falta invoice_id.'], 400); }

                $logFace = __DIR__ . '/data/logs/face.log';
                @mkdir(dirname($logFace), 0775, true);

                $im  = new InvoiceManager();
                $inv = $im->getInvoiceById($invoiceId);
                if (!$inv) { json_response(['success'=>false, 'message'=>'Factura no encontrada.'], 404); }

                $issuerConfigRaw = file_exists($cfgPath) ? (string)@file_get_contents($cfgPath) : '';
                $issuerConfig = $issuerConfigRaw !== '' ? (json_decode($issuerConfigRaw, true) ?: []) : [];
                $issuerData = (array)($issuerConfig['issuer'] ?? $issuerConfig);

                // Cliente (DIR3/email)
                $clientId = (string)($inv->client->id ?? '');
                $dm = new DataManager('clients');
                $client = $dm->getItemById($clientId);
                if (!$client) { json_response(['success'=>false, 'message'=>'Ficha de cliente no encontrada.'], 400); }

                // Normaliza una sola vez
                $clientArr = Normalizers::client($client);
                $dir3      = Normalizers::dir3($clientArr);
                $dir3OC    = $dir3['OC'];
                $dir3OG    = $dir3['OG'];
                $dir3UT    = $dir3['UT'];

                if ($dir3OC === '' || $dir3OG === '' || $dir3UT === '') {
                    json_response(['success'=>false, 'message'=>'Faltan códigos DIR3 (OG/UT/OC).'], 400);
                }
                $notifyEmail = (string)($clientArr['faceNotifyEmail'] ?? '');
                $signedInfo = $im->getSignedFacturaeInfo($invoiceId);
                if (!$signedInfo || empty($signedInfo['absolutePath'])) {
                    json_response(['success'=>false,'message'=>'La factura no está firmada. Firma con AutoFirma antes de enviarla a FACE.'], 400);
                }

                $xmlPath = $signedInfo['absolutePath'];
                $xmlData = (string)@file_get_contents($xmlPath);
                if ($xmlData === '') {
                    json_response(['success'=>false,'message'=>'No se pudo leer el fichero Facturae firmado.'], 500);
                }
                if (!preg_match('/<([a-z0-9._-]+:)?Signature\b/i', $xmlData)) {
                    json_response(['success'=>false,'message'=>'El fichero Facturae no contiene firma digital.'], 400);
                }

                $hasOC = ($dir3OC !== '' && strpos($xmlData, $dir3OC) !== false);
                $hasOG = ($dir3OG !== '' && strpos($xmlData, $dir3OG) !== false);
                $hasUT = ($dir3UT !== '' && strpos($xmlData, $dir3UT) !== false);
                if (!($hasOC && $hasOG && $hasUT)) {
                    @file_put_contents($logFace,
                        '['.date('c').'] send_abort missingDIR3 oc='.($hasOC?'1':'0').' og='.($hasOG?'1':'0').' ut='.($hasUT?'1':'0')
                        .' xml='.basename($xmlPath)."\n", FILE_APPEND
                    );
                    json_response(['success'=>false, 'message'=>'El Facturae firmado no incluye los tres DIR3 (OC/OG/UT). Revisa los datos antes de reenviar.'], 400);
                }

                if (!class_exists('\josemmo\Facturae\Face\FaceClient')) {
                    @require_once __DIR__ . '/vendor/josemmo/facturae-php/src/Face/FaceClient.php';
                }
                if (!class_exists('\josemmo\Facturae\FacturaeFile')) {
                    @require_once __DIR__ . '/vendor/josemmo/facturae-php/src/FacturaeFile.php';
                }

                $platformCredentials = ff_platform_credentials();
                $certPath = $platformCredentials['path'] ?? null;
                $certPass = (string)($platformCredentials['pass'] ?? '');
                if (!$certPath || !is_file($certPath)) {
                    json_response(['success'=>false,'message'=>'No se localizó el certificado de plataforma para el canal FACE.'], 500);
                }
                if (!function_exists('openssl_pkcs12_read')) {
                    json_response(['success'=>false, 'message'=>'PHP sin OpenSSL: no se puede firmar la petición a FACE.'], 500);
                }

                $factFile = new \josemmo\Facturae\FacturaeFile();
                $factFile->loadData($xmlData, basename($xmlPath));

                $face = new \josemmo\Facturae\Face\FaceClient(
                    $certPath,
                    null,
                    $certPass
                );
                if (method_exists($face, 'setProduction')) { $face->setProduction(true); }
                // if (method_exists($face, 'setExclusiveC14n')) { $face->setExclusiveC14n(false); }

                if ($notifyEmail === '') $notifyEmail = (string)($issuerData['email'] ?? '');

                $resp = $face->sendInvoice($notifyEmail, $factFile);
                $arr  = json_decode(json_encode($resp, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?: [];

                @file_put_contents($logFace, '['.date('c').'] send_resp '.json_encode($arr, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);

                $reg  = $arr['registerNumber'] ?? $arr['numeroRegistro'] ?? $arr['registrationCode'] ?? null;

                if ($reg) {
                    $flagDir = __DIR__ . '/data/face/sent';
                    if (!is_dir($flagDir)) @mkdir($flagDir, 0775, true);
                    @file_put_contents($flagDir . '/' . basename($invoiceId) . '.flag', $reg);

                    if (method_exists($im, 'setFaceCode')) {
                        $im->setFaceCode($invoiceId, (string)$reg);
                    }

                    json_response(['success'=>true, 'message'=>'Factura enviada a FACe.', 'registerNumber'=>$reg]);
                } else {
                    $descResultado = $arr['resultado']['descripcion'] ?? null;
                    $codResultado  = $arr['resultado']['codigo'] ?? null;
                    $seguimiento   = $arr['resultado']['codigoSeguimiento'] ?? null;

                    $msg = $descResultado
                        ?? ($arr['mensaje'] ?? $arr['message']
                        ?? $arr['faultstring'] ?? ($arr['fault']['faultstring'] ?? null)
                        ?? $arr['estado'] ?? $arr['descripcion']
                        ?? 'FACe respondió sin número de registro.');

                    if ($codResultado !== null) {
                        $msg .= " (código $codResultado";
                        if ($seguimiento) $msg .= ", seguimiento $seguimiento";
                        $msg .= ")";
                    }

                    @file_put_contents($logFace,
                        '['.date('c').'] send_resp_parsed code=' . ($codResultado ?? '-') .
                        ' desc=' . ($descResultado ?? '-') . "\n", FILE_APPEND
                    );

                    json_response(['success'=>false, 'message'=>$msg, 'raw'=>$arr], 502);
                }
                break;
            }

            case 'sync_face': {
                // Sincroniza estado de facturas enviadas a FACe a partir del número de registro
                $oneId = trim((string)($_POST['id'] ?? $_POST['invoice_id'] ?? ''));
                $im = new InvoiceManager();
                // Carga config opcional para Face Providers (data/face.json o <cifra>/face.json)
                if (!class_exists('FaceProvidersClient')) {
                    @require_once __DIR__ . '/src/FaceProvidersClient.php';
                }
                $faceCfg = [];
                $platformCreds = ff_platform_credentials();
                if (!empty($platformCreds['path'])) {
                    $faceCfg['p12_path'] = $platformCreds['path'];
                }
                if (!empty($platformCreds['pass'])) {
                    $faceCfg['p12_pass'] = $platformCreds['pass'];
                }
                $client = new FaceProvidersClient($faceCfg);

                $targets = [];
                if ($oneId !== '') {
                    $x = $im->getInvoiceById($oneId);
                    if ($x) $targets[] = $x;
                } else {
                    $targets = $im->getAllInvoices(null);
                }

                $updated = 0; $errors = 0; $skipped = 0;
                foreach ($targets as $x) {
                    $id = (string)($x->id ?? '');
                    $reg = trim((string)($x->face->registerNumber ?? ''));
                    if ($reg === '') { $skipped++; continue; }
                    try {
                        $res = $client->getInvoice($reg);
                        if (!empty($res['success']) && is_array($res['response'])) {
                            $ok = $im->setFaceStatusFromApi($id, $res['response']);
                            if ($ok) $updated++; else $errors++;
                        } else {
                            $errors++;
                        }
                    } catch (\Throwable $e) { $errors++; }
                }

                json_response(['success'=>true, 'updated_count'=>$updated, 'skipped_count'=>$skipped, 'error_count'=>$errors]);
                break;
            }

            // --- Facturas recibidas ---
            case 'upload_received': {
                if (!isset($_FILES['receivedFile'])) {
                    json_response(['success'=>false,'message'=>'Falta el archivo.'], 400);
                }
                $rm = new ReceivedManager();
                $res = $rm->saveUploaded($_FILES['receivedFile']);
                if (empty($res['success'])) {
                    json_response(['success'=>false,'message'=>$res['message'] ?? 'No se pudo subir.'], 400);
                }
                json_response(['success'=>true, 'id'=>$res['id']]);
                break;
            }

            case 'update_received_status': {
                $id     = (string)($_POST['id'] ?? '');
                $action = (string)($_POST['statusAction'] ?? '');
                $reason = isset($_POST['reason']) ? (string)$_POST['reason'] : null;
                $date   = isset($_POST['paymentDate']) ? (string)$_POST['paymentDate'] : null;

                if ($id === '' || $action === '') {
                    json_response(['success'=>false,'message'=>'Parámetros insuficientes.'], 400);
                }
                $rm = new ReceivedManager();
                $opts = [];
                if ($reason !== null && $reason !== '') $opts['reason'] = $reason;
                if ($date !== null && $date !== '') $opts['paymentDate'] = $date;
                $res = $rm->updateStatus($id, $action, $opts);
                if (empty($res['success'])) {
                    json_response(
                        ['success' => false, 'message' => ($res['message'] ?? 'No se pudo actualizar el estado.')],
                        400
                    );
                }
                json_response([
                    'success' => true,
                    'notice' => ($res['notice'] ?? 'Recuerda notificar al proveedor inmediatamente el cambio de estado.'),
                    'meta' => ($res['meta'] ?? null),
                ]);
                break;
            }

            // --- FACeB2B: diagnóstico rápido ---
            case 'debug_faceb2b': {
                try {
                    $cfg     = file_exists($cfgPath) ? json_decode((string)file_get_contents($cfgPath), true) : [];
                    $issuer  = (array)($cfg['issuer'] ?? $cfg);

                    $nif  = strtoupper(trim((string)($issuer['nif']  ?? '')));
                    $dire = strtoupper(trim((string)($issuer['dire'] ?? '')));

                    $faceCfg = (array)($cfg['faceb2b'] ?? []);
                    $fb2b    = new FaceB2BClient($faceCfg);

                    $list = $fb2b->debugList(['dire' => $dire]);

                    $peek = null;
                    $tryId = (string)($_POST['registry'] ?? $_POST['id'] ?? '');
                    if ($tryId !== '') {
                        $peek = $fb2b->debugDownloadPeek($tryId);
                    }

                    json_response([
                        'success' => true,
                        'issuer' => ['nif'=>$nif, 'dire'=>$dire],
                        'list' => $list,
                        'peek' => $peek,
                    ]);
                } catch (\Throwable $e) {
                    json_response([
                        'success' => false,
                        'message' => 'Debug FACeB2B falló: ' . $e->getMessage()
                    ], 200);
                }
                // sin break; json_response hace exit
            }

            case 'sync_faceb2b': {
                try {
                    $rm = new ReceivedManager();
                    $result = $rm->syncWithFACeB2B();

                    if (!is_array($result)) {
                        $result = ['success' => false, 'message' => 'Respuesta inesperada del sincronizador.'];
                    } elseif (!array_key_exists('success', $result)) {
                        $result['success'] = true;
                    }

                    json_response($result);
                } catch (\Throwable $e) {
                    $logDir = __DIR__ . '/data/logs';
                    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
                    @file_put_contents(
                        $logDir.'/sync_faceb2b_error.log',
                        '['.date('c').'] '.$e::class.' :: '.$e->getMessage()."\n".$e->getTraceAsString()."\n\n",
                        FILE_APPEND
                    );

                    json_response([
                        'success' => false,
                        'message' => 'Error sincronizando con FACeB2B: ' . $e->getMessage()
                    ], 200);
                }
                // sin break; json_response ya hizo exit
            }

            // --- FACeB2B: guardar subformulario avanzado ---
            case 'save_faceb2b': {
                $cfg     = file_exists($cfgPath) ? json_decode((string)file_get_contents($cfgPath), true) : [];
                if (!is_array($cfg)) $cfg = [];
                $fb      = (array)($cfg['faceb2b'] ?? []);

                // Campos admin-only preservados
                if (!function_exists('ff_platform_dir')) { @require_once __DIR__ . '/src/helpers.php'; }
                $platDir = function_exists('ff_platform_dir') ? ff_platform_dir() : null;
                $lockedP12Path = $fb['p12_path'] ?? ($platDir ? ($platDir . '/max.p12') : '/var/www/html/cifra/max.p12');
                $lockedP12Pass = $fb['p12_pass'] ?? null;

                // Campos de texto/número permitidos
                $mapText = [
                    'wsdl_prod'       => 'faceb2b_wsdl_prod',
                    'wsdl_pre'        => 'faceb2b_wsdl_pre',
                    'method_list'     => 'faceb2b_method_list',
                    'method_download' => 'faceb2b_method_download',
                    'param_nif'       => 'faceb2b_param_nif',
                    'param_dire'      => 'faceb2b_param_dire',
                    'param_id'        => 'faceb2b_param_id',
                ];
                foreach ($mapText as $k => $formName) {
                    if (array_key_exists($formName, $_POST)) {
                        $val = trim((string)($_POST[$formName] ?? ''));
                        if ($val !== '') $fb[$k] = $val;
                    }
                }

                // Timeout
                if (array_key_exists('faceb2b_timeout', $_POST)) {
                    $fb['timeout'] = max(1, (int)$_POST['faceb2b_timeout']);
                    if ($fb['timeout'] <= 0) $fb['timeout'] = 30;
                }

                // Flags booleanas
                $boolish = function (string $name): ?bool {
                    if (!array_key_exists($name, $_POST)) return null;
                    $v = strtolower(trim((string)$_POST[$name]));
                    return in_array($v, ['1','true','on','yes','si','sí'], true);
                };
                $flags = [
                    'validate_signature' => 'faceb2b_validate_signature',
                    'confirm_download'   => 'faceb2b_confirm_download',
                    'debug'              => 'faceb2b_debug',
                    'use_pre'            => 'faceb2b_use_pre',
                ];
                foreach ($flags as $k => $formName) {
                    $val = $boolish($formName);
                    if ($val !== null) $fb[$k] = $val;
                }

                // Recolocar campos bloqueados
                $fb['p12_path'] = $lockedP12Path;
                if ($lockedP12Pass !== null && $lockedP12Pass !== '') {
                    $fb['p12_pass'] = $lockedP12Pass;
                } else {
                    if (empty($fb['p12_pass'])) {
                        $fb['p12_pass'] = $fb['p12_pass'] ?? '';
                    }
                }

                // Forzar carpetas de log (idempotente)
                @mkdir(__DIR__ . '/data/aeat_debug', 0775, true);
                @mkdir(__DIR__ . '/data/logs',       0775, true);

                // Guardar
                $cfg['faceb2b'] = $fb;
                if (!is_dir(dirname($cfgPath))) { @mkdir(dirname($cfgPath), 0775, true); }
                @file_put_contents($cfgPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                json_response([
                    'success' => true,
                    'message' => 'Configuración FACeB2B guardada (certificado y contraseña: solo administrador).'
                ]);
                break;
            }

            // --- Ajustes generales (Mis datos) ---
            case 'save_settings': {
                $settingsFile = __DIR__ . '/data/config.json';
                $settings = file_exists($settingsFile) ? json_decode((string)file_get_contents($settingsFile), true) : [];
                if (!is_array($settings)) $settings = [];

                $textFields = [
                    'entityType','companyName','nif','address','postCode','town','province',
                    'firstName','lastName','secondSurname','irpfRate','dire','email','iban','countryCode',
                    'face_dir3_oc','face_dir3_og','face_dir3_ut',
                    'face_expedient','face_contract_ref'
                ];
                foreach ($textFields as $field) {
                    if (array_key_exists($field, $_POST)) {
                        $settings[$field] = $_POST[$field];
                    }
                }

                if (isset($_POST['nif'])) {
                    $settings['nif'] = strtoupper(preg_replace('/[\s-]+/', '', (string)$_POST['nif']));
                }

                // Logo
                if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/data/uploads/';
                    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
                    $logoName = 'logo_' . uniqid() . '_' . basename($_FILES['logo']['name']);
                    $logoPath = $uploadDir . $logoName;
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $logoPath)) {
                        if (!empty($settings['logoPath']) && file_exists($settings['logoPath'])) @unlink($settings['logoPath']);
                        $settings['logoPath'] = 'data/uploads/' . $logoName;
                    }
                }

                // Merge NO destructivo de FACeB2B si este formulario trae campos faceb2b
                $fb = (array)($settings['faceb2b'] ?? []);
                $mapFb = [
                    'wsdl_prod'       => 'faceb2b_wsdl_prod',
                    'wsdl_pre'        => 'faceb2b_wsdl_pre',
                    'timeout'         => 'faceb2b_timeout',
                    'method_list'     => 'faceb2b_method_list',
                    'method_download' => 'faceb2b_method_download',
                    'param_nif'       => 'faceb2b_param_nif',
                    'param_dire'      => 'faceb2b_param_dire',
                    'param_id'        => 'faceb2b_param_id',
                ];
                foreach ($mapFb as $k => $formName) {
                    if (array_key_exists($formName, $_POST) && $_POST[$formName] !== '') {
                        $fb[$k] = ($k === 'timeout') ? (int)$_POST[$formName] : trim((string)$_POST[$formName]);
                    }
                }
                if (array_key_exists('faceb2b_p12_pass', $_POST) && $_POST['faceb2b_p12_pass'] !== '') {
                    $raw = (string)$_POST['faceb2b_p12_pass'];
                    $fb['p12_pass'] = (str_starts_with($raw, 'enc:v1:')) ? $raw : SecureConfig::encrypt($raw);
                }
                if (!empty($_FILES['faceb2b_p12']['tmp_name']) && is_uploaded_file($_FILES['faceb2b_p12']['tmp_name'])) {
                    $certDir = __DIR__ . '/data/certs';
                    if (!is_dir($certDir)) { @mkdir($certDir, 0775, true); }
                    $dest = $certDir . '/faceb2b.p12';
                    if (!move_uploaded_file($_FILES['faceb2b_p12']['tmp_name'], $dest)) {
                        json_response(['success'=>false, 'message'=>'No se pudo guardar el P12 de FACeB2B.'], 400);
                    }
                    $fb['p12_path'] = $dest;
                }
                if (!empty($fb)) {
                    $settings['faceb2b'] = $fb;
                }

                file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                json_response(['success' => true, 'message' => 'Datos guardados correctamente.']);
                break;
            }

            // --- Productos ---
            case 'add_product': {
                $dm = new DataManager('products');
                $result = $dm->addItem($_POST);
                json_response($result);
                break;
            }
            case 'update_product': {
                $id = (string)($_POST['id'] ?? '');
                if ($id === '') {
                    json_response(['success' => false, 'message' => 'Falta el ID del producto'], 400);
                }
                $dm = new DataManager('products');
                $result = $dm->updateItem($id, $_POST);
                json_response($result);
                break;
            }
            case 'delete_product': {
                $id = (string)($_POST['id'] ?? '');
                if ($id === '') {
                    json_response(['success' => false, 'message' => 'Falta el ID del producto'], 400);
                }
                $dm = new DataManager('products');
                $result = $dm->deleteItem($id);
                json_response($result);
                break;
            }

            // --- Clientes ---
            case 'add_client': {
                $dm = new DataManager('clients');

                // Normaliza countryCode a ISO-3
                $cc = strtoupper(trim((string)($_POST['countryCode'] ?? '')));
                if ($cc === '' || $cc === 'ES') { $cc = 'ESP'; }
                $_POST['countryCode'] = $cc;

                // LIMPIEZA NIF CLIENTE
                if (isset($_POST['nif'])) {
                    $_POST['nif'] = strtoupper(preg_replace('/[\s-]+/', '', (string)$_POST['nif']));
                }

                // Si es persona física, componemos el nombre mostrado
                $type = (string)($_POST['entityType'] ?? 'company');
                if ($type === 'freelancer') {
                    $fn = trim((string)($_POST['firstName'] ?? ''));
                    $ln = trim((string)($_POST['lastName'] ?? ''));
                    $sn = trim((string)($_POST['secondSurname'] ?? ''));
                    $_POST['name'] = trim($fn . ' ' . $ln . ($sn ? ' ' . $sn : ''));
                }

                $result = $dm->addItem($_POST);
                json_response($result);
                break;
            }

            case 'update_client': {
                $id = (string)($_POST['id'] ?? '');
                if ($id === '') {
                    json_response(['success' => false, 'message' => 'Falta el ID del cliente'], 400);
                }
                $dm = new DataManager('clients');

                // Normaliza countryCode a ISO-3
                $cc = strtoupper(trim((string)($_POST['countryCode'] ?? '')));
                if ($cc === '' || $cc === 'ES') { $cc = 'ESP'; }
                $_POST['countryCode'] = $cc;

                // LIMPIEZA NIF CLIENTE
                if (isset($_POST['nif'])) {
                    $_POST['nif'] = strtoupper(preg_replace('/[\s-]+/', '', (string)$_POST['nif']));
                }

                // Si es persona física, (re)componemos el nombre mostrado
                $type = (string)$_POST['entityType'] ?? 'company';
                if ($type === 'freelancer') {
                    $fn = trim((string)($_POST['firstName'] ?? ''));
                    $ln = trim((string)($_POST['lastName'] ?? ''));
                    $sn = trim((string)($_POST['secondSurname'] ?? ''));
                    $_POST['name'] = trim($fn . ' ' . $ln . ($sn ? ' ' . $sn : ''));
                }

                $result = $dm->updateItem($id, $_POST);
                json_response($result);
                break;
            }

            case 'delete_client': {
                $id = (string)($_POST['id'] ?? '');
                if ($id === '') {
                    json_response(['success' => false, 'message' => 'Falta el ID del cliente'], 400);
                }
                $dm = new DataManager('clients');
                $result = $dm->deleteItem($id);
                json_response($result);
                break;
            }

            // --- AEAT ---
            case 'aeat_test': {
                $comm = new AEATCommunicator();
                $ops = $comm->listOperations();
                if (!empty($ops['success'])) {
                    json_response(['success' => true, 'operations' => $ops['operations']]);
                }
                json_response(['success' => false, 'message' => $ops['message'] ?? 'No se pudieron listar operaciones'], 400);
                break;
            }

            case 'aeat_send': {
                $id = (string)($_POST['id'] ?? '');
                if ($id === '') {
                    json_response(['success' => false, 'message' => 'Falta el ID de la factura'], 400);
                }
                $comm = new AEATCommunicator();
                $res  = $comm->sendInvoice($id);
                if (!empty($res['success'])) {
                    json_response(['success' => true, 'message' => $res['message'] ?? 'Envío correcto', 'receipt' => $res['receipt'] ?? null]);
                } else {
                    json_response(['success' => false, 'message' => $res['message'] ?? 'Error en el envío'], 400);
                }
                break;
            }

            default: {
                json_response(['success'=>false, 'message'=>'Acción no reconocida.'], 400);
            }
        } // <-- cierre switch
    } catch (\Throwable $e) {
        json_response(['success'=>false, 'message'=>'Error inesperado: '.$e->getMessage()], 500);
    }
} // <-- cierre if POST (acciones)

// ------- AUTO-LOGOUT POR INACTIVIDAD -------
$IDLE_MAX_SECONDS = 20 * 60;   // 20 min
$ABSOLUTE_MAX     = 8  * 3600; // 8 h

if (auth_is_authenticated($auth)) {
    $now  = time();
    $last = (int)($_SESSION['last_activity'] ?? $now);
    $loginTime = (int)($_SESSION['login_time'] ?? $now);

    if (($now - $last) > $IDLE_MAX_SECONDS) {
        $auth->logoutUser();
        header('Location: index.php?session=expired');
        exit;
    }
    if (($now - $loginTime) > $ABSOLUTE_MAX) {
        $auth->logoutUser();
        header('Location: index.php?session=reauth');
        exit;
    }
    $_SESSION['last_activity'] = $now;
}

// ----------------- DESCARGA DEL REGISTRO VERIFACTU -----------------
if ($page === 'settings' && isset($_GET['download_verifactu'])) {
    $vfPath = __DIR__ . '/data/verifactu/verifactu_log.xml';
    if (!file_exists($vfPath)) {
        if (!class_exists('VeriFactu')) { @require_once __DIR__ . '/src/VeriFactu.php'; }
        if (class_exists('VeriFactu')) { (new VeriFactu())->ensureLog(); }
    }
    if (file_exists($vfPath)) {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="verifactu_log.xml"');
        readfile($vfPath);
        exit;
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        die('Registro Verifactu no disponible.');
    }
}

// ----------------- MANEJADOR GET PARA EXPORTAR FACTURA-E -----------------
if ($page === 'export_facturae') {
    $invoiceId = (string)($_GET['id'] ?? '');
    if ($invoiceId === '') {
        header('Content-Type: text/plain; charset=utf-8');
        die('Falta el identificador de la factura.');
    }

    $invoiceManager = new InvoiceManager();
    $signedInfo = $invoiceManager->getSignedFacturaeInfo($invoiceId);
    if (!$signedInfo || empty($signedInfo['absolutePath']) || !is_file($signedInfo['absolutePath'])) {
        header('Content-Type: text/plain; charset=utf-8');
        die('La factura no está firmada todavía. Firma con AutoFirma y vuelve a exportar.');
    }

    $filePath = $signedInfo['absolutePath'];
    $fileName = basename($filePath);
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    readfile($filePath);
    exit;
}


// ----------------- RENDERIZADO DE PÁGINAS GET -----------------
ob_start();
switch ($page) {
    case 'dashboard': {
        $im = new InvoiceManager();
        $invoices = $im->getAllInvoices();

        // Backfill de metadatos en índice (rellena NIF/nombre/fecha) y refresco de proveedores al entrar al panel
        try {
            $rm = new ReceivedManager();
            if (method_exists($rm, 'backfillIndexMeta')) { $rm->backfillIndexMeta(); }
            $rm->refreshProvidersCache();
        } catch (\Throwable $e) { /* noop */ }

        $usable = [];
        foreach ($invoices as $inv) {
            if (isset($inv->isCancelled) && strtolower((string)$inv->isCancelled) === 'true') {
                continue;
            }
            $usable[] = $inv;
        }

        $yearlyStats = [];
        foreach ($usable as $inv) {
            $issue = (string)($inv->issueDate ?? '');
            $year  = (preg_match('/^\d{4}/', $issue)) ? substr($issue, 0, 4) : date('Y', strtotime($issue ?: 'now'));
            if (!isset($yearlyStats[$year])) {
                $yearlyStats[$year] = ['count' => 0, 'total' => 0.0];
            }
            $yearlyStats[$year]['count']++;
            $yearlyStats[$year]['total'] += (float)($inv->totalAmount ?? 0);
        }
        krsort($yearlyStats);

        $recentInvoices = array_slice($usable, 0, 10);

        $dmClients   = new DataManager('clients');
        $clients     = $dmClients->getAllItems();
        $clientCount = is_array($clients) ? count($clients) : 0;

        include __DIR__ . '/templates/dashboard.php';
        break;
    }

    case 'terms': {
        include __DIR__ . '/templates/terms.php';
        break;
    }

    case 'invoice_list': {
        $im = new InvoiceManager();
        $invoices = $im->getAllInvoices();
        // Orden por defecto: fecha (más reciente primero)
        $sort = $_GET['sort'] ?? 'date';
        if ($sort === 'date') {
            usort($invoices, function($a, $b) {
                $timeA = isset($a->creationTimestamp) ? strtotime((string)$a->creationTimestamp) : strtotime((string)$a->issueDate);
                $timeB = isset($b->creationTimestamp) ? strtotime((string)$b->creationTimestamp) : strtotime((string)$b->issueDate);
                return $timeB <=> $timeA;
            });
        } else {
            usort($invoices, function($a, $b) {
                return strcmp((string)$b->id, (string)$a->id);
            });
        }
        $signedMap = [];
        foreach ($invoices as $inv) {
            $id = (string)($inv->id ?? '');
            if ($id === '') continue;
            $info = $im->getSignedFacturaeInfo($id);
            if ($info) {
                $signedMap[$id] = $info;
            }
        }
        include __DIR__ . '/templates/invoice_list.php';
        break;
    }

    case 'view_invoice': {
        $invoiceId = (string)($_GET['id'] ?? '');
        $im = new InvoiceManager();
        $invoice = $invoiceId !== '' ? $im->getInvoiceById($invoiceId) : null;
        $issuer = file_exists($cfgPath) ? (array)json_decode((string)file_get_contents($cfgPath), true) : [];
        $signedInfo = $invoice ? $im->getSignedFacturaeInfo($invoiceId) : null;
        include __DIR__ . '/templates/view_invoice.php';
        break;
    }

    case 'print_invoice': {
        $invoiceId = (string)($_GET['id'] ?? '');
        $im = new InvoiceManager();
        $invoice = $invoiceId !== '' ? $im->getInvoiceById($invoiceId) : null;
        $issuer = file_exists($cfgPath) ? (array)json_decode((string)file_get_contents($cfgPath), true) : [];
        if (!$invoice) {
            header('Content-Type: text/plain; charset=utf-8');
            die('Factura no encontrada');
        }
        // Render HTML for PDF
        ob_start();
        include __DIR__ . '/templates/pdf_invoice.php';
        $html = ob_get_clean();
        // Generate PDF using Dompdf
        $options = new Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $fileName = 'factura-' . preg_replace('~[^A-Za-z0-9_-]+~','_', (string)$invoice->id) . '.pdf';
        header('Content-Type: application/pdf');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        $dompdf->stream($fileName, ['Attachment' => false]);
        exit;
    }

    case 'create_invoice': {
        $issuer = file_exists($cfgPath) ? (array)json_decode((string)file_get_contents($cfgPath), true) : [];
        $defaultSeries   = generate_default_series($issuer);
        $presetSeries    = strtoupper(trim((string)($_GET['series'] ?? $defaultSeries)));
        $presetRectifies = (string)($_GET['rectifies'] ?? '');
        $presetMotive    = (string)($_GET['motive'] ?? '');
        $dmClients = new DataManager('clients');
        $clients   = $dmClients->getAllItems();
        $dmProducts = new DataManager('products'); // <-- FIX de corchete
        $products  = $dmProducts->getAllItems();

        include __DIR__ . '/templates/create_invoice.php';
        break;
    }

    case 'rectify_prompt': {
        $invoiceId = (string)($_GET['id'] ?? '');
        $im = new InvoiceManager();
        $invoice = $invoiceId !== '' ? $im->getInvoiceById($invoiceId) : null;
        if (!$invoice) {
            echo '<div class="card"><h2>Error</h2><p>No se encontró la factura solicitada.</p></div>';
            break;
        }
        include __DIR__ . '/templates/rectify_prompt.php';
        break;
    }

    case 'clients': {
        $dm = new DataManager('clients');
        $clients = $dm->getAllItems();
        include __DIR__ . '/templates/client_list.php';
        break;
    }

    case 'products': {
        $dm = new DataManager('products');
        $products = $dm->getAllItems();
        include __DIR__ . '/templates/product_list.php';
        break;
    }

    case 'edit_product': {
        $id = (string)($_GET['id'] ?? '');
        $dm = new DataManager('products');
        $product = $id !== '' ? $dm->getItemById($id) : null;
        include __DIR__ . '/templates/edit_product.php';
        break;
    }

    case 'edit_client': {
        $id = (string)($_GET['id'] ?? '');
        $dm = new DataManager('clients');
        $client = $id !== '' ? $dm->getItemById($id) : null;
        include __DIR__ . '/templates/edit_client.php';
        break;
    }

    case 'settings': {
        $settings = file_exists($cfgPath) ? json_decode((string)file_get_contents($cfgPath), true) : [];

        // Cargar y paginar Verifactu
        $vfPath = __DIR__ . '/data/verifactu/verifactu_log.xml';
        $vfEntries = [];
        if (is_file($vfPath)) {
            $log = simplexml_load_file($vfPath);
            if ($log && isset($log->entry)) {
                foreach ($log->entry as $e) {
                    $vfEntries[] = [
                        'timestamp' => (string)($e->timestamp ?? ''),
                        'invoiceId' => (string)($e->invoiceId ?? ''),
                        'issueDate' => (string)($e->issueDate ?? ''),
                        'issuerNif' => (string)($e->issuerNif ?? ''),
                        'buyerNif'  => (string)($e->buyerNif  ?? ''),
                        'base'   => (string)($e->totals->baseAmount ?? ''),
                        'vat'    => (string)($e->totals->vatAmount  ?? ''),
                        'total'  => (string)($e->totals->totalAmount ?? ''),
                        'opsCount' => isset($e->operations) ? count($e->operations->op) : 0,
                    ];
                }
            }
        }
        usort($vfEntries, function($a,$b){ return strcmp($b['timestamp'], $a['timestamp']); });

        $vfPerPage = 10;
        $vfPage = max(1, (int)($_GET['vf_page'] ?? 1));
        $vfTotalPages = max(1, (int)ceil(count($vfEntries) / $vfPerPage));
        if ($vfPage > $vfTotalPages) $vfPage = $vfTotalPages;
        $vfEntriesPage = array_slice($vfEntries, ($vfPage-1)*$vfPerPage, $vfPerPage);

        include __DIR__ . '/templates/settings.php';
        break;
    }

    case 'received': {
        $rm = new ReceivedManager();
        try { if (method_exists($rm, 'backfillIndexMeta')) { $rm->backfillIndexMeta(); } } catch (\Throwable $e) {}
        try { $rm->refreshProvidersCache(); } catch (\Throwable $e) {}
        $received = $rm->listAll();
        $providers = $rm->getProvidersMap();
        if (!empty($_GET['download_zip'])) {
            $zipPath = $rm->buildZipOfReceived();
            if (is_file($zipPath)) {
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="received_invoices.zip"');
                header('Content-Length: ' . filesize($zipPath));
                readfile($zipPath);
                @unlink($zipPath);
                exit;
            } else {
                echo '<div class="card"><h2>Error</h2><p>No se pudo crear el ZIP.</p></div>';
            }
        }

        include __DIR__ . '/templates/received_list.php';
        break;
    }

    case 'received_view': {
        $id = $_GET['id'] ?? '';
        $rm = new ReceivedManager();
        $rv = $rm->getViewDataById((string)$id);
        require __DIR__ . '/templates/received_view.php';
        break;
    }

    case 'print_received': {
        $id = $_GET['id'] ?? '';
        $rm = new ReceivedManager();
        $rv = $rm->getViewDataById((string)$id);
        if (empty($rv) || empty($rv['success'])) {
            header('Content-Type: text/plain; charset=utf-8');
            die('Factura recibida no disponible');
        }
        ob_start();
        include __DIR__ . '/templates/pdf_received.php';
        $html = ob_get_clean();
        $options = new Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $fileName = 'recibida-' . preg_replace('~[^A-Za-z0-9_-]+~','_', (string)($rv['header']['number'] ?? $id)) . '.pdf';
        header('Content-Type: application/pdf');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        $dompdf->stream($fileName, ['Attachment' => false]);
        exit;
    }

    default: {
        $im = new InvoiceManager();
        $invoices = $im->getAllInvoices();
        $dmClients = new DataManager('clients');
        $clients = $dmClients->getAllItems();
        $clientCount = is_array($clients) ? count($clients) : 0;
        include __DIR__ . '/templates/dashboard.php';
        break;
    }
}
$content = ob_get_clean();


// ----------------- RENDERIZADO FINAL CON EL LAYOUT -----------------
include __DIR__ . '/templates/layout.php';
