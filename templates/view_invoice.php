<?php
/**
 * templates/view_invoice.php
 * Muestra los detalles completos de una factura.
 */
if (!$invoice) { echo "<h2>Error</h2><p>La factura no ha sido encontrada.</p>"; return; }
$cancelled        = (string)($invoice->isCancelled ?? 'false') === 'true';
$isRectificative  = (string)($invoice->isRectificative ?? 'false') === 'true';
$totalIrpfAmount  = (float)($invoice->totalIrpfAmount ?? 0);
$irpfRate         = (float)($invoice->irpfRate ?? 0);
$hasSuplidos      = isset($invoice->suplidos) && isset($invoice->suplidos->suplido) && count($invoice->suplidos->suplido) > 0;

// Normaliza $issuer y deriva email/iban (config.json -> XML -> existente)
if (!isset($issuer) || !is_array($issuer)) { $issuer = []; }

// Lee config
$__cfgPath = __DIR__ . '/../data/config.json';
$__cfg = is_file($__cfgPath) ? (json_decode((string)file_get_contents($__cfgPath), true) ?: []) : [];
$__cfgIssuer = $__cfg['issuer'] ?? $__cfg;

// Completa $issuer con datos de config si faltan
foreach (['companyName','nif','address','postCode','town','province','logoPath','email','iban'] as $__k) {
    if (!isset($issuer[$__k]) || $issuer[$__k] === '' || $issuer[$__k] === null) {
        if (isset($__cfgIssuer[$__k])) $issuer[$__k] = $__cfgIssuer[$__k];
    }
}

// Fallback desde el propio XML de la factura (nodos añadidos por InvoiceManager)
$issuerEmail = $issuer['email'] ?? (string)($invoice->issuer->email ?? '');
$issuerIban  = $issuer['iban']  ?? (string)($invoice->issuer->iban  ?? '');

// Normaliza IBAN (sin espacios)
if ($issuerIban !== '') $issuerIban = preg_replace('/\s+/', '', $issuerIban);

// ---------- Carga del QR: ruta física -> base64 -> “no disponible” ----------
$qrPathRel = (string)($invoice->verifactu->qrImagePath ?? $invoice->qrImagePath ?? '');


$qrSrc   = '';
// Ruta y base64: contempla tanto nodos bajo <verifactu> como nodos raíz
$qrPath  = (string)($invoice->verifactu->qrImagePath ?? $invoice->qrImagePath ?? '');
$qrB64   = (string)(
    $invoice->verifactu->qrCodeB64
    ?? $invoice->verifactu->qrCode
    ?? $invoice->qrCodeB64
    ?? $invoice->qrCode
    ?? ''
);
$baseDir = dirname(__DIR__); // .../templates -> sube a raíz del proyecto

// 1) Si hay ruta física y el fichero existe, úsala directamente
if ($qrPath !== '') {
    $abs = $baseDir . '/' . ltrim($qrPath, '/');
    if (is_file($abs)) {
        $qrSrc = $qrPath; // relativo para <img src="">
    }
}

// 2) Si no hay fichero, intenta con base64 o data-URL
if ($qrSrc === '' && $qrB64 !== '') {
    if (preg_match('~^data:image/[^;]+;base64,~i', $qrB64)) {
        // ya es un data-URL completo
        $qrSrc = $qrB64;
    } else {
        // base64 “puro”
        $qrSrc = 'data:image/png;base64,' . $qrB64;
    }
}

// ---------- Cadena VeriFactu: helpers ----------
function verifactu_load_log_entries(): array {
    $path = __DIR__ . '/../data/verifactu/verifactu_log.xml';
    if (!is_file($path)) return [];
    $xml = @simplexml_load_file($path);
    if (!$xml) return [];

    $entries = [];
    $nodes = [];
    if (isset($xml->entry))  { foreach ($xml->entry  as $n) $nodes[] = $n; }
    if (isset($xml->record)) { foreach ($xml->record as $n) $nodes[] = $n; }

    foreach ($nodes as $n) {
        $entries[] = [
            'invoiceId'   => (string)($n->invoiceId   ?? ''),
            'timestamp'   => (string)($n->timestamp   ?? ''),
            'hash'        => (string)($n->hash        ?? ''),
            'prevHash'    => (string)($n->prevHash    ?? ''),
            // NUEVO: campos QR si existen
            'qrImagePath' => (string)($n->qrImagePath ?? ''),
            'qrPngB64'    => (string)($n->qrPngB64    ?? ''),
            'qrString'    => (string)($n->qrString    ?? ''),
        ];
    }
    return $entries;
}


