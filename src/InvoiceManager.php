<?php
// src/InvoiceManager.php
declare(strict_types=1);

final class InvoiceManager {

    /** @var string */
    private string $invoicesDir;
    /** @var string */
    private string $baseDataDir;
    /** @var string */
    private string $facturaeExportsDir;

    public function __construct() {
        $base = realpath(__DIR__ . '/../data') ?: (__DIR__ . '/../data');
        $this->baseDataDir = rtrim($base, '/');
        $this->invoicesDir = $this->baseDataDir . '/invoices/';
        $this->facturaeExportsDir = $this->baseDataDir . '/facturae_exports/';
        $this->ensureDir($this->invoicesDir);
        // subcarpetas que solemos usar
        $this->ensureDir($this->facturaeExportsDir);
        $this->ensureDir($this->baseDataDir . '/logs');
    }

    public function getFacturaeExportsDir(): string {
        return $this->facturaeExportsDir;
    }

    // --------------------------------------------------
    // Crear y rectificar
    // --------------------------------------------------

    /**
     * Crea una factura en XML (modelo interno) bajo data/invoices/YYYY/SERIE-YYYY-NNNN.xml
     * Devuelve ['success'=>bool, 'invoiceId'=>..., 'path'=>...]
     */
    public function createInvoice(array $data) : array {
        // Fecha de emisión SIEMPRE la fija el sistema
        $issueDate = date('Y-m-d');
        $year      = substr($issueDate, 0, 4);
        $yearDir   = $this->invoicesDir . $year . '/';
        $this->ensureDir($yearDir);

        // Vencimiento (solo desde $data; sin tocar $_POST)
        $dueOption = (string)($data['due_option'] ?? 'on_receipt');
        $rawDue    = isset($data['due_date']) ? (string)$data['due_date'] : '';

        $issueTs = strtotime($issueDate) ?: time();
        $minYmd  = date('Y-m-d', $issueTs);
        $maxYmd  = date('Y-m-d', strtotime('+60 days', $issueTs));

        $dueType = 'on_receipt';
        $dueDate = null;

        switch ($dueOption) {
            case 'on_receipt':
                $dueType = 'on_receipt';
                break;
            case 'plus60':
                $dueType = 'plus60';
                $dueDate = $maxYmd;
                break;
            case 'custom':
                $dueType = 'custom';
                if ($rawDue === '') throw new \RuntimeException('Debes indicar la fecha de vencimiento.');
                if ($rawDue < $minYmd || $rawDue > $maxYmd) {
                    throw new \RuntimeException('La fecha de vencimiento debe estar dentro de los 60 días desde la emisión.');
                }
                $dueDate = $rawDue;
                break;
            default:
                throw new \RuntimeException('Opción de vencimiento no válida.');
        }

        // Serie e ID
        $series = strtoupper(trim((string)($data['series'] ?? '')));
        if ($series === '') {
            $cfgPath = __DIR__ . '/../data/config.json';
            $series  = 'FAC';
            if (is_file($cfgPath) && function_exists('generate_default_series')) {
                $cfg    = json_decode((string)@file_get_contents($cfgPath), true) ?: [];
                $issuer = $cfg['issuer'] ?? $cfg;
                $series = generate_default_series($issuer) ?: 'FAC';
            }
        }

        $nextNum   = $this->getNextInvoiceNumber((int)$year, $series);
        $invoiceId = sprintf('%s-%s-%04d', $series, $year, $nextNum);

        // Cliente (por id/nif o datos embebidos)
        $clientObj = null;
        $clientId  = trim((string)($data['clientId'] ?? $data['client'] ?? ''));
        if ($clientId !== '') {
            try {
                $dm = new DataManager('clients');
                $clientObj = $dm->getItemById($clientId);
                if (!$clientObj && !empty($data['clientNif'])) {
                    foreach ($dm->getAllItems() as $c) {
                        if (strcasecmp(trim((string)($c->nif ?? '')), (string)$data['clientNif']) === 0) {
                            $clientObj = $c; $clientId = (string)($c->id ?? ''); break;
                        }
                    }
                }
            } catch (\Throwable $e) { $clientObj = null; }
        }
        if (!$clientObj && !empty($data['clientData']) && is_array($data['clientData'])) {
            $clientObj = (object)$data['clientData'];
        }
        if (!$clientObj) {
            return ['success'=>false, 'message'=>'No se ha seleccionado ningún cliente.'];
        }

        // XML de la factura (modelo interno)
        $xml = new \SimpleXMLElement('<invoice/>');
        $xml->addChild('id',           $invoiceId);
        $xml->addChild('series',       $series);
        $xml->addChild('issueDate',    $issueDate);

        $pt = $xml->addChild('paymentTerms');
        $pt->addChild('dueType', htmlspecialchars($dueType));
        $pt->addChild('dueDate', $dueDate ? htmlspecialchars($dueDate) : '');

        $xml->addChild('concept', htmlspecialchars(trim((string)($data['concept'] ?? ''))));

        // Referencias FACe opcionales
        if (!empty($data['fileReference']))             $xml->addChild('fileReference', htmlspecialchars((string)$data['fileReference']));
        if (!empty($data['receiverContractReference'])) $xml->addChild('receiverContractReference', htmlspecialchars((string)$data['receiverContractReference']));

        // Emisor (extra)
        try {
            $cfgPath = __DIR__ . '/../data/config.json';
            if (is_file($cfgPath)) {
                $cfg    = json_decode((string)@file_get_contents($cfgPath), true) ?: [];
                $issuer = $cfg['issuer'] ?? $cfg;
                $issuerNode = $xml->addChild('issuer');
                if (!empty($issuer['email'])) $issuerNode->addChild('email', htmlspecialchars((string)$issuer['email']));
                if (!empty($issuer['iban']))  $issuerNode->addChild('iban',  htmlspecialchars(preg_replace('/\s+/', '', (string)$issuer['iban'])));
            }
        } catch (\Throwable $e) { /* no bloqueante */ }

        // Cliente
        $cli = $xml->addChild('client');
        $cli->addChild('id',         htmlspecialchars((string)$clientId));
        $cli->addChild('nif',        htmlspecialchars((string)($clientObj->nif ?? '')));
        $cli->addChild('name',       htmlspecialchars((string)($clientObj->name ?? '')));
        $cli->addChild('address',    htmlspecialchars((string)($clientObj->address ?? '')));
        $cli->addChild('postCode',   htmlspecialchars((string)($clientObj->postCode ?? '')));
        $cli->addChild('town',       htmlspecialchars((string)($clientObj->town ?? '')));
        $cli->addChild('province',   htmlspecialchars((string)($clientObj->province ?? '')));
        $cli->addChild('countryCode',htmlspecialchars((string)($clientObj->countryCode ?? 'ESP')));
        if (isset($clientObj->entityType)) $cli->addChild('entityType', htmlspecialchars((string)$clientObj->entityType));

        // Líneas y totales
        $itemsNode = $xml->addChild('items');
        $totalBase = 0.0; $totalVat = 0.0;
        if (!empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $it) {
                $qty   = (float)($it['qty']   ?? $it['quantity']  ?? 0);
                $price = (float)($it['price'] ?? $it['unitPrice'] ?? 0);
                $vat   = (float)($it['vat']   ?? $it['vatRate']   ?? 21);

                $lineBase = $qty * $price;
                $lineVat  = $lineBase * ($vat / 100);

                $itemNode = $itemsNode->addChild('item');
                $itemNode->addChild('description',    htmlspecialchars((string)($it['desc'] ?? $it['description'] ?? '')));
                $itemNode->addChild('quantity',       self::sx($qty));
                $itemNode->addChild('unitPrice',      self::sx($price));
                $itemNode->addChild('lineBaseTotal',  self::sx($lineBase));
                $itemNode->addChild('vatRate',        self::sx($vat));
                $itemNode->addChild('lineTotal',      self::sx($lineBase + $lineVat));

                $totalBase += $lineBase;
                $totalVat  += $lineVat;
            }
        }

