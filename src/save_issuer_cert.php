<?php
declare(strict_types=1);

require_once __DIR__ . '/../IssuerCert.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
        exit;
    }

    // Campos esperados:
    //  - file:  name="issuer_cert" (multipart/form-data)
    //  - text:  name="issuer_pass" (opcional)
    $file = $_FILES['issuer_cert'] ?? null;
    $pass = isset($_POST['issuer_pass']) ? (string)$_POST['issuer_pass'] : null;

    $res = \IssuerCert::saveUploaded($file, $pass);
    if (!$res['ok']) {
        http_response_code(400);
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}

