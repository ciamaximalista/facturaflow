<?php
// templates/create_invoice.php

// Normaliza entradas esperadas desde el controlador
$issuer         = isset($issuer) && is_array($issuer) ? $issuer : [];
$products       = isset($products) && is_iterable($products) ? $products : [];
$clients        = isset($clients) && is_iterable($clients) ? $clients : [];
$presetSeries   = isset($presetSeries) ? (string)$presetSeries : '';
$presetRectifies= isset($presetRectifies) ? (string)$presetRectifies : '';
$presetMotive   = isset($presetMotive) ? (string)$presetMotive : '';

// Normaliza catálogo de productos a un array plano seguro
$normalizedProducts = [];
foreach ($products as $p) {
  $id=''; $desc=''; $price=0.0; $vat=21;
  if (is_object($p)) {
    $id    = (string)($p->id ?? '');
    $desc  = (string)($p->description ?? '');
    $price = (float)($p->price ?? 0);
    $vat   = (float)($p->vat ?? 21);
  } elseif (is_array($p)) {
    $id    = (string)($p['id'] ?? '');
    $desc  = (string)($p['description'] ?? '');
    $price = (float)($p['price'] ?? 0);
    $vat   = (float)($p['vat'] ?? 21);
  }
  if ($id !== '') {
    $normalizedProducts[] = ['id'=>$id,'description'=>$desc,'price'=>$price,'vat'=>$vat];
  }
}
?>
<h2>Nueva Factura</h2>

