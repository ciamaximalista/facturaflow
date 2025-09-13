<?php
// templates/received_view.php
if (empty($rv) || empty($rv['success'])) {
    $msg = $rv['message'] ?? 'No se pudo cargar la factura recibida.';
    $idQ = $_GET['id'] ?? '';
    echo '<div class="card"><h2>Error</h2>';
    echo '<p>' . htmlspecialchars($msg) . '</p>';
    if ($idQ !== '') echo '<p><small>ID: ' . htmlspecialchars($idQ) . '</small></p>';
    // Enlace redundante eliminado: el layout superior ya ofrece navegación contextual
    echo '</div>';
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
$canceledAt = isset($meta['canceledAt']) ? (string)$meta['canceledAt'] : '';

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
    // Corrige ruta absoluta al fichero original: partimos del raíz del proyecto
    // (__DIR__ apunta a templates/, por lo que dirname(__DIR__) es la carpeta raíz).
    $fullFile = realpath(dirname(__DIR__) . '/' . $fileRel);
    if ($fullFile && is_file($fullFile)) {
        $hashes['sha1']   = hash_file('sha1',   $fullFile) ?: '';
        $hashes['sha256'] = hash_file('sha256', $fullFile) ?: '';
        $hashes['sha512'] = hash_file('sha512', $fullFile) ?: '';
        $raw = (string)@file_get_contents($fullFile);
        if ($raw !== '') {
            // Extrae QR de etiquetas dedicadas
            if ($qrString === '' && preg_match('/<(?:QR|QRCode|QRString)>([^<]+)<\/(?:QR|QRCode|QRString)>/i', $raw, $m)) {
                $qrString = trim((string)$m[1]);
            }
            // Extrae QR/Hash de InvoiceAdditionalInformation si viene "Hash: ... QR: ..."
            if (preg_match('/<InvoiceAdditionalInformation>([^<]+)</i', $raw, $mInfo)) {
                $infoTxt = trim((string)$mInfo[1]);
                if ($qrString === '' && preg_match('/QR:\s*([^\r\n]+)/u', $infoTxt, $mQ)) {
                    $qrString = trim($mQ[1]);
                    // Decodifica entidades XML (&amp; -> &)
                    $qrString = html_entity_decode($qrString, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                if (empty($meta['vfHash']) && preg_match('/Hash:\s*([0-9A-Fa-f]{32,64})/u', $infoTxt, $mH)) {
                    $meta['vfHash'] = strtoupper($mH[1]);
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
        <a href="index.php?page=print_received&id=<?php echo urlencode((string)$rid); ?>" class="btn">PDF para imprimir</a>
        <?php if (!empty($rv['fileRel'])): ?>
            <a href="<?php echo htmlspecialchars($rv['fileRel']); ?>" class="btn">Descargar original</a>
        <?php endif; ?>
        <!-- Botonera fuera del card -->
        <button type="button" class="btn btn-success" id="btn-accept" data-id="<?php echo htmlspecialchars($rid); ?>">Aceptar</button>
        <button type="button" class="btn btn-danger"  id="btn-reject" data-id="<?php echo htmlspecialchars($rid); ?>">Rechazar</button>
        <button type="button" class="btn btn-primary" id="btn-paid"   data-id="<?php echo htmlspecialchars($rid); ?>">Marcar pagada</button>
    </div>
</div>

<div class="card invoice-container">
    <header class="invoice-header-grid">
        <div class="issuer-details">
            <p>
                <strong><?php echo htmlspecialchars($seller['name'] ?? ''); ?></strong><br>
                NIF: <?php echo htmlspecialchars($seller['nif'] ?? ''); ?><br>
                <?php
                  $addrPieces = array_filter([
                    $seller['addr'] ?? '',
                    trim(((string)($seller['pc'] ?? '')).' '.((string)($seller['town'] ?? ''))),
                    $seller['prov'] ?? '',
                  ]);
                  if ($addrPieces) echo htmlspecialchars(implode(', ', $addrPieces));
                  if (!empty($seller['cc'])) echo ' ('.htmlspecialchars($seller['cc']).')';
                ?>
            </p>
        </div>
        <div class="client-details">
            <strong>Facturar a:</strong>
            <p>
                <strong><?php echo htmlspecialchars($buyer['name'] ?? ''); ?></strong><br>
                NIF: <?php echo htmlspecialchars($buyer['nif'] ?? ''); ?><br>
                <?php
                  $baddrPieces = array_filter([
                    $buyer['addr'] ?? '',
                    trim(((string)($buyer['pc'] ?? '')).' '.((string)($buyer['town'] ?? ''))),
                    $buyer['prov'] ?? '',
                  ]);
                  if ($baddrPieces) echo htmlspecialchars(implode(', ', $baddrPieces));
                  if (!empty($buyer['cc'])) echo ' ('.htmlspecialchars($buyer['cc']).')';
                ?>
            </p>
        </div>
        <!-- QR de la factura recibido por FACeB2B -->
        <div class="qr-host" style="display:flex;align-items:center;justify-content:center;">
            <?php
              $qrImgB64 = '';
              // Si ya viene desde el parser, úsalo
              if (!empty($meta['qrPngB64'])) {
                  $qrImgB64 = (string)$meta['qrPngB64'];
              }
              // Si no, generamos desde el texto
              if ($qrImgB64 === '' && $qrString !== '') {
                  try {
                      $opts = new \chillerlan\QRCode\QROptions([
                          'eccLevel'   => \chillerlan\QRCode\QRCode::ECC_M,
                          'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
                          'scale'      => 4,
                      ]);
                      $qrPng = (new \chillerlan\QRCode\QRCode($opts))->render($qrString);
                      if (is_string($qrPng) && $qrPng !== '') {
                          if (preg_match('~^data:image/[^;]+;base64,~i', $qrPng)) {
                              $qrImgB64 = substr($qrPng, strpos($qrPng, ',') + 1) ?: '';
                          } else {
                              $qrImgB64 = base64_encode($qrPng);
                          }
                      }
                  } catch (\Throwable $e) { $qrImgB64 = ''; }
              }
              if ($qrImgB64 !== '') {
                  echo '<img alt="QR" style="height:120px; image-rendering:crisp-edges;" src="data:image/png;base64,'.htmlspecialchars($qrImgB64).'" />';
              } else {
                  echo '<div class="qr-missing">QR no disponible</div>';
              }
            ?>
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

            <!-- No mostramos hashes genéricos; basta con VF hash + QR según regulación -->
          </div>
        </div>
        <div class="totals-details">
            <p><strong>Base Imponible:</strong> <?php echo number_format((float)$totals['base'], 2, ',', '.'); ?> €</p>
            <p><strong>Total IVA:</strong> <?php echo number_format((float)$totals['vat'], 2, ',', '.'); ?> €</p>
            <?php if (!empty($totals['reimb'])): ?>
                <p><strong>Suplidos:</strong> <?php echo number_format((float)$totals['reimb'], 2, ',', '.'); ?> €</p>
            <?php endif; ?>
            <?php
              $isPF = !empty($seller['isIndividual']);
              $showIrpf = $isPF && ( (!empty($totals['hasIrpf'])) || (!empty($totals['irpf']) && (float)$totals['irpf'] !== 0.0) );
              if ($showIrpf):
                $irpfPct = isset($totals['irpfRate']) ? (float)$totals['irpfRate'] : 0.0;
            ?>
                <p>
                  <strong>Retenciones IRPF:</strong>
                  <?php echo number_format((float)$totals['irpf'], 2, ',', '.'); ?> €
                  <?php if ($irpfPct > 0): ?>
                    (<?php echo number_format($irpfPct, 2, ',', '.'); ?>%)
                  <?php endif; ?>
                </p>
            <?php endif; ?>
            <h3 class="total-amount">Total Factura: <?php echo number_format((float)$totals['total'], 2, ',', '.'); ?> €</h3>
        </div>
    </footer>
</div>

<!-- Modal Rechazo -->
<div class="modal" id="modal-reject" hidden>
  <div class="modal-content">
    <h3>Rechazar factura</h3>
    <p>Explica el motivo del rechazo (se registrará en el sistema y se notificará a FACeB2B cuando sea posible):</p>
    <textarea id="reject-reason" rows="4" class="form-control" placeholder="Describe el motivo del rechazo" required></textarea>
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
.stamp-anulada         { color:#6b7280; } /* gris */

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
    d.innerHTML = html;
    if (alertBox) alertBox.appendChild(d);
    setTimeout(() => { d.remove(); }, 5000);
  };

  const postStatus = async (statusAction, extra = {}) => {
    const fd = new FormData();
    fd.append('action', 'update_received_status');
    fd.append('id', id || '');
    fd.append('statusAction', statusAction);
    if (extra.reason) fd.append('reason', String(extra.reason));
    if (extra.paymentDate) fd.append('paymentDate', String(extra.paymentDate));
    const res = await fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); } catch(e) { throw new Error('Respuesta no válida'); }
    if (!res.ok || !data.success) {
      throw new Error((data && data.message) ? data.message : 'No se pudo actualizar el estado');
    }
    return data;
  };

  // Aceptar → pasa a "Pendiente de pago" y notifica FACeB2B
  btnAccept?.addEventListener('click', async () => {
    try{
      btnAccept.disabled = true;
      const data = await postStatus('accepted');
      showNotice('alert-success', 'Factura aceptada. ' + (data.notice || ''));
      setStamp('Pendiente de pago');
    }catch(err){
      showNotice('alert-danger', 'Error al aceptar: ' + (err.message || err));
    }finally{
      btnAccept.disabled = false;
    }
  });

  // Abrir/cerrar modal Rechazo
  btnReject?.addEventListener('click', async () => {
    modalReject.removeAttribute('hidden');
    rejectReason?.focus();
  });
  closeReject?.addEventListener('click', () => { modalReject.setAttribute('hidden',''); });
  confirmReject?.addEventListener('click', async () => {
    const reason = (rejectReason?.value || '').trim();
    if (!reason) { rejectReason?.focus(); return; }
    try{
      confirmReject.disabled = true;
      const data = await postStatus('rejected', { reason });
      showNotice('alert-success', 'Factura rechazada. ' + (data.notice || ''));
      setStamp('Rechazada', new Date().toISOString());
      modalReject.setAttribute('hidden','');
    }catch(err){
      showNotice('alert-danger', 'Error al rechazar: ' + (err.message || err));
    }finally{
      confirmReject.disabled = false;
    }
  });

  // Abrir/cerrar modal Pagada
  btnPaid?.addEventListener('click', () => { modalPaid.removeAttribute('hidden'); paidDate.focus(); });
  closePaid?.addEventListener('click', () => { modalPaid.setAttribute('hidden',''); });
  confirmPaid?.addEventListener('click', async () => {
    const ymd = (paidDate?.value || '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(ymd)) { paidDate.focus(); return; }
    try{
      confirmPaid.disabled = true;
      const data = await postStatus('paid', { paymentDate: ymd });
      showNotice('alert-success', 'Factura marcada como pagada. ' + (data.notice || ''));
      setStamp('Pagada', ymd);
      modalPaid.setAttribute('hidden','');
    }catch(err){
      showNotice('alert-danger', 'Error al marcar pagada: ' + (err.message || err));
    }finally{
      confirmPaid.disabled = false;
    }
  });

});
</script>