/** Devuelve la última entrada del log VeriFactu para un id dado (o null). */
function verifactu_find_entry_for_invoice(string $invoiceId): ?array {
    $entries = verifactu_load_log_entries();
    $found = null;
    foreach ($entries as $e) {
        if ((string)($e['invoiceId'] ?? '') === $invoiceId) {
            $found = $e; // se queda con la última coincidencia
        }
    }
    return $found;
}


function verifactu_get_chain_context($invoice): array {
    $id = (string)($invoice->id ?? '');
    $currFromXml = null;

    if (isset($invoice->verifactu) && isset($invoice->verifactu->hash) && (string)$invoice->verifactu->hash !== '') {
        $currFromXml = (string)$invoice->verifactu->hash;
    } elseif (isset($invoice->verifactuHash) && (string)$invoice->verifactuHash !== '') {
        $currFromXml = (string)$invoice->verifactuHash;
    } elseif (isset($invoice->hash) && (string)$invoice->hash !== '') {
        $currFromXml = (string)$invoice->hash;
    }

    $entries = verifactu_load_log_entries();
    $byHash  = [];
    $byId    = [];
    foreach ($entries as $e) {
        if ($e['hash'] !== '')       { $byHash[$e['hash']] = $e; }
        if ($e['invoiceId'] !== '')  { $byId[$e['invoiceId']] = $e; }
    }

    $curr = $byId[$id] ?? null;
    if (!$curr && $currFromXml) {
        $curr = [
            'invoiceId' => $id,
            'timestamp' => '',
            'hash'      => $currFromXml,
            'prevHash'  => '',
        ];
    }

    if (!$curr) {
        return [
            'currentHash' => null,
            'prevHash'    => null,
            'prevInvoice' => null,
            'nextHash'    => null,
            'nextInvoice' => null,
        ];
    }

    $prev = ($curr['prevHash'] && isset($byHash[$curr['prevHash']])) ? $byHash[$curr['prevHash']] : null;

    $next = null;
    if (!empty($curr['hash'])) {
        foreach ($entries as $e) {
            if ($e['prevHash'] !== '' && $e['prevHash'] === $curr['hash']) {
                $next = $e;
                break;
            }
        }
    }

    return [
        'currentHash' => $curr['hash'] ?: null,
        'prevHash'    => $curr['prevHash'] ?: ($prev['hash'] ?? null),
        'prevInvoice' => $prev['invoiceId'] ?? null,
        'nextHash'    => $next['hash'] ?? null,
        'nextInvoice' => $next['invoiceId'] ?? null,
    ];
}

// ===== Pago FACeB2B: normalización de estado para banda superior =====
$fmtDate = function(?string $s): string {
    $s = (string)$s;
    if ($s === '') return '';
    $ts = strtotime($s);
    return $ts ? date('d/m/Y', $ts) : $s;
};

$fb = isset($invoice->faceb2b) ? $invoice->faceb2b : null;
$rawStatus = '';
$paidAt = $acceptedAt = $rejectedAt = '';
$rejectReason = '';

if ($fb) {
  $rawStatus = strtolower(trim((string)(
    $fb->paymentStatus
      ?? $fb->status
      ?? ($fb->payment->status ?? '')
      ?? ($fb->payment_state ?? '')
      ?? ($fb->paymentState ?? '')
      ?? ($fb->state ?? '')
  )));
  $paidAt       = (string)($fb->paymentDate ?? $fb->paidAt ?? ($fb->payment->date ?? ''));
  $acceptedAt   = (string)($fb->acceptedAt ?? $fb->acceptanceDate ?? ($fb->payment->acceptedAt ?? ''));
  $rejectedAt   = (string)($fb->rejectedAt ?? $fb->rejectionDate ?? '');
  $rejectReason = (string)($fb->rejectionReason ?? $fb->rejectReason ?? '');
}

$payStatus = '';
// Preferir estado de FACE si hay registro o estado en el nodo <face>
$faceNode = isset($invoice->face) ? $invoice->face : null;
$faceReg  = $faceNode ? trim((string)($faceNode->registerNumber ?? '')) : '';
if ($faceReg !== '' || ($faceNode && (string)($faceNode->statusText ?? $faceNode->statusName ?? '') !== '')) {
  $t = strtolower(trim((string)($faceNode->statusText ?? $faceNode->statusName ?? '')));
  if ($t !== '') {
    if (str_contains($t, 'pagada'))       $payStatus = 'Pagada';
    elseif (str_contains($t, 'rechaz'))   $payStatus = 'Rechazada';
    elseif (str_contains($t, 'anulad'))   $payStatus = 'Anulada';
    elseif (str_contains($t, 'trámite') || str_contains($t, 'pago')) $payStatus = 'Pendiente de pago';
    elseif (str_contains($t, 'registr'))  $payStatus = 'Pendiente de aceptación';
  }
}
// Si no hay dato de FACE, usar FACeB2B
if ($payStatus === '') {
  if ($paidAt !== '' || in_array($rawStatus, ['paid','pagada','abonada','satisfecha'], true)) {
    $payStatus = 'Pagada';
  } elseif ($rejectedAt !== '' || in_array($rawStatus, ['rejected','rechazada'], true)) {
    $payStatus = 'Rechazada';
  } elseif ($acceptedAt !== '' || in_array($rawStatus, ['accepted','aceptada','conformada','reconocida','pendiente de pago'], true)) {
    $payStatus = 'Pendiente de pago';
  } else {
    // Enviada sin reacción del buyer
    $payStatus = 'Pendiente de aceptación';
  }
}