        // Suplidos
        $totalSuplidos = 0.0;
        if (!empty($data['suplidos']) && is_array($data['suplidos'])) {
            $supNode = $xml->addChild('suplidos');
            foreach ($data['suplidos'] as $s) {
                $amount = self::moneyToFloatEU($s['amount'] ?? $s['value'] ?? 0);
                // Acepta tanto positivos (factura normal) como negativos (rectificativa)
                if ($amount == 0.0) continue;
                $desc = trim((string)($s['description'] ?? $s['desc'] ?? 'Suplido')) ?: 'Suplido';
                $sx   = $supNode->addChild('suplido');
                $sx->addChild('description', htmlspecialchars($desc));
                $sx->addChild('amount',      number_format($amount, 2, '.', ''));
                $totalSuplidos += $amount;
            }
            $totalSuplidos = round($totalSuplidos, 2);
        }

        // IRPF desde config si procede
        $irpfRate = isset($data['irpfRate']) ? (float)$data['irpfRate'] : -1.0;
        if ($irpfRate === -1.0) {
            $cfgPath = __DIR__ . '/../data/config.json';
            if (is_file($cfgPath)) {
                $cfg    = json_decode((string)@file_get_contents($cfgPath), true) ?: [];
                $issuer = $cfg['issuer'] ?? $cfg;
                $irpfRate = ((($issuer['entityType'] ?? 'company') === 'freelancer') ? (float)($issuer['irpfRate'] ?? 0.0) : 0.0);
            } else {
                $irpfRate = 0.0;
            }
        }

        $totalIrpfAmount = $irpfRate != 0 ? ($totalBase * ($irpfRate / 100.0)) : 0.0;