<div class="card">
  <form id="invoice-form">
    <input type="hidden" name="action" value="create_invoice">
    <!-- Que el backend aplique automáticamente el IRPF del emisor si procede -->
    <input type="hidden" name="irpfRate" value="-1">
    <input type="hidden" name="series" value="<?php echo htmlspecialchars($presetSeries); ?>">

    <?php if ($presetRectifies !== ''): ?>
      <input type="hidden" name="rectifies" value="<?php echo htmlspecialchars($presetRectifies); ?>">
      <input type="hidden" name="motive" value="<?php echo htmlspecialchars($presetMotive); ?>">
      <input type="hidden" name="isRectificative" value="true">
    <?php endif; ?>

    <div class="form-group">
      <label for="client">Cliente</label>
      <select id="client" name="clientId" class="form-control" required>
        <option value="">-- Selecciona un cliente --</option>
        <?php foreach ($clients as $client): ?>
          <option
            value="<?php echo htmlspecialchars((string)$client->id); ?>"
            data-entity-type="<?php echo htmlspecialchars(strtolower((string)($client->entityType ?? ''))); ?>">
            <?php echo htmlspecialchars((string)$client->name); ?> (<?php echo htmlspecialchars((string)$client->nif); ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- FACe: referencias opcionales, visible solo para Administraciones Públicas -->
    <div id="face-refs-block" class="card" style="margin-top:1rem; display:none;">
      <h3 style="margin:0 0 .75rem 0;">Datos FACe (opcionales)</h3>
      <div class="form-group">
        <label for="fileReference">Expediente de contratación (FileReference)</label>
        <input type="text" id="fileReference" name="fileReference" class="form-control" placeholder="p. ej. EXP-2025/000123">
      </div>
      <div class="form-group">
        <label for="receiverContractReference">Ref. contrato principal (ReceiverContractReference)</label>
        <input type="text" id="receiverContractReference" name="receiverContractReference" class="form-control" placeholder="p. ej. CONT-2024-15">
      </div>
      <small class="muted">Se incluirán en el XML Facturae si se rellenan.</small>
    </div>

    <div style="display:flex; gap:2rem;">
      <div class="form-group" style="flex:3;">
        <label for="concept">Concepto de la Factura</label>
        <input type="text" id="concept" name="concept" class="form-control" required>
      </div>

      <!-- Vencimiento -->
      <div class="form-group">
        <label class="d-block mb-1">Vencimiento</label>
        <div><label><input type="radio" name="due_option" value="on_receipt" checked> Pago a la recepción de la factura</label></div>
        <div><label><input type="radio" name="due_option" value="plus60"> Vencimiento a los sesenta días (se calculará automáticamente)</label></div>
        <div>
          <label><input type="radio" name="due_option" value="custom"> Otro (elige una fecha)</label>
          <input type="date" id="due_date" name="due_date" class="ml-2" style="display:none;">
        </div>
        <small class="text-muted d-block mt-1">Si eliges “Otro”, la fecha debe estar dentro de los 60 días desde hoy.</small>
      </div>
    </div>

    <h3>Líneas de la Factura</h3>
    <div class="invoice-items">
      <table>
        <thead>
          <tr>
            <th style="width:40%;">Descripción</th>
            <th>Cant.</th>
            <th>Precio Unit.</th>
            <th>Total Base</th>
            <th>IVA (%)</th>
            <th>Total Línea</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="invoice-item-list"></tbody>
      </table>

      <div style="margin-top:.75rem; display:flex; gap:.5rem; flex-wrap:wrap;">
        <button type="button" id="add-item-btn" class="btn">Añadir Producto</button>
        <button type="button" id="add-suplido-btn" class="btn" style="background-color:#6B7280;">Añadir Suplido</button>
      </div>
    </div>

    <div class="totals-summary">
      <p><strong>Base Imponible:</strong> <span id="total-base">0,00</span> €</p>
      <p><strong>Total IVA:</strong> <span id="total-vat">0,00</span> €</p>
      <p id="irpf-row" style="display:none;">
        <strong>Retención IRPF (15%):</strong> <span id="total-irpf">0,00</span> €
      </p>
      <p><strong>Total Suplidos:</strong> <span id="total-suplidos">0,00</span> €</p>
      <h3 class="grand-total">Total Factura: <span id="total-amount">0,00</span> €</h3>
    </div>

    <div style="text-align:right; margin-top:2rem;">
      <button type="submit" class="btn btn-primary">Finalizar y Guardar Factura</button>
    </div>
  </form>
</div>

<style>
.totals-summary { text-align:right; margin-top:2rem; border-top:1px solid var(--border-color); padding-top:1rem; }
.totals-summary p { margin:.5rem 0; }
.grand-total { margin-top:1rem; }
.invoice-items table { width:100%; border-collapse:collapse; }
.invoice-items th, .invoice-items td { padding:.4rem .5rem; border-bottom:1px solid var(--border-color); }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // Datos inyectados
  const issuerType  = "<?php echo htmlspecialchars($issuer['entityType'] ?? 'company'); ?>";
  const irpfRate    = parseFloat("<?php echo htmlspecialchars($issuer['irpfRate'] ?? '0'); ?>");
  const productList = <?php echo json_encode($normalizedProducts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

  const form     = document.getElementById('invoice-form');
  const itemList = document.getElementById('invoice-item-list');
  const clientSel= document.getElementById('client');
  const faceBlock= document.getElementById('face-refs-block');
  const fileRefEl= document.getElementById('fileReference');
  const recvRefEl= document.getElementById('receiverContractReference');

  // Mostrar/ocultar bloque FACe
  function toggleFaceBlock(){
    const opt = clientSel.options[clientSel.selectedIndex];
    const et  = (opt && opt.dataset.entityType) ? opt.dataset.entityType.toLowerCase() : '';
    const isPublic = (et === 'public_admin');
    faceBlock.style.display = isPublic ? '' : 'none';
    if (!isPublic) { if (fileRefEl) fileRefEl.value=''; if (recvRefEl) recvRefEl.value=''; }
  }
  clientSel.addEventListener('change', toggleFaceBlock);
  toggleFaceBlock();

  // Helpers ES
  function parseNumberES(str){
    if (typeof str !== 'string') return 0;
    str = str.replace(/\./g,'').replace(',', '.').trim();
    const n = Number(str);
    return isFinite(n) ? n : 0;
  }
  function formatNumberES(value, decimals){
    const n = (typeof value === 'number') ? value : parseNumberES(String(value||'0'));
    const d = Number(decimals ?? 2);
    try {
      const s = n.toLocaleString('es-ES', { minimumFractionDigits:d, maximumFractionDigits:d, useGrouping:true });
      if (Math.abs(n) >= 1000 && s.indexOf('.') === -1) throw new Error('no grouping');
      return s;
    } catch {
      const neg = n < 0 ? '-' : '';
      const abs = Math.abs(n);
      const fixed = abs.toFixed(d);
      const parts = fixed.split('.');
      const intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
      const decPart = d > 0 ? ',' + (parts[1] || '').padEnd(d, '0') : '';
      return neg + intPart + decPart;
    }
  }

  // Totales
  function updateTotals(){
    let totalBase=0, totalVat=0, totalSuplidos=0;

    document.querySelectorAll('tr.item-row').forEach(row => {
      const qtyEl   = row.querySelector('input[name*="[qty]"]');
      const priceEl = row.querySelector('input[name*="[price]"]'); // hidden
      const vatEl   = row.querySelector('input[name*="[vat]"]');   // hidden

      const qty     = parseNumberES(qtyEl?.value || '0');
      const price   = parseFloat(priceEl?.value || '0');
      const vatRate = parseFloat(vatEl?.value || '0');

      const lineBase = qty * price;
      const lineVat  = lineBase * (vatRate / 100);

      row.querySelector('.line-base-total').textContent = formatNumberES(lineBase, 2);
      row.querySelector('.line-total').textContent      = formatNumberES(lineBase + lineVat, 2);

      totalBase += lineBase;
      totalVat  += lineVat;
    });

    document.querySelectorAll('tr.suplido-row').forEach(row => {
      const amountEl = row.querySelector('input[name*="[amount]"]');
      const amount   = parseNumberES(amountEl?.value || '0');
      row.querySelector('.line-total').textContent = formatNumberES(amount, 2);
      totalSuplidos += amount;
    });

    const irpfRow = document.getElementById('irpf-row');
    const irpfAmount = (issuerType === 'freelancer' && irpfRate > 0) ? totalBase * (irpfRate / 100) : 0;

    document.getElementById('total-base').textContent     = formatNumberES(totalBase, 2);
    document.getElementById('total-vat').textContent      = formatNumberES(totalVat, 2);
    document.getElementById('total-suplidos').textContent = formatNumberES(totalSuplidos, 2);

    if (issuerType === 'freelancer' && irpfRate > 0) {
      irpfRow.style.display = 'block';
      irpfRow.querySelector('strong').textContent = `Retención IRPF (${irpfRate}%):`;
      document.getElementById('total-irpf').textContent = formatNumberES(-irpfAmount, 2);
    } else {
      irpfRow.style.display = 'none';
    }

    const grand = totalBase + totalVat - irpfAmount + totalSuplidos;
    document.getElementById('total-amount').textContent = formatNumberES(grand, 2);
  }

  // Construcción de filas
  let itemCounter = 0;
  function createRowHTML(type){
    itemCounter++;
    if (type === 'item') {
      const productOptions = productList.map(p => {
        const desc  = (p.description || '').replace(/"/g, '&quot;');
        const price = Number(p.price || 0);
        const vat   = Number(p.vat   || 0);
        return `<option value="${p.id}" data-price="${price}" data-vat="${vat}">${desc}</option>`;
      }).join('');
      return `
        <tr class="item-row invoice-line" data-id="${itemCounter}">
          <td>
            <select name="items[${itemCounter}][id]" class="form-control item-product-selector">
              <option value="">-- Selecciona producto --</option>
              ${productOptions}
            </select>
            <input type="hidden" name="items[${itemCounter}][desc]">
            <input type="hidden" name="items[${itemCounter}][price]">
            <input type="hidden" name="items[${itemCounter}][vat]">
          </td>
          <td>
            <input type="text" name="items[${itemCounter}][qty]" class="form-control num-es" data-decimals="3"
                   inputmode="decimal" placeholder="0,000" value="1">
          </td>
          <td class="unit-price" style="text-align:right;">0,00</td>
          <td class="line-base-total" style="text-align:right;">0,00</td>
          <td class="vat-rate" style="text-align:center;">0%</td>
          <td class="line-total" style="text-align:right; font-weight: bold;">0,00</td>
          <td><button type="button" class="btn btn-danger remove-item-btn">X</button></td>
        </tr>`;
    } else {
      return `
        <tr class="suplido-row" data-id="${itemCounter}">
          <td colspan="2">
            <input type="text" name="suplidos[${itemCounter}][desc]" class="form-control"
                   placeholder="Descripción del suplido" required>
          </td>
          <td colspan="3">
            <input type="text" name="suplidos[${itemCounter}][amount]" class="form-control num-es"
                   data-decimals="2" inputmode="decimal" placeholder="0,00" value="0">
          </td>
          <td class="line-total" style="text-align:right; font-weight: bold;">0,00</td>
          <td><button type="button" class="btn btn-danger remove-item-btn">X</button></td>
        </tr>`;
    }
  }

  // Delegación de eventos en la tabla
  itemList.addEventListener('change', (e) => {
    if (e.target.classList.contains('item-product-selector')) {
      const row = e.target.closest('tr');
      const selectedOption = e.target.options[e.target.selectedIndex];

      const price = selectedOption?.dataset.price || '0';
      const vat   = selectedOption?.dataset.vat   || '0';

      // Campos ocultos que se envían al servidor (formato máquina)
      row.querySelector('input[name*="[desc]"]').value  = selectedOption ? selectedOption.text : '';
      row.querySelector('input[name*="[price]"]').value = String(parseFloat(price) || 0);
      row.querySelector('input[name*="[vat]"]').value   = String(parseFloat(vat)   || 0);

      // Vista para el usuario
      row.querySelector('.unit-price').textContent = formatNumberES(parseFloat(price) || 0, 2);
      row.querySelector('.vat-rate').textContent   = `${parseFloat(vat) || 0}%`;

      updateTotals();
    }
  });

  // Cualquier cambio en inputs recalcula
  itemList.addEventListener('input', (e) => {
    const el = e.target;
    if (el && el.classList && el.classList.contains('num-es')) {
      let v = el.value;
      v = v.replace(/[^\d\.,]/g, '');
      const lastSep = Math.max(v.lastIndexOf(','), v.lastIndexOf('.'));
      if (lastSep !== -1){
        const head = v.slice(0, lastSep).replace(/[.,]/g, '');
        const tail = v.slice(lastSep);
        v = head + tail;
      }
      el.value = v;
      updateTotals();
    }
  });

  // Formateo ES al perder foco
  itemList.addEventListener('blur', (e) => {
    const el = e.target;
    if (el && el.classList && el.classList.contains('num-es')) {
      const decimals = Number(el.getAttribute('data-decimals') || 2);
      el.value = formatNumberES(el.value, decimals);
    }
  }, true);

  // Quitar filas
  itemList.addEventListener('click', e => {
    if (e.target.classList.contains('remove-item-btn')) {
      e.target.closest('tr').remove();
      updateTotals();
    }
  });

  // Botones añadir
  document.getElementById('add-item-btn').addEventListener('click', () => {
    itemList.insertAdjacentHTML('beforeend', createRowHTML('item'));
    updateTotals();
  });
  document.getElementById('add-suplido-btn').addEventListener('click', () => {
    itemList.insertAdjacentHTML('beforeend', createRowHTML('suplido'));
    updateTotals();
  });

  // ===== Vencimiento (sin #issueDate) =====
  (function(){
    const dueInput = document.getElementById('due_date');
    const radios   = document.querySelectorAll('input[name="due_option"]');

    function ymd(d){ return d.toISOString().slice(0,10); }
    function setBounds(){
      if(!dueInput) return;
      const base = new Date(); // hoy (el backend fijará issueDate = hoy)
      const max  = new Date(base); max.setDate(max.getDate()+60);
      dueInput.min = ymd(base);
      dueInput.max = ymd(max);
      if(dueInput.value){
        if(dueInput.value < dueInput.min) dueInput.value = dueInput.min;
        if(dueInput.value > dueInput.max) dueInput.value = dueInput.max;
      }
    }
    function onOptionChange(){
      const selected = document.querySelector('input[name="due_option"]:checked')?.value;
      if(selected === 'custom'){
        dueInput.style.display = '';
        setBounds();
      } else {
        dueInput.style.display = 'none';
        dueInput.value = '';
      }
    }
    radios.forEach(r => r.addEventListener('change', onOptionChange));
    setBounds();
    onOptionChange();
  })();

  // ===== Envío del formulario =====
  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    // Normaliza TODOS los inputs num-es a formato máquina (1234.56) ANTES de capturar el FormData
    form.querySelectorAll('input.num-es').forEach(el => {
      const normalized = (el.value || '').replace(/\./g,'').replace(',', '.');
      el.value = normalized;
    });

    const fd = new FormData(form);
    try {
      const resp = await fetch('index.php', { method:'POST', body: fd, credentials:'same-origin' });
      const ct  = (resp.headers.get('content-type') || '').toLowerCase();
      const raw = await resp.text();
      if (!ct.includes('application/json')) throw new Error('Respuesta no JSON del servidor:\n' + raw.slice(0,300));
      const result = JSON.parse(raw);
      if (resp.ok && result.success) {
        alert('Factura creada con éxito: ' + result.invoiceId);
        window.location.href = 'index.php?page=invoices';
      } else {
        throw new Error(result.message || 'Error desconocido al crear la factura');
      }
    } catch (err) {
      console.error('Error de comunicación:', err);
      alert('Error de comunicación. Revisa la consola del navegador.');
    }
  }, {capture:true});

  // Inicialización: añade una fila de producto de inicio
  document.getElementById('add-item-btn').click();

  // Si el emisor es autónomo con IRPF, muestra el bloque desde el inicio
  if (issuerType === 'freelancer' && irpfRate > 0) {
    const irpfRow = document.getElementById('irpf-row');
    irpfRow.style.display = 'block';
    irpfRow.querySelector('strong').textContent = `Retención IRPF (${irpfRate}%):`;
  }

  // Totales iniciales
  updateTotals();
});
</script>

