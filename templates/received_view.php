<?php
// templates/received_view.php
if (empty($rv) || empty($rv['success'])) {
    $msg = $rv['message'] ?? 'No se pudo cargar la factura recibida.';
    $idQ = $_GET['id'] ?? '';
    echo '<div class="card"><h2>Error</h2>';
    echo '<p>' . htmlspecialchars($msg) . '</p>';
    if ($idQ !== '') echo '<p><small>ID: ' . htmlspecialchars($idQ) . '</small></p>';
    echo '<p><a href="index.php?page=received" class="btn">Volver al listado</a></p></div>';
    return;
}

$h       = $rv['header'] ?? [];
$seller  = $rv['seller'] ?? [];
$buyer   = $rv['buyer'] ?? [];
$lines   = $rv['lines'] ?? [];
$totals  = $rv['totals'] ?? ['base'=>0,'vat'=>0,'irpf'=>0,'total'=>0];
$meta    = $rv['meta'] ?? [];
$status  = $meta['status'] ?? 'Pendiente';

// ID robusto
$rid = $h['id'] ?? ($meta['id'] ?? ($_GET['id'] ?? ''));

// Fechas de estado (para sello)
$acceptedAt = isset($meta['acceptedAt']) ? (string)$meta['acceptedAt'] : '';
$rejectedAt = isset($meta['rejectedAt']) ? (string)$meta['rejectedAt'] : '';
$paymentDate= isset($meta['paymentDate'])? (string)$meta['paymentDate']: '';

// Utilidades
$fmtDate = function(?string $s): string {
    $s = (string)$s;
    if ($s === '') return '';
    // admite 'YYYY-MM-DD' o ISO-8601
    $ts = strtotime($s);
    return $ts ? date('d/m/Y', $ts) : $s;
};

// QR + hashes: de meta, de XML o calculado por fichero
$qrString = (string)($meta['qrString'] ?? $meta['qr'] ?? '');

// Calcular hashes del fichero recibido (seguro y robusto)
$fileRel = (string)($rv['fileRel'] ?? '');
$hashes = ['sha1'=>'','sha256'=>'','sha512'=>''];
if ($fileRel !== '') {
    $fullFile = realpath(dirname(__DIR__) . '/../' . $fileRel);
    if ($fullFile && is_file($fullFile)) {
        $hashes['sha1']   = hash_file('sha1',   $fullFile) ?: '';
        $hashes['sha256'] = hash_file('sha256', $fullFile) ?: '';
        $hashes['sha512'] = hash_file('sha512', $fullFile) ?: '';
        if ($qrString === '') {
            $raw = (string)@file_get_contents($fullFile);
            if ($raw !== '') {
                if (preg_match('/<(?:QR|QRCode|QRString)>([^<]+)<\/(?:QR|QRCode|QRString)>/i', $raw, $m)) {
                    $qrString = trim((string)$m[1]);
                }
            }
        }
    }
}

// Clase badge por estado (se mantiene por compatibilidad visual)
$badgeClass = 'badge-pendiente';
switch (mb_strtolower($status, 'UTF-8')) {
    case 'aceptada':  $badgeClass = 'badge-aceptada';  break;
    case 'rechazada': $badgeClass = 'badge-rechazada'; break;
    case 'pagada':    $badgeClass = 'badge-pagada';    break;
}

// Sello: clase y texto con fecha
$st = trim((string)$status);
$stLower = mb_strtolower($st, 'UTF-8');
$stampClass = match ($stLower) {
  'rechazada'           => 'stamp-rechazada',
  'pagada'              => 'stamp-pagada',
  'pendiente de pago'   => 'stamp-pendiente-pago',
  default               => 'stamp-pendiente',
};

