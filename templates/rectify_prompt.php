<?php
/**
 * templates/rectify_prompt.php
 * Pantalla de confirmación para emitir una rectificativa de una factura existente
 *
 * Requiere que $invoice esté definido por index.php antes de incluir esta plantilla.
 */
if (!isset($invoice)) {
    echo '<div class="card"><h2>Error</h2><p>No se encontró la factura solicitada.</p></div>';
    return;
}
$invoiceId = htmlspecialchars((string)$invoice->id);
$issueDate = htmlspecialchars((string)$invoice->issueDate);
$clientName = htmlspecialchars((string)($invoice->client->name ?? ''));
?>
<h2>Rectificar factura</h2>

<div class="card">
  <p>
    Vas a crear una <strong>factura rectificativa</strong> de
    <code><?php echo $invoiceId; ?></code> (emitida el <?php echo $issueDate; ?> a <?php echo $clientName; ?>).
  </p>

  <form id="rectify-form" method="post" action="index.php" data-invoice-id="<?php echo $invoiceId; ?>">
    <!-- Imprescindible: forzar POST para evitar caídas a GET -->
    <input type="hidden" name="action" value="rectify_create">
    <input type="hidden" name="id"     value="<?php echo $invoiceId; ?>">

    <div class="form-group">
      <label for="reason">Motivo de la rectificación</label>
      <textarea id="reason" name="reason" class="form-control" rows="3" required placeholder="Explica brevemente el motivo"></textarea>
    </div>

    <div class="form-group" style="display:flex;align-items:center;gap:.5rem;">
      <input type="checkbox" id="openSecond" name="openSecond" value="yes">
      <label for="openSecond" style="margin:0;">Crear <strong>otra factura</strong> ahora (serie <b>R</b>) que sustituya a la original</label>
    </div>

    <div id="rectify-msg" class="form-message" aria-live="polite"></div>

    <div style="display:flex; gap:.5rem; justify-content:flex-end; margin-top:1rem;">
      <a class="btn" href="index.php?page=view_invoice&id=<?php echo $invoiceId; ?>">Cancelar</a>
      <button type="submit" class="btn btn-primary">Confirmar rectificación</button>
    </div>
  </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const form   = document.getElementById('rectify-form');
  const msgBox = document.getElementById('rectify-msg');

  if (!form) return;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();

    const btn = form.querySelector('button[type="submit"], input[type="submit"]');
    if (btn) btn.disabled = true;

    try {
      // Aseguramos los campos clave (por si el HTML cambia)
      const id = form.querySelector('input[name="id"]')?.value || form.getAttribute('data-invoice-id') || '';
      if (!id) throw new Error('Falta el ID de la factura a rectificar.');

      const reasonEl = form.querySelector('#reason');
      const reason   = (reasonEl?.value || '').trim();
      if (!reason) throw new Error('Indica el motivo de la rectificación.');

      // Normaliza openSecond (yes/no) – el backend acepta yes/true/on/1/sí/si
      const openSecond = form.querySelector('#openSecond')?.checked ? 'yes' : 'no';

      // Construimos FormData (forzando POST)
      const fd = new FormData();
      fd.set('action',     'rectify_create');
      fd.set('id',         id);
      fd.set('reason',     reason);
      fd.set('openSecond', openSecond);

      const res = await fetch('index.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      });

      const ct  = (res.headers.get('content-type') || '').toLowerCase();
      const raw = await res.text(); // leemos siempre como texto

      let data;
      if (ct.includes('application/json')) {
        data = JSON.parse(raw);
      } else {
        // Si llegara HTML (login, error PHP...), mostramos un extracto
        throw new Error('Respuesta no JSON del servidor:\n' + raw.slice(0, 300));
      }

      if (!res.ok || !data || data.success !== true) {
        throw new Error((data && data.message) ? data.message : 'No se pudo crear la rectificativa.');
      }

      // Si pediste abrir la segunda factura, el backend devuelve redirect a create_invoice&series=R...
      if (data.redirect) {
        window.location.href = data.redirect;
        return;
      }

      // Si no, vamos a ver la rectificativa recién creada
      if (data.rectificativeId) {
        window.location.href = 'index.php?page=view_invoice&id=' + encodeURIComponent(data.rectificativeId);
        return;
      }

      // Fallback seguro (evita alias "invoices")
      window.location.href = 'index.php?page=invoice_list';
    } catch (err) {
      const text = String(err && err.message ? err.message : err);
      if (msgBox) {
        msgBox.innerHTML = '<div class="alert alert-danger" role="alert">' + text + '</div>';
      } else {
        alert(text);
      }
    } finally {
      if (btn) btn.disabled = false;
    }
  });
});
</script>

