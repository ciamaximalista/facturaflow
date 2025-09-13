<?php
require_once __DIR__ . '/../src/InvoiceManager.php';
require_once __DIR__ . '/../src/ReceivedManager.php';
require_once __DIR__ . '/../src/DataManager.php';

$get = fn(string $k, $def = '') => isset($_GET[$k]) ? (string)$_GET[$k] : $def;
function ymd(string $s): string { $t = strtotime($s ?: ''); return $t ? date('Y-m-d', $t) : ''; }
function fmt_eur(float $v): string { return number_format($v, 2, ',', '.'); }

$im = new InvoiceManager();
$rx = new ReceivedManager();
$dmClients = new DataManager('clients');
$dmProducts = new DataManager('products');

$invoices = array_values(array_filter($im->getAllInvoices(), function($inv){
  return !(isset($inv->isCancelled) && strtolower((string)$inv->isCancelled) === 'true');
}));
$received = $rx->listAll();

// Filtros emitidas: cliente + periodo (mes en curso por defecto)
$ep = $get('ep', 'M0');
$es = $get('es', '');
$ee = $get('ee', '');
$ecRaw = $_GET['ec'] ?? [];
$ecSel = array_values(array_filter(is_array($ecRaw) ? $ecRaw : ($ecRaw!=='' ? [$ecRaw] : []), fn($v)=> (string)$v !== ''));
$wantAdmin  = in_array('group:admin',   $ecSel, true);
$wantPriv   = in_array('group:private', $ecSel, true);
$ecIds = array_values(array_filter($ecSel, fn($v)=> strncmp((string)$v,'group:',6) !== 0));

$nowY = (int)date('Y');
[$startE,$endE] = (function($ep,$es,$ee,$nowY){
  $first = fn($y,$m,$d) => sprintf('%04d-%02d-%02d', $y,$m,$d);
  $last  = fn($y,$m,$d) => sprintf('%04d-%02d-%02d', $y,$m,$d);
  switch ($ep) {
    case 'M0': { $y=(int)date('Y'); $m=(int)date('m'); return [$first($y,$m,1), $last($y,$m,(int)date('t'))]; }
    case 'Q1': return [$first($nowY,1,1),  $last($nowY,3,31)];
    case 'Q2': return [$first($nowY,4,1),  $last($nowY,6,30)];
    case 'Q3': return [$first($nowY,7,1),  $last($nowY,9,30)];
    case 'Q4': return [$first($nowY,10,1), $last($nowY,12,31)];
    case 'Y-1':return [$first($nowY-1,1,1),$last($nowY-1,12,31)];
    case 'custom':
      $s = ymd($es); $e = ymd($ee); return [$s?:'', $e?:''];
    case 'Y0':
    default: return [$first($nowY,1,1), $last($nowY,12,31)];
  }
})($ep,$es,$ee,$nowY);

$clients = $dmClients->getAllItems();
$clientOpts = [];
foreach ($clients as $c) {
  $clientOpts[] = [
    'id'    => (string)$c->id,
    'label' => (string)$c->name,
    'type'  => strtolower((string)($c->entityType ?? ''))
  ];
}

$productOpts = [];
foreach ($dmProducts->getAllItems() as $p) {
  $label = (string)($p->description ?? '');
  if ($label !== '') $productOpts[] = ['label'=>$label];
}

$epdRaw = $_GET['epd'] ?? [];
$epdSel = array_values(array_unique(array_filter(is_array($epdRaw) ? $epdRaw : ($epdRaw!=='' ? [$epdRaw] : []), fn($v)=> (string)$v !== '')));

$emitidas = array_values(array_filter($invoices, function($inv) use ($startE,$endE,$ecIds,$wantAdmin,$wantPriv,$epdSel){
  $d = ymd((string)($inv->issueDate ?? ''));
  if ($startE && $d && $d < $startE) return false;
  if ($endE   && $d && $d > $endE)   return false;
  if (!empty($ecIds) || $wantAdmin || $wantPriv) {
    $cliType = strtolower((string)($inv->client->entityType ?? ''));
    $cliId   = (string)($inv->client->id ?? '');
    $ok = false;
    if ($wantAdmin && $cliType === 'public_admin') $ok = true;
    if ($wantPriv  && $cliType !== 'public_admin') $ok = true;
    if (!$ok && !empty($ecIds) && in_array($cliId, $ecIds, true)) $ok = true;
    if (!$ok) return false;
  }
  if (!empty($epdSel)) {
    $have = false;
    if (isset($inv->items->item)) {
      foreach ($inv->items->item as $it) {
        $desc = trim(mb_strtolower((string)$it->description));
        foreach ($epdSel as $p) { if ($desc === trim(mb_strtolower((string)$p))) { $have = true; break; } }
        if ($have) break;
      }
    }
    if (!$have) return false;
  }
  return true;
}));

