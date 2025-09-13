<?php
// templates/received_list.php
// Espera $received = (new ReceivedManager())->listAll();
?>
<div class="page-header">

  <div class="toolbar" style="margin: 12px 0;">
    <form id="faceb2bSyncForm" method="POST" action="index.php" style="display:inline;">
      <input type="hidden" name="action" value="sync_faceb2b">
      <button id="btnSyncFaceB2B" class="btn btn-primary" type="submit">🔄 Sincronizar FACeB2B</button>
    </form>

    <span id="faceb2bSyncMsg" style="margin-left:10px;"></span>
  </div>

  <div class="right">
    <form id="upload-form" class="inline" method="post" enctype="multipart/form-data" action="index.php">
      <input type="hidden" name="action" value="upload_received">
      <label class="file">
        <input type="file" name="receivedFile" required>
      </label>
      <button class="btn btn-primary" type="submit">Agregar factura recibida por otros medios</button>
    </form>
  </div>
</div>

<div class="card">
  <?php
    // Paginación: 16 elementos por página
    $perPage = 16;
    $pageParam = 'p';
    $currPage = max(1, (int)($_GET[$pageParam] ?? 1));
    $all = is_array($received) ? $received : (is_iterable($received) ? iterator_to_array($received) : []);
    $all = array_values($all);
    $total = count($all);
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($currPage > $totalPages) { $currPage = $totalPages; }
    $pageItems = array_slice($all, ($currPage - 1) * $perPage, $perPage);
  ?>
  <?php if (empty($pageItems)): ?>
    <p>No hay facturas recibidas aún.</p>
  <?php else: ?>
    <table class="table rx-list">
      <thead>
        <tr>
          <th style="text-align:left;">F. subida</th>
          <th style="text-align:left;">Serie-Número</th>
          <th style="text-align:left;">Emisor</th>
          <th style="text-align:left;">Concepto</th>
          <th style="text-align:right;">Importe</th>
          <th style="text-align:center;">Estado</th>
          <th style="text-align:center;">Ver</th>
        </tr>
      </thead>
	<tbody>
	<?php foreach ($pageItems as $r):
	  $id        = (string)($r['id'] ?? '');
	  $serie     = (string)($r['series'] ?? '');
	  $num       = (string)($r['invoiceNumber'] ?? '');
	  $seller    = (string)($r['sellerName'] ?? '');   // ← nombre emisor
	  $sellerNif = (string)($r['sellerNif'] ?? '');    // ← NIF emisor
	  $concept   = (string)($r['concept'] ?? '');      // ← concepto (ItemDescription 1ª línea)
	  $amt       = (float)($r['totalAmount'] ?? 0);
	  $status    = (string)($r['status'] ?? 'Pendiente');
	  $statusText = $status;

	  // badge
	  $badge = 'badge-pendiente';
	  switch (mb_strtolower($status, 'UTF-8')) {
	    case 'aceptada':          $badge = 'badge-aceptada';   break;
	    case 'rechazada':         $badge = 'badge-rechazada';  break;
	    case 'pagada':            $badge = 'badge-pagada';     break;
	    case 'pendiente de pago': $badge = 'badge-pend-pago';  break;
	    case 'anulada':           $badge = 'badge-anulada';    break;
	  }

	  // Etiqueta de estado: cuando no hay aún aceptación/rechazo/pago → "Pdte de aceptación"
	  if (in_array(mb_strtolower($status,'UTF-8'), ['pendiente',''], true)) {
	    $statusText = 'Pdte de aceptación';
	  }

	  // Ocultar estado visual para facturas anuladas
	  if (in_array(mb_strtolower($status,'UTF-8'), ['anulada','cancelada','cancelled','canceled'], true)) {
	    $statusText = '';
	    $badge = '';
	  }

	  // Fecha en formato español dd/mm/YYYY HH:MM
	  $upAtRaw = (string)($r['uploadedAt'] ?? '');
	  $upAt = '';
	  if ($upAtRaw !== '') {
	    $ts = strtotime($upAtRaw);
	    $upAt = $ts ? date('d/m/Y H:i', $ts) : $upAtRaw;
	  }
	?>
	  <tr>
	    <td><small><?= htmlspecialchars($upAt) ?></small></td>
	    <td><strong><?= htmlspecialchars($serie . ($serie && $num ? '-' : '') . $num) ?></strong></td>
	    <td><?= htmlspecialchars(trim(($seller ?: '') . ($sellerNif ? " ({$sellerNif})" : ''))) ?></td>
	    <td><?= htmlspecialchars($concept) ?></td>
	    <td style="text-align:right;"><?= number_format($amt, 2, ',', '.') ?> €</td>
	    <td style="text-align:center;">
	      <?php if ($statusText !== ''): ?>
	        <span class="badge <?= $badge; ?>"><?= htmlspecialchars($statusText) ?></span>
	      <?php else: ?>&nbsp;<?php endif; ?>
	    </td>
	    <td style="text-align:center;">
	      <a style="font-size:22px; text-decoration:none; line-height:1; display:inline-block;" href="index.php?page=received_view&id=<?= urlencode($id) ?>">👁</a>
	    </td>
	  </tr>
	<?php endforeach; ?>
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
          // Construye enlaces numéricos: 1..8 … últimas 4 (si > 12)
          $blocks = [];
          if ($totalPages <= 12) {
            $blocks[] = [1, $totalPages];
            $useDots = false;
          } else {
            $blocks[] = [1, 8];
            $blocks[] = [$totalPages-3, $totalPages];
            $useDots = true;
          }
          $first = true;
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
  <?php endif; ?>
