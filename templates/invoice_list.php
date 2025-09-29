<?php
/** templates/invoice_list.php */
// Paginación: 16 elementos por página
$perPage = 16;
$pageParam = 'p';
$currPage = max(1, (int)($_GET[$pageParam] ?? 1));
$all = is_array($invoices) ? $invoices : (is_iterable($invoices) ? iterator_to_array($invoices) : []);
$all = array_values($all);
$total = count($all);
$totalPages = max(1, (int)ceil($total / $perPage));
if ($currPage > $totalPages) { $currPage = $totalPages; }
$pageItems = array_slice($all, ($currPage - 1) * $perPage, $perPage);
?>
<!-- Título removido por solicitud -->

<div class="toolbar" style="margin: 12px 0; display:flex; align-items:center; gap:.5rem;">
  <form id="faceb2bSyncFormInv" method="POST" action="index.php" style="display:inline;">
    <input type="hidden" name="action" value="sync_faceb2b">
    <button id="btnSyncFaceB2BInv" class="btn btn-primary" type="submit">🔄 Sincronizar FACeB2B</button>
  </form>
  <span id="faceb2bSyncMsgInv" style="margin-left:10px;"></span>
  <form id="faceSyncFormInv" method="POST" action="index.php" style="display:inline; margin-left:.5rem;">
    <input type="hidden" name="action" value="sync_face">
    <button id="btnSyncFaceInv" class="btn btn-primary" type="submit">🔄 Sincronizar FACE</button>
  </form>
  <span id="faceSyncMsgInv" style="margin-left:10px;"></span>
  <span class="muted" style="margin-left:auto; font-size:.9rem;">Consejo: sincroniza para refrescar estados</span>
</div>

<div class="card" style="margin-bottom: 1.5rem; padding: 1rem 1.5rem;">
  <form method="get" id="sort-form" style="display:flex; align-items:center; gap:1rem;">
    <input type="hidden" name="page" value="invoices">
    <label for="sort-select" style="font-weight:500;">Ordenar por:</label>
    <select name="sort" id="sort-select" class="form-control" onchange="this.form.submit()" style="width:auto;">
      <option value="series" <?= ($sort==='series'?'selected':'') ?>>Serie</option>
      <option value="date"   <?= ($sort==='date'  ?'selected':'') ?>>Fecha</option>
    </select>
    <noscript><button type="submit" class="btn">Ordenar</button></noscript>
  </form>
</div>