$totE = ['base'=>0.0,'iva'=>0.0,'irpf'=>0.0,'suplidos'=>0.0,'total'=>0.0];
foreach ($emitidas as $inv) {
  $totE['base']     += (float)($inv->totalBase       ?? 0.0);
  $totE['iva']      += (float)($inv->totalVatAmount  ?? 0.0);
  $totE['irpf']     += (float)($inv->totalIrpfAmount ?? 0.0);
  $totE['suplidos'] += (float)($inv->totalSuplidos   ?? 0.0);
  $totE['total']    += (float)($inv->totalAmount     ?? 0.0);
}

// Filtros recibidas: proveedor (NIF) + periodo (mes en curso por defecto)
$rp = $get('rp', 'M0');
$rsRaw = $_GET['rs'] ?? [];
$rsSel = array_values(array_filter(is_array($rsRaw) ? $rsRaw : ($rsRaw!=='' ? [$rsRaw] : []), fn($v)=> (string)$v !== ''));
$rs_s = $get('rs_s','');
$rs_e = $get('rs_e','');

[$startR,$endR] = (function($rp,$rs_s,$rs_e,$nowY){
  $first = fn($y,$m,$d) => sprintf('%04d-%02d-%02d', $y,$m,$d);
  $last  = fn($y,$m,$d) => sprintf('%04d-%02d-%02d', $y,$m,$d);
  switch ($rp) {
    case 'M0': { $y=(int)date('Y'); $m=(int)date('m'); return [$first($y,$m,1), $last($y,$m,(int)date('t'))]; }
    case 'Q1': return [$first($nowY,1,1),  $last($nowY,3,31)];
    case 'Q2': return [$first($nowY,4,1),  $last($nowY,6,30)];
    case 'Q3': return [$first($nowY,7,1),  $last($nowY,9,30)];
    case 'Q4': return [$first($nowY,10,1), $last($nowY,12,31)];
    case 'Y-1':return [$first($nowY-1,1,1),$last($nowY-1,12,31)];
    case 'custom':
      $s = ymd($rs_s); $e = ymd($rs_e); return [$s?:'', $e?:''];
    case 'Y0':
    default: return [$first($nowY,1,1), $last($nowY,12,31)];
  }
})($rp,$rs_s,$rs_e,$nowY);

// Proveedores (desde caché 5 años, con fallback)
$providers = [];
try {
  if (method_exists($rx, 'getProvidersMap')) {
    $providers = $rx->getProvidersMap();
  }
} catch (\Throwable $e) { $providers = []; }
if (!$providers) {
  foreach ((array)$received as $r) {
    $nif = strtoupper(trim((string)($r['sellerNif'] ?? $r['supplierNif'] ?? '')));
    $nam = (string)($r['sellerName'] ?? $r['supplierName'] ?? '');
    if ($nif !== '') $providers[$nif] = $nam ? ($nam.' ('.$nif.')') : $nif;
  }
}
ksort($providers);

$recibidas = [];
foreach ((array)$received as $r) {
  $iss = ymd((string)($r['issueDate'] ?? $r['uploadedAt'] ?? ''));
  if ($startR && $iss && $iss < $startR) continue;
  if ($endR   && $iss && $iss > $endR)   continue;
  if (!empty($rsSel)) {
    $nif = strtoupper(trim((string)($r['sellerNif'] ?? $r['supplierNif'] ?? '')));
    if ($nif === '' || !in_array($nif, array_map('strtoupper',$rsSel), true)) continue;
  }
  $recibidas[] = $r;
}

$totR = ['base'=>0.0,'iva'=>0.0,'irpf'=>0.0,'total'=>0.0];
foreach ($recibidas as $r) {
  $id = (string)($r['id'] ?? '');
  $sum = false;
  if ($id !== '') {
    try {
      $vd = $rx->getViewDataById($id);
      if (!empty($vd['success'])) {
        $t = (array)($vd['totals'] ?? []);
        $totR['base']  += (float)($t['base'] ?? 0.0);
        $totR['iva']   += (float)($t['vat'] ?? 0.0);
        $totR['irpf']  += (float)($t['irpf'] ?? 0.0);
        $totR['total'] += (float)($t['total'] ?? ($r['totalAmount'] ?? 0.0));
        $sum = true;
      }
    } catch (\Throwable $e) {}
  }
  if (!$sum) $totR['total'] += (float)($r['totalAmount'] ?? 0.0);
}
?>