        // Totales
        $xml->addChild('totalBase',       self::sx($totalBase));
        $xml->addChild('totalVatAmount',  self::sx($totalVat));
        $xml->addChild('irpfRate',        self::sx($irpfRate));
        $xml->addChild('totalIrpfAmount', self::sx($totalIrpfAmount));
        if ($totalSuplidos > 0) $xml->addChild('totalSuplidos', self::sx($totalSuplidos));
        $xml->addChild('totalAmount',     self::sx($totalBase + $totalVat - $totalIrpfAmount + $totalSuplidos));

        if (!empty($data['isRectificative'])) $xml->addChild('isRectificative', 'true');
        if (!empty($data['rectifies']))       $xml->addChild('rectifies', (string)$data['rectifies']);
        if (!empty($data['motive']))          $xml->addChild('rectificationReason', htmlspecialchars((string)$data['motive']));
        $xml->addChild('isCancelled', 'false');
        $xml->addChild('creationTimestamp', date('c'));

        $filePath = $yearDir . $invoiceId . '.xml';
        $xml->asXML($filePath);

        // Si es rectificativa, marca original
        if (!empty($data['isRectificative']) && !empty($data['rectifies'])) {
            try {
                $origId = (string)$data['rectifies'];
                $orig   = $this->getInvoiceById($origId);
                if ($orig) {
                    $yearOrig = date('Y', strtotime((string)$orig->issueDate));
                    $origPath = $this->invoicesDir . $yearOrig . '/' . $origId . '.xml';
                    if (is_file($origPath) && is_writable($origPath)) {
                        $ox = @simplexml_load_file($origPath);
                        if ($ox) {
                            $ox->isCancelled = 'true';
                            if (!isset($ox->rectificativeId) || (string)$ox->rectificativeId !== $invoiceId) {
                                $ox->addChild('rectificativeId', $invoiceId);
                            }
                            $ox->asXML($origPath);
                        }
                    }
                }
            } catch (\Throwable $e) {}
        }

        // VeriFactu: no bloqueante
        try { if (class_exists('VeriFactu')) (new \VeriFactu())->appendLogRecord($invoiceId); } catch (\Throwable $e) {}

