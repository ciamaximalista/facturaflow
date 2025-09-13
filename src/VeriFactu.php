<?php
// src/VeriFactu.php
declare(strict_types=1);

/**
 * Integración Veri*Factu:
 * - Usa josemmo/verifactu-php si está disponible para construir y validar el Registro de Facturación,
 *   calcular la huella digital (hash encadenado) y, opcionalmente, enviar a la AEAT.
 * - Mantiene fallback a la lógica antigua de hash para no romper entornos sin la librería.
 * - Genera QR ISO/IEC 18004 con ECC=M (HAC/1177/2024, art. 21) y tamaño suficiente en representación.
 */

// 1) IMPORTS — deben ir antes de cualquier otra sentencia
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

// Los siguientes 'use' son seguros aunque la lib no esté instalada.
// Si no existe, NO fallará mientras no se instancien las clases.
use josemmo\Verifactu\Models\ComputerSystem;
use josemmo\Verifactu\Models\Records\{
    BreakdownDetails,
    FiscalIdentifier,
    InvoiceIdentifier,
    InvoiceType,
    OperationType,
    RegimeType,
    RegistrationRecord,
    TaxType
};
use josemmo\Verifactu\Services\AeatClient;

// 2) Requires (pueden ir después de los 'use')
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/helpers.php';

class VeriFactu {
    /** Ruta al log inmutable de la cadena de huellas */
    private string $logFile;

    /** Config general (data/config.json) */
    private array $config;

    public function __construct(?string $logFile = null)
    {
        $this->logFile = $logFile ?: (__DIR__ . '/../data/verifactu/verifactu_log.xml');
        $this->config  = read_config(__DIR__ . '/../data/config.json'); // helper ya robusto
        $this->ensureLog();
    }

    // ---------------------------
    //  API PÚBLICA PRINCIPAL
    // ---------------------------