<!-- Encabezados retirados por solicitud -->

<?php
// -------- Gráfico mensual (base imponible) --------
$cp = $get('cp', 'M12'); // NAT1, NAT2, M12, M24, CUST
$cs = $get('cs', '');    // custom start
$ce = $get('ce', '');    // custom end

$monthStartEnd = function(string $cp, string $cs, string $ce): array {
  $today = new DateTime('today');
  $first = fn($y,$m) => new DateTime(sprintf('%04d-%02d-01', $y, $m));
  $lastM = fn(DateTime $d) => (clone $d)->modify('last day of this month');
  switch ($cp) {
    case 'NAT1': { $y=(int)$today->format('Y')-1; $s=$first($y,1);  $e=$lastM($first($y,12)); return [$s,$e]; }
    case 'NAT2': { $y=(int)$today->format('Y');   $s=$first($y-2,1);$e=$lastM($first($y-1,12)); return [$s,$e]; }
    case 'M24':  { $e=$lastM($today); $s=(clone $e)->modify('-23 months')->modify('first day of this month'); return [$s,$e]; }
    case 'CUST': {
      $sd = ymd($cs) ? new DateTime(ymd($cs)) : new DateTime('first day of this month');
      $ed = ymd($ce) ? new DateTime(ymd($ce)) : new DateTime('last day of this month');
      if ($sd > $ed) { $tmp=$sd; $sd=$ed; $ed=$tmp; }
      $sd = new DateTime($sd->format('Y-m-01'));
      $ed = (new DateTime($ed->format('Y-m-01')))->modify('last day of this month');
      return [$sd,$ed];
    }
    case 'M12':
    default:    { $e=$lastM($today); $s=(clone $e)->modify('-11 months')->modify('first day of this month'); return [$s,$e]; }
  }
};
[$chartStart,$chartEnd] = $monthStartEnd($cp,$cs,$ce);

$months = [];
for ($d = clone $chartStart; $d <= $chartEnd; $d->modify('+1 month')) { $months[] = $d->format('Y-m'); }

$emitBase = array_fill_keys($months, 0.0);
foreach ($invoices as $inv) {
  $d = (string)($inv->issueDate ?? ''); if ($d==='') continue; $ym = date('Y-m', strtotime($d));
  if (!array_key_exists($ym, $emitBase)) continue; $emitBase[$ym] += (float)($inv->totalBase ?? 0.0);
}

$recvBase = array_fill_keys($months, 0.0);
foreach ($received as $r) {
  $d = (string)($r['issueDate'] ?? ($r['uploadedAt'] ?? '')); if ($d==='') continue; $ym = date('Y-m', strtotime($d));
  if (!array_key_exists($ym, $recvBase)) continue; $id=(string)($r['id'] ?? ''); $base=0.0;
  if ($id!=='') { try { $vd=$rx->getViewDataById($id); $t=(array)($vd['totals'] ?? []); $base=(float)($t['base'] ?? 0.0); } catch (\Throwable $e) {} }
  $recvBase[$ym] += $base;
}

$labels = array_map(fn($ym)=>date('m/y', strtotime($ym.'-01')), $months);
$dataE  = array_values($emitBase);
$dataR  = array_values($recvBase);
?>

