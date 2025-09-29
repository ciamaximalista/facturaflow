<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$rootDir       = dirname(__DIR__, 2);
$bucketDir     = $rootDir . '/data/autofirma/storage';
$logFile       = $rootDir . '/data/autofirma/debug.log';
$crossDomainOk = [
    'https://ruralnext.org',
    'https://www.ruralnext.org',
];

if (!is_dir($bucketDir)) {
    @mkdir($bucketDir, 0775, true);
}

function log_debug(string $message): void {
    global $logFile;
    @file_put_contents($logFile, '[' . date('c') . "] STORAGE " . $message . "\n", FILE_APPEND);
}

function allow_cors(array $allowedOrigins): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin && (in_array($origin, $allowedOrigins, true) || in_array('*', $allowedOrigins, true))) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
        http_response_code(204);
        exit;
    }
}

allow_cors($crossDomainOk);

$ttlSeconds = 3600;
$now = time();
foreach (@scandir($bucketDir) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    $path = $bucketDir . '/' . $entry;
    if (is_dir($path)) {
        continue;
    }
    if ($now - @filemtime($path) > $ttlSeconds) {
        @unlink($path);
    }
}

function respond(int $status, string $body = ''): void {
    http_response_code($status);
    if ($body !== '') {
        echo $body;
    }
    exit;
}

function clean_id(string $id): ?string {
    if ($id === '') {
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9]{1,64}$/', $id)) {
        return null;
    }
    return $id;
}

function path_for(string $dir, string $id, string $suffix): string {
    return $dir . '/' . $id . $suffix;
}

$op = strtolower((string)($_REQUEST['op'] ?? ''));
if ($op === '') {
    respond(400, 'ERR-00');
}

switch ($op) {
    case 'check':
        log_debug('CHECK');
        respond(200, 'OK');

    case 'put':
        $id = clean_id((string)($_REQUEST['id'] ?? ''));
        $data = (string)($_REQUEST['dat'] ?? '');
        if ($data !== '') {
            $data = strtr($data, [' ' => '+']);
        }
        if ($id === null || $data === '') {
            respond(400, 'ERR-01');
        }
        $dataPath = path_for($bucketDir, $id, '.dat');
        if (@file_put_contents($dataPath, $data, LOCK_EX) === false) {
            respond(500, 'ERR-02');
        }
        $meta = [
            'created_at' => $now,
            'remote'     => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];
        @file_put_contents(path_for($bucketDir, $id, '.meta.json'), json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        // Copiamos también al buzón del retriever para que la aplicación nativa pueda descargar la configuración.
        $retrieveDir = dirname($bucketDir) . '/retrieve';
        if (!is_dir($retrieveDir)) {
            @mkdir($retrieveDir, 0775, true);
        }
        @file_put_contents($retrieveDir . '/' . $id . '.dat', $data, LOCK_EX);
        @file_put_contents($retrieveDir . '/' . $id . '.meta.json', json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        log_debug("PUT id={$id} bytes=" . strlen($data));
        respond(200, 'OK');

    case 'get':
        $id = clean_id((string)($_REQUEST['id'] ?? ''));
        if ($id === null) {
            respond(400, 'ERR-01');
        }
        $dataPath = path_for($bucketDir, $id, '.dat');
        if (!is_file($dataPath)) {
            respond(404, 'ERR-06');
        }
        $payload = (string)@file_get_contents($dataPath);
        log_debug("GET id={$id} bytes=" . strlen($payload));
        respond(200, $payload);

    default:
        respond(400, 'ERR-99');
}
