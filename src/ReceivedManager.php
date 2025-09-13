<?php
// src/ReceivedManager.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/FaceB2BClient.php';
// Asegura disponibilidad de InvoiceManager cuando se usa fuera de index.php
if (!class_exists('InvoiceManager') && file_exists(__DIR__ . '/InvoiceManager.php')) {
    require_once __DIR__ . '/InvoiceManager.php';
}

final class ReceivedManager
{
    private string $receivedDir;
    private string $indexFile;
    /** @var array<string,mixed> */
    private array $config;

    public function __construct(?string $receivedDir = null)
    {
        $baseData = realpath(__DIR__ . '/../data') ?: (__DIR__ . '/../data');
        $this->receivedDir = $receivedDir ?: ($baseData . '/received');
        $this->indexFile   = $this->receivedDir . '/index.json';

        ensure_dir($this->receivedDir);
        $this->config = read_config(__DIR__ . '/../data/config.json');
        $this->ensureIndex();
    }

    /**
     * Sincroniza con FACeB2B usando josemmo (vía FaceB2BClient drop-in).
     * - Lista recibidas por NIF/DIRe
     * - Descarga XML firmado (valida firma en origen)
     * - Actualiza índice local
     * @return array{success:bool,added_ids:array<int,string>,added_count:int,message:string}
     */
    public function syncWithFACeB2B(): array
    {
        // 1) Identificación receptor
        $cfg    = $this->config;
        $issuer = (array)($cfg['issuer'] ?? []);
        if (empty($issuer)) {
            $issuer = [
                'nif'  => $cfg['nif']  ?? null,
                'dire' => $cfg['dire'] ?? null,
            ];
        }
        $nif  = strtoupper(preg_replace('/[\s-]+/', '', (string)($issuer['nif']  ?? '')));
        $dire = strtoupper(preg_replace('/[^A-Z0-9._-]/', '', (string)($issuer['dire'] ?? '')));

        if ($nif === '' && $dire === '') {
            return json_error('Falta NIF o DIRe del receptor en data/config.json', [
                'added_ids'   => [],
                'added_count' => 0
            ]);
        }

        // 2) Cliente FACeB2B (usa cert del EMISOR y WS-Security)
        $fb2b = new FaceB2BClient((array)($cfg['faceb2b'] ?? []));

        // 3) Listar
        try {
            $pending = $fb2b->listReceived(['nif'=>$nif, 'dire'=>$dire]);
            // Log diagnóstico de la consulta de lista a FACeB2B
            $logDir = __DIR__ . '/../data/logs';
            if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
            @file_put_contents(
                $logDir . '/faceb2b_list.log',
                '['.date('c').'] listReceived count='.(is_array($pending)?count($pending):0).' payload='.
                json_encode($pending, JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR).PHP_EOL,
                FILE_APPEND
            );
        } catch (\Throwable $e) {
            return json_error('Error listando FACeB2B: ' . $e->getMessage(), [
                'added_ids'   => [],
                'added_count' => 0
            ]);
        }
        if (!is_array($pending)) $pending = [];

        // 4) Índice local → conocidos
        $index = load_json($this->indexFile);
        $known = [];
        foreach ((array)($index['items'] ?? []) as $row) {
            $ext = (string)($row['external_id'] ?? '');
            if ($ext !== '') $known[$ext] = true;
        }

        // 5) Filtrar las que no tenemos
        $toDownload = array_values(array_filter($pending, fn($it) =>
            ($id = (string)($it['id'] ?? '')) !== '' && !isset($known[$id])
        ));
        // Además: mapa de todos los ids devueltos por FACeB2B
        $allRemoteIds = array_values(array_filter(array_map(fn($it) => (string)($it['id'] ?? ''), $pending))); 

        // 6) Descargar XML firmado (validación en origen habilitada en FaceB2BClient)
        $added = [];
        $ownNif = strtoupper(preg_replace('/[\s-]+/', '', (string)(
            $cfg['issuer']['nif'] ?? $cfg['nif'] ?? ''
        )));

        foreach ($toDownload as $it) {
            $extId = (string)($it['id'] ?? '');
            if ($extId === '') continue;

            try {
                // Filtro previo con detalles: solo descargar si somos realmente destinatarios
                $details = $fb2b->getDetails($extId);
                $recUnit = strtoupper(trim((string)($details['invoiceDetail']['receivingUnit']['code'] ?? '')));
                $sellerNif = strtoupper(trim((string)($details['invoiceDetail']['sellerTaxIdentification'] ?? '')));
                // Nuestro NIF y DIRe (calculados al inicio)
                $ownNif = strtoupper(preg_replace('/[\s-]+/', '', (string)($cfg['issuer']['nif'] ?? $cfg['nif'] ?? '')));
                $ownDire = strtoupper(trim((string)($cfg['dire'] ?? ($cfg['issuer']['dire'] ?? ''))));

                if (($recUnit !== '' && $ownDire !== '' && $recUnit !== $ownDire) || ($sellerNif !== '' && $sellerNif === $ownNif)) {
                    // No es para nosotros o la emitimos nosotros: saltar descarga
                    continue;
                }

                $bundle = $fb2b->downloadInvoiceBundle($extId); // ← josemmo valida firma
                $xml    = (string)$bundle['invoiceData'];
                if ($xml === '') {
                    throw new \RuntimeException('Descarga vacía');
                }

                // Metadatos (serie, número, fecha, emisor/receptor, totales…)
                $meta = $this->extractMetaFromFacturae($xml);

                // Si somos el EMISOR, no es “recibida”: actualiza estado de pago de la emitida y no guardes aquí
                $supplierNif = strtoupper(preg_replace('/[\s-]+/', '', (string)($meta['supplierNif'] ?? '')));
                if ($ownNif !== '' && $supplierNif === $ownNif) {
                    $remoteRaw = strtolower(trim((string)($it['status'] ?? $it['state'] ?? '')));
                    $state = match ($remoteRaw) {
                        'accepted','aceptada' => 'Pendiente de pago',
                        'paid','pagada'       => 'Pagada',
                        'rejected','rechazada'=> 'Rechazada',
                        default               => '',
                    };
                    $this->upsertFaceb2bPaymentInInvoice($extId, [
                        'series'    => $meta['series'] ?? null,
                        'number'    => $meta['number'] ?? null,
                        'issueDate' => $meta['issueDate'] ?? null,
                        'state'     => $state
                    ]);
                    continue;
                }

                // Nombre de fichero
                $number    = trim((string)($it['number']    ?? $meta['number']    ?? ''));
                $issueDate = trim((string)($it['issueDate'] ?? $meta['issueDate'] ?? ''));
                $baseName  = safe_filename(($number !== '' ? $number : $extId) . '_' . $extId) . '.xml';
                $fullPath  = $this->receivedDir . '/' . $baseName;

                file_put_contents($fullPath, $xml);

                // Guarda report si viene y extrae QR si es posible
                $qrPngB64 = null; $qrText = null;
                if (!empty($bundle['reportData']) && is_string($bundle['reportData'])) {
                    $repDir = $this->receivedDir . '/reports';
                    if (!is_dir($repDir)) @mkdir($repDir, 0775, true);
                    @file_put_contents($repDir . '/' . $extId . '.xml', (string)$bundle['reportData']);
                    $rep = @simplexml_load_string((string)$bundle['reportData']);
                    if ($rep) {
                        // Busca posibles campos de QR (heurístico)
                        $cand = [
                            '//QR', '//QRCode', '//QRString', '//*[contains(local-name(),"QR")]'
                        ];
                        foreach ($cand as $xp) {
                            $n = @$rep->xpath($xp);
                            if ($n && isset($n[0]) && trim((string)$n[0]) !== '') { $qrText = trim((string)$n[0]); break; }
                        }
                    }
                }

                // Confirmación (si está activada)
                $fbCfg = (array)($cfg['faceb2b'] ?? []);
                if (!empty($fbCfg['confirm_download'])) {
                    try { $fb2b->confirmDownload($extId); } catch (\Throwable $e) { /* non-blocking */ }
                }

                // Índice
                $index['items'][] = [
                    'external_id' => $extId,
                    'series'      => $meta['series']      ?? null,
                    'number'      => $meta['number']      ?? null,
                    'issueDate'   => $meta['issueDate']   ?? $issueDate ?: null,
                    'supplierNif' => $meta['supplierNif'] ?? null,
                    'supplierName'=> $meta['supplierName']?? null,
                    'buyerNif'    => $meta['buyerNif']    ?? null,
                    'totalAmount' => isset($meta['totalAmount']) ? (float)$meta['totalAmount'] : null,
                    'concept'     => $meta['concept']     ?? null,
                    'file'        => $baseName,
                    'uploadedAt'  => date('c'),
                    'status'      => (string)($it['status'] ?? $it['state'] ?? 'Pendiente'),
                    'validated'   => 'remote-signature-ok',
                    'qrString'    => $qrText,
                    'qrPngB64'    => $qrPngB64
                ];

                $added[] = $extId;
            } catch (\Throwable $e) {
                @file_put_contents(__DIR__.'/../data/logs/received_errors.log',
                    '['.date('c')."] id={$extId} err=".$e->getMessage().PHP_EOL, FILE_APPEND);
            }
        }

        // 7) Actualizar estados de emitidas propias. No dependas solo del listado de la unidad
        //    porque los registrationCode pertenecen a la unidad receptora y podrían no aparecer
        //    en nuestro listado local. Consulta siempre nuestros propios códigos.
        try {
            $ownRegs = [];
            $im = new \InvoiceManager();
            foreach ($im->getAllInvoices() as $inv) {
                $reg = trim((string)($inv->faceb2b->registrationCode ?? $inv->faceb2bCode ?? ''));
                if ($reg !== '') $ownRegs[$reg] = true;
            }
            // Consulta SIEMPRE todos nuestros registrationCode y añade además cualquier id remoto devuelto
            // (evita perder actualizaciones cuando $allRemoteIds no contiene nuestras emitidas)
            $toCheck = array_values(array_unique(array_merge(array_keys($ownRegs), $allRemoteIds)));
            foreach ($toCheck as $reg) {
                try {
                    // Algunas versiones no exponen getInvoiceStatus; usa getInvoiceDetails
                    $st = $fb2b->getDetails($reg);
                    // Log diagnóstico de la consulta de estado
                    $logDir = __DIR__ . '/../data/logs';
                    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
                    @file_put_contents(
                        $logDir . '/faceb2b_status_query.log',
                        '['.date('c')."] reg={$reg} response=".
                        json_encode($st, JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR).PHP_EOL,
                        FILE_APPEND
                    );
                    // Normaliza estado desde campos estructurados (preferente)
                    $detail     = is_array($st) ? (array)($st['invoiceDetail'] ?? []) : [];
                    $statusInfo = (array)($detail['statusInfo'] ?? []);
                    $statusNode = (array)($statusInfo['status'] ?? []);
                    $code = (string)($statusNode['code'] ?? '');
                    $name = mb_strtolower((string)($statusNode['name'] ?? ''), 'UTF-8');
                    $desc = mb_strtolower((string)($statusNode['description'] ?? ''), 'UTF-8');
                    $when = (string)($statusInfo['modificationDate'] ?? '');

                    $state = '';
                    if ($code !== '') {
                        if ($code === '1400') $state = 'Rechazada';
                        elseif ($code === '1500') $state = 'Pagada';
                        elseif ($code === '1300') $state = 'Pendiente de pago';
                        elseif ($code === '3100') $state = 'Anulada';
                    }
                    if ($state === '') {
                        $raw = $name.' '.$desc.' '.$code;
                        if (preg_match('/paid|pagad|abonad|satisfecha/u', $raw)) $state = 'Pagada';
                        elseif (preg_match('/reject|rechaz/u', $raw)) $state = 'Rechazada';
                        elseif (preg_match('/accept|aceptad|conformad|reconocid/u', $raw)) $state = 'Pendiente de pago';
                        elseif (preg_match('/anul|cancel/u', $raw)) $state = 'Anulada';
                    }
                    if ($state !== '') {
                        $meta = ['state' => $state];
                        if ($state === 'Pagada' && $when !== '') $meta['paymentDate'] = substr($when, 0, 10);
                        if ($state === 'Pendiente de pago' && $when !== '') $meta['acceptedAt'] = $when;
                        if ($state === 'Rechazada' && $when !== '') $meta['rejectedAt'] = $when;
                        if ($state === 'Anulada' && $when !== '') $meta['canceledAt'] = $when;
                        $this->upsertFaceb2bPaymentInInvoice($reg, $meta);
                    }
                } catch (\Throwable $e) { /* noop individual */ }
            }
        } catch (\Throwable $e) { /* noop global */ }

        // 8) Persistir índice
        $index['updatedAt'] = date('c');
        save_json($this->indexFile, $index);

        return [
            'success'     => true,
            'added_ids'   => $added,
            'added_count' => count($added),
            'message'     => $added ? 'Nuevas facturas descargadas' : 'Sin nuevas facturas',
            'refreshed'   => true,
        ];
    }