        $this->log('create_invoice', ['id'=>$invoiceId, 'path'=>$filePath]);
        return ['success'=>true, 'invoiceId'=>$invoiceId, 'path'=>$filePath];
    }

    /**
     * Crea una rectificativa negativa de la original.
     */
    public function createRectificative(string $originalInvoiceId, string $reason = '', string $series = 'R') : array {
        $original = $this->getInvoiceById($originalInvoiceId);
        if (!$original) return ['success'=>false, 'message'=>'Factura original no encontrada.'];

        $items = [];
        if (isset($original->items->item)) {
            foreach ($original->items->item as $it) {
                $items[] = [
                    'desc'  => 'Anulación de: ' . (string)$it->description,
                    'qty'   => -1 * abs((float)$it->quantity),
                    'price' => (float)$it->unitPrice,
                    'vat'   => (float)$it->vatRate,
                ];
            }
        }

        // Suplidos de la original (si existen) → anular en negativo
        $suplidos = [];
        if (isset($original->suplidos->suplido)) {
            foreach ($original->suplidos->suplido as $s) {
                $desc = trim((string)($s->description ?? 'Suplido')) ?: 'Suplido';
                $amt  = (float)($s->amount ?? 0);
                if ($amt <= 0) continue;
                $suplidos[] = [
                    'desc'   => 'Anulación de: ' . $desc,
                    'amount' => -1 * abs($amt),
                ];
            }
        }

        $data = [
            'clientId'        => (string)$original->client->id,
            'series'          => $series,
            'concept'         => 'Rectificación de factura ' . $originalInvoiceId . ($reason ? '. Motivo: '.$reason : ''),
            'items'           => $items,
            'suplidos'        => $suplidos,
            'irpfRate'        => (float)($original->irpfRate ?? 0),
            'isRectificative' => true,
            'rectifies'       => $originalInvoiceId,
            'motive'          => $reason,
        ];
        return $this->createInvoice($data);
    }

    // --------------------------------------------------
    // Lectura y listado
    // --------------------------------------------------

    /** Carga la factura (SimpleXMLElement) por ID. */
    public function getInvoiceById(string $invoiceId) : ?\SimpleXMLElement {
        $path = $this->getInvoicePathFromId($invoiceId);
        if (!is_file($path)) {
            $hits = glob(rtrim($this->invoicesDir, '/').'/*/'.$invoiceId.'.xml') ?: [];
            if ($hits && is_file($hits[0])) $path = $hits[0]; else return null;
        }
        return @simplexml_load_file($path) ?: null;
    }

    /** Devuelve paths de todas las facturas (opcional por año). */
    public function listInvoices(?int $year = null) : array {
        $out = [];
        if ($year === null) {
            foreach (glob($this->invoicesDir . '*/', GLOB_ONLYDIR) ?: [] as $dir) {
                foreach (glob($dir.'*.xml') ?: [] as $f) $out[] = $f;
            }
        } else {
            $dir = $this->invoicesDir . $year . '/';
            if (is_dir($dir)) foreach (glob($dir.'*.xml') ?: [] as $f) $out[] = $f;
        }
        sort($out);
        return $out;
    }

    /**
     * Devuelve un array de SimpleXMLElement de todas las facturas, ordenadas
     * para dashboard/lista.
     */
    public function getAllInvoices(?int $year = null) : array {
        $paths = $this->listInvoices($year);
        $invoices = [];
        foreach ($paths as $p) {
            $xml = @simplexml_load_file($p);
            if ($xml) $invoices[] = $xml;
        }
        usort($invoices, function($a, $b) {
            $da = strtotime((string)($a->issueDate ?? '1970-01-01'));
            $db = strtotime((string)($b->issueDate ?? '1970-01-01'));
            if ($da === $db) return strcmp((string)$b->id, (string)$a->id);
            return $db <=> $da;
        });
        return $invoices;
    }

    // --------------------------------------------------
    // Estados / marcas persistidas en el XML
    // --------------------------------------------------

    public function setFaceCode(string $invoiceId, string $registerNumber): bool {
        $inv = $this->getInvoiceById($invoiceId);
        if (!$inv) return false;

        if (!isset($inv->face)) $inv->addChild('face');
        $inv->face->registerNumber = $registerNumber;
        $inv->face->status = 'sent';
        $inv->face->lastUpdate = date('c');

        return $this->saveInvoiceObject($inv);
    }

    /** Actualiza estado de FACE a partir de respuesta del API /v1/invoices/{registryCode} */
    public function setFaceStatusFromApi(string $invoiceId, array $api): bool {
        $inv = $this->getInvoiceById($invoiceId);
        if (!$inv) return false;
        if (!isset($inv->face)) $inv->addChild('face');

        $face = $inv->face;
        // status actual: intenta último del historial; si no, usa 'status'
        $statusCode = '';
        $statusName = '';
        if (isset($api['statusHistory']) && is_array($api['statusHistory']) && count($api['statusHistory']) > 0) {
            $last = end($api['statusHistory']);
            if (is_array($last)) {
                $statusCode = (string)($last['code'] ?? '');
                $statusName = (string)($last['name'] ?? '');
            }
        }
        if ($statusName === '' && isset($api['status'])) {
            $statusName = (string)$api['status'];
        }

        // Normaliza etiqueta para UI
        $nameLower = mb_strtolower($statusName, 'UTF-8');
        $ui = 'Pendiente de aceptación';
        if ($statusCode === '2500' || str_contains($nameLower, 'pagada')) {
            $ui = 'Pagada';
        } elseif ($statusCode === '2600' || str_contains($nameLower, 'rechaz')) {
            $ui = 'Rechazada';
        } elseif ($statusCode === '3100' || str_contains($nameLower, 'anulad')) {
            $ui = 'Anulada';
        } elseif ($statusCode === '2400' || str_contains($nameLower, 'trámite') || str_contains($nameLower, 'contabiliz')) {
            $ui = 'Pendiente de pago';
        } elseif ($statusCode === '1200' || $statusCode === '1300' || str_contains($nameLower, 'registr')) {
            $ui = 'Pendiente de aceptación';
        }

        // Persistir campos clave
        $face->statusCode  = $statusCode;
        $face->statusName  = $statusName;
        $face->statusText  = $ui;
        if (isset($api['registryCode'])) $face->registerNumber = (string)$api['registryCode'];
        if (isset($api['registryDate'])) $face->registryDate   = (string)$api['registryDate'];
        $face->lastUpdate  = date('c');

        // (opcional) historial resumido (codes)
        if (isset($api['statusHistory']) && is_array($api['statusHistory'])) {
            // Borra historial previo para evitar crecimiento infinito
            if (isset($face->statusHistory)) unset($face->statusHistory);
            $hist = $face->addChild('statusHistory');
            foreach ($api['statusHistory'] as $h) {
                if (!is_array($h)) continue;
                $e = $hist->addChild('entry');
                if (isset($h['code'])) $e->addChild('code', htmlspecialchars((string)$h['code']));
                if (isset($h['name'])) $e->addChild('name', htmlspecialchars((string)$h['name']));
            }
        }

        return $this->saveInvoiceObject($inv);
    }

    public function setFaceb2bCode(string $invoiceId, string $code): bool {
        $path = $this->getInvoicePathFromId($invoiceId);
        if (!is_file($path) || !is_writable($path)) return false;

        $x = @simplexml_load_file($path);
        if (!$x) return false;

        if (!isset($x->faceb2b)) $x->addChild('faceb2b');
        $x->faceb2b->registrationCode = $code;

        $dom = new \DOMDocument('1.0','UTF-8');
        $dom->preserveWhiteSpace=false; $dom->formatOutput=true;
        $dom->loadXML($x->asXML());
        return (bool)$dom->save($path);
    }

    public function setAeatStatus(string $invoiceId, string $status, ?string $message = null, ?string $receiptId = null): bool {
        $path = $this->getInvoicePathFromId($invoiceId);
        if (!is_file($path) || !is_writable($path)) return false;

        $x = @simplexml_load_file($path);
        if (!$x) return false;

        if (!isset($x->aeat))             $x->addChild('aeat');
        if (!isset($x->aeat->status))     $x->aeat->addChild('status','');
        if (!isset($x->aeat->lastAttempt))$x->aeat->addChild('lastAttempt','');
        if (!isset($x->aeat->lastMessage))$x->aeat->addChild('lastMessage','');
        if (!isset($x->aeat->receipt))    $x->aeat->addChild('receipt','');

        $x->aeat->status      = $status;
        $x->aeat->lastAttempt = date('c');
        $x->aeat->lastMessage = $message ?? '';
        $x->aeat->receipt     = $receiptId ?? '';

        $dom = new \DOMDocument('1.0','UTF-8');
        $dom->preserveWhiteSpace=false; $dom->formatOutput=true;
        $dom->loadXML($x->asXML());
        return (bool)$dom->save($path);
    }

    /** Inserta/verifica <verifactu><hash>... en el XML. */
    public function embedVerifactuHash(string $invoiceId, string $hash): bool {
        $path = $this->getInvoicePathFromId($invoiceId);
        if (!is_file($path) || !is_writable($path)) return false;

        $x = @simplexml_load_file($path);
        if (!$x) return false;

        if (!isset($x->verifactu)) $x->addChild('verifactu');
        if (isset($x->verifactu->hash)) $x->verifactu->hash = $hash; else $x->verifactu->addChild('hash', $hash);

        $dom = new \DOMDocument('1.0','UTF-8');
        $dom->preserveWhiteSpace=false; $dom->formatOutput=true;
        $dom->loadXML($x->asXML());
        return (bool)$dom->save($path);
    }

    /** Inserta hash + QR base64 */
    public function embedVerifactuData(string $invoiceId, string $hash, ?string $qrCodeBase64): bool {
        $path = $this->getInvoicePathFromId($invoiceId);
        if (!is_file($path) || !is_writable($path)) return false;

        $xml = @simplexml_load_file($path);
        if (!$xml) return false;

        if (!isset($xml->verifactu)) $xml->addChild('verifactu');
        $xml->verifactu->hash = $hash;
        if ($qrCodeBase64) {
            if (isset($xml->qrCode)) $xml->qrCode = $qrCodeBase64;
            else $xml->addChild('qrCode', $qrCodeBase64);
        }

        $dom = new \DOMDocument('1.0','UTF-8');
        $dom->preserveWhiteSpace=false; $dom->formatOutput=true;
        $dom->loadXML($xml->asXML());
        return (bool)$dom->save($path);
    }

    // --------------------------------------------------
    // Facturae (exportación)
    // --------------------------------------------------

    /** Devuelve la ruta del Facturae firmado previamente o null si no existe. */
    public function generateFacturae(string $invoiceId): ?string {
        $info = $this->getSignedFacturaeInfo($invoiceId);
        return $info['absolutePath'] ?? null;
    }

    /**
     * Devuelve la ruta al Facturae firmado listo para FACeB2B.
     * Lanza excepción si la factura aún no ha sido firmada localmente.
     */
    public function generateFacturaeFreshForFaceB2B(string $invoiceId, string $dire): string {
        $info = $this->getSignedFacturaeInfo($invoiceId);
        if (!$info) {
            throw new \RuntimeException('La factura aún no está firmada. Usa AutoFirma antes de enviarla.');
        }
        return $info['absolutePath'];
    }

    /** alias para compatibilidad con index */
    public function generateFreshForFaceB2B(string $invoiceId, string $dire): string {
        return $this->generateFacturaeFreshForFaceB2B($invoiceId, $dire);
    }
    /** hash y qr verifactu */
    public function embedVerifactuMeta(string $invoiceId, array $meta): void {
        $xml = $this->getInvoiceById($invoiceId);
        if (!$xml) return;

        if (!isset($xml->verifactu)) $xml->addChild('verifactu');
        $vf = $xml->verifactu;

        $set = function($node, $name, $val){
            if ($val === null || $val === '') return;
            if (isset($node->{$name})) unset($node->{$name});
            $node->addChild($name, htmlspecialchars((string)$val));
        };

        $set($vf, 'hash',        $meta['hash']        ?? null);
        $set($vf, 'qrText',      $meta['qrUrl']       ?? null);
        $set($vf, 'qrCode',      $meta['qrCodeB64']   ?? null); // base64 PNG
        $set($vf, 'qrImagePath', $meta['qrImagePath'] ?? null); // ruta relativa (si la hay)

        // Persistir en disco usando el guardado interno existente
        $this->saveInvoiceObject($xml);
    }



    // --------------------------------------------------
    // Utilidades privadas
    // --------------------------------------------------

    private function getInvoicePathFromId(string $invoiceId) : string {
        if (preg_match('/-(\d{4})-\d{1,6}$/', $invoiceId, $m)) {
            $year = $m[1];
            return rtrim($this->invoicesDir, '/') . '/' . $year . '/' . $invoiceId . '.xml';
        }
        $hits = glob(rtrim($this->invoicesDir, '/') . '/*/' . $invoiceId . '.xml') ?: [];
        return $hits ? $hits[0] : rtrim($this->invoicesDir, '/') . '/' . $invoiceId . '.xml';
    }

    /** Guarda el objeto SimpleXMLElement en su fichero */
    public function saveInvoiceObject(\SimpleXMLElement $invoice): bool {
        $id = (string)$invoice->id;
        $path = $this->getInvoicePathFromId($id);
        if ($path === '') return false;
        $xml = $invoice->asXML();
        return $xml !== false && @file_put_contents($path, $xml) !== false;
    }

    /** Siguiente número dentro de la serie-año (NNNN) */
    public function getNextInvoiceNumber(int $year, string $series = 'FAC') : int {
        $pattern = $this->invoicesDir . $year . '/' . $series . '-' . $year . '-*.xml';
        $files   = glob($pattern) ?: [];
        $max     = 0;
        foreach ($files as $f) {
            if (preg_match('/^' . preg_quote($series, '/') . '-' . $year . '-(\d{4})\.xml$/', basename($f), $m)) {
                $n = (int)$m[1]; if ($n > $max) $max = $n;
            }
        }
        return $max + 1;
    }

    /** Mapea tu XML interno a la estructura esperada por FacturaeGenerator/SignatureManager (sin credenciales). */
    private function mapInvoiceToFacturaeInput(\SimpleXMLElement $inv, ?string $receivingDire = null): array {
        // ---- Serie/número a partir de ID SERIE-YYYY-NNNN → SERIEYYYY / NNNN
        $rawId  = (string)($inv->id ?? '');
        $series = 'FAC' . date('Y');
        $number = '0001';
        if (preg_match('/^(.+)-(\d{4})-(\d{1,6})$/', $rawId, $m)) {
            $series = $m[1] . $m[2];
            $number = str_pad($m[3], 4, '0', STR_PAD_LEFT);
        }

        // ---- Emisor desde config.json (completo + certificado)
        $cfgPath = __DIR__ . '/../data/config.json';
        $cfgFull = is_file($cfgPath) ? (array)json_decode((string)@file_get_contents($cfgPath), true) : [];
        $issuer  = (array)($cfgFull['issuer'] ?? $cfgFull);

        $sellerIsFreelancer = (strtolower((string)($issuer['entityType'] ?? 'company')) === 'freelancer');
        $seller = [
            'isLegalEntity' => !$sellerIsFreelancer ? true : false,
            'taxNumber'     => strtoupper(preg_replace('/[\s-]+/','', (string)($issuer['nif'] ?? ''))),
            'name'          => $sellerIsFreelancer
                                ? trim(((string)($issuer['firstName'] ?? '')).' '.((string)($issuer['lastName'] ?? '')).' '.((string)($issuer['secondSurname'] ?? '')))
                                : (string)($issuer['companyName'] ?? $issuer['name'] ?? ''),
            'address'       => (string)($issuer['address'] ?? ''),
            'postCode'      => (string)($issuer['postCode'] ?? ''),
            'town'          => (string)($issuer['town'] ?? ''),
            'province'      => (string)($issuer['province'] ?? ''),
            'countryCode'   => (string)($issuer['countryCode'] ?? 'ESP'),
        ];

        // ---- Cliente: merge del XML con la ficha (para traer DIR3, etc.)
        $cliXml = $inv->client ?? null;
        $clientId = (string)($cliXml->id ?? '');
        $clientObj = null;
        try {
            if ($clientId !== '') {
                $dm = new \DataManager('clients');
                $clientObj = $dm->getItemById($clientId);
            }
        } catch (\Throwable $e) { $clientObj = null; }

        // Si no se suministra el DIRe explícitamente, toma el de la ficha del cliente.
        if (($receivingDire === null || trim($receivingDire) === '') && $clientObj && isset($clientObj->dire)) {
            $receivingDire = (string)$clientObj->dire;
        }

        // Construye buyer con máximos datos disponibles
        $buyerEntityType = (string)($cliXml->entityType ?? ($clientObj->entityType ?? 'company'));
        $buyerIsFreelancer = (strtolower($buyerEntityType) === 'freelancer');
        $buyer = [
            'isLegalEntity' => !$buyerIsFreelancer ? true : false,
            'taxNumber'     => strtoupper(preg_replace('/[\s-]+/','', (string)($cliXml->nif ?? ($clientObj->nif ?? '')))),
            'name'          => (string)($cliXml->name ?? ($clientObj->name ?? '')),
            'address'       => (string)($cliXml->address ?? ($clientObj->address ?? '')),
            'postCode'      => (string)($cliXml->postCode ?? ($clientObj->postCode ?? '')),
            'town'          => (string)($cliXml->town ?? ($clientObj->town ?? '')),
            'province'      => (string)($cliXml->province ?? ($clientObj->province ?? '')),
            'countryCode'   => (string)($cliXml->countryCode ?? ($clientObj->countryCode ?? 'ESP')),
        ];

        // Residencia: fuerza código o bandera UE según ficha del cliente
        $residency = isset($clientObj->residency) ? (string)$clientObj->residency : '';
        if ($residency !== '') {
            if ($residency === 'resident_es') {
                $buyer['countryCode'] = 'ESP';
            } elseif ($residency === 'eu') {
                $buyer['isEuropeanUnionResident'] = true;  // fuerza U en <ResidenceTypeCode>
            } elseif ($residency === 'non_eu') {
                $buyer['isEuropeanUnionResident'] = false; // fuerza E en <ResidenceTypeCode>
            }
        }

        // ---- DIR3/centros desde la ficha si existen
        $buyer['face_dir3_oc'] = strtoupper(trim((string)($clientObj->face_dir3_oc ?? $clientObj->dir3_oc ?? '')));
        $buyer['face_dir3_og'] = strtoupper(trim((string)($clientObj->face_dir3_og ?? $clientObj->dir3_og ?? '')));
        $buyer['face_dir3_ut'] = strtoupper(trim((string)($clientObj->face_dir3_ut ?? $clientObj->dir3_ut ?? '')));

        if (!empty($clientObj->administrativeCentres) && is_array($clientObj->administrativeCentres)) {
            $buyer['administrativeCentres'] = $clientObj->administrativeCentres;
        } elseif (!empty($clientObj->centres) && is_array($clientObj->centres)) {
            $buyer['centres'] = $clientObj->centres;
        }

        // ---- Líneas
        $irpfRate = isset($inv->irpfRate) ? (float)$inv->irpfRate : 0.0;
        $items = [];
        if (isset($inv->items->item)) {
            foreach ($inv->items->item as $it) {
                $items[] = [
                    'description'   => (string)$it->description,
                    'quantity'      => (float)$it->quantity,
                    'unitPrice'     => (float)$it->unitPrice,  // sin IVA
                    'vat'           => (float)$it->vatRate,    // % (FacturaeGenerator acepta 'vat' o 'taxRate')
                    'unitOfMeasure' => '01',
                ];
            }
        }

        // ---- Suplidos → reimbursables
        $reimbursables = [];
        if (isset($inv->suplidos->suplido)) {
            foreach ($inv->suplidos->suplido as $s) {
                $amt = (float)($s->amount ?? 0);
                if ($amt <= 0) continue;
                $reimbursables[] = [
                    'description' => (string)($s->description ?? 'Suplido'),
                    'amount'      => round($amt, 2),
                ];
            }
        } elseif (isset($inv->totalSuplidos)) {
            $amt = (float)$inv->totalSuplidos;
            if ($amt > 0) $reimbursables[] = ['description' => 'Suplidos', 'amount' => round($amt, 2)];
        }

        // ---- Adjuntos ligeros (hash/QR si existen)
        $attachments = [];
        $vfHash = (string)($inv->verifactu->hash ?? $inv->hash ?? '');
        if ($vfHash !== '') $attachments['hashes'] = [$vfHash];
        $qrText = (string)($inv->verifactu->qrText ?? $inv->qrCode ?? '');
        if ($qrText !== '') $attachments['qr_text'] = $qrText;

        // ---- Facturae input
        $input = [
            'series'    => $series,
            'number'    => $number,
            'issueDate' => date('Y-m-d', strtotime((string)($inv->issueDate ?? 'now'))),
            'seller'    => $seller,
            'buyer'     => $buyer,
            'items'     => $items,
            'irpfRate'  => $irpfRate,
        ];

        // Referencias FACe (opcionales)
        if (!empty($inv->fileReference))             $input['fileReference']             = (string)$inv->fileReference;
        if (!empty($inv->receiverContractReference)) $input['receiverContractReference'] = (string)$inv->receiverContractReference;

        if (!empty($reimbursables)) $input['reimbursables'] = $reimbursables;
        if (!empty($attachments))   $input['attachments']   = $attachments;

        // DIRe para FACeB2B si se nos pasa
        if ($receivingDire) {
            $input['receivingUnit'] = strtoupper(preg_replace('/\s+/', '', $receivingDire));
        }

        return $input;
    }

    /** Devuelve el payload normalizado listo para generar el XML Facturae sin firma. */
    public function getFacturaePayload(string $invoiceId, array $options = []): array {
        $xml = $this->getInvoiceById($invoiceId);
        if (!$xml) {
            throw new \RuntimeException('Factura no encontrada: ' . $invoiceId);
        }
        $receivingDire = $options['receivingDire'] ?? null;
        return $this->mapInvoiceToFacturaeInput($xml, $receivingDire);
    }

    /** Registra metadatos de una Facturae firmada localmente y persiste el XML. */
    public function recordSignedFacturae(string $invoiceId, string $absolutePath, array $meta = []): bool {
        $absolutePath = realpath($absolutePath) ?: $absolutePath;
        if ($absolutePath === '' || !is_file($absolutePath)) {
            return false;
        }

        $invoice = $this->getInvoiceById($invoiceId);
        if (!$invoice) {
            return false;
        }

        $relativePath = $this->toRelativePath($absolutePath);
        $sha256 = $meta['sha256'] ?? (is_file($absolutePath) ? hash_file('sha256', $absolutePath) : '');
        $size   = $meta['size']   ?? (is_file($absolutePath) ? (int)filesize($absolutePath) : 0);
        $signedAt = $meta['signedAt'] ?? date('c');
        $source   = $meta['source']   ?? 'autofirma';

        if (isset($invoice->signedFacturae)) {
            unset($invoice->signedFacturae);
        }
        $node = $invoice->addChild('signedFacturae');
        $node->addChild('path', $relativePath);
        if ($sha256 !== '') $node->addChild('sha256', $sha256);
        $node->addChild('size', (string)$size);
        $node->addChild('signedAt', $signedAt);
        $node->addChild('source', $source);
        $node->addChild('filename', basename($absolutePath));

        return $this->saveInvoiceObject($invoice);
    }

    /** Obtiene la información de la Facturae firmada si existe y es accesible. */
    public function getSignedFacturaeInfo(string $invoiceId): ?array {
        $invoice = $this->getInvoiceById($invoiceId);
        if (!$invoice || !isset($invoice->signedFacturae)) {
            return null;
        }

        $node = $invoice->signedFacturae;
        $path = trim((string)($node->path ?? ''));
        if ($path === '') {
            return null;
        }

        $absolute = $this->toAbsolutePath($path);
        if (!is_file($absolute)) {
            return null;
        }

        $sha256 = trim((string)($node->sha256 ?? ''));
        if ($sha256 === '') {
            $sha256 = hash_file('sha256', $absolute) ?: '';
        }
        $size = (int)((string)($node->size ?? '0'));
        if ($size <= 0) {
            $size = (int)filesize($absolute);
        }

        return [
            'path'         => $path,
            'absolutePath' => $absolute,
            'sha256'       => $sha256,
            'signedAt'     => (string)($node->signedAt ?? ''),
            'source'       => (string)($node->source ?? ''),
            'size'         => $size,
            'filename'     => (string)($node->filename ?? basename($absolute)),
        ];
    }

    private function projectRoot(): string {
        static $root = null;
        if ($root === null) {
            $root = dirname(__DIR__);
        }
        return $root;
    }

    private function toAbsolutePath(string $path): string {
        if ($path === '') {
            return '';
        }
        if (
            $path[0] === '/' ||
            (
                strlen($path) >= 3 &&
                ctype_alpha($path[0]) &&
                $path[1] === ':' &&
                ($path[2] === '\\' || $path[2] === '/')
            )
        ) {
            return $path;
        }
        return rtrim($this->projectRoot(), '/') . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    private function toRelativePath(string $path): string {
        if ($path === '') {
            return '';
        }
        $normalized = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $this->projectRoot()), '/');
        $prefix = $root . '/';
        if (str_starts_with($normalized, $prefix)) {
            return ltrim(substr($normalized, strlen($prefix)), '/');
        }
        return $normalized;
    }


    private static function moneyToFloatEU($v): float {
        if (is_null($v)) return 0.0;
        if (is_float($v) || is_int($v)) return (float)$v;
        $s = trim((string)$v); if ($s === '') return 0.0;
        $s = str_replace(["\xC2\xA0", ' '], '', $s);
        $hasComma = strpos($s, ',') !== false;
        $hasDot   = strpos($s, '.') !== false;
        if ($hasComma && $hasDot) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
        elseif ($hasComma)       { $s = str_replace(',', '.', $s); }
        return (float)$s;
    }

    private function ensureDir(string $dir) : void {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
    }

    private function log(string $tag, array $data = []) : void {
        $log = __DIR__ . '/../data/logs/invoice_manager.log';
        @mkdir(dirname($log), 0775, true);
        @file_put_contents($log, '['.date('c')."] {$tag} ".json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
    }

    private static function sx($v): string {
        if ($v === null) return '';
        if (is_float($v) || is_int($v)) {
            $t = rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.');
            return ($t === '') ? '0' : $t;
        }
        return (string)$v;
    }
}