$stampText = match ($stLower) {
  'rechazada'           => 'RECHAZADA' . ($rejectedAt ? ' · ' . $fmtDate($rejectedAt) : ''),
  'pagada'              => 'PAGADA'    . ($paymentDate? ' · ' . $fmtDate($paymentDate): ''),
  'pendiente de pago'   => 'PENDIENTE DE PAGO' . ($acceptedAt ? ' · ' . $fmtDate($acceptedAt) : ''),
  default               => 'PENDIENTE DE ACEPTAR',
};
?>
<div class="invoice-view-header">
    <h2>
        Factura recibida:
        <?php echo htmlspecialchars(($h['series'] ?? '').(($h['series'] ?? '') && ($h['number'] ?? '') ? '-' : '').($h['number'] ?? '')); ?>
        
        
    </h2>
    <div class="actions-right">
        <?php if (!empty($rv['fileRel'])): ?>
            <a href="<?php echo htmlspecialchars($rv['fileRel']); ?>" class="btn">Descargar original</a>
        <?php endif; ?>
        <a href="index.php?page=received" class="btn">Volver al listado</a>
    </div>
</div>

<div class="card invoice-container">
    <header class="invoice-header-grid">
        <div class="issuer-details">
            <p>
                <strong><?php echo htmlspecialchars($seller['name'] ?? ''); ?></strong><br>
                NIF: <?php echo htmlspecialchars($seller['nif'] ?? ''); ?><br>
                <?php if (!empty($seller['addr'])): ?>
                    <?php echo htmlspecialchars($seller['addr']); ?><br>
                    <?php echo htmlspecialchars(($seller['pc'] ?? '').' '.($seller['town'] ?? '')); ?>
                    <?php if (!empty($seller['prov'])): echo ', '.htmlspecialchars($seller['prov']); endif; ?>
                    <?php if (!empty($seller['cc'])): echo ' ('.htmlspecialchars($seller['cc']).')'; endif; ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="client-details">
            <strong>Facturar a:</strong>
            <p>
                <strong><?php echo htmlspecialchars($buyer['name'] ?? ''); ?></strong><br>
                NIF: <?php echo htmlspecialchars($buyer['nif'] ?? ''); ?><br>
                <?php if (!empty($buyer['addr'])): ?>
                    <?php echo htmlspecialchars($buyer['addr']); ?><br>
                    <?php echo htmlspecialchars(($buyer['pc'] ?? '').' '.($buyer['town'] ?? '')); ?>
                    <?php if (!empty($buyer['prov'])): echo ', '.htmlspecialchars($buyer['prov']); endif; ?>
                    <?php if (!empty($buyer['cc'])): echo ' ('.htmlspecialchars($buyer['cc']).')'; endif; ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="action-buttons">
            <button type="button" class="btn btn-success" id="btn-accept" data-id="<?php echo htmlspecialchars($rid); ?>">Aceptar</button>
            <button type="button" class="btn btn-danger"  id="btn-reject" data-id="<?php echo htmlspecialchars($rid); ?>">Rechazar</button>
            <button type="button" class="btn btn-primary" id="btn-paid"   data-id="<?php echo htmlspecialchars($rid); ?>">Marcar pagada</button>
        </div>
    </header>
    
    <section class="invoice-meta-data stamp-host">
      <div><strong>Fecha:</strong> <span><?= htmlspecialchars($h['issueDate'] ?? '') ?></span></div>
      <?php if (!empty($h['concept'])): ?>
        <div><strong>Concepto:</strong> <span><?= htmlspecialchars($h['concept']) ?></span></div>
      <?php endif; ?>

      <!-- Sello diagonal -->
      <div id="status-stamp" class="stamp <?= $stampClass; ?>"><?= htmlspecialchars($stampText) ?></div>
    </section>

    <div id="rx-alerts">
        <?php if ($stLower === 'rechazada' && !empty($meta['rejectionReason'])): ?>
            <div class="alert alert-danger">
                Factura rechazada: <em><?php echo htmlspecialchars($meta['rejectionReason']); ?></em>
            </div>
        <?php elseif ($stLower === 'pagada' && !empty($meta['paymentDate'])): ?>
            <div class="alert alert-success">
                Factura marcada como pagada el <?php echo htmlspecialchars($meta['paymentDate']); ?>.
            </div>
        <?php endif; ?>
    </div>

    <section class="invoice-body">
        <h3>Detalles</h3>
        <?php if (!$lines): ?>
            <div class="alert alert-warning">
                No se han podido extraer las líneas de la factura. Puedes descargar el original.
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:40%;">Descripción</th>
                        <th style="text-align:center;">Cant.</th>
                        <th style="text-align:right; min-width:6rem;">Precio Base</th>
                        <th style="text-align:right; min-width:6rem;">Base</th>
                        <th style="text-align:center;">IVA (%)</th>
                        <th style="text-align:right; min-width:6rem;">Total Línea</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $ln): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)($ln['description'] ?? '')); ?></td>
                        <td style="text-align:center;"><?php echo htmlspecialchars((string)($ln['quantity'] ?? '')); ?></td>
                        <td style="text-align:right;"><?php echo number_format((float)($ln['unitPrice'] ?? 0), 2, ',', '.'); ?> €</td>
                        <td style="text-align:right;"><?php echo number_format((float)($ln['base'] ?? 0), 2, ',', '.'); ?> €</td>
                        <td style="text-align:center;"><?php echo htmlspecialchars((string)($ln['vatRate'] ?? 0)); ?>%</td>
                        <td style="text-align:right;"><?php echo number_format((float)($ln['total'] ?? 0), 2, ',', '.'); ?> €</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <footer class="invoice-footer-grid">
        <div>
          <!-- Panel de verificación: QR + hashes -->
          <div class="verify-box">
            <h4>Verificación</h4>
            <?php if (!empty($meta['qrPngB64'])): ?>
		  <div class="verify-row">
		    <div class="verify-label">QR (imagen):</div>
		    <div class="verify-value">
		      <img src="data:image/png;base64,<?= htmlspecialchars($meta['qrPngB64']) ?>" alt="QR" style="height:120px; image-rendering:crisp-edges;">
		    </div>
		  </div>
		<?php endif; ?>

		<?php if (!empty($meta['vfHash']) || !empty($meta['vfPrev'])): ?>
		  <div class="verify-row">
		    <div class="verify-label">VF hash:</div>
		    <div class="verify-value"><code class="wrap"><?= htmlspecialchars($meta['vfHash'] ?? '') ?></code></div>
		  </div>
		  <?php if (!empty($meta['vfPrev'])): ?>
		  <div class="verify-row">
		    <div class="verify-label">VF prev:</div>
		    <div class="verify-value"><code class="wrap"><?= htmlspecialchars($meta['vfPrev']) ?></code></div>
		  </div>
		  <?php endif; ?>
		<?php endif; ?>

            <?php if ($qrString !== ''): ?>
              <div class="verify-row">
                <div class="verify-label">QR (texto):</div>
                <div class="verify-value"><code class="wrap"><?= htmlspecialchars($qrString) ?></code></div>
              </div>
            <?php endif; ?>
            <div class="verify-row">
              <div class="verify-label">SHA-256:</div>
              <div class="verify-value"><code class="wrap"><?= htmlspecialchars($hashes['sha256']) ?></code></div>
            </div>
            <div class="verify-row">
              <div class="verify-label">SHA-1:</div>
              <div class="verify-value"><code class="wrap"><?= htmlspecialchars($hashes['sha1']) ?></code></div>
            </div>
            <div class="verify-row">
              <div class="verify-label">SHA-512:</div>
              <div class="verify-value"><code class="wrap"><?= htmlspecialchars($hashes['sha512']) ?></code></div>
            </div>
          </div>
        </div>
        <div class="totals-details">
            <p><strong>Base Imponible:</strong> <?php echo number_format((float)$totals['base'], 2, ',', '.'); ?> €</p>
            <p><strong>Total IVA:</strong> <?php echo number_format((float)$totals['vat'], 2, ',', '.'); ?> €</p>
            <?php if (isset($totals['reimbursables'])): ?>
                <p><strong>Suplidos:</strong> <?php echo number_format((float)$totals['reimbursables'], 2, ',', '.'); ?> €</p>
            <?php endif; ?>
            <?php if (!empty($totals['irpf'])): ?>
                <p><strong>Retenciones:</strong> <?php echo number_format((float)$totals['irpf'], 2, ',', '.'); ?> €</p>
            <?php endif; ?>
            <h3 class="total-amount">Total Factura: <?php echo number_format((float)$totals['total'], 2, ',', '.'); ?> €</h3>
        </div>
    </footer>