    /** Lista recibidas desde índice local, ocultando las que resulten ser emitidas por nosotros. */
    public function listAll(): array
    {
        $ownNif = strtoupper(preg_replace('/[\s-]+/', '', (string)(
            $this->config['issuer']['nif'] ?? $this->config['nif'] ?? ''
        )));
        // Conjunto de IDs (registrationCode) de facturas emitidas por nosotros y enviadas a FACeB2B
        $ownFaceb2bRegs = [];
        try {
            $im = new \InvoiceManager();
            foreach ($im->getAllInvoices() as $inv) {
                $reg = trim((string)($inv->faceb2b->registrationCode ?? ''));
                if ($reg !== '') $ownFaceb2bRegs[$reg] = true;
            }
        } catch (\Throwable $e) { /* noop */ }
        $index = load_json($this->indexFile);
        $rows  = [];

        foreach ((array)($index['items'] ?? []) as $it) {
            $sellerNifNorm = strtoupper(preg_replace('/[\s-]+/', '', (string)($it['supplierNif'] ?? '')));
            $buyerNifNorm  = strtoupper(preg_replace('/[\s-]+/', '', (string)($it['buyerNif']    ?? '')));
            $extId = (string)($it['external_id'] ?? '');
            // Ocultar las emitidas por nosotros según NIF o si el ID coincide con alguno de nuestros registrationCode
            if ($ownNif && $sellerNifNorm !== '' && $sellerNifNorm === $ownNif) continue;
            if ($extId !== '' && isset($ownFaceb2bRegs[$extId])) continue;
            // Mostrar solo las facturas dirigidas a nosotros (buyerNif == ownNif)
            if ($ownNif && $buyerNifNorm !== '' && $buyerNifNorm !== $ownNif) continue;
            // Y si faltan NIFs, el filtro previo por registrationCode cubrirá casos propios

            $file  = (string)($it['file'] ?? '');
            $rows[] = [
                'id'            => $extId !== '' ? $extId : ($file !== '' ? pathinfo($file, PATHINFO_FILENAME) : ''),
                'series'        => (string)($it['series'] ?? ''),
                'invoiceNumber' => $it['number']       ?? null,
                'sellerNif'     => $it['supplierNif']  ?? null,
                'sellerName'    => $it['supplierName'] ?? null,
                'buyerNif'      => $it['buyerNif']     ?? null,
                'concept'       => $it['concept']      ?? null,
                'totalAmount'   => isset($it['totalAmount']) ? (float)$it['totalAmount'] : 0.0,
                'status'        => (string)($it['status'] ?? $it['meta']['status'] ?? 'Pendiente'),
                'uploadedAt'    => $it['uploadedAt'] ?? (
                    $file && file_exists($this->receivedDir.'/'.$file) ? date('c', filemtime($this->receivedDir.'/'.$file)) : null
                ),
                'fileRel'       => $file !== '' ? ('data/received/' . $file) : null,
                'issueDate'     => $it['issueDate'] ?? null,
            ];
        }

        usort($rows, fn($a,$b) => strcmp((string)($b['issueDate'] ?? ''), (string)($a['issueDate'] ?? '')));
        return $rows;
    }