$bannerClass = match (mb_strtolower($payStatus, 'UTF-8')) {
  'rechazada'         => 'status-rechazada',
  'pagada'            => 'status-pagada',
  'pendiente de pago' => 'status-pendiente-pago',
  default             => 'status-pendiente',
};

$bannerText = match (mb_strtolower($payStatus, 'UTF-8')) {
  'rechazada'         => 'Factura rechazada' . ($rejectedAt ? ' · ' . $fmtDate($rejectedAt) : '') . ($rejectReason ? ' · ' . $rejectReason : ''),
  'pagada'            => 'Factura pagada'    . ($paidAt     ? ' · ' . $fmtDate($paidAt)     : ''),
  'pendiente de pago' => 'Aceptada; pendiente de pago' . ($acceptedAt ? ' · ' . $fmtDate($acceptedAt) : ''),
  default             => 'Pendiente de aceptación',
};


    $downloadPath = $signedInfo['path'] ?? null;
    $signedAtText = isset($signedInfo['signedAt']) && $signedInfo['signedAt'] !== ''
        ? date('d/m/Y H:i', strtotime($signedInfo['signedAt']))
        : null;


?>

        <form id="faceb2bSyncFormView" method="POST" action="index.php" style="display:inline-block; margin-right:.4rem;">
            <input type="hidden" name="action" value="sync_faceb2b">
            <button id="btnSyncFaceB2BView" class="btn btn-primary" type="submit">🔄 Sincronizar FACeB2B</button>
            <span id="faceb2bSyncMsgView" class="muted" style="margin-left:.5rem;"></span>
        </form>

<div class="invoice-view-header">
    <h2>
        Factura: <?php echo htmlspecialchars((string)$invoice->id); ?>
        
        <?php if ($isRectificative): ?><span class="badge badge-rectificative">Rectificativa</span><?php endif; ?>
        <?php if ($cancelled): ?><span class="badge badge-cancelled">Rectificada</span><?php endif; ?>
    </h2>
    <span id="signature-status-pill" class="pill pill-ok">Creada & Firmada el <?= $signedAtText ? ' · ' . htmlspecialchars($signedAtText) : '' ?></span>
    <div>

        <?php
          // Número de registro FACeB2B (usado por otras funcionalidades)
          $reg = isset($invoice->faceb2b->registrationCode) ? (string)$invoice->faceb2b->registrationCode : '';
        ?>
        <?php if (!$cancelled): ?>
            <a href="index.php?page=rectify_prompt&id=<?php echo urlencode((string)$invoice->id); ?>" class="btn btn-danger">Rectificar</a>
        <?php endif; ?>
        
        <a href="index.php?page=print_invoice&id=<?php echo urlencode((string)$invoice->id); ?>" class="btn">PDF para imprimir</a>






        <a id="signature-download"
           class="btn btn-extra-small"
           href="<?= $signedInfo && $downloadPath ? htmlspecialchars($downloadPath) : '#' ?>"
           target="_blank" rel="noopener"
           style="<?= $signedInfo && $downloadPath ? '' : 'display:none;' ?>">
           Descargar Facturae
        </a>
    </div>






</div>

 

<!-- ===== NUEVO: Banda de estado de pagos arriba ===== -->
<div class="status-banner <?php echo $bannerClass; ?>">
  <strong><?php echo htmlspecialchars($bannerText); ?></strong>
</div>