<div class="card">
  <table class="table">
    <thead>
      <tr>
        <th>Nº</th>
        <th>Cliente</th>
        <th>Concepto</th>
        <th>Fecha</th>
        <th style="text-align:right; min-width:8rem;">Importe</th>
        <th style="text-align:center;">Estado de Pagos</th>
        <th style="text-align:center;">Firma</th>
        <th style="text-align:center;">Ver</th>
        <th style="text-align:center;">AEAT</th>
        <th style="text-align:center;">FACeB2B</th>
        <th style="text-align:center;">FACE</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($pageItems)): ?>
      <tr><td colspan="11" class="muted" style="text-align:center; padding:2rem;">No hay facturas todavía.</td></tr>
    <?php else: ?>
      <?php foreach ($pageItems as $invoice):
        $id               = (string)($invoice->id ?? '');
        $isCancelled      = (string)($invoice->isCancelled ?? 'false') === 'true';
        $isRectificative  = (string)($invoice->isRectificative ?? 'false') === 'true';
        $rowClass         = $isCancelled ? 'row-cancelled' : '';
        $clientName       = (string)($invoice->client->name ?? '');

        // === FACeB2B: ¿está enviada? (para poder mostrar "Pendiente" como mínimo) ===
        $ROOT = dirname(__DIR__);
        $invoiceId = $id;
        $clientId  = (string)($invoice->client->id ?? '');
        $dire = '';
        if ($clientId !== '') {
          $clientFile = $ROOT . '/data/clients/' . $clientId . '.xml';
          if (is_file($clientFile)) {
            $cx = @simplexml_load_file($clientFile);
            if ($cx && isset($cx->dire)) $dire = trim((string)$cx->dire);
          }
        }
        $sentFlagPath  = $invoiceId ? ($ROOT . '/data/faceb2b/sent/' . basename($invoiceId) . '.flag') : '';
        $faceb2bNode   = isset($invoice->faceb2b) ? $invoice->faceb2b : null;
        $regFromNode   = '';
        if ($faceb2bNode) {
          $regFromNode = (string)(
            ($faceb2bNode->registrationCode ?? '') !== '' ? $faceb2bNode->registrationCode :
            (($faceb2bNode->registryNumber  ?? '') !== '' ? $faceb2bNode->registryNumber  :
            (($faceb2bNode->registerNumber  ?? '') !== '' ? $faceb2bNode->registerNumber  : ''))
          );
        }
        $statusFromNode = $faceb2bNode ? (string)($faceb2bNode->status ?? '') : '';
        $regFallback    = (string)($invoice->faceb2bCode ?? $invoice->faceb2b_registrationCode ?? '');
        $alreadySent    =
          ($sentFlagPath && is_file($sentFlagPath)) ||
          ($statusFromNode === 'sent') ||
          ($regFromNode !== '') ||
          ($regFallback !== '');

        // === Estado de pagos (robusto): lee varias rutas/alias ===
        $fb = $faceb2bNode;
        $rawStatus = '';
        $paidAt    = '';
        $acceptedAt= '';
        $rejectedAt= '';
        $canceledAt= '';
        $rejectReason = '';

        if ($fb) {
          // status textual
          $rawStatus = strtolower(trim((string)(
            $fb->paymentStatus
              ?? $fb->status
              ?? ($fb->payment->status ?? '')
              ?? ($fb->payment_state ?? '')
              ?? ($fb->paymentState ?? '')
              ?? ($fb->state ?? '')
          )));

          // fechas clave
          $paidAt       = (string)($fb->paymentDate ?? $fb->paidAt ?? ($fb->payment->date ?? ''));
          $acceptedAt   = (string)($fb->acceptedAt ?? $fb->acceptanceDate ?? ($fb->payment->acceptedAt ?? ''));
          $rejectedAt   = (string)($fb->rejectedAt ?? $fb->rejectionDate ?? '');
          $canceledAt   = (string)($fb->canceledAt ?? $fb->cancellationDate ?? '');
          $rejectReason = (string)($fb->rejectionReason ?? $fb->rejectReason ?? '');
        }

        // Normalización → etiqueta visible
        $payStatus = '';
        $s = $rawStatus;
        if ($paidAt !== '' || in_array($s, ['paid','pagada','abonada','satisfecha'], true)) {
          $payStatus = 'Pagada';
        } elseif ($rejectedAt !== '' || in_array($s, ['rejected','rechazada'], true)) {
          $payStatus = 'Rechazada';
        } elseif ($acceptedAt !== '' || in_array($s, ['accepted','aceptada','conformada','reconocida','pendiente de pago'], true)) {
          $payStatus = 'Pendiente de pago';
        } elseif ($alreadySent) {
          // Enviada a FACeB2B pero sin reacción del buyer → “Pendiente de aceptación”
          $payStatus = 'Pendiente de aceptación';
        } // si no está enviada ni hay datos, se deja en blanco

        $badgeClass = '';
        if ($payStatus !== '') {
          switch (mb_strtolower($payStatus, 'UTF-8')) {
            case 'rechazada':         $badgeClass = 'badge-rechazada';  break;
            case 'pagada':            $badgeClass = 'badge-pagada';     break;
            case 'pendiente de pago': $badgeClass = 'badge-pend-pago';  break;
            case 'anulada':           $badgeClass = 'badge-anulada';    break;
            default:                  $badgeClass = 'badge-pendiente';  break; // Pendiente
          }
        }
        
        // Etiqueta visible con fecha cuando aplique (formato español dd/mm/YYYY)
        $fmtDate = function(?string $s): string {
          $s = (string)$s; if ($s === '') return '';
          $ts = strtotime($s); return $ts ? date('d/m/Y', $ts) : $s;
        };
	$payLabel = $payStatus;
	if ($payStatus === 'Pagada' && $paidAt !== '') {
	  $payLabel .= ' ' . $fmtDate($paidAt);
	} elseif ($payStatus === 'Rechazada' && $rejectedAt !== '') {
	  $payLabel .= ' ' . $fmtDate($rejectedAt);
	} elseif ($payStatus === 'Pendiente de pago' && $acceptedAt !== '') {
	  $payLabel .= ' ' . $fmtDate($acceptedAt);
	} elseif ($payStatus === 'Anulada' && $canceledAt !== '') {
	  $payLabel .= ' ' . $fmtDate($canceledAt);
	}

        
      ?>
      <tr class="<?= $rowClass ?>">
        <td>
          <?php if ($isCancelled): ?>
            <span style="font-family:sans-serif;">🔴</span>
            <a class="link-invoice" href="index.php?page=view_invoice&id=<?= urlencode($id) ?>"><?= htmlspecialchars($id) ?></a>
            <div class="muted" style="font-size:.8em; margin-top:.25rem;">
              Rectificada por:
              <?php
                $ids = [];
                if (isset($invoice->rectificativeId)) {
                  foreach ($invoice->rectificativeId as $node) {
                    $val = trim((string)$node);
                    if ($val !== '') $ids[] = $val;
                  }
                  $ids = array_values(array_unique($ids));
                }
                if (!empty($ids)) {
                  $rectLinks = [];
                  foreach ($ids as $rid) {
                    $url = 'index.php?page=view_invoice&id=' . urlencode($rid);
                    $rectLinks[] = '<a href="'.$url.'">'.htmlspecialchars($rid).'</a>';
                  }
                  echo implode(', ', $rectLinks);
                } else {
                  echo '—';
                }
              ?>
            </div>
          <?php else: ?>
            <a class="link-invoice" href="index.php?page=view_invoice&id=<?= urlencode($id) ?>"><?= htmlspecialchars($id) ?></a>
            <?php if ($isRectificative): ?><span class="tag tag-rectificative">R</span><?php endif; ?>
          <?php endif; ?>
          <?php /* Indicador de cancelación eliminado */ ?>
        </td>

        <td><?= htmlspecialchars($clientName) ?></td>
        <td><?= htmlspecialchars((string)($invoice->concept ?? '')) ?></td>
        <td><?= date('d/m/Y', strtotime((string)$invoice->issueDate)) ?></td>
        <td style="text-align:right;"><?= number_format((float)($invoice->totalAmount ?? 0), 2, ',', '.') ?> €</td>

        <?php $signedInfo = $signedMap[$id] ?? null; $isSigned = $signedInfo !== null; ?>

        <!-- Estado de pagos FACeB2B -->
        <td style="text-align:center;">
          <?php if ($payStatus !== ''): ?>
            <?php $title = $payStatus === 'Rechazada' && $rejectReason !== '' ? ('Motivo: ' . $rejectReason) : ''; ?>
            <span class="badge <?= $badgeClass ?>" title="<?= htmlspecialchars($title) ?>"><?= htmlspecialchars($payLabel) ?></span>
          <?php else: ?>
            &nbsp; <!-- sin estado -->
          <?php endif; ?>
        </td>

        <!-- Firma -->
        <td style="text-align:center;">
          <?php if ($isSigned): ?>
            <?php $signedAt = !empty($signedInfo['signedAt']) ? date('d/m/Y H:i', strtotime($signedInfo['signedAt'])) : null; ?>
            <span class="pill pill-ok" title="<?= htmlspecialchars($signedAt ? ('Firmada el '.$signedAt) : 'Firmada con AutoFirma') ?>">Firmada</span>
          <?php else: ?>
            <span class="pill pill-wait" title="Pendiente de firma local">Sin firmar</span>
            <div style="margin-top:.25rem;"><a class="btn btn-small" href="index.php?page=view_invoice&id=<?= urlencode($id) ?>">Firmar</a></div>
          <?php endif; ?>
          <div class="muted" style="margin-top:.35rem; font-size:.8rem;">
            <a href="https://github.com/ciamaximalista/facturaflow#guia-integracion-autofirma" target="_blank" rel="noopener" title="¿Problemas para firmar? Abre la guía de integración y solución de problemas.">¿Problemas para firmar?</a>
          </div>
        </td>

        <td style="text-align:center;">
          <a style="font-size:22px; text-decoration:none; line-height:1; display:inline-block;" href="index.php?page=view_invoice&id=<?= urlencode($id) ?>">👁</a>
        </td>

        <!-- AEAT -->
        <td style="text-align:center;">
        <?php
          $row = $invoice ?? null;
          if (!$row) {
            echo '<span class="pill pill-pending">—</span>';
          } else {
            $status  = isset($row->aeat->status)      ? (string)$row->aeat->status      : 'Pending';
            $msg     = isset($row->aeat->lastMessage) ? (string)$row->aeat->lastMessage : '';
            $receipt = isset($row->aeat->receipt)     ? (string)$row->aeat->receipt     : '';
            $label = match ($status) { 'Success'=>'Correcto', 'Failed'=>'Fallido', default=>'Pendiente' };
            $pillCls = 'pill ' . ($status==='Success' ? 'pill-ok' : ($status==='Failed' ? 'pill-bad' : 'pill-pending'));
            echo '<span class="'.htmlspecialchars($pillCls).'" title="'.htmlspecialchars($msg ?: $label).'">';
            echo htmlspecialchars($label);
            if ($receipt) echo ' · '.htmlspecialchars($receipt);
            echo '</span>';
            if ($status !== 'Success') {
              echo '<br/><button class="btn btn-small aeat-retry" data-id="'.htmlspecialchars((string)$row->id).'">Enviar</button>';
            }
          }
        ?>
        </td>

        <!-- FACeB2B (enviado/pendiente de envío) -->
        <?php
          echo "\n<!-- FF_DBG invoiceId={$invoiceId} dire={$dire} sentFlag=" . ($alreadySent ? '1' : '0') . " -->\n";
        ?>
        <td class="text-center">
          <?php if ($dire === ''): ?>
            &nbsp;
          <?php else: ?>
            <?php if ($alreadySent): ?>
              <span class="pill pill-ok" title="Enviado a FACeB2B">Enviado</span>
            <?php else: ?>
              <div style="display:flex; flex-direction:column; align-items:center; gap:.3rem;">
                <span class="pill pill-wait" title="Pendiente de envío por FACeB2B">Pendiente</span>
                <?php if ($isSigned): ?>
                  <button type="button" class="btn btn-small faceb2b-retry" data-id="<?= htmlspecialchars($invoiceId) ?>">Enviar</button>
                <?php else: ?>
                  <button type="button" class="btn btn-small" disabled title="Firma pendiente">Enviar</button>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </td>

        <!-- FACE -->
        <?php
          $clientFile = $clientId ? ($ROOT . '/data/clients/' . $clientId . '.xml') : '';
          $isPublic   = false; $dir3OC=$dir3OG=$dir3UT='';
          if ($clientFile && is_file($clientFile)) {
            $cx = @simplexml_load_file($clientFile);
            if ($cx) {
              $isPublic = (strtolower((string)($cx->entityType ?? '')) === 'public_admin');
              $dir3OC = trim((string)($cx->dir3OC ?? $cx->dir3_accounting ?? $cx->face_dir3_oc ?? ''));
              $dir3OG = trim((string)($cx->dir3OG ?? $cx->dir3_managing   ?? $cx->face_dir3_og ?? ''));
              $dir3UT = trim((string)($cx->dir3UT ?? $cx->dir3_processing ?? $cx->face_dir3_ut ?? ''));
            }
          }
          $hasDir3 = $isPublic && $dir3OC!=='' && $dir3OG!=='' && $dir3UT!=='';
          $faceStatus   = isset($invoice->face->statusText) ? strtolower((string)$invoice->face->statusText) : (isset($invoice->face->status) ? strtolower((string)$invoice->face->status) : '');
          $faceRegNum   = trim((string)($invoice->face->registerNumber ?? ''));
          $faceFlagPath = $invoiceId ? ($ROOT . '/data/face/sent/' . basename($invoiceId) . '.flag') : '';
          $faceSent     = ($faceStatus === 'sent') || ($faceRegNum !== '') || ($faceFlagPath && is_file($faceFlagPath));
          $faceCode     = trim((string)($invoice->face->statusCode ?? ''));
          $faceName     = trim((string)($invoice->face->statusName ?? ''));
          $faceText     = trim((string)($invoice->face->statusText ?? ''));
        ?>
        <td class="text-center">
          <?php if (!$hasDir3): ?>
            &nbsp;
          <?php else: ?>
            <?php if ($faceSent): ?>
              <?php
                $label = '';
                $cls   = 'pill pill-ok';
                $title = $faceRegNum ?: '';
                $t = mb_strtolower($faceText ?: $faceName, 'UTF-8');
                if ($t !== '') {
                  if (str_contains($t, 'pagada')) { $label = 'Pagada'; $cls='pill pill-ok'; }
                  elseif (str_contains($t, 'rechaz')) { $label='Rechazada'; $cls='pill pill-bad'; }
                  elseif (str_contains($t, 'anulad')) { $label='Anulada'; $cls='pill'; }
                  elseif (str_contains($t, 'trámite') || str_contains($t, 'pago')) { $label='Pendiente de pago'; $cls='pill pill-wait'; }
                  elseif (str_contains($t, 'registr')) { $label='Pendiente de aceptación'; $cls='pill pill-wait'; }
                }
                if ($label === '') { $label = 'Enviado'; }
              ?>
              <span class="<?= htmlspecialchars($cls) ?>" title="<?= htmlspecialchars(($title ? ($label.' · '.$title) : $label)) ?>">
                <?= htmlspecialchars($label) ?><?= $faceRegNum && $label==='Enviado' ? ' · ' . htmlspecialchars($faceRegNum) : '' ?>
              </span>
            <?php else: ?>
              <div style="display:flex; flex-direction:column; align-items:center; gap:.3rem;">
                <span class="pill pill-wait" title="Pendiente de envío a FACE">Pendiente</span>
                <?php if ($isSigned): ?>
                  <button type="button" class="btn btn-small face-send" data-id="<?= htmlspecialchars($invoiceId) ?>">Enviar</button>
                <?php else: ?>
                  <button type="button" class="btn btn-small" disabled title="Firma pendiente">Enviar</button>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  <?php if ($totalPages > 1): ?>
    <div class="pager" style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; margin-top:.5rem;">
      <?php $q = $_GET; ?>
      <span>Mostrando <?= ($total===0?0:(($currPage-1)*$perPage+1)) ?>–<?= min($total, $currPage*$perPage) ?> de <?= $total ?></span>
      <span style="opacity:.6;">·</span>
      <?php if ($currPage > 1): ?>
        <?php $q[$pageParam] = $currPage - 1; ?>
        <a class="btn btn-sm" href="index.php?<?= htmlspecialchars(http_build_query($q)) ?>">« Anterior</a>
      <?php else: ?>
        <span class="btn btn-sm" style="opacity:.5; pointer-events:none;">« Anterior</span>
      <?php endif; ?>

      <?php
        // Números: 1..8 … últimas 4 (si > 12)
        $blocks = [];
        if ($totalPages <= 12) {
          $blocks[] = [1, $totalPages];
          $useDots = false;
        } else {
          $blocks[] = [1, 8];
          $blocks[] = [$totalPages-3, $totalPages];
          $useDots = true;
        }
        foreach ($blocks as $idx => $range) {
          [$a,$b] = $range;
          if ($idx>0 && $useDots) echo '<span style="opacity:.6;">…</span>';
          for ($n=$a; $n<=$b; $n++) {
            $q[$pageParam] = $n;
            if ($n === $currPage) {
              echo '<span class="btn btn-sm" style="pointer-events:none; font-weight:600;">'.(int)$n.'</span>';
            } else {
              echo '<a class="btn btn-sm" href="index.php?'.htmlspecialchars(http_build_query($q)).'">'.(int)$n.'</a>';
            }
          }
        }
      ?>

      <?php if ($currPage < $totalPages): ?>
        <?php $q[$pageParam] = $currPage + 1; ?>
        <a class="btn btn-sm" href="index.php?<?= htmlspecialchars(http_build_query($q)) ?>">Siguiente »</a>
      <?php else: ?>
        <span class="btn btn-sm" style="opacity:.5; pointer-events:none;">Siguiente »</span>
      <?php endif; ?>
      <span style="opacity:.6;">· Página <?= $currPage ?> de <?= $totalPages ?></span>
    </div>
  <?php endif; ?>