</div>

<!-- Modal Rechazo -->
<div class="modal" id="modal-reject" hidden>
  <div class="modal-content">
    <h3>Rechazar factura</h3>
    <p>Indica el motivo del rechazo:</p>
    <textarea id="reject-reason" rows="4" class="form-control" placeholder="Motivo de rechazo" required></textarea>
    <div class="modal-actions">
      <button class="btn btn-danger" id="confirm-reject">Rechazar</button>
      <button class="btn" id="close-reject">Cancelar</button>
    </div>
  </div>
</div>

<!-- Modal Pagada -->
<div class="modal" id="modal-paid" hidden>
  <div class="modal-content">
    <h3>Marcar como pagada</h3>
    <p>Fecha de pago:</p>
    <input type="date" id="paid-date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
    <div class="modal-actions">
      <button class="btn btn-primary" id="confirm-paid">Guardar</button>
      <button class="btn" id="close-paid">Cancelar</button>
    </div>
  </div>
</div>

<style>
.invoice-view-header{ display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; }
.actions-right .btn{ margin-left:.5rem; }
.badge{ display:inline-block; font-size:.75rem; padding:.25rem .6rem; border-radius:.4rem; margin-left:.5rem; font-weight:600; }
.badge-pendiente { background:#fff3cd; color:#856404; border:1px solid #ffeeba; }
.badge-aceptada  { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.badge-rechazada { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.badge-pagada    { background:#e2f0d9; color:#1d643b; border:1px solid #c7e5b2; }

.alert { padding:.8rem 1rem; border-radius:.4rem; margin:.8rem 0; }
.alert-danger { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.alert-success{ background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.alert-warning{ background:#fff3cd; color:#856404; border:1px solid #ffeeba; }

.invoice-header-grid {
    display:grid;
    grid-template-columns:1fr 1fr auto;
    gap:2rem;
    margin-bottom:1rem;
    align-items:start;
}
.invoice-meta-data{ display:flex; gap:2rem; margin:1rem 0; }
.table{ width:100%; border-collapse:collapse; }
.table th,.table td{ border-bottom:1px solid #e5e7eb; padding:.5rem .6rem; }
.totals-details{ text-align:right; background:#f9fafb; padding:1rem; border-radius:.5rem; }
.total-amount{ margin-top:.5rem; font-size:1.2rem; color:#0b74c4; }

/* Botones básicos */
.btn{ display:inline-block; padding:.45rem .8rem; border-radius:.4rem; background:#e5e7eb; color:#111827; text-decoration:none; border:1px solid #d1d5db; cursor:pointer; }
.btn:hover{ background:#dfe3e7; }
.btn-primary{ background:#0b74c4; border-color:#0b74c4; color:#fff; }
.btn-primary:hover{ opacity:.95; }
.btn-danger{ background:#dc2626; border-color:#dc2626; color:#fff; }
.btn-danger:hover{ opacity:.95; }
.btn-success{ background:#16a34a; border-color:#16a34a; color:#fff; }
.btn-success:hover{ opacity:.95; }

/* Contenedor para posicionar el sello */
.stamp-host { position: relative; }

/* Sello diagonal */
.stamp{
  position:absolute;
  top:-6px;
  right:0;
  transform: rotate(16deg);
  transform-origin:center;
  font-weight:800;
  font-size:4em;
  letter-spacing:.05em;
  text-transform:uppercase;
  white-space:nowrap;
  padding:.35rem .75rem;
  border:3px solid currentColor;
  border-radius:.25rem;
  opacity:.33;
  pointer-events:none;
  user-select:none;
}

/* Colores por estado */
.stamp-pendiente       { color:#db2777; } /* rosa */
.stamp-pendiente-pago  { color:#111827; } /* negro */
.stamp-rechazada       { color:#dc2626; } /* rojo */
.stamp-pagada          { color:#2563eb; } /* azul */

/* Verificación */
.verify-box{ background:#f9fafb; border:1px solid #e5e7eb; border-radius:.5rem; padding:.8rem 1rem; }
.verify-box h4{ margin:.2rem 0 .6rem; }
.verify-row{ display:flex; gap:.6rem; align-items:flex-start; margin:.25rem 0; }
.verify-label{ min-width:6.5rem; color:#374151; font-weight:600; }
.verify-value{ flex:1; }
.wrap{ word-break:break-all; white-space:pre-wrap; }

/* Modales */
.modal{ position:fixed; inset:0; background:rgba(0,0,0,.4); display:flex; align-items:center; justify-content:center; z-index:1000; }
.modal[hidden]{ display:none; }
.modal-content{ background:#fff; border-radius:.5rem; padding:1rem; width:min(520px, 94vw); box-shadow:0 10px 30px rgba(0,0,0,.18); }
.modal-actions{ margin-top:1rem; display:flex; gap:.6rem; justify-content:flex-end; }
.form-control{ width:100%; padding:.45rem .55rem; border:1px solid #d1d5db; border-radius:.4rem; }
.action-buttons .btn{ display:block; width:100%; margin-bottom:.4rem; text-align:center; }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const id = <?php echo json_encode($rid); ?>;
  const alertBox = document.getElementById('rx-alerts');

  const btnAccept = document.getElementById('btn-accept');
  const btnReject = document.getElementById('btn-reject');
  const btnPaid   = document.getElementById('btn-paid');

  const modalReject = document.getElementById('modal-reject');
  const modalPaid   = document.getElementById('modal-paid');
  const rejectReason = document.getElementById('reject-reason');
  const paidDate     = document.getElementById('paid-date');

  const closeReject = document.getElementById('close-reject');
  const closePaid   = document.getElementById('close-paid');
  const confirmReject = document.getElementById('confirm-reject');
  const confirmPaid   = document.getElementById('confirm-paid');

  const stamp = document.getElementById('status-stamp');

  // Actualiza sello con estado + fecha (si procede)
  const setStamp = (status, ymd) => {
    const s = (status || '').toLowerCase();
    const cls =
      s === 'rechazada'          ? 'stamp-rechazada' :
      s === 'pagada'             ? 'stamp-pagada' :
      s === 'pendiente de pago'  ? 'stamp-pendiente-pago' :
                                   'stamp-pendiente';
    let label = '';
    const pretty = (d)=> {
      if (!d) return '';
      if (/^\d{4}-\d{2}-\d{2}$/.test(d)) {
        const [Y,M,D] = d.split('-'); return `${D}/${M}/${Y}`;
      }
      const dt = new Date(d); if (!isNaN(dt)) return dt.toLocaleDateString('es-ES');
      return d;
    };
    if (s === 'rechazada')      label = 'RECHAZADA' + (ymd ? ' · ' + pretty(ymd) : '');
    else if (s === 'pagada')    label = 'PAGADA'    + (ymd ? ' · ' + pretty(ymd) : '');
    else if (s === 'pendiente de pago')
                               label = 'PENDIENTE DE PAGO' + (ymd ? ' · ' + pretty(ymd) : '');
    else                        label = 'PENDIENTE DE ACEPTAR';

    stamp.textContent = label;
    stamp.className = 'stamp ' + cls;
  };

  const showNotice = (cls, html) => {
    const d = document.createElement('div');
    d.className = 'alert ' + cls;
    d.innerHTML

