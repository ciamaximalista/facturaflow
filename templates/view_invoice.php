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
$qrPath  = (string)($invoice->verifactu->qrImagePath ?? $invoice->qrImagePath ?? '');
$qrB64   = (string)($invoice->verifactu->qrCodeB64   ?? $invoice->qrCodeB64   ?? $invoice->qrCode ?? '');
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

if ($fb) {
  $rawStatus = strtolower(trim((string)(
    $fb->paymentStatus
      ?? $fb->status
      ?? ($fb->payment->status ?? '')
      ?? ($fb->payment_state ?? '')
      ?? ($fb->paymentState ?? '')
      ?? ($fb->state ?? '')
  )));
  $paidAt     = (string)($fb->paymentDate ?? $fb->paidAt ?? ($fb->payment->date ?? ''));
  $acceptedAt = (string)($fb->acceptedAt ?? $fb->acceptanceDate ?? ($fb->payment->acceptedAt ?? ''));
  $rejectedAt = (string)($fb->rejectedAt ?? $fb->rejectionDate ?? '');
}

$payStatus = '';
if ($paidAt !== '' || in_array($rawStatus, ['paid','pagada','abonada','satisfecha'], true)) {
  $payStatus = 'Pagada';
} elseif ($rejectedAt !== '' || in_array($rawStatus, ['rejected','rechazada'], true)) {
  $payStatus = 'Rechazada';
} elseif ($acceptedAt !== '' || in_array($rawStatus, ['accepted','aceptada','conformada','reconocida','pendiente de pago'], true)) {
  $payStatus = 'Pendiente de pago';
} else {
  $payStatus = 'Pendiente de aprobar';
}

$bannerClass = match (mb_strtolower($payStatus, 'UTF-8')) {
  'rechazada'         => 'status-rechazada',
  'pagada'            => 'status-pagada',
  'pendiente de pago' => 'status-pendiente-pago',
  default             => 'status-pendiente',
};

$bannerText = match (mb_strtolower($payStatus, 'UTF-8')) {
  'rechazada'         => 'Factura rechazada' . ($rejectedAt ? ' · ' . $fmtDate($rejectedAt) : ''),
  'pagada'            => 'Factura pagada'    . ($paidAt     ? ' · ' . $fmtDate($paidAt)     : ''),
  'pendiente de pago' => 'Aceptada; pendiente de pago' . ($acceptedAt ? ' · ' . $fmtDate($acceptedAt) : ''),
  default             => 'Pendiente de aprobación',
};
?>

<div class="invoice-view-header">
    <h2>
        Factura: <?php echo htmlspecialchars((string)$invoice->id); ?>
        <?php if ($isRectificative): ?><span class="badge badge-rectificative">Rectificativa</span><?php endif; ?>
        <?php if ($cancelled): ?><span class="badge badge-cancelled">Rectificada</span><?php endif; ?>
    </h2>
    <div>
        <a href="index.php?page=export_facturae&id=<?php echo urlencode((string)$invoice->id); ?>" class="btn">Exportar Factura-e</a>
        <a href="javascript:window.print()" class="btn">Imprimir</a>
        <?php if (!$cancelled): ?>
            <a href="index.php?page=rectify_prompt&id=<?php echo urlencode((string)$invoice->id); ?>" class="btn btn-danger">Rectificar</a>
        <?php endif; ?>
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
          $qrPath = (string)($invoice->verifactu->qrImagePath ?? '');
          $qrB64  = (string)($invoice->verifactu->qrCodeB64 ?? $invoice->qrCode ?? '');
          if ($qrPath !== '' && file_exists(__DIR__ . '/../' . $qrPath)) {
            // preferimos el fichero PNG real
            $src = htmlspecialchars($qrPath, ENT_QUOTES, 'UTF-8');
            echo '<img src="' . $src . '" alt="QR">';
          } elseif ($qrB64 !== '') {
            // cae al base64 “puro” (¡OJO!: sin prefijo data:)
            $src = 'data:image/png;base64,' . htmlspecialchars($qrB64, ENT_QUOTES, 'UTF-8');
            echo '<img src="' . $src . '" alt="QR">';
          } else {
            echo '<div class="qr-missing">QR no disponible</div>';
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
  background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe;
}
.status-rechazada{
  background:#fef2f2; color:#dc2626; border-color:#fecaca;
}
.status-pendiente-pago{
  background:#fefce8; color:#92400e; border-color:#fde68a;
}
.status-pendiente{
  background:#f3f4f6; color:#111827; border-color:#e5e7eb;
}

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
</style>

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