</div>

<style>
/* Tabla / filas */
.row-cancelled td{ color:#808080; background:#fafafa; }
.row-cancelled .link-invoice{ text-decoration:line-through; color:#666; }
.tag{ display:inline-block; font-size:.7rem; padding:.1rem .4rem; border-radius:.25rem; margin-left:.35rem; vertical-align:middle; font-weight:bold; }
.tag-rectificative{ background:#e8f4ff; color:#0b74c4; border:1px solid #b7d8ff; }
.muted{ color:#666; font-size:.9rem; }
.table{ width:100%; border-collapse:collapse; }
.table th,.table td{ padding:.75rem; border-bottom:1px solid #eee; text-align:left; vertical-align:middle; }

/* Pills (AEAT / envíos) */
.pill{ display:inline-block; padding:.15rem .45rem; border-radius:999px; font-size:.8rem; line-height:1; }
.pill-ok{ background:#e6ffed; color:#036703; border:1px solid #a6e8b0; }
.pill-bad{ background:#ffecec; color:#8a0e0e; border:1px solid #e8a6a6; }
.pill-pending{ background:#fff7e6; color:#7a4b00; border:1px solid #e8cfa6; margin-bottom:4px;}
.pill-wait{ background:#ffe6f3; color:#7a0050; border:1px solid #f0a6cc; }
.btn-small{ padding:.2rem .5rem; font-size:.82rem; }

/* Badges de estado de pagos */
.badge{ display:inline-block; font-size:.75rem; padding:.25rem .6rem; border-radius:.4rem; font-weight:600; }
.badge-pendiente { background:#e6f4ff; color:#0b74c4; border:1px solid #b3dbff; }
.badge-rechazada { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.badge-pagada    { background:#e2f0d9; color:#1d643b; border:1px solid #c7e5b2; }
.badge-pend-pago { background:#fff7e6; color:#7a4b00; border:1px solid #e8cfa6; }
.badge-anulada   { background:#f3f4f6; color:#374151; border:1px solid #e5e7eb; }
/* Botones base (como en recibidas) */
.btn{ display:inline-block; padding:.4rem .7rem; border-radius:.4rem; background:#e6f4ff; color:#0b74c4; text-decoration:none; border:1px solid #b3dbff; cursor:pointer; }
.btn:hover{ background:#dbeeff; }
</style>

<script>
// Sincronizar FACeB2B desde emitidas (refresca estados de pago)
(function(){
  const form = document.getElementById('faceb2bSyncFormInv');
  const msg  = document.getElementById('faceb2bSyncMsgInv');
  const btn  = document.getElementById('btnSyncFaceB2BInv');
  if (!form) return;
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
})();

// Sincronizar FACE (proveedores)
(function(){
  const form = document.getElementById('faceSyncFormInv');
  const msg  = document.getElementById('faceSyncMsgInv');
  const btn  = document.getElementById('btnSyncFaceInv');
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
      if (msg) msg.textContent = data.updated_count > 0 ? `Actualizadas ${data.updated_count} factura(s).` : 'Sin cambios en FACE';
      setTimeout(()=> location.reload(), 600);
    }catch(err){ if (msg) msg.textContent = String(err.message || err); }
    finally{ if (btn) btn.disabled = false; }
  });
})();
document.addEventListener('click', async (e)=>{
  const btn = e.target.closest('.aeat-retry');
  if (!btn) return;
  e.preventDefault();
  const id = btn.getAttribute('data-id');
  if (!id) return;
  btn.disabled = true;
  try{
    const fd = new FormData();
    fd.set('action','aeat_send');
    fd.set('id', id);
    const res = await fetch('index.php', { method:'POST', body: fd, credentials:'same-origin' });
    const raw = await res.text();
    const json = (res.headers.get('content-type')||'').toLowerCase().includes('application/json') ? JSON.parse(raw) : null;
    if (!res.ok || !json || !json.success) {
      alert((json && json.message) ? json.message : ('Error AEAT: ' + raw.slice(0,200)));
    } else {
      location.reload();
    }
  } catch(err){ alert(String(err.message||err)); }
  finally{ btn.disabled = false; }
});
</script>

<script>
document.addEventListener('click', async (ev)=>{
  const btn = ev.target.closest('.faceb2b-retry');
  if (!btn) return;
  ev.preventDefault();
  const invoiceId = btn.dataset.id;
  if (!invoiceId) return;
  btn.disabled = true;
  const cell = btn.closest('td');
  const pill = cell.querySelector('.pill-wait');
  if (pill) pill.textContent = 'Enviando…';
  try{
    const res = await fetch('index.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ action:'send_faceb2b', invoice_id: invoiceId }),
      credentials:'same-origin'
    });
    const raw = await res.text();
    const json = (res.headers.get('content-type')||'').toLowerCase().includes('application/json') ? JSON.parse(raw) : null;
    if (!json || !json.success){
      if (pill) pill.textContent = 'Pendiente';
      btn.disabled = false;
      alert((json && json.message) ? json.message : ('Error FACeB2B: ' + raw.slice(0,200)));
      return;
    }
    cell.innerHTML = '<span class="pill pill-ok" title="Enviado a FACeB2B">Enviado</span>';
  } catch(e){
    if (pill) pill.textContent = 'Pendiente';
    btn.disabled = false;
    alert('Error al enviar por FACeB2B: ' + (e && e.message ? e.message : e));
  }
});
</script>

<script>
document.addEventListener('click', async (ev)=>{
  const btn = ev.target.closest('.face-send');
  if (!btn) return;
  ev.preventDefault();
  const invoiceId = btn.dataset.id;
  if (!invoiceId) return;
  btn.disabled = true;
  const cell = btn.closest('td');
  const pill = cell.querySelector('.pill-wait');
  if (pill) pill.textContent = 'Enviando…';
  try{
    const res = await fetch('index.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ action:'send_face', invoice_id: invoiceId }),
      credentials:'same-origin'
    });
    const raw = await res.text();
    const json = (res.headers.get('content-type')||'').toLowerCase().includes('application/json') ? JSON.parse(raw) : null;
    if (!json || !json.success){
      if (pill) pill.textContent = 'Pendiente';
      btn.disabled = false;
      alert((json && json.message) ? json.message : ('Error FACE: ' + raw.slice(0,200)));
      return;
    }
    const reg = json.registerNumber ? (' · ' + json.registerNumber) : '';
    cell.innerHTML = '<span class="pill pill-ok" title="Enviado a FACE">Enviado' + reg + '</span>';
  } catch(e){
    if (pill) pill.textContent = 'Pendiente';
    btn.disabled = false;
    alert('Error al enviar a FACE: ' + (e && e.message ? e.message : e));
  }
});
</script>