<div class="card" style="margin-top: .25rem; padding-bottom: .5rem;">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:.75rem; flex-wrap:wrap; margin-bottom:.5rem;">
    <h3 style="margin:0;">Evolución mensual (base imponible)</h3>
    <form method="get" style="margin:0; display:flex; align-items:center; gap:.5rem;">
      <input type="hidden" name="page" value="dashboard">
      <label>Periodo:
        <select name="cp" id="chartPeriod" onchange="toggleCustom('chartPeriod'); this.form.submit()">
          <option value="NAT1" <?php echo $cp==='NAT1'?'selected':''; ?>>Último año natural</option>
          <option value="NAT2" <?php echo $cp==='NAT2'?'selected':''; ?>>Últimos dos años</option>
          <option value="M12"  <?php echo $cp==='M12'?'selected':''; ?>>Últimos 12 meses</option>
          <option value="M24"  <?php echo $cp==='M24'?'selected':''; ?>>Últimos 24 meses</option>
          <option value="CUST" <?php echo $cp==='CUST'?'selected':''; ?>>Periodo a elegir…</option>
        </select>
      </label>
      <span class="period-range" id="chartPeriod-range" style="display: <?php echo ($cp==='CUST'?'inline-flex':'none'); ?>; gap:.25rem;">
        <input type="date" name="cs" value="<?php echo htmlspecialchars($cs); ?>">
        <input type="date" name="ce" value="<?php echo htmlspecialchars($ce); ?>">
        <button class="btn btn-sm" type="submit">Aplicar</button>
      </span>
    </form>
  </div>
  <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap; margin:.25rem 0 .5rem 0;">
    <span><span style="display:inline-block; width:12px; height:12px; background:#1f78ff; margin-right:.35rem; border-radius:2px;"></span>Emitidas (base)</span>
    <span><span style="display:inline-block; width:12px; height:12px; background:#e11d48; margin-right:.35rem; border-radius:2px;"></span>Recibidas (base)</span>
  </div>
  <canvas id="baseChart" height="260" style="width:100%; max-width:100%;"></canvas>
</div>

<script>
(function(){
  const labels = <?php echo json_encode($labels, JSON_UNESCAPED_UNICODE); ?>;
  const dataE  = <?php echo json_encode($dataE); ?>;
  const dataR  = <?php echo json_encode($dataR); ?>;
  const canvas = document.getElementById('baseChart');
  if (!canvas) return; const ctx = canvas.getContext('2d');
  function draw(){
    canvas.width = canvas.clientWidth; const W=canvas.width, H=canvas.height;
    ctx.clearRect(0,0,W,H); const m={l:48,r:12,t:12,b:32}; const plotW=Math.max(10,W-m.l-m.r), plotH=Math.max(10,H-m.t-m.b);
    const n=labels.length; const vmax=Math.max(1, Math.max(...dataE, ...dataR))*1.1;
    const y2px=v=> m.t + plotH * (1 - v / vmax); const x2px=i=> m.l + (n<=1? plotW/2 : plotW * (i/(n-1)));
    // grid + y labels
    ctx.strokeStyle='#e5e7eb'; ctx.lineWidth=1; ctx.font='12px sans-serif'; ctx.fillStyle='#374151'; ctx.textAlign='right'; ctx.textBaseline='middle';
    const yTicks=4; for(let k=0;k<=yTicks;k++){ const v=vmax*k/yTicks, y=y2px(v); ctx.beginPath(); ctx.moveTo(m.l,y); ctx.lineTo(W-m.r,y); ctx.stroke(); ctx.fillText(new Intl.NumberFormat('es-ES',{maximumFractionDigits:0}).format(v), m.l-6, y); }
    // x labels
    ctx.textAlign='center'; ctx.textBaseline='top'; labels.forEach((lab,i)=>{ const x=x2px(i); ctx.fillText(lab, x, m.t+plotH+6); });
    // lines
    function line(arr,color){ ctx.strokeStyle=color; ctx.lineWidth=2; ctx.beginPath(); arr.forEach((v,i)=>{ const x=x2px(i), y=y2px(v); if(i===0) ctx.moveTo(x,y); else ctx.lineTo(x,y); }); ctx.stroke(); ctx.fillStyle=color; arr.forEach((v,i)=>{ const x=x2px(i), y=y2px(v); ctx.beginPath(); ctx.arc(x,y,2.5,0,Math.PI*2); ctx.fill(); }); }
    line(dataE,'#1f78ff'); line(dataR,'#e11d48');
  }
  window.addEventListener('resize', draw, {passive:true}); draw();
})();
</script>