<div class="card invoice-container">
    <?php if ($cancelled): ?>
    <div class="rectified-overlay">
        <div class="rectified-text">Rectificada</div>
    </div>
    <div class="cancellation-notice">
        <p>
            <strong>Factura Rectificada:</strong> Esta factura ha sido rectificada por 
            <?php
		$ids = [];
        if (isset($invoice->rectificativeId)) {
            foreach ($invoice->rectificativeId as $node) {
                $val = trim((string)$node);
                if ($val !== '') $ids[] = $val;
            }
            $ids = array_values(array_unique($ids));
        }
        $rectLinks = [];
        foreach ($ids as $rid) {
            $url = 'index.php?page=view_invoice&id=' . urlencode($rid);
            $rectLinks[] = '<a href="' . $url . '"><strong>' . htmlspecialchars($rid) . '</strong></a>';
        }
        echo implode(', ', $rectLinks);
		?>
            <?php if (!empty($invoice->cancellationReason)): ?>
                por el siguiente motivo: <em>"<?php echo htmlspecialchars((string)$invoice->cancellationReason); ?>"</em>
            <?php endif; ?>
        </p>
    </div>
    <?php endif; ?>

    <header class="invoice-header-grid">
        <div class="issuer-details">
            <?php if (!empty($issuer['logoPath']) && file_exists($issuer['logoPath'])): ?>
                <img src="<?php echo htmlspecialchars($issuer['logoPath']); ?>" alt="Logo" class="invoice-logo">
            <?php endif; ?>
            <p>
                <strong><?php echo htmlspecialchars($issuer['companyName'] ?? 'Tu Empresa'); ?></strong><br>
                NIF: <?php echo htmlspecialchars($issuer['nif'] ?? ''); ?><br>
                <?php if (!empty($issuerEmail)): ?>
		            Email: <?php echo htmlspecialchars($issuerEmail); ?><br>
		        <?php endif; ?>
		        <?php if (!empty($issuerIban)): ?>
		            IBAN: <?php echo htmlspecialchars($issuerIban); ?><br>
		        <?php endif; ?>
                <?php echo htmlspecialchars($issuer['address'] ?? ''); ?><br>
                <?php echo htmlspecialchars($issuer['postCode'] ?? ''); ?> <?php echo htmlspecialchars($issuer['town'] ?? ''); ?>, <?php echo htmlspecialchars($issuer['province'] ?? ''); ?>
            </p>
        </div>

        <div class="client-details">
            <strong>Facturar a:</strong>
            <p>
                <strong><?php echo htmlspecialchars((string)$invoice->client->name); ?></strong><br>
                NIF: <?php echo htmlspecialchars((string)$invoice->client->nif); ?><br>
                <?php echo htmlspecialchars((string)$invoice->client->address); ?><br>
                <?php echo htmlspecialchars((string)$invoice->client->postCode); ?> <?php echo htmlspecialchars((string)$invoice->client->town); ?>, <?php echo htmlspecialchars((string)$invoice->client->province); ?>
                (<?php echo htmlspecialchars((string)$invoice->client->countryCode); ?>)
            </p>
        </div>

        <div class="qr-code">
        <?php
          $qrPath = (string)($invoice->verifactu->qrImagePath ?? $invoice->qrImagePath ?? '');
          $qrB64  = (string)(
              $invoice->verifactu->qrCodeB64
              ?? $invoice->verifactu->qrCode
              ?? $invoice->qrCodeB64
              ?? $invoice->qrCode
              ?? ''
          );
          $absTry = ($qrPath !== '' && $qrPath[0] === '/') ? $qrPath : (__DIR__ . '/../' . ltrim($qrPath, '/'));
          if ($qrPath !== '' && file_exists($absTry)) {
            // preferimos el fichero PNG real
            $src = htmlspecialchars($qrPath, ENT_QUOTES, 'UTF-8');
            echo '<img src="' . $src . '" alt="QR">';
          } elseif ($qrB64 !== '') {
            // admite ya sea data:... o base64 “puro”
            if (preg_match('~^data:image/[^;]+;base64,~i', $qrB64)) {
              $src = $qrB64;
            } else {
              $src = 'data:image/png;base64,' . $qrB64;
            }
            echo '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="QR">';
          } else {
            // Fallback: busca en el log verifactu si hay QR para esta factura
            $entry = verifactu_find_entry_for_invoice((string)$invoice->id);
            $logPath = is_array($entry) ? (string)($entry['qrImagePath'] ?? '') : '';
            $logB64  = is_array($entry) ? (string)($entry['qrPngB64']    ?? '') : '';
            $absLog  = ($logPath !== '' && $logPath[0] === '/') ? $logPath : (__DIR__ . '/../' . ltrim($logPath, '/'));

            if ($logPath !== '' && file_exists($absLog)) {
              echo '<img src="' . htmlspecialchars($logPath, ENT_QUOTES, 'UTF-8') . '" alt="QR">';
            } elseif ($logB64 !== '') {
              $src = preg_match('~^data:image/[^;]+;base64,~i', $logB64) ? $logB64 : ('data:image/png;base64,' . $logB64);
              echo '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="QR">';
            } else {
              echo '<div class="qr-missing">QR no disponible</div>';
            }
          }
        ?>
        </div>



    </header>

    <!-- Metadatos (sello diagonal ELIMINADO) -->
    <section class="invoice-meta-data">
        <div><strong>Fecha:</strong> <span><?php echo date('d/m/Y', strtotime((string)$invoice->issueDate)); ?></span></div>

        <?php if (!empty((string)$invoice->faceb2b->registrationCode)): ?>
          <div><strong>NºRegistro FACeB2B:</strong>
            <span><?php echo htmlspecialchars((string)$invoice->faceb2b->registrationCode); ?></span>
          </div>
        <?php endif; ?>

        <div><strong>Concepto:</strong> <span><?php echo htmlspecialchars((string)$invoice->concept); ?></span></div>
        <?php if (!empty((string)$invoice->rectifies)): ?>
            <div><strong>Rectifica a:</strong> <span><?php echo htmlspecialchars((string)$invoice->rectifies); ?></span></div>
        <?php endif; ?>
        <?php if (!empty((string)$invoice->rectificationReason)): ?>
            <div><strong>Motivo:</strong> <span><?php echo htmlspecialchars((string)$invoice->rectificationReason); ?></span></div>
        <?php endif; ?>

        <?php
        // Vencimiento
        $__dueType = (string)($invoice->paymentTerms->dueType ?? '');
        $__dueDate = (string)($invoice->paymentTerms->dueDate ?? '');
        $__label = '';
        if ($__dueType !== '') {
            switch ($__dueType) {
                case 'on_receipt': $__label = 'Pago a la recepción'; break;
                case 'plus60':     $__label = 'Vencimiento a 60 días'; break;
                case 'custom':     $__label = 'Vencimiento elegido'; break;
                default:           $__label = 'Vencimiento';
            }
            $__showDate = ($__dueDate !== '') ? date('d/m/Y', strtotime($__dueDate)) : '';
            echo '<div><strong>' . htmlspecialchars($__label) . ':</strong> <span>' . htmlspecialchars($__showDate) . '</span></div>';
        }
        ?>
    </section>

    <section class="invoice-body">
        <h3>Detalles</h3>
        <table class="table">
            <thead>
                <tr>
                    <th style="width:40%;">Descripción</th>
                    <th style="text-align:center;">Cant.</th>
                    <th style="text-align:right; min-width:6rem;">Precio Base</th>
                    <th style="text-align:right; min-width:6rem;">Total Base</th>
                    <th style="text-align:center;">IVA (%)</th>
                    <th style="text-align:right; min-width:6rem;">Total Línea</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoice->items->item as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$item->description); ?></td>
                    <td style="text-align:center;"><?php echo htmlspecialchars((string)$item->quantity); ?></td>
                    <td style="text-align:right;"><?php echo number_format((float)$item->unitPrice, 2, ',', '.'); ?> €</td>
                    <td style="text-align:right;"><?php echo number_format((float)$item->lineBaseTotal, 2, ',', '.'); ?> €</td>
                    <td style="text-align:center;"><?php echo htmlspecialchars((string)$item->vatRate); ?>%</td>
                    <td style="text-align:right;"><?php echo number_format((float)$item->lineTotal, 2, ',', '.'); ?> €</td>
                </tr>
                <?php endforeach; ?>

                <?php if ($hasSuplidos): ?>
                    <tr class="suplido-separator"><td colspan="6"><strong>SUPLIDOS</strong></td></tr>
                    <?php foreach ($invoice->suplidos->suplido as $suplido): ?>
                        <tr>
                            <td colspan="5"><?php echo htmlspecialchars((string)$suplido->description); ?></td>
                            <td style="text-align:right;"><?php echo number_format((float)$suplido->amount, 2, ',', '.'); ?> €</td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <footer class="invoice-footer-grid">
        <div class="hash-container">
            <?php
              $chain = verifactu_get_chain_context($invoice);
              $hCur  = $chain['currentHash'];
              $hPrev = $chain['prevHash'];
              $idPrev= $chain['prevInvoice'];
            ?>
            <div style="margin-bottom:.5rem;">
              <strong>Hash actual:</strong>
              <?php if ($hCur): ?>
                <code id="vf-hash-code" class="mono" style="word-break:break-all;"><?php echo htmlspecialchars($hCur); ?></code>
                <button id="vf-copy" class="btn btn-small" type="button" style="margin-left:.5rem;">Copiar</button>
              <?php else: ?>
                <em>No disponible</em>
              <?php endif; ?>
            </div>

            <div style="margin-bottom:.25rem;">
              <strong>Hash anterior:</strong>
              <?php if ($hPrev): ?>
                <code class="mono" style="word-break:break-all;"><?php echo htmlspecialchars($hPrev); ?></code>
                <?php if ($idPrev): ?>
                  <small>(Factura anterior: <a href="index.php?page=view_invoice&id=<?php echo urlencode($idPrev); ?>"><?php echo htmlspecialchars($idPrev); ?></a>)</small>
                <?php endif; ?>
              <?php else: ?>
                <em>No disponible</em>
              <?php endif; ?>
            </div>
        </div>

        <div class="totals-details">
            <p><strong>Base Imponible:</strong> <?php echo number_format((float)$invoice->totalBase, 2, ',', '.'); ?> €</p>
            <p><strong>Total IVA:</strong> <?php echo number_format((float)$invoice->totalVatAmount, 2, ',', '.'); ?> €</p>
            <?php if ($totalIrpfAmount != 0): ?>
                <p><strong>Retención IRPF (<?php echo $irpfRate; ?>%):</strong> <?php echo number_format($totalIrpfAmount, 2, ',', '.'); ?> €</p>
            <?php endif; ?>
            <?php if ($hasSuplidos): ?>
                <p><strong>Total Suplidos:</strong> <?php echo number_format((float)$invoice->totalSuplidos, 2, ',', '.'); ?> €</p>
            <?php endif; ?>
            <h3 class="total-amount">Total Factura: <?php echo number_format((float)$invoice->totalAmount, 2, ',', '.'); ?> €</h3>
        </div>
    </footer>