    /**
     * Genera y encadena la huella Veri*Factu para una factura emitida.
     * - Si está instalada la librería de josemmo: usa RegistrationRecord + validate()
     * - Si no: fallback a algoritmo anterior (prevHash + JSON canónico)
     *
     * Devuelve:
     *  [
     *    success => bool,
     *    hash    => string,         // huella calculada
     *    qrPngB64=> ?string,        // PNG en base64 del QR (si se generó)
     *    qrUrl   => ?string,        // URL/Qr-string usado para el QR
     *    notice  => ?string
     *  ]
     */
    public function appendLogRecord(string $invoiceId): array
    {
        // 1) Cargar factura emitida
        require_once __DIR__ . '/InvoiceManager.php';
        $im  = new \InvoiceManager();
        $inv = $im->getInvoiceById($invoiceId);
        if (!$inv) {
            return ['success' => false, 'message' => 'No se encontró la factura para VeriFactu'];
        }


        // 2) Datos del emisor y receptor
        $issuer = $this->normalizeIssuer((array)($this->config['issuer'] ?? []) + $this->config);
        $client = isset($inv->client) ? $inv->client : null;

        $issuerNif = strtoupper(preg_replace('/[\s-]+/', '', (string)($issuer['nif'] ?? '')));
        $issuerName = (string)(
            $issuer['companyName'] ?? $issuer['name'] ??
            trim(((string)($issuer['firstName'] ?? '')).' '.((string)($issuer['lastName'] ?? '')))
        );
        $buyerNif   = ($client && isset($client->nif)) ? strtoupper(preg_replace('/[\s-]+/', '', (string)$client->nif)) : '';

        $issueDate  = (string)($inv->issueDate ?? '');
        $number     = (string)($inv->number ?? $inv->id ?? ''); // intenta número “humano”; si no, usa id
        $series     = (string)($inv->series ?? '');             // algunos XML nuestros ya guardan <series>

        // 3) Totales y desglose por tipos (agrupamos líneas por tipo de IVA)
        $breakdownAgg = $this->aggregateBreakdownFromInvoice($inv);
        $totalTax  = array_sum(array_column($breakdownAgg, 'taxAmount'));
        $totalBase = array_sum(array_column($breakdownAgg, 'baseAmount'));

        // IRPF + suplidos (si existieran)
        $irpfRate   = (float)($inv->irpfRate ?? 0);
        $irpfAmount = $irpfRate ? round($totalBase * ($irpfRate / 100), 2) : 0.0;

        $reimbursable = 0.0;
        if (isset($inv->totalSuplidos)) {
            $reimbursable = (float)$inv->totalSuplidos;
        } elseif (isset($inv->suplidos) && isset($inv->suplidos->suplido)) {
            foreach ($inv->suplidos->suplido as $s) {
                $reimbursable += (float)($s->amount ?? 0);
            }
        }

        $invoiceTotal = round($totalBase + $totalTax - $irpfAmount + $reimbursable, 2);

        // 4) Obtener previousHash (cadena)
        $prev = $this->getLastHash();

        // 5) Construir registro y calcular hash
        $hashedAt = new \DateTimeImmutable(); // momento de la huella
        [$hash, $notice] = $this->computeHashWithJosemmoIfPossible(
            prevHash: ($prev === str_repeat('0', 64) ? null : $prev),
            hashedAt: $hashedAt,
            issuerNif: $issuerNif,
            issuerName: $issuerName,
            buyerNif: $buyerNif,
            series: $series,
            number: $number,
            issueDate: $issueDate,
            description: $this->guessConcept($inv),
            breakdownAgg: $breakdownAgg,
            totalTax: $totalTax,
            totalAmount: $invoiceTotal
        );

        // Fallback (no josemmo o error): json canónico + sha256(prev|payload)
        if ($hash === null) {
            $payload = $this->legacyCanonicalPayload(
                $invoiceId, $issueDate, $issuerNif, $buyerNif, $inv, $breakdownAgg, $totalBase, $totalTax, $irpfRate, $irpfAmount, $reimbursable, $invoiceTotal
            );
            $hash = hash('sha256', ($prev ?: str_repeat('0', 64)) . '|' . $payload);
            $notice = ($notice ? $notice.' ' : '') . '(hash por algoritmo de reserva)';
        }

        // 6) Generar QR (contenido dependiente de URL AEAT; parametrizable)
        [$qrString, $qrPngB64] = $this->makeQrForInvoice(
            invoiceId: (string)($inv->id ?? $number),
            series: $series,
            number: $number,
            issueDate: $issueDate,
            issuerNif: $issuerNif,
            buyerNif: $buyerNif,
            totalAmount: $invoiceTotal,
            hash: $hash
        );

        // Guardar PNG en disco y preparar ruta relativa web
        $qrRel = null;
        if (!empty($qrPngB64)) {
            // Normaliza a base64 “puro” del PNG, evitando doble codificaciones tipo base64(data-url)
            $b64 = $qrPngB64;
            if (preg_match('~^data:image/[^;]+;base64,~i', $b64)) {
                $b64 = substr($b64, strpos($b64, ',') + 1);
            } else {
                // ¿Es base64 del propio data-url? Decodifica una vez y vuelve a mirar
                $tmp = base64_decode($b64, true);
                if ($tmp !== false && stripos($tmp, 'data:image/') === 0) {
                    $pos = strpos($tmp, ',');
                    if ($pos !== false) {
                        $b64 = substr($tmp, $pos + 1);
                    }
                }
            }

            // Decodifica y persistencia si es PNG válido
            $raw = base64_decode($b64, true);
            if ($raw !== false && strlen($raw) > 8) {
                $isPng = (substr($raw, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A");
                $qrDir = __DIR__ . '/../data/verifactu/qr/';
                if (!is_dir($qrDir)) @mkdir($qrDir, 0775, true);
                $fname = safe_filename((string)($inv->id ?? $number)) . '.png';
                $qrAbs = $qrDir . $fname;
                if ($isPng) {
                    @file_put_contents($qrAbs, $raw);
                    @chmod($qrAbs, 0644);
                    $qrRel = 'data/verifactu/qr/' . $fname;
                    // Sobreescribe $qrPngB64 con base64 “puro” para persistir/plantilla
                    $qrPngB64 = $b64;
                } else {
                    @file_put_contents($qrDir . 'qr_debug_invalid.bin', $raw);
                }
            }
        }


        // 7) Añadir entrada al log (con bloqueo de fichero)
            $this->appendEntryToXmlLog([
            'timestamp'   => (new \DateTimeImmutable())->format(DATE_ATOM),
            'hashedAt'    => $hashedAt->format(DATE_ATOM),
            'invoiceId'   => (string)($inv->id ?? $number),
            'issueDate'   => $issueDate,
            'issuerNif'   => $issuerNif,
            'buyerNif'    => $buyerNif,
            'series'      => $series ?: null,
            'number'      => $number ?: null,
            'totalAmount' => number_format($invoiceTotal, 2, '.', ''),
            'prevHash'    => $prev,
            'hash'        => $hash,
            'qrString'    => $qrString ?: null,
            'qrPngB64'    => $qrPngB64 ?: null,
            'qrImagePath' => $qrRel ?: null,  
        ]);

        // 8) Persistir hash dentro de la factura emitida
            if (method_exists($im, 'embedVerifactuHash')) {
                try { $im->embedVerifactuHash($invoiceId, $hash); } catch (\Throwable $e) { /* no bloqueante */ }
            }

            // Meta adicional (si tu InvoiceManager lo soporta)
            if (method_exists($im, 'embedVerifactuMeta')) {
                try {
                    $im->embedVerifactuMeta($invoiceId, [
                        'hash'        => $hash,
                        'qrUrl'       => $qrString,
                        // Guarda SIEMPRE base64 “puro” (sin prefijo data:), la vista lo envolverá
                        'qrCodeB64'   => $qrPngB64,
                        'qrImagePath' => $qrRel,
                    ]);
                } catch (\Throwable $e) { /* opcional */ }
            }

            // 9) Devolver resultado a quien llamó (índice/AJAX o quien sea)
            return [
                'success'     => true,
                'hash'        => $hash,
                'qrUrl'       => $qrString,
                'qrPngB64'    => $qrPngB64,
                'qrImagePath' => $qrRel,
                'notice'      => $notice,
            ];
        } // <-- CIERRA appendLogRecord() AQUÍ




    /**
     * Devuelve la última huella de la cadena (o 64 ceros si no hay entradas válidas).
     */
    public function getLastHash(): string
    {
        $this->ensureLog();

        $xml = @simplexml_load_file($this->logFile);
        if (!$xml) return str_repeat('0', 64);

        // Formato moderno <entry>, retrocompat <record>
        foreach (['//entry[last()]/hash', '//record[last()]/hash'] as $xp) {
            $n = @$xml->xpath($xp);
            if ($n && isset($n[0]) && trim((string)$n[0]) !== '') {
                return trim((string)$n[0]);
            }
        }
        return str_repeat('0', 64);
    }

    /**
     * Genera un PNG (bytes) de QR según ISO/IEC 18004 con ECC=M.
     * Devuelve los bytes; el método superior lo codifica a base64 si procede.
     */
    public function generateQrPng(string $content): string
    {
        $options = new QROptions([
            'eccLevel'   => QRCode::ECC_M,           // HAC/1177/2024, art. 21.3
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
        ]);
        return (new QRCode($options))->render($content);
    }

    // ---------------------------
    //  PRIVADOS
    // ---------------------------

    /** Asegura la existencia del fichero de log y su raíz */
    private function ensureLog(): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        // migración desde ubicación antigua si existe
        $legacy = __DIR__ . '/../data/verifactu_log.xml';
        if (is_file($legacy) && !file_exists($this->logFile)) {
            @rename($legacy, $this->logFile);
            @chmod($this->logFile, 0660);
        }

        if (!is_file($this->logFile)) {
            $root = new \SimpleXMLElement('<verifactuLog/>');
            $root->addAttribute('version', '1.0');
            $root->asXML($this->logFile);
            @chmod($this->logFile, 0660);
        }
    }

    /**
     * Construye el hash con josemmo si es posible.
     * Devuelve [hash|null, notice|null]
     */
    private function computeHashWithJosemmoIfPossible(
        ?string $prevHash,
        \DateTimeImmutable $hashedAt,
        string $issuerNif,
        string $issuerName,
        string $buyerNif,
        string $series,
        string $number,
        string $issueDate,
        string $description,
        array $breakdownAgg,
        float $totalTax,
        float $totalAmount
    ): array {
        if (!class_exists(\josemmo\Verifactu\Models\Records\RegistrationRecord::class)) {
            return [null, 'Librería josemmo/verifactu-php no instalada'];
        }

        try {
            // 1) Identificación de factura
            $ii = new InvoiceIdentifier();
            $ii->issuerId      = $issuerNif;
            $ii->invoiceNumber = $this->composeInvoiceNumberForRecord($series, $number);
            $ii->issueDate     = new \DateTimeImmutable($issueDate);

            // 2) Registro de facturación
            $rec = new RegistrationRecord();
            $rec->invoiceId    = $ii;
            $rec->issuerName   = $issuerName ?: $issuerNif;
            $rec->invoiceType  = InvoiceType::Simplificada; // ajusta según tu casuística
            $rec->description  = $description ?: 'Factura';

            // 3) Desglose por tipo impositivo (agrupado)
            $rec->breakdown = [];
            $idx = 0;
            foreach ($breakdownAgg as $row) {
                $b = new BreakdownDetails();
                $b->taxType       = TaxType::IVA;
                $b->regimeType    = RegimeType::C01;     // régimen general
                $b->operationType = OperationType::S1;   // sujeta y no exenta
                $b->taxRate       = number_format((float)$row['taxRate'], 2, '.', '');
                $b->baseAmount    = number_format((float)$row['baseAmount'], 2, '.', '');
                $b->taxAmount     = number_format((float)$row['taxAmount'], 2, '.', '');
                $rec->breakdown[$idx++] = $b;
            }

            $rec->totalTaxAmount = number_format($totalTax,   2, '.', '');
            $rec->totalAmount    = number_format($totalAmount,2, '.', '');

            // 4) Encadenamiento y cálculo de huella
            $rec->previousInvoiceId = null;        // primera de cadena o no informado
            $rec->previousHash      = $prevHash;   // null si génesis
            $rec->hashedAt          = $hashedAt;
            $hash = $rec->calculateHash();

            // Validación de consistencia
            $rec->validate();

            return [$hash, null];

        } catch (\Throwable $e) {
            return [null, 'Aviso josemmo: ' . $e->getMessage()];
        }
    }

    /** Genera el contenido del QR y el PNG base64; parámetros de la URL configurables */
    private function makeQrForInvoice(
        string $invoiceId,
        string $series,
        string $number,
        string $issueDate,
        string $issuerNif,
        string $buyerNif,
        float $totalAmount,
        string $hash
    ): array {
        // Art. 21 HAC/1177/2024: la AEAT publicará la URL/forma exacta -> configurable.
        $vfCfg = (array)($this->config['verifactu'] ?? []);
        $base  = (string)($vfCfg['qr_base_url'] ?? ''); // deja vacío hasta que la AEAT publique su URL

        // Mapeo de claves (permite alinearse al esquema oficial cuando se publique)
        $map = (array)($vfCfg['qr_param_map'] ?? [
            'issuer' => 'emisor',
            'series' => 'serie',
            'number' => 'numero',
            'date'   => 'fecha',
            'total'  => 'total',
            'mode'   => 'verifactu',
            // 'hash' => 'hash', // opcional
        ]);

        $pairs = [
            $map['issuer'] => $issuerNif,
            $map['series'] => $series,
            $map['number'] => $number ?: $invoiceId,
            $map['date']   => $issueDate,
            $map['total']  => number_format($totalAmount, 2, '.', ''),
            $map['mode']   => '1', // marcamos que es Veri*Factu
        ];

        if (!empty($vfCfg['qr_include_hash_short'])) {
            $pairs[$map['hash'] ?? 'h'] = substr($hash, 0, 8);
        }

        // Montaje de cadena; si no hay base, devolvemos sólo la query canónica
        $query = http_build_query($pairs, arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);
        $content = $base ? rtrim($base, '?&') . '?' . $query : $query;

        // Generar PNG
        $png = '';
        try { $png = $this->generateQrPng($content); } catch (\Throwable $e) { /* silencioso */ }

        return [$content, ($png !== '' ? base64_encode($png) : null)];
    }

    /** Escribe la entrada en el XML con bloqueo */
    private function appendEntryToXmlLog(array $entry): void
    {
        $this->ensureLog();

        $fh = fopen($this->logFile, 'c+');
        if ($fh === false) return;
        try {
            // Bloqueo exclusivo
            if (function_exists('flock')) @flock($fh, LOCK_EX);

            // Leer XML actual
            $raw = stream_get_contents($fh);
            $xml = ($raw && trim($raw) !== '') ? @simplexml_load_string($raw) : new \SimpleXMLElement('<verifactuLog/>');

            $node = $xml->addChild('entry');
            foreach ($entry as $k => $v) {
                if ($v === null || $v === '') continue;
                $node->addChild($k, htmlspecialchars((string)$v));
            }

            // Reescribir completo
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $xml->asXML() ?: '');

        } finally {
            if (function_exists('flock')) @flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** Normaliza emisor desde config */
    private function normalizeIssuer(array $issuer): array
    {
        if (empty($issuer)) return [];
        $issuer['nif'] = strtoupper(preg_replace('/[\s-]+/', '', (string)($issuer['nif'] ?? '')));
        return $issuer;
    }

    /** Obtiene un concepto razonable: primera línea o descripción de cabecera */
    private function guessConcept(\SimpleXMLElement $inv): string
    {
        $desc = '';
        if (isset($inv->items->item[0]->description)) {
            $desc = (string)$inv->items->item[0]->description;
        } elseif (isset($inv->description)) {
            $desc = (string)$inv->description;
        }
        return trim($desc);
    }

    /**
     * Agrupa líneas por tipo de IVA → [ ['taxRate'=>xx, 'baseAmount'=>yy, 'taxAmount'=>zz], ... ]
     */
    private function aggregateBreakdownFromInvoice(\SimpleXMLElement $inv): array
    {
        $agg = []; // taxRate => ['base'=>..., 'tax'=>...]
        if (isset($inv->items) && isset($inv->items->item)) {
            foreach ($inv->items->item as $it) {
                $qty   = (float)($it->quantity ?? 1);
                $price = (float)($it->unitPrice ?? 0);
                $rate  = (float)($it->vatRate ?? 0);
                $base  = round($qty * $price, 2);
                $tax   = round($base * ($rate / 100), 2);

                $k = number_format($rate, 2, '.', '');
                if (!isset($agg[$k])) $agg[$k] = ['baseAmount' => 0.0, 'taxAmount' => 0.0, 'taxRate' => (float)$k];
                $agg[$k]['baseAmount'] += $base;
                $agg[$k]['taxAmount']  += $tax;
            }
        }
        // Normalizar a lista
        return array_values(array_map(function ($row) {
            $row['baseAmount'] = (float)number_format((float)$row['baseAmount'], 2, '.', '');
            $row['taxAmount']  = (float)number_format((float)$row['taxAmount'],  2, '.', '');
            return $row;
        }, $agg));
    }

    /** Construye el número de factura para el registro (serie-número o id) */
    private function composeInvoiceNumberForRecord(string $series, string $number): string
    {
        $num = trim($number);
        $ser = trim($series);
        if ($ser !== '' && $num !== '') return $ser . '-' . $num;
        if ($num !== '') return $num;
        return 'FACT-' . date('Ymd-His');
    }

    /** Carga JSON canónico anterior (para fallback), basado en las líneas reales de la factura */
    private function legacyCanonicalPayload(
        string $invoiceId,
        string $issueDate,
        string $issuerNif,
        string $buyerNif,
        \SimpleXMLElement $inv,
        array $breakdownAgg,
        float $baseTotal,
        float $vatTotal,
        float $irpfRate,
        float $irpfAmount,
        float $reimbursable,
        float $invoiceTotal
    ): string {
        // reconstruye items para no depender de orden de XML
        $ops = [];
        if (isset($inv->items) && isset($inv->items->item)) {
            foreach ($inv->items->item as $it) {
                $qty   = round((float)($it->quantity ?? 1), 3);
                $price = round((float)($it->unitPrice ?? 0), 6);
                $rate  = round((float)($it->vatRate ?? 0), 2);
                $base  = round($qty * $price, 2);
                $tax   = round($base * ($rate/100), 2);
                $ops[] = ['d'=>(string)($it->description ?? ''), 'q'=>$qty,'p'=>$price,'r'=>$rate,'b'=>$base,'v'=>$tax];
            }
        }

        $canon = [
            'invoiceId' => (string)$invoiceId,
            'issueDate' => (string)$issueDate,
            'issuerNif' => (string)$issuerNif,
            'buyerNif'  => (string)$buyerNif,
            'ops'       => $ops,
            'breakdown' => $breakdownAgg,
            'totals'    => [
                'base'  => round($baseTotal, 2),
                'vat'   => round($vatTotal,  2),
                'irpfR' => round($irpfRate,  2),
                'irpfA' => round($irpfAmount,2),
                'reimb' => round($reimbursable, 2),
                'total' => round($invoiceTotal, 2),
            ],
        ];
        return json_encode($canon, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '';
    }
}