<div class="card" style="margin-top: 1rem;">
  <h3 style="margin:0 0 .75rem 0;">Facturas emitidas</h3>
  <form method="get" style="margin:0;">
    <input type="hidden" name="page" value="dashboard">
    <table>
      <thead>
        <tr>
          <th>Factura</th>
          <th>Cliente
            <div class="th-filter fd-wrap">
              <?php $ecCount = count($ecIds) + ($wantAdmin?1:0) + ($wantPriv?1:0); ?>
              <button type="button" class="fd-button" data-target="#fd-clients">
                <span>Filtrar clientes</span>
                <span class="fd-badge" <?php echo $ecCount? '':'style="display:none;"'; ?>><?php echo $ecCount; ?></span>
                <span class="fd-caret">▾</span>
              </button>
              <div id="fd-clients" class="fd-panel">
                <div class="fd-search">
                  <input type="text" placeholder="Buscar…" data-filter-list="#fd-clients-list">
                </div>
                <div class="fd-section">
                  <label class="fd-check"><input type="checkbox" name="ec[]" value="group:admin" <?php echo $wantAdmin? 'checked':''; ?>> Administraciones</label>
                  <label class="fd-check"><input type="checkbox" name="ec[]" value="group:private" <?php echo $wantPriv? 'checked':''; ?>> Sector Privado</label>
                </div>
                <div class="fd-sep"></div>
                <div id="fd-clients-list" class="fd-list">
                  <?php foreach ($clientOpts as $c): ?>
                    <label class="fd-check" data-text="<?php echo htmlspecialchars(mb_strtolower($c['label'])); ?>">
                      <input type="checkbox" name="ec[]" value="<?php echo htmlspecialchars($c['id']); ?>" <?php echo (in_array((string)$c['id'], $ecIds??[], true)?'checked':''); ?>>
                      <?php echo htmlspecialchars($c['label']); ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="fd-actions">
                  <button type="button" class="btn btn-sm fd-clear" data-clear="ec[]">Limpiar</button>
                  <button class="btn btn-sm" type="submit">Aplicar</button>
                </div>
              </div>
            </div>
          </th>
          <th>Periodo
            <div class="th-filter period">
              <select name="ep" id="ep" onchange="toggleCustom('ep','es','ee'); this.form.submit()">
                <option value="M0"  <?php echo $ep==='M0'?'selected':''; ?>>Mes en curso</option>
                <option value="Q1"  <?php echo $ep==='Q1'?'selected':''; ?>>1º trimestre</option>
                <option value="Q2"  <?php echo $ep==='Q2'?'selected':''; ?>>2º trimestre</option>
                <option value="Q3"  <?php echo $ep==='Q3'?'selected':''; ?>>3º trimestre</option>
                <option value="Q4"  <?php echo $ep==='Q4'?'selected':''; ?>>4º trimestre</option>
                <option value="Y0"  <?php echo $ep==='Y0'?'selected':''; ?>>Año actual</option>
                <option value="Y-1" <?php echo $ep==='Y-1'?'selected':''; ?>>Año pasado</option>
                <option value="custom" <?php echo $ep==='custom'?'selected':''; ?>>Otro intervalo…</option>
              </select>
              <span class="period-range" id="ep-range" style="display: <?php echo ($ep==='custom'?'inline-flex':'none'); ?>; gap:.25rem;">
                <input type="date" name="es" value="<?php echo htmlspecialchars($es); ?>">
                <input type="date" name="ee" value="<?php echo htmlspecialchars($ee); ?>">
                <button class="btn btn-sm" type="submit">Aplicar</button>
              </span>
            </div>
          </th>
          <th>Incluye producto
            <div class="th-filter fd-wrap">
              <?php $pdCount = count($epdSel??[]); ?>
              <button type="button" class="fd-button" data-target="#fd-products">
                <span>Filtrar productos</span>
                <span class="fd-badge" <?php echo $pdCount? '':'style="display:none;"'; ?>><?php echo $pdCount; ?></span>
                <span class="fd-caret">▾</span>
              </button>
              <div id="fd-products" class="fd-panel">
                <div class="fd-search">
                  <input type="text" placeholder="Buscar…" data-filter-list="#fd-products-list">
                </div>
                <div id="fd-products-list" class="fd-list">
                  <?php foreach ($productOpts as $p): ?>
                    <label class="fd-check" data-text="<?php echo htmlspecialchars(mb_strtolower($p['label'])); ?>">
                      <input type="checkbox" name="epd[]" value="<?php echo htmlspecialchars($p['label']); ?>" <?php echo (in_array($p['label'], $epdSel??[], true)?'checked':''); ?>>
                      <?php echo htmlspecialchars($p['label']); ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="fd-actions">
                  <button type="button" class="btn btn-sm fd-clear" data-clear="epd[]">Limpiar</button>
                  <button class="btn btn-sm" type="submit">Aplicar</button>
                </div>
              </div>
            </div>
          </th>
          <th>Concepto</th>
          <th style="text-align:right;">Base imponible</th>
          <th style="text-align:right;">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($emitidas)): ?>
          <tr><td colspan="7" style="text-align:center; color: var(--text-light);">No hay facturas para los filtros seleccionados.</td></tr>
        <?php else: ?>
          <?php foreach ($emitidas as $inv): $date = ymd((string)($inv->issueDate ?? '')); ?>
          <tr>
            <td>
              <a href="index.php?page=view_invoice&id=<?php echo urlencode((string)$inv->id); ?>">
                <?php echo htmlspecialchars((string)$inv->id); ?>
              </a>
            </td>
            <td><?php echo htmlspecialchars((string)($inv->client->name ?? '')); ?></td>
            <td><?php echo htmlspecialchars(date('m/Y', strtotime($date ?: 'now'))); ?></td>
            <td>
              <?php
                $plist = [];
                if (isset($inv->items->item)) {
                  foreach ($inv->items->item as $it) {
                    $d = trim((string)$it->description);
                    if ($d !== '' && !in_array($d, $plist, true)) $plist[] = $d;
                  }
                }
                echo $plist ? htmlspecialchars(implode(', ', array_slice($plist, 0, 5))) : '—';
              ?>
            </td>
            <td><?php echo htmlspecialchars((string)($inv->concept ?? '')); ?></td>
            <td style="text-align:right;"><?php echo fmt_eur((float)($inv->totalBase ?? 0)); ?> €</td>
            <td style="text-align:right; font-weight:600;"><?php echo fmt_eur((float)($inv->totalAmount ?? 0)); ?> €</td>
          </tr>
          <?php endforeach; ?>
          <tr>
            <td colspan="4" style="text-align:right; font-weight:600;">Totales</td>
            <td style="text-align:right; font-weight:600;">
              Base: <?php echo fmt_eur($totE['base']); ?> € · IVA: <?php echo fmt_eur($totE['iva']); ?> € · IRPF: <?php echo fmt_eur($totE['irpf']); ?> € · Suplidos: <?php echo fmt_eur($totE['suplidos']); ?> €
            </td>
            <td style="text-align:right; font-weight:700;" colspan="2"><?php echo fmt_eur($totE['total']); ?> €</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </form>