</div>

<style>
.badge{ display:inline-block; font-size:.75rem; padding:.25rem .5rem; border-radius:.4rem; margin-left:.5rem; vertical-align:middle; font-weight: 600; }
.badge-rectificative{ background:#e8f4ff; color:#0b74c4; border:1px solid #b7d8ff; }
.badge-cancelled{ background:#ffe8e8; color:#c00; border:1px solid #ffc2c2; }

/* Banda superior de estado de pagos */
.status-banner{
  margin: .75rem 0 1rem 0;
  padding:.6rem .9rem;
  border-radius:.5rem;
  font-weight:600;
  border:1px solid transparent;
}
.status-pagada{
  background:#e2f0d9; color:#1d643b; border-color:#c7e5b2;
}
.status-rechazada{
  background:#f8d7da; color:#721c24; border-color:#f5c6cb;
}
.status-pendiente-pago{
  background:#fff7e6; color:#7a4b00; border-color:#e8cfa6;
}
.status-pendiente{
  background:#e6f4ff; color:#0b74c4; border-color:#b3dbff;
}
.status-anulada{
  background:#f3f4f6; color:#374151; border-color:#e5e7eb;
}

/* Banner aviso cancelación pendiente */
 

/* Modales (reutilizable) */
.modal{ position:fixed; inset:0; background:rgba(0,0,0,.4); display:flex; align-items:center; justify-content:center; z-index:1000; }
.modal[hidden]{ display:none; }
.modal-content{ background:#fff; border-radius:.5rem; padding:1rem; width:min(520px, 94vw); box-shadow:0 10px 30px rgba(0,0,0,.18); }
.modal-actions{ margin-top:1rem; display:flex; gap:.6rem; justify-content:flex-end; }
.form-control{ width:100%; padding:.45rem .55rem; border:1px solid #d1d5db; border-radius:.4rem; }

.suplido-separator td{ border-top:2px solid #ccc; background:#f9f9f9; padding-top:.5rem!important; padding-bottom:.5rem!important; }
.qr-code img{ display:block; width:120px; height:120px; object-fit:contain; }
.qr-missing{ width:120px; height:120px; display:flex; align-items:center; justify-content:center; background:#f2f2f2; color:#666; font-size:.8rem; border-radius:.5rem; }

/* Overlay “Rectificada” */
.invoice-container { position: relative; }
.rectified-overlay {
  position: absolute; top: 0; left: 0; width: 100%; height: 100%;
  display: flex; justify-content: center; align-items: center;
  overflow: hidden; pointer-events: none; z-index: 10;
}
.rectified-text {
  font-size: clamp(40px, 8vw, 120px); font-weight: bold;
  color: rgba(220, 38, 38, 0.15); border: 10px solid rgba(220, 38, 38, 0.15);
  padding: 1rem 2rem; transform: rotate(-25deg); white-space: nowrap; user-select: none; text-transform: uppercase;
}
.cancellation-notice {
  background-color: #FFFBEB; color: #92400E; border: 1px solid #FDE68A;
  border-radius: 8px; padding: 0.5rem 1.5rem; margin-bottom: 2rem; position: relative; z-index: 1;
}
.cancellation-notice p { margin: 0.75rem 0; }
.cancellation-notice a { color: #92400E; font-weight: bold; text-decoration: underline; }

.hash-container{padding:.75rem 1rem;border:1px solid #e5e7eb;border-radius:.5rem;background:#fafafa;margin-right:1rem}
.hash-container .mono{font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
.signature-panel{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin:1.5rem 0;padding:1rem;border:1px solid #e5e7eb;border-radius:.75rem;background:#f9fafb;flex-wrap:wrap}
.signature-status{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
.signature-actions{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
.autofirma-instructions{flex:1 1 100%;margin-top:.75rem;padding:.75rem 1rem;border:1px dashed #94a3b8;background:#f8fafc;border-radius:.65rem}
.autofirma-instructions ol{margin:.5rem 0 0;padding-left:1.5rem;font-size:.95rem;line-height:1.5}
.autofirma-instructions .muted{margin-top:.65rem;}
.btn-extra-small{padding:.3rem .75rem;font-size:.85rem;border-radius:.4rem;background:#1d4ed8;color:#fff;text-decoration:none;display:inline-block;border:1px solid #1d4ed8;}
.btn-extra-small:hover{opacity:.9;}
</style>

<script src="public/js/autofirma.js"></script>

<script>
// Sincronizar FACeB2B desde vista individual (refresca estado de pago)
(function(){
  const form = document.getElementById('faceb2bSyncFormView');
  const msg  = document.getElementById('faceb2bSyncMsgView');
  const btn  = document.getElementById('btnSyncFaceB2BView');
  if (form) {
    form.addEventListener('submit', async function(e){
      e.preventDefault();
      if (msg) msg.textContent = 'Comprobando FACeB2B...';
      if (btn) btn.disabled = true;
      try{
        const fd = new FormData(form);
        const res = await fetch('index.php', { method:'POST', body: fd, credentials:'same-origin', cache:'no-store' });
        const txt = await res.text();
        let data; try { data = JSON.parse(txt); } catch { throw new Error('Respuesta no válida'); }
        if (!res.ok || !data.success) throw new Error(data.message || 'Error en la sincronización');
        if (msg) msg.textContent = data.added_count > 0 ? `Descargadas ${data.added_count} factura(s) nueva(s).` : 'Sin cambios en FACeB2B';
        setTimeout(()=> location.reload(), 600);
      }catch(err){ if (msg) msg.textContent = String(err.message || err); }
      finally{ if (btn) btn.disabled = false; }
    });
  }
})();

 

</script>

<script>
(function(){
  const form = document.getElementById('faceSyncFormView');
  const msg  = document.getElementById('faceSyncMsgView');
  const btn  = document.getElementById('btnSyncFaceView');
  if (!form) return;
  form.addEventListener('submit', async function(e){
    e.preventDefault();
    if (msg) msg.textContent = 'Consultando FACE...';
    if (btn) btn.disabled = true;
    try{
      const fd = new FormData(form);
      const res = await fetch('index.php', { method:'POST', body: fd, credentials:'same-origin', cache:'no-store' });
      const txt = await res.text();
      let data; try { data = JSON.parse(txt); } catch { throw new Error('Respuesta no válida'); }
      if (!res.ok || !data.success) throw new Error(data.message || 'Error en la sincronización FACE');
      if (msg) msg.textContent = (data.updated_count > 0 ? `Actualizada` : 'Sin cambios');
      setTimeout(()=> location.reload(), 600);
    }catch(err){ if (msg) msg.textContent = String(err.message || err); }
    finally{ if (btn) btn.disabled = false; }
  });
})();
</script>

<script>
(function(){
  const btn = document.getElementById('signature-action-btn');
  if (!btn) return;
  const statusPill = document.getElementById('signature-status-pill');
  const msgEl = document.getElementById('signature-action-msg');
  const downloadEl = document.getElementById('signature-download');
  const container = document.querySelector('.signature-panel');
  const invoiceId = btn.getAttribute('data-invoice-id');
  const statusText = document.getElementById('autofirma-status-text');
  const configuratorLink = document.getElementById('autofirma-configurator-link');

  function setMessage(text, isError) {
    if (!msgEl) return;
    msgEl.textContent = text || '';
    msgEl.style.color = isError ? '#b00020' : '#4b5563';
  }

  function decodeBase64ToString(b64) {
    const binary = atob(b64);
    const len = binary.length;
    const bytes = new Uint8Array(len);
    for (let i = 0; i < len; i++) {
      bytes[i] = binary.charCodeAt(i);
    }
    const decoder = new TextDecoder('utf-8');
    return decoder.decode(bytes);
  }

  function mapError(err) {
    const reason = err && err.reason ? err.reason : 'unknown';
    const fallback = err && err.message ? err.message : null;
    if (globalThis.AutofirmaClient && typeof AutofirmaClient.messageFor === 'function') {
      return AutofirmaClient.messageFor(reason, fallback);
    }
    return fallback || 'No se pudo completar la operación con AutoFirma.';
  }

  async function refreshAvailability() {
    if (!statusText) return;
    if (!globalThis.AutofirmaClient || typeof AutofirmaClient.detect !== 'function') {
      statusText.textContent = 'Cargando integración de AutoFirma…';
      return;
    }
    statusText.textContent = 'Comprobando AutoFirma…';
    try {
      const availability = await AutofirmaClient.detect({ timeout: 2000 });
      const ok = availability && availability.ok;
      if (statusText) {
        statusText.textContent = ok
          ? 'AutoFirma está lista para firmar (protocolo afirma://).'
          : (availability && availability.message ? availability.message : 'El protocolo afirma:// no ha respondido. Abre AutoFirma y reintenta.');
      }
      if (configuratorLink) {
        configuratorLink.style.display = (!ok && availability && availability.reason === 'protocol-unavailable')
          ? 'inline-block'
          : 'none';
      }
    } catch (err) {
      if (statusText) {
        statusText.textContent = err && err.message ? err.message : 'No se pudo comprobar AutoFirma.';
      }
      if (configuratorLink) {
        configuratorLink.style.display = 'inline-block';
      }
    }
  }

  async function signWithAutofirma() {
    if (!invoiceId) return;
    if (!globalThis.AutofirmaClient || typeof AutofirmaClient.signFacturaeXml !== 'function') {
      setMessage('No se pudo cargar el módulo de AutoFirma en esta página.', true);
      return;
    }

    btn.disabled = true;
    setMessage('Preparando factura para firmar...');

    try {
      const params = new URLSearchParams();
      params.set('action', 'get_unsigned_facturae');
      params.set('id', invoiceId);
      const unsignedRes = await fetch('index.php', {
        method: 'POST',
        body: params,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      });
      const unsignedJson = await unsignedRes.json();
      if (!unsignedRes.ok || !unsignedJson.success || !unsignedJson.xml) {
        throw new Error(unsignedJson && unsignedJson.message ? unsignedJson.message : 'No se pudo obtener el XML sin firmar.');
      }

      setMessage('Firma en curso...');
      const xmlString = decodeBase64ToString(unsignedJson.xml);
      const result = await AutofirmaClient.signFacturaeXml(xmlString, invoiceId + '.xsig', { invoiceId });
      const saveJson = result && result.saveResponse ? result.saveResponse : result;

      setMessage('Factura firmada correctamente.');
      if (statusPill) {
        statusPill.classList.remove('pill-wait');
        statusPill.classList.add('pill-ok');
        const signedAt = (saveJson && saveJson.meta && saveJson.meta.signedAt) ? new Date(saveJson.meta.signedAt) : null;
        statusPill.textContent = signedAt ? 'Firmada · ' + signedAt.toLocaleString() : 'Firmada';
      }
      if (downloadEl) {
        const meta = (saveJson && saveJson.meta) || {};
        const linkPath = meta.path || (saveJson ? saveJson.path : '') || '';
        if (linkPath) {
          downloadEl.href = linkPath;
          downloadEl.textContent = 'Descargar Facturae';
          downloadEl.style.display = 'inline-block';
        }
      }
      if (container) {
        container.setAttribute('data-signed', '1');
      }
      btn.textContent = 'Re-firmar con AutoFirma';
      await refreshAvailability();
    } catch (err) {
      const friendly = mapError(err);
      setMessage(friendly, true);
      if (statusText && err && err.reason === 'protocol-unavailable') {
        statusText.textContent = friendly;
      }
      if (configuratorLink && err && err.reason === 'protocol-unavailable') {
        configuratorLink.style.display = 'inline-block';
      }
      if (!err || err.reason !== 'protocol-unavailable') {
        await refreshAvailability();
      }
    } finally {
      btn.disabled = false;
    }
  }

  btn.addEventListener('click', signWithAutofirma);
  refreshAvailability();
})();
</script>

<script>
document.addEventListener('click', function(e){
  const btn = e.target.closest('#vf-copy');
  if (!btn) return;
  const code = document.getElementById('vf-hash-code');
  if (!code) return;
  const text = code.textContent.trim();
  navigator.clipboard.writeText(text).then(()=>{
    btn.textContent = 'Copiado';
    setTimeout(()=> btn.textContent = 'Copiar', 1200);
  });
});
</script>
