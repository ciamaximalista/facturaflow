<?php
/** templates/invoice_list.php */
?>
<h2>Facturas emitidas</h2>

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
        <th style="text-align:center;">Estado de Pagos (FACeB2B)</th>
        <th style="text-align:center;">Ver</th>
        <th style="text-align:center;">AEAT</th>
        <th style="text-align:center;">FACeB2B</th>
        <th style="text-align:center;">FACE</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($invoices)): ?>
      <tr><td colspan="10" class="muted" style="text-align:center; padding:2rem;">No hay facturas todavía.</td></tr>
    <?php else: ?>
      <?php foreach ($invoices as $invoice):
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
          $paidAt     = (string)($fb->paymentDate ?? $fb->paidAt ?? ($fb->payment->date ?? ''));
          $acceptedAt = (string)($fb->acceptedAt ?? $fb->acceptanceDate ?? ($fb->payment->acceptedAt ?? ''));
          $rejectedAt = (string)($fb->rejectedAt ?? $fb->rejectionDate ?? '');
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
          // Enviada a FACeB2B pero sin más feedback → “Pendiente”
          $payStatus = 'Pendiente';
        } // si no está enviada ni hay datos, se deja en blanco

        $badgeClass = '';
        if ($payStatus !== '') {
          switch (mb_strtolower($payStatus, 'UTF-8')) {
            case 'rechazada':         $badgeClass = 'badge-rechazada';  break;
            case 'pagada':            $badgeClass = 'badge-pagada';     break;
            case 'pendiente de pago': $badgeClass = 'badge-pend-pago';  break;
            default:                  $badgeClass = 'badge-pendiente';  break; // Pendiente
          }
        }
        
        // Etiqueta visible con fecha cuando aplique
	$payLabel = $payStatus;
	if ($payStatus === 'Pagada' && $paidAt !== '') {
	  $payLabel .= ' ' . substr($paidAt, 0, 10);
	} elseif ($payStatus === 'Rechazada' && $rejectedAt !== '') {
	  $payLabel .= ' ' . substr($rejectedAt, 0, 10);
	} elseif ($payStatus === 'Pendiente de pago' && $acceptedAt !== '') {
	  $payLabel .= ' ' . substr($acceptedAt, 0, 10);
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
        </td>

        <td><?= htmlspecialchars($clientName) ?></td>
        <td><?= htmlspecialchars((string)($invoice->concept ?? '')) ?></td>
        <td><?= date('d/m/Y', strtotime((string)$invoice->issueDate)) ?></td>
        <td style="text-align:right;"><?= number_format((float)($invoice->totalAmount ?? 0), 2, ',', '.') ?> €</td>

        <!-- Estado de pagos FACeB2B -->
        <td style="text-align:center;">
          <?php if ($payStatus !== ''): ?>
            <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($payLabel) ?></span>
          <?php else: ?>
            &nbsp; <!-- sin estado -->
          <?php endif; ?>
        </td>

        <td style="text-align:center;">
          <a class="btn" style="font-size:24px;" href="index.php?page=view_invoice&id=<?= urlencode($id) ?>">👁</a>
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
                <button type="button" class="btn btn-small faceb2b-retry" data-id="<?= htmlspecialchars($invoiceId) ?>">Enviar</button>
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
          $faceStatus   = isset($invoice->face->status) ? strtolower((string)$invoice->face->status) : '';
          $faceRegNum   = trim((string)($invoice->face->registerNumber ?? ''));
          $faceFlagPath = $invoiceId ? ($ROOT . '/data/face/sent/' . basename($invoiceId) . '.flag') : '';
          $faceSent     = ($faceStatus === 'sent') || ($faceRegNum !== '') || ($faceFlagPath && is_file($faceFlagPath));
        ?>
        <td class="text-center">
          <?php if (!$hasDir3): ?>
            &nbsp;
          <?php else: ?>
            <?php if ($faceSent): ?>
              <span class="pill pill-ok" title="<?= htmlspecialchars($faceRegNum ?: 'Enviado a FACE') ?>">
                Enviado<?= $faceRegNum ? ' · ' . htmlspecialchars($faceRegNum) : '' ?>
              </span>
            <?php else: ?>
              <div style="display:flex; flex-direction:column; align-items:center; gap:.3rem;">
                <span class="pill pill-wait" title="Pendiente de envío a FACE">Pendiente</span>
                <button type="button" class="btn btn-small face-send" data-id="<?= htmlspecialchars($invoiceId) ?>">Enviar</button>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
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
.badge-pendiente { background:#fff3cd; color:#856404; border:1px solid #ffeeba; }
.badge-rechazada { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.badge-pagada    { background:#e2f0d9; color:#1d643b; border:1px solid #c7e5b2; }
.badge-pend-pago { background:#111827; color:#fff;    border:1px solid #111827; }
</style>

<script>
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