</div>

<style>
.page-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; }
.page-header .right{ display:flex; align-items:center; gap:.5rem; }
.inline{ display:flex; gap:.5rem; align-items:center; }
.file input[type="file"]{ padding:.35rem; border:1px solid #d1d5db; border-radius:.4rem; background:#fff; }

.table{ width:100%; border-collapse:collapse; }
.table th,.table td{ border-bottom:1px solid #e5e7eb; padding:.55rem .6rem; }
.table thead th{ background:#f9fafb; text-align:left; font-weight:600; }

.badge{ display:inline-block; font-size:.75rem; padding:.25rem .6rem; border-radius:.4rem; font-weight:600; }
.badge-pendiente { background:#e6f4ff; color:#0b74c4; border:1px solid #b3dbff; }
.badge-aceptada  { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.badge-rechazada { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.badge-pagada    { background:#e2f0d9; color:#1d643b; border:1px solid #c7e5b2; }
.badge-pend-pago { background:#fff7e6; color:#7a4b00; border:1px solid #e8cfa6; }
.badge-anulada   { background:#f3f4f6; color:#374151; border:1px solid #e5e7eb; }

.btn{ display:inline-block; padding:.4rem .7rem; border-radius:.4rem; background:#e6f4ff; color:#0b74c4; text-decoration:none; border:1px solid #b3dbff; cursor:pointer; }
.btn:hover{ background:#dbeeff; }
.btn-primary{ background:#0b74c4; border-color:#0b74c4; color:#fff; }
.btn-primary:hover{ opacity:.95; }
</style>

<script>
// Subida AJAX opcional (mantiene la acción clásica si falla)
document.getElementById('upload-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.currentTarget);
  try{
    const res = await fetch('index.php', { method:'POST', body:fd, credentials:'same-origin' });
    const raw = await res.text();
    const data = JSON.parse(raw);
    if (!res.ok || !data.success) throw new Error(data.message || 'No se pudo subir el archivo');
    location.reload();
  }catch(err){
    alert(String(err.message || err));
  }
});
</script>
<script>
(function () {
  const form = document.getElementById('faceb2bSyncForm');
  const msg  = document.getElementById('faceb2bSyncMsg');
  const btn  = document.getElementById('btnSyncFaceB2B');
  if (!form) return;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    msg.textContent = 'Comprobando FACeB2B...';
    if (btn) btn.disabled = true;

    try {
      const fd = new FormData(form);
      const res = await fetch('index.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: fd,
        cache: 'no-store'
      });

      const txt = await res.text();
      let data;
      try { data = JSON.parse(txt); }
      catch { throw new Error((txt || 'Respuesta no JSON del servidor').slice(0, 600)); }

      if (data.success) {
        const added = (data.added_ids && data.added_ids.length)
          ? data.added_ids.length
          : (data.added_count || 0);
        msg.textContent = added > 0
          ? `Descargadas ${added} factura(s) nueva(s).`
          : 'No hay facturas nuevas.';
        if (added > 0) setTimeout(() => location.reload(), 600);
      } else {
        msg.textContent = data.message || 'Error en la sincronización.';
      }
    } catch (err) {
      console.error(err);
      msg.textContent = 'Error de red o respuesta inválida.';
    } finally {
      if (btn) btn.disabled = false;
    }
  });
})();
</script>