    public function buildZipOfReceived(?array $onlyIds = null): string
    {
        $rows = $this->listAll();
        $files = [];
        foreach ($rows as $r) {
            if (!empty($onlyIds) && !in_array($r['id'], $onlyIds, true)) continue;
            $rel = $r['fileRel'] ?? null;
            if (!$rel) continue;
            $full = dirname($this->indexFile) . '/' . basename($rel);
            if (is_file($full)) $files[] = $full;
        }
        if (!$files) return '';

        $tmpDir = dirname($this->indexFile) . '/../tmp';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
        $zipPath = $tmpDir . '/received_' . date('Ymd_His') . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) return '';
        foreach ($files as $f) $zip->addFile($f, basename($f));
        $zip->close();
        return $zipPath;
    }

    /**
     * Actualiza estado local y notifica a FACeB2B (vía FaceB2BClient) si es posible.
     * $action: 'accepted' | 'rejected' | 'paid'
     */
    public function updateStatus(string $externalId, string $action, array $opts = []): array
    {
        $index = load_json($this->indexFile);
        $items = (array)($index['items'] ?? []);
        $found = false;
        $nowIso = date('c');
        $newStatus = null;

        foreach ($items as &$it) {
            if ((string)($it['external_id'] ?? '') !== $externalId) continue;
            $found = true;

            switch (strtolower(trim($action))) {
                case 'accepted':
                    if (($it['status'] ?? '') !== 'Pagada') $it['status'] = 'Pendiente de pago';
                    $it['acceptedAt'] = $nowIso;
                    unset($it['rejectedAt'], $it['rejectionReason']);
                    $newStatus = $it['status'];
                    break;

                case 'rejected':
                    $reason = trim((string)($opts['reason'] ?? ''));
                    if ($reason === '') return json_error('Debes indicar el motivo del rechazo.');
                    $it['status'] = 'Rechazada';
                    $it['rejectedAt'] = $nowIso;
                    $it['rejectionReason'] = $reason;
                    unset($it['paymentDate']);
                    $newStatus = $it['status'];
                    break;

                case 'paid':
                    $pay = trim((string)($opts['paymentDate'] ?? ''));
                    if ($pay === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pay))
                        return json_error('Fecha de pago inválida (formato YYYY-MM-DD).');
                    $it['status'] = 'Pagada';
                    $it['paymentDate'] = $pay;
                    $newStatus = $it['status'];
                    break;

                default:
                    return json_error('Acción de estado desconocida.');
            }
            break;
        }
        unset($it);

        if (!$found) return json_error('Factura no encontrada en el índice.');

        // Guardar local
        $index['items'] = $items;
        $index['updatedAt'] = $nowIso;
        save_json($this->indexFile, $index);

        // Notificar a FACeB2B (best-effort)
        $pushMsg = null;
        try {
            $client = new FaceB2BClient((array)($this->config['faceb2b'] ?? []));
            $act = strtolower(trim($action));
            switch ($act) {
                case 'accepted': {
                    $res = $client->updateInvoiceStatus($externalId, '1300', 'confirmada');
                    if (!empty($res['success'])) $pushMsg = 'Estado notificado a FACeB2B (confirmada).';
                    else {
                        try { $client->confirmDownload($externalId); $pushMsg = 'Estado notificado (descarga confirmada).'; }
                        catch (\Throwable $e) { $pushMsg = 'Estado local actualizado; no se pudo notificar a FACeB2B.'; }
                    }
                    break; }
                case 'rejected': {
                    $rawReason = trim((string)($opts['reason'] ?? ''));
                    $res = $client->updateInvoiceStatus($externalId, '2600', ($rawReason !== '' ? $rawReason : 'rechazada'));
                    if (!empty($res['success'])) $pushMsg = 'Estado notificado a FACeB2B (rechazada).';
                    else {
                        $code = preg_match('/^[A-Z]\d{3}$/', $rawReason) ? $rawReason : 'R001';
                        $comment = $code !== $rawReason && $rawReason !== '' ? $rawReason : null;
                        $client->reject($externalId, $code, $comment);
                        $pushMsg = 'Estado notificado a FACeB2B (rechazada).';
                    }
                    break; }
                case 'paid': {
                    $res = $client->updateInvoiceStatus($externalId, '2500', 'pagada');
                    if (!empty($res['success'])) $pushMsg = 'Estado notificado a FACeB2B (pagada).';
                    else { $client->markAsPaid($externalId); $pushMsg = 'Estado notificado a FACeB2B (pagada).'; }
                    break; }
            }
        } catch (\Throwable $e) {
            $pushMsg = 'Estado actualizado localmente. Aviso: error notificando a FACeB2B: ' . $e->getMessage();
            @file_put_contents(
                __DIR__.'/../data/logs/faceb2b_status_push.log',
                '['.date('c')."] id={$externalId} action=".strtolower(trim($action))." err=".$e->getMessage().PHP_EOL,
                FILE_APPEND
            );
        }

        return [
            'success' => true,
            'status'  => $newStatus,
            'notice'  => $pushMsg ?: null,
        ];
    }

    /** Vista enriquecida de una recibida (extrae líneas/totales; mantiene adjuntos QR/VF si existen). */
    public function getViewDataById(string $externalId): array
    {
        $index = load_json($this->indexFile);

        $row = null;
        foreach ((array)($index['items'] ?? []) as $it) {
            if ((string)($it['external_id'] ?? '') === $externalId) { $row = $it; break; }
        }

        // Localiza fichero
        $fileName = null;
        if ($row && !empty($row['file'])) {
            $fileName = (string)$row['file'];
        } else {
            $cand = glob($this->receivedDir . '/*_' . $externalId . '.xml');
            if (!$cand) $cand = glob($this->receivedDir . '/' . $externalId . '.xml');
            if ($cand && is_file($cand[0])) $fileName = basename($cand[0]);
        }
        if (!$fileName) return ['success'=>false, 'message'=>'No se encontró el fichero asociado a ese ID.'];

        $fullPath = $this->receivedDir . '/' . $fileName;
        if (!is_file($fullPath)) return ['success'=>false, 'message'=>'El fichero de la factura no existe en disco.'];

        $xml = (string)@file_get_contents($fullPath);
        if ($xml === '') return ['success'=>false, 'message'=>'El fichero XML está vacío.'];

        // Reutiliza el extractor rico ya existente (mantiene QR/VeriFactu)
        return $this->viewFromXml($externalId, $fileName, $row ?? [], $xml);
    }

    // -------------------- Privados --------------------

    private function ensureIndex(): void
    {
        if (!file_exists($this->indexFile)) {
            save_json($this->indexFile, ['items'=>[], 'updatedAt'=>date('c')]);
        }
    }

    /** Igual que tu extractMetaFromFacturae original (con robustez NS y totales). */
    private function extractMetaFromFacturae(string $xml): array
    {
        // (copiado de tu versión actual; sin cambios funcionales)
        $out = [];
        try {
            $sx = @simplexml_load_string($xml);
            if ($sx === false) return $out;

            $ns = [
                'fe'    => 'http://www.facturae.es/Facturae/2009/v3.2/Facturae',
                'fe321' => 'http://www.facturae.es/Facturae/2014/v3.2.1/Facturae',
                'fe322' => 'http://www.facturae.es/Facturae/2014/v3.2.2/Facturae',
                'fe33'  => 'http://www.facturae.es/Facturae/2014/v3.3/Facturae',
            ];
            foreach ($ns as $p => $u) $sx->registerXPathNamespace($p, $u);

            $toFloat = static function(string $s): float {
                $s = trim($s);
                if ($s === '') return 0.0;
                if (strpos($s, ',') !== false) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
                $s = preg_replace('/[^0-9\.\-]/', '', $s) ?? '0';
                return (float)$s;
            };
            $xp = static function(\SimpleXMLElement $node, array $paths): string {
                foreach ($paths as $p) { $n = @$node->xpath($p); if ($n && isset($n[0])) return trim((string)$n[0]); }
                return '';
            };
            $LN = static function(string ...$names): string {
                return implode('', array_map(fn($n) => "/*[local-name()='{$n}']", $names));
            };

            $out['number'] = $xp($sx, [
                '//fe:Facturae/fe:Invoices/fe:Invoice/fe:InvoiceHeader/fe:InvoiceNumber',
                '//fe321:Facturae/fe321:Invoices/fe321:Invoice/fe321:InvoiceHeader/fe321:InvoiceNumber',
                '//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','InvoiceHeader','InvoiceNumber'),
            ]);
            $out['series'] = $xp($sx, [
                '//fe:Facturae/fe:Invoices/fe:Invoice/fe:InvoiceHeader/fe:InvoiceSeriesCode',
                '//fe321:Facturae/fe321:Invoices/fe321:Invoice/fe321:InvoiceHeader/fe321:InvoiceSeriesCode',
                '//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','InvoiceHeader','InvoiceSeriesCode'),
            ]);
            $out['issueDate'] = $xp($sx, [
                '//fe:Facturae/fe:Invoices/fe:Invoice/fe:InvoiceIssueData/fe:IssueDate',
                '//fe321:Facturae/fe321:Invoices/fe321:Invoice/fe321:InvoiceIssueData/fe321:IssueDate',
                '//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','InvoiceIssueData','IssueDate'),
            ]);

            $out['concept'] = $xp($sx, [
                '(//fe:Facturae//fe:InvoiceLine/fe:ItemDescription)[1]',
                '(//fe321:Facturae//fe321:InvoiceLine/fe321:ItemDescription)[1]',
                '(//*[local-name()="Facturae"]//*[local-name()="InvoiceLine"]/*[local-name()="ItemDescription"])[1]',
            ]);
            if ($out['concept'] === '') {
                $out['concept'] = $xp($sx, [
                    '//fe:Facturae/fe:Invoices/fe:Invoice/fe:InvoiceHeader/fe:InvoiceDocumentType',
                    '//fe321:Facturae/fe321:Invoices/fe321:Invoice/fe321:InvoiceHeader/fe321:InvoiceDocumentType',
                    '//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','InvoiceHeader','InvoiceDocumentType'),
                ]);
            }

            $out['supplierNif'] = $xp($sx, [
                '//fe321:Facturae/fe321:Parties/fe321:SellerParty/fe321:TaxIdentification/fe321:TaxIdentificationNumber',
                '//fe:Facturae/fe:Parties/fe:SellerParty/fe:TaxIdentification/fe:TaxIdentificationNumber',
                '//*[local-name()="Facturae"]'.$LN('Parties','Sellers','Seller','TaxIdentification','TaxIdentificationNumber'),
            ]);
            $out['supplierName'] = $xp($sx, [
                '//*[local-name()="Facturae"]'.$LN('Parties','SellerParty','LegalEntity','CorporateName'),
                '//*[local-name()="Facturae"]'.$LN('Parties','Sellers','Seller','LegalEntity','CorporateName'),
            ]);
            if ($out['supplierName'] === '') {
                $pf = trim(implode(' ', array_filter([
                    $xp($sx, ['//*[local-name()="Facturae"]'.$LN('Parties','SellerParty','Individual','Name')]),
                    $xp($sx, ['//*[local-name()="Facturae"]'.$LN('Parties','SellerParty','Individual','FirstSurname')]),
                    $xp($sx, ['//*[local-name()="Facturae"]'.$LN('Parties','SellerParty','Individual','SecondSurname')]),
                ])));
                if ($pf !== '') $out['supplierName'] = $pf;
            }

            $out['buyerNif'] = $xp($sx, [
                '//fe321:Facturae/fe321:Parties/fe321:BuyerParty/fe321:TaxIdentification/fe321:TaxIdentificationNumber',
                '//fe:Facturae/fe:Parties/fe:BuyerParty/fe:TaxIdentification/fe:TaxIdentificationNumber',
                '//*[local-name()="Facturae"]'.$LN('Parties','Buyers','Buyer','TaxIdentification','TaxIdentificationNumber'),
            ]);

            $exec = $xp($sx, [
                '//fe321:Facturae/fe321:Invoices/fe321:Invoice/fe321:InvoiceTotals/fe321:TotalExecutableAmount',
                '//fe:Facturae/fe:Invoices/fe:Invoice/fe:InvoiceTotals/fe:TotalExecutableAmount',
                '//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','InvoiceTotals','TotalExecutableAmount'),
            ]);
            $invTotal = $xp($sx, [
                '//fe321:Facturae/fe321:Invoices/fe321:Invoice/fe321:InvoiceTotals/fe321:InvoiceTotal',
                '//fe:Facturae/fe:Invoices/fe:Invoice/fe:InvoiceTotals/fe:InvoiceTotal',
                '//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','InvoiceTotals','InvoiceTotal'),
            ]);
            $reimb = $xp($sx, [
                '//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','InvoiceTotals','TotalReimbursableExpenses'),
            ]);
            if ($reimb !== '') $out['reimbursableAmount'] = (string)$toFloat($reimb);
            if ($exec !== '')  $out['totalAmount'] = (string)$toFloat($exec);
            elseif ($invTotal !== '') $out['totalAmount'] = (string)$toFloat($invTotal);
        } catch (\Throwable $e) { /* noop */ }

        return $out;
    }

    /** Construye la vista enriquecida reutilizando tu extractor original (con QR/VF). */
    private function viewFromXml(string $externalId, string $fileName, array $row, string $xml): array
    {
        $sx = @simplexml_load_string($xml);
        if ($sx === false) return ['success'=>false,'message'=>'XML inválido o no parseable.'];

        $ns = [
            'fe'    => 'http://www.facturae.es/Facturae/2009/v3.2/Facturae',
            'fe321' => 'http://www.facturae.es/Facturae/2014/v3.2.1/Facturae',
            'fe322' => 'http://www.facturae.es/Facturae/2014/v3.2.2/Facturae',
            'fe33'  => 'http://www.facturae.es/Facturae/2014/v3.3/Facturae',
        ];
        foreach ($ns as $p => $u) $sx->registerXPathNamespace($p, $u);

        $toFloat = static function($s): float {
            $s = trim((string)$s);
            if ($s === '') return 0.0;
            if (strpos($s, ',') !== false) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
            $s = preg_replace('/[^0-9\.\-]/', '', $s) ?? '0';
            return (float)$s;
        };
        $xp = static function(\SimpleXMLElement $node, array $paths): string {
            foreach ($paths as $p) { $n = @$node->xpath($p); if ($n && isset($n[0])) return trim((string)$n[0]); }
            return '';
        };
        $LN = static function(string ...$names): string {
            return implode('', array_map(fn($n) => "/*[local-name()='{$n}']", $names));
        };

        // Header
        $series = $xp($sx, [
            '//fe:Facturae/fe:Invoices/fe:Invoice/fe:InvoiceHeader/fe:InvoiceSeriesCode',
            '//fe321:Facturae/fe321:Invoices/fe321:Invoice/fe321:InvoiceHeader/fe321:InvoiceSeriesCode',
            '//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','InvoiceHeader','InvoiceSeriesCode'),
        ]);
        $number = $xp($sx, [
            '//fe:Facturae/fe:Invoices/fe:Invoice/fe:InvoiceHeader/fe:InvoiceNumber',
            '//fe321:Facturae/fe321:Invoices/fe321:Invoice/fe321:InvoiceHeader/fe321:InvoiceNumber',
            '//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','InvoiceHeader','InvoiceNumber'),
        ]);
        $issueDate = $xp($sx, [
            '//fe:Facturae/fe:Invoices/fe:Invoice/fe:InvoiceIssueData/fe:IssueDate',
            '//fe321:Facturae/fe321:Invoices/fe321:Invoice/fe321:InvoiceIssueData/fe321:IssueDate',
            '//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','InvoiceIssueData','IssueDate'),
        ]);
        $concept = $xp($sx, [
            '(//fe:Facturae//fe:InvoiceLine/fe:ItemDescription)[1]',
            '(//fe321:Facturae//fe321:InvoiceLine/fe321:ItemDescription)[1]',
            '(//*[local-name()="Facturae"]//*[local-name()="InvoiceLine"]/*[local-name()="ItemDescription"])[1]',
        ]);

        $header = [
            'id'        => $externalId,
            'series'    => $series,
            'number'    => $number,
            'issueDate' => $issueDate,
            'concept'   => $concept,
        ];

        // Parties
        // Detecta tipo de emisor (persona física vs jurídica)
        $sellerPT = $xp($sx, ['//*[local-name()="Facturae"]'.$LN('Parties','SellerParty','TaxIdentification','PersonTypeCode')]);
        $sellerIndName = $xp($sx, ['//*[local-name()="Facturae"]'.$LN('Parties','SellerParty','Individual','Name')]);
        $sellerIsIndividual = (strtoupper($sellerPT) === 'F') || ($sellerIndName !== '');
        $seller = [
            'nif'  => $xp($sx, ['//*[local-name()="Facturae"]'.$LN('Parties','SellerParty','TaxIdentification','TaxIdentificationNumber')]),
            'name' => $xp($sx, [
                '//*[local-name()="Facturae"]'.$LN('Parties','SellerParty','LegalEntity','CorporateName'),
                '(//*[local-name()="Facturae"]'.$LN('Parties','SellerParty','Individual','Name').' )[1]'
            ]),
            'addr' => $xp($sx, [
                '//*[local-name()="Facturae"]'.$LN('Parties','SellerParty','LegalEntity','AddressInSpain','Address'),
                '//*[local-name()="Facturae"]'.$LN('Parties','SellerParty','LegalEntity','OverseasAddress','Address'),
            ]),
            'pc'   => $xp($sx, [
                '//*[local-name()="Facturae"]'.$LN('Parties','SellerParty','LegalEntity','AddressInSpain','PostCode'),
            ]),
            'town' => $xp($sx, [
                '//*[local-name()="Facturae"]'.$LN('Parties','SellerParty','LegalEntity','AddressInSpain','Town'),
            ]),
            'prov' => $xp($sx, [
                '//*[local-name()="Facturae"]'.$LN('Parties','SellerParty','LegalEntity','AddressInSpain','Province'),
            ]),
            'cc'   => $xp($sx, [
                '//*[local-name()="Facturae"]'.$LN('Parties','SellerParty','LegalEntity','AddressInSpain','CountryCode'),
            ]),
            'personType'   => $sellerPT ?: null,
            'isIndividual' => $sellerIsIndividual,
        ];
        $buyer = [
            'nif'  => $xp($sx, ['//*[local-name()="Facturae"]'.$LN('Parties','BuyerParty','TaxIdentification','TaxIdentificationNumber')]),
            'name' => $xp($sx, [
                '//*[local-name()="Facturae"]'.$LN('Parties','BuyerParty','LegalEntity','CorporateName'),
                '(//*[local-name()="Facturae"]'.$LN('Parties','BuyerParty','Individual','Name').' )[1]'
            ]),
            'addr' => $xp($sx, [
                '//*[local-name()="Facturae"]'.$LN('Parties','BuyerParty','LegalEntity','AddressInSpain','Address'),
                '//*[local-name()="Facturae"]'.$LN('Parties','BuyerParty','LegalEntity','OverseasAddress','Address'),
            ]),
            'pc'   => $xp($sx, [
                '//*[local-name()="Facturae"]'.$LN('Parties','BuyerParty','LegalEntity','AddressInSpain','PostCode'),
            ]),
            'town' => $xp($sx, [
                '//*[local-name()="Facturae"]'.$LN('Parties','BuyerParty','LegalEntity','AddressInSpain','Town'),
            ]),
            'prov' => $xp($sx, [
                '//*[local-name()="Facturae"]'.$LN('Parties','BuyerParty','LegalEntity','AddressInSpain','Province'),
            ]),
            'cc'   => $xp($sx, [
                '//*[local-name()="Facturae"]'.$LN('Parties','BuyerParty','LegalEntity','AddressInSpain','CountryCode'),
            ]),
        ];

        // Lines
        $lineNodes = $sx->xpath('//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','Items','InvoiceLine')) ?: [];
        $lines = [];
        $withheldRates = [];
        $withheldSumLines = 0.0;
        foreach ($lineNodes as $ln) {
            $desc = $xp($ln, ['*[local-name()="ItemDescription"]']);
            $qty  = $toFloat($xp($ln, ['*[local-name()="Quantity"]']));
            $upwt = $toFloat($xp($ln, ['*[local-name()="UnitPriceWithoutTax"]']));
            $base = $toFloat($xp($ln, ['*[local-name()="TotalCost"]']));
            $vatR = $toFloat($xp($ln, ['*[local-name()="TaxesOutputs"]/*[local-name()="Tax"][1]/*[local-name()="TaxRate"]']));
            $taxA = $toFloat($xp($ln, ['*[local-name()="TaxesOutputs"]/*[local-name()="Tax"][1]/*[local-name()="TaxAmount"]/*[local-name()="TotalAmount"]']));
            $whR  = $toFloat($xp($ln, ['*[local-name()="TaxesWithheld"]/*[local-name()="Tax"][1]/*[local-name()="TaxRate"]']));
            $whA  = $toFloat($xp($ln, ['*[local-name()="TaxesWithheld"]/*[local-name()="Tax"][1]/*[local-name()="TaxAmount"]/*[local-name()="TotalAmount"]']));
            $total= $base + $taxA;
            $lines[] = [
                'description' => $desc,
                'quantity'    => $qty,
                'unitPrice'   => $upwt,
                'base'        => $base,
                'vatRate'     => $vatR,
                'total'       => $total,
            ];
            if ($whR > 0) $withheldRates[] = $whR;
            if ($whA > 0) $withheldSumLines += $whA;
        }

        // Totals
        $baseBefore = $toFloat($xp($sx, ['//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','InvoiceTotals','TotalGrossAmountBeforeTaxes')]));
        $taxOut     = $toFloat($xp($sx, ['//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','InvoiceTotals','TotalTaxOutputs')]));
        $taxWith    = $toFloat($xp($sx, ['//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','InvoiceTotals','TotalTaxesWithheld')]));
        // Fallback: si no viene el total de retenciones, súmalas por líneas
        if ($taxWith <= 0.0 && $withheldSumLines > 0.0) {
            $taxWith = $withheldSumLines;
        }
        $reimb      = $toFloat($xp($sx, ['//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','InvoiceTotals','TotalReimbursableExpenses')]));
        $exec       = $toFloat($xp($sx, ['//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','InvoiceTotals','TotalExecutableAmount')]));
        $invTotal   = $toFloat($xp($sx, ['//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','InvoiceTotals','InvoiceTotal')]));
        $total      = $exec ?: ($invTotal ?: ($baseBefore + $taxOut - $taxWith));

        // Señaliza si hay IRPF (por total o por presencia de nodos de retención)
        $hasWithheldNodes = $sx->xpath('//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','Items','InvoiceLine').'//*[local-name()="TaxesWithheld"]/*[local-name()="Tax"]');
        // IRPF %: usa tasa de línea si existe y es consistente; si no, deduce de totales
        $irpfRate = 0.0;
        if (!empty($withheldRates)) {
            // Si hay varias tasas distintas, toma la máxima (suele ser uniforme en autónomos)
            $irpfRate = max($withheldRates);
        } elseif ($taxWith > 0.0 && ($baseBefore ?: 0.0) > 0.0) {
            $irpfRate = round(($taxWith / ($baseBefore ?: 1.0)) * 100.0, 2);
        }
        $totals = [
            'base'  => $baseBefore ?: array_sum(array_column($lines, 'base')),
            'vat'   => $taxOut,
            'irpf'  => $taxWith ? -$taxWith : 0.0,
            'reimb' => $reimb,
            'total' => $total,
            'hasIrpf' => ($taxWith > 0) || (is_array($hasWithheldNodes) && count($hasWithheldNodes) > 0),
            'irpfRate' => $irpfRate > 0 ? $irpfRate : null,
        ];

        $fileRel = 'data/received/' . $fileName;

        // Extra: intenta extraer VF hash y QR de InvoiceAdditionalInformation
        $vfHash = '';
        $qrTxt  = '';
        $qrPngB64 = null;
        $infoNodes = $sx->xpath('//*[local-name()="Facturae"]'.$LN('Invoices','Invoice','AdditionalData','InvoiceAdditionalInformation')) ?: [];
        if ($infoNodes && isset($infoNodes[0])) {
            $info = trim((string)$infoNodes[0]);
            if ($info !== '') {
                if (preg_match('/Hash:\s*([0-9A-Fa-f]{32,64})/u', $info, $m)) $vfHash = strtoupper($m[1]);
                if (preg_match('/QR:\s*([^\r\n]+)/u', $info, $m)) {
                    $qrTxt = trim($m[1]);
                    // Asegura que &amp; del XML se convierte a & para generar QR correcto
                    $qrTxt = html_entity_decode($qrTxt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }
        // Genera QR PNG si tenemos texto
        if ($qrTxt !== '') {
            try {
                $opts = new \chillerlan\QRCode\QROptions([
                    'eccLevel'   => \chillerlan\QRCode\QRCode::ECC_M,
                    'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
                    'scale'      => 4,
                ]);
                $png = (new \chillerlan\QRCode\QRCode($opts))->render($qrTxt);
                if (is_string($png) && $png !== '') {
                    if (preg_match('~^data:image/[^;]+;base64,~i', $png)) {
                        $qrPngB64 = substr($png, strpos($png, ',') + 1) ?: null;
                    } else {
                        $qrPngB64 = base64_encode($png);
                    }
                }
            } catch (\Throwable $e) { /* noop */ }
        }

        // Meta combinado: incluye estado/fechas del índice si existen
        $metaCombined = [
            'id' => $externalId,
            'vfHash' => $vfHash ?: null,
            'qrString' => $qrTxt ?: null,
            'qrPngB64' => $qrPngB64 ?: null,
        ];
        if (!empty($row)) {
            foreach (['status','acceptedAt','rejectedAt','rejectionReason','paymentDate'] as $k) {
                if (isset($row[$k]) && $row[$k] !== '') $metaCombined[$k] = $row[$k];
            }
        }

        return [
            'success' => true,
            'header'  => $header,
            'seller'  => $seller,
            'buyer'   => $buyer,
            'lines'   => $lines,
            'totals'  => $totals,
            'meta'    => $metaCombined,
            'fileRel' => $fileRel,
        ];
    }

    /**
     * Actualiza estado de pago en una emitida local emparejando por código/serie-número.
     */
    private function upsertFaceb2bPaymentInInvoice(string $registry, array $meta): void
    {
        $registry = trim((string)$registry);
        $series   = (string)($meta['series'] ?? '');
        $number   = (string)($meta['number'] ?? '');
        $state    = (string)($meta['state']  ?? '');

        $im = new \InvoiceManager();
        $invoices = $im->getAllInvoices();
        $target = null;

        if ($registry !== '') {
            foreach ($invoices as $inv) {
                $reg = trim((string)(
                    $inv->faceb2b->registrationCode
                    ?? $inv->faceb2bCode
                    ?? ''
                ));
                if ($reg !== '' && $reg === $registry) { $target = $inv; break; }
            }
        }
        if (!$target && $series !== '' && $number !== '') {
            foreach ($invoices as $inv) {
                $id = (string)$inv->id;
                $parts = explode('-', $id);
                $s = ($parts[0] ?? 'FAC') . ($parts[1] ?? '');
                $n = $parts[2] ?? '';
                if ($s === $series && $n === $number) { $target = $inv; break; }
            }
        }
        if (!$target) return;

        if (!isset($target->faceb2b)) $target->addChild('faceb2b');
        if ($registry !== '' && (string)($target->faceb2b->registrationCode ?? '') === '') {
            $target->faceb2b->registrationCode = $registry;
        }
        if ($state !== '') {
            $target->faceb2b->paymentStatus = $state;
            $target->faceb2b->lastSync = date('c');
            // Usa fechas proporcionadas si vienen; si no, establece por defecto
            if ($state === 'Pagada') {
                $pd = (string)($meta['paymentDate'] ?? '');
                $target->faceb2b->paymentDate = $pd !== '' ? $pd : ((string)($target->faceb2b->paymentDate ?? '') ?: date('Y-m-d'));
            }
            if ($state === 'Rechazada') {
                $rd = (string)($meta['rejectedAt'] ?? '');
                $target->faceb2b->rejectedAt = $rd !== '' ? $rd : ((string)($target->faceb2b->rejectedAt ?? '') ?: date('c'));
            }
            if ($state === 'Pendiente de pago') {
                $ad = (string)($meta['acceptedAt'] ?? '');
                $target->faceb2b->acceptedAt = $ad !== '' ? $ad : ((string)($target->faceb2b->acceptedAt ?? '') ?: date('c'));
            }
            if ($state === 'Anulada') {
                $cd = (string)($meta['canceledAt'] ?? '');
                $target->faceb2b->canceledAt = $cd !== '' ? $cd : ((string)($target->faceb2b->canceledAt ?? '') ?: date('c'));
            }
        }

        $before = [
            'status'      => (string)($target->faceb2b->paymentStatus ?? ''),
            'paymentDate' => (string)($target->faceb2b->paymentDate ?? ''),
            'acceptedAt'  => (string)($target->faceb2b->acceptedAt ?? ''),
            'rejectedAt'  => (string)($target->faceb2b->rejectedAt ?? ''),
            'canceledAt'  => (string)($target->faceb2b->canceledAt ?? ''),
        ];
        $this->saveInvoiceSimpleXml($target);
        // Log de aplicación de estado en emitidas
        try {
            $logDir = __DIR__ . '/../data/logs';
            if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
            $after = [
                'status'      => (string)($target->faceb2b->paymentStatus ?? ''),
                'paymentDate' => (string)($target->faceb2b->paymentDate ?? ''),
                'acceptedAt'  => (string)($target->faceb2b->acceptedAt ?? ''),
                'rejectedAt'  => (string)($target->faceb2b->rejectedAt ?? ''),
                'canceledAt'  => (string)($target->faceb2b->canceledAt ?? ''),
            ];
            $entry = [
                'id'       => (string)$target->id,
                'registry' => $registry,
                'before'   => $before,
                'after'    => $after,
            ];
            @file_put_contents($logDir . '/faceb2b_status_apply.log', '['.date('c').'] '.json_encode($entry, JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR)."\n", FILE_APPEND);
        } catch (\Throwable $e) { /* noop */ }
    }

    private function saveInvoiceSimpleXml(\SimpleXMLElement $inv): void
    {
        // Persistir usando la ruta real de la factura (respeta subcarpeta por año)
        try {
            if (!class_exists('InvoiceManager') && file_exists(__DIR__ . '/InvoiceManager.php')) {
                require_once __DIR__ . '/InvoiceManager.php';
            }
            if (class_exists('InvoiceManager')) {
                $im = new \InvoiceManager();
                $im->saveInvoiceObject($inv);
                return;
            }
        } catch (\Throwable $e) {
            // Fallback de emergencia (mismo algoritmo que InvoiceManager):
            $id = trim((string)$inv->id);
            if ($id === '') return;
            $dirBase = realpath(__DIR__ . '/../data/invoices') ?: (__DIR__ . '/../data/invoices');
            @mkdir($dirBase, 0775, true);
            $year = 'misc';
            if (preg_match('/-(\d{4})-\d{1,6}$/', $id, $m)) $year = $m[1];
            $dir = rtrim($dirBase,'/') . '/' . $year;
            @mkdir($dir, 0775, true);
            $path = $dir . '/' . basename($id) . '.xml';
            $xml = $inv->asXML();
            if ($xml !== false) @file_put_contents($path, $xml);
        }
    }
}