</div>

<style>
.fd-wrap{ position:relative; display:inline-block; }
.fd-button{ display:inline-flex; align-items:center; gap:.4rem; padding:.35rem .6rem; border:1px solid var(--border-color); border-radius:.4rem; background:#fff; cursor:pointer; font-size:.8rem; }
.fd-button:hover{ background:#f9fafb; }
.fd-caret{ opacity:.6; }
.fd-badge{ background:#0b74c4; color:#fff; font-size:.7rem; padding:.05rem .35rem; border-radius:.5rem; }
.fd-panel{ position:absolute; z-index:30; top:110%; left:0; background:#fff; border:1px solid var(--border-color); border-radius:.5rem; box-shadow:0 6px 20px rgba(0,0,0,.08); width: 260px; max-height: 340px; overflow:auto; padding:.5rem; display:none; }
.fd-panel.open{ display:block; }
.fd-search input{ width:100%; padding:.4rem .5rem; border:1px solid var(--border-color); border-radius:.35rem; font-size:.85rem; }
.fd-section{ display:flex; flex-direction:column; gap:.35rem; margin:.4rem 0; }
.fd-list{ display:flex; flex-direction:column; gap:.3rem; margin-top:.35rem; }
.fd-check{ display:flex; align-items:center; gap:.45rem; font-size:.85rem; }
.fd-check input{ transform:scale(1.1); }
.fd-sep{ height:1px; background:#eef2f7; margin:.4rem 0; }
.fd-actions{ display:flex; justify-content:space-between; gap:.5rem; margin-top:.5rem; }
</style>

<script>
(function(){
  function qs(a,sel){return Array.prototype.slice.call((a||document).querySelectorAll(sel));}
  function togglePanel(btn){ var id=btn.getAttribute('data-target'); var p=document.querySelector(id); if(!p) return; var open=p.classList.contains('open'); closeAll(); if(!open){ p.classList.add('open'); } }
  function closeAll(){ qs(null,'.fd-panel.open').forEach(p=>p.classList.remove('open')); }
  qs(null,'.fd-button').forEach(function(btn){ btn.addEventListener('click', function(e){ e.stopPropagation(); togglePanel(btn); }); });
  document.addEventListener('click', closeAll);
  // live filter
  qs(null,'.fd-search input').forEach(function(inp){ inp.addEventListener('input', function(){ var list=document.querySelector(inp.getAttribute('data-filter-list')); if(!list) return; var q=inp.value.trim().toLowerCase(); qs(list,'.fd-check').forEach(function(lbl){ var t=lbl.getAttribute('data-text')||''; lbl.style.display = (!q || t.indexOf(q)>=0)?'flex':'none'; }); }); });
  function submitParentForm(node){ var f=node.closest('form'); if(f){ f.submit(); } }
  // clear buttons (y enviar)
  qs(null,'.fd-clear').forEach(function(btn){ btn.addEventListener('click', function(){ var name=btn.getAttribute('data-clear'); qs(btn.closest('.fd-panel'),'input[type="checkbox"]').forEach(function(cb){ if(cb.name===name) cb.checked=false; }); // update badge
    var wrap=btn.closest('.fd-wrap'); var badge=wrap && wrap.querySelector('.fd-badge'); if(badge){ badge.style.display='none'; badge.textContent=''; }
    submitParentForm(btn);
  }); });
  // update badges on change y auto-submit
  qs(null,'.fd-panel input[type="checkbox"]').forEach(function(cb){ cb.addEventListener('change', function(){ var wrap=cb.closest('.fd-wrap'); if(!wrap) return; var all=qs(wrap,'.fd-panel input[type="checkbox"]').filter(function(x){return x.checked;}); var badge=wrap.querySelector('.fd-badge'); if(!badge) return; if(all.length){ badge.style.display='inline-block'; badge.textContent=all.length; } else { badge.style.display='none'; badge.textContent=''; }
    submitParentForm(cb);
  }); });
  // seleccionar todo
  qs(null,'.fd-select-all').forEach(function(sel){ sel.addEventListener('change', function(){ var list = document.querySelector(sel.getAttribute('data-list')); var name = sel.getAttribute('data-name'); if(!list||!name) return; var cbs = qs(list,'input[type="checkbox"]'); cbs = cbs.filter(function(x){ return x.name===name; }); cbs.forEach(function(cb){ cb.checked = sel.checked; }); var wrap=sel.closest('.fd-wrap'); if(wrap){ var badge=wrap.querySelector('.fd-badge'); if(badge){ var count=cbs.filter(function(x){return x.checked;}).length; if(count){ badge.style.display='inline-block'; badge.textContent=count; } else { badge.style.display='none'; badge.textContent=''; } } } submitParentForm(sel); }); });
})();
</script>

<div class="card" style="margin-top: 1.5rem;">
  <h3 style="margin:0 0 .75rem 0;">Facturas recibidas</h3>
  <form method="get" style="margin:0;">
    <input type="hidden" name="page" value="dashboard">
    <table>
      <thead>
        <tr>
          <th>Factura</th>
          <th>Proveedor
            <div class="th-filter fd-wrap">
              <?php $rsCount = count($rsSel??[]); ?>
              <button type="button" class="fd-button" data-target="#fd-providers">
                <span>Filtrar proveedores</span>
                <span class="fd-badge" <?php echo $rsCount? '':'style="display:none;"'; ?>><?php echo $rsCount; ?></span>
                <span class="fd-caret">▾</span>
              </button>
              <div id="fd-providers" class="fd-panel">
                <div class="fd-search">
                  <input type="text" placeholder="Buscar…" data-filter-list="#fd-providers-list">
                </div>
                <div id="fd-providers-list" class="fd-list">
                  <?php foreach ($providers as $nif=>$lab): ?>
                    <label class="fd-check" data-text="<?php echo htmlspecialchars(mb_strtolower($lab.' '.$nif)); ?>">
                      <input type="checkbox" name="rs[]" value="<?php echo htmlspecialchars($nif); ?>" <?php echo (in_array($nif, $rsSel??[], true)?'checked':''); ?>>
                      <?php echo htmlspecialchars($lab); ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="fd-actions">
                  <button type="button" class="btn btn-sm fd-clear" data-clear="rs[]">Limpiar</button>
                  <button class="btn btn-sm" type="submit">Aplicar</button>
                </div>
              </div>
            </div>
          </th>
          <th>Periodo
            <div class="th-filter period">
              <select name="rp" id="rp" onchange="toggleCustom('rp','rs_s','rs_e'); this.form.submit()">
                <option value="M0"  <?php echo $rp==='M0'?'selected':''; ?>>Mes en curso</option>
                <option value="Q1"  <?php echo $rp==='Q1'?'selected':''; ?>>1º trimestre</option>
                <option value="Q2"  <?php echo $rp==='Q2'?'selected':''; ?>>2º trimestre</option>
                <option value="Q3"  <?php echo $rp==='Q3'?'selected':''; ?>>3º trimestre</option>
                <option value="Q4"  <?php echo $rp==='Q4'?'selected':''; ?>>4º trimestre</option>
                <option value="Y0"  <?php echo $rp==='Y0'?'selected':''; ?>>Año actual</option>
                <option value="Y-1" <?php echo $rp==='Y-1'?'selected':''; ?>>Año pasado</option>
                <option value="custom" <?php echo $rp==='custom'?'selected':''; ?>>Otro intervalo…</option>
              </select>
              <span class="period-range" id="rp-range" style="display: <?php echo ($rp==='custom'?'inline-flex':'none'); ?>; gap:.25rem;">
                <input type="date" name="rs_s" value="<?php echo htmlspecialchars($rs_s); ?>">
                <input type="date" name="rs_e" value="<?php echo htmlspecialchars($rs_e); ?>">
                <button class="btn btn-sm" type="submit">Aplicar</button>
              </span>
            </div>
          </th>
          <th>Concepto</th>
          <th style="text-align:right;">Base imponible</th>
          <th style="text-align:right;">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recibidas)): ?>
          <tr><td colspan="6" style="text-align:center; color: var(--text-light);">No hay recibidas para los filtros seleccionados.</td></tr>
        <?php else: ?>
          <?php foreach ($recibidas as $r):
            $id    = (string)($r['id'] ?? '');
            $prov  = trim((string)($r['sellerName'] ?? $r['supplierName'] ?? ''));
            $nif   = trim((string)($r['sellerNif'] ?? $r['supplierNif'] ?? ''));
            $concept = (string)($r['concept'] ?? '');
            $base = null; $total = (float)($r['totalAmount'] ?? 0.0);
            try {
              if ($id !== '') {
                $vd = $rx->getViewDataById($id);
                if (!empty($vd['success'])) {
                  $t = (array)($vd['totals'] ?? []);
                  $base  = isset($t['base']) ? (float)$t['base'] : null;
                  $total = isset($t['total']) ? (float)$t['total'] : $total;
                }
              }
            } catch (\Throwable $e) {}
          ?>
          <tr>
            <td>
              <a href="index.php?page=received_view&id=<?php echo urlencode($id); ?>">
                <?php
                  $serie = (string)($r['series'] ?? '');
                  $num   = (string)($r['invoiceNumber'] ?? '');
                  $label = ($serie !== '' || $num !== '') ? trim($serie.($serie&&$num?'-':'').$num) : $id;
                  echo htmlspecialchars($label);
                ?>
              </a>
            </td>
            <td><?php echo htmlspecialchars(trim($prov.($nif?" ({$nif})":''))); ?></td>
            <td><?php echo htmlspecialchars(($r['issueDate'] ?? '') ? date('m/Y', strtotime((string)$r['issueDate'])) : ''); ?></td>
            <td><?php echo htmlspecialchars($concept); ?></td>
            <td style="text-align:right;">&nbsp;<?php echo $base!==null ? fmt_eur($base).' €' : '—'; ?></td>
            <td style="text-align:right; font-weight:600;">&nbsp;<?php echo fmt_eur($total); ?> €</td>
          </tr>
          <?php endforeach; ?>
          <tr>
            <td colspan="3" style="text-align:right; font-weight:600;">Totales</td>
            <td style="text-align:right; font-weight:600;">
              Base: <?php echo fmt_eur($totR['base']); ?> € · IVA: <?php echo fmt_eur($totR['iva']); ?> € · IRPF: <?php echo fmt_eur($totR['irpf']); ?> €
            </td>
            <td style="text-align:right; font-weight:700;" colspan="2">&nbsp;<?php echo fmt_eur($totR['total']); ?> €</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </form>
</div>

<style>
.th-filter { margin-top:.35rem; }
.th-filter select { font-size:.8rem; padding:.25rem .35rem; }
.btn.btn-sm { padding:.2rem .45rem; font-size:.75rem; }
.period-range input[type=date]{ font-size:.8rem; padding:.25rem .35rem; }
</style>

<script>
function toggleCustom(selId){
  var sel = document.getElementById(selId);
  var rng = document.getElementById(selId+'-range');
  if (!sel || !rng) return;
  rng.style.display = (sel.value === 'custom') ? 'inline-flex' : 'none';
}
document.addEventListener('DOMContentLoaded', function(){
  toggleCustom('ep');
  toggleCustom('rp');
});
</script>
