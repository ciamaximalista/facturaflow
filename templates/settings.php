<?php
// templates/settings.php
// Espera $settings, y opcionalmente $vfEntriesPage, $vfTotalPages, $vfPage construidos en index.php (case 'settings')
?>
<h2>Mis Datos de Emisor</h2>
<p style="color: var(--text-light); margin-top: -1rem; margin-bottom: 2rem;">
    Toda la información necesaria para poder hacer tus facturas.
</p>

<?php if (isset($_GET['success'])): ?>
    <div class="msg-ok">Datos guardados correctamente.</div>
<?php endif; ?>

<div class="card">
  <form id="settings-form" method="post" action="index.php?page=settings" enctype="multipart/form-data" class="card"">
    <input type="hidden" name="action" value="save_settings">

    <h4>Datos de Identificación</h4>

    <div class="form-group">
      <label>Tipo de Entidad</label>
      <div class="radio-group-styled">
        <label>
          <input type="radio" name="entityType" value="company" <?php echo (($settings['entityType'] ?? 'company') === 'company') ? 'checked' : ''; ?>>
          <span>🏢 Empresa</span>
        </label>
        <label>
          <input type="radio" name="entityType" value="freelancer" <?php echo (($settings['entityType'] ?? '') === 'freelancer') ? 'checked' : ''; ?>>
          <span>👤 Trabajador Autónomo</span>
        </label>
      </div>
    </div>

    <div id="company-fields">
      <div class="form-group">
        <label for="companyName">Razón Social</label>
        <input type="text" id="companyName" name="companyName" class="form-control"
               value="<?php echo htmlspecialchars($settings['companyName'] ?? ''); ?>">
      </div>
    </div>

    <div id="freelancer-fields">
      <div class="flex-gap">
        <div class="form-group flex-1">
          <label for="firstName">Nombre</label>
          <input type="text" id="firstName" name="firstName" class="form-control"
                 value="<?php echo htmlspecialchars($settings['firstName'] ?? ''); ?>">
        </div>
        <div class="form-group flex-1">
          <label for="lastName">Primer Apellido</label>
          <input type="text" id="lastName" name="lastName" class="form-control"
                 value="<?php echo htmlspecialchars($settings['lastName'] ?? ''); ?>">
        </div>
        <div class="form-group flex-1">
          <label for="secondSurname">Segundo Apellido</label>
          <input type="text" id="secondSurname" name="secondSurname" class="form-control"
                 value="<?php echo htmlspecialchars($settings['secondSurname'] ?? ''); ?>">
        </div>
      </div>
    </div>

    <div class="form-group">
      <label for="nif">NIF/CIF</label>
      <input type="text" id="nif" name="nif" class="form-control"
             value="<?php echo htmlspecialchars((string)($settings['nif'] ?? '')); ?>"
             required pattern="[A-Za-z0-9]+"
             oninput="this.value=this.value.replace(/[\s-]/g,'').toUpperCase()">
      <small class="help-text">Sin espacios ni guiones.</small>
    </div>

    <div class="form-group">
      <label for="irpfRate">Retención IRPF a aplicar (si procede)</label>
      <select id="irpfRate" name="irpfRate" class="form-control">
        <?php $currentIrpf = (float)($settings['irpfRate'] ?? 0); ?>
        <option value="0"  <?php echo $currentIrpf == 0  ? 'selected' : ''; ?>>No aplicar IRPF</option>
        <option value="15" <?php echo $currentIrpf == 15 ? 'selected' : ''; ?>>15%</option>
        <option value="7"  <?php echo $currentIrpf == 7  ? 'selected' : ''; ?>>7% (Nuevos autónomos)</option>
      </select>
    </div>

    <div class="form-group">
      <label for="address">Dirección</label>
      <input type="text" id="address" name="address" class="form-control"
             value="<?php echo htmlspecialchars($settings['address'] ?? ''); ?>">
    </div>

    <div class="flex-gap">
      <div class="form-group flex-1">
        <label for="postCode">Código Postal</label>
        <input type="text" id="postCode" name="postCode" class="form-control"
               value="<?php echo htmlspecialchars($settings['postCode'] ?? ''); ?>">
      </div>
      <div class="form-group flex-2">
        <label for="town">Ciudad</label>
        <input type="text" id="town" name="town" class="form-control"
               value="<?php echo htmlspecialchars($settings['town'] ?? ''); ?>">
      </div>
      <div class="form-group flex-2">
        <label for="province">Provincia</label>
        <input type="text" id="province" name="province" class="form-control"
               value="<?php echo htmlspecialchars($settings['province'] ?? ''); ?>">
      </div>
    </div>

    <div class="form-group">
      <label for="email">Email de facturación</label>
      <input type="email" id="email" name="email" inputmode="email" class="form-control"
             value="<?php echo htmlspecialchars($settings['email'] ?? '', ENT_QUOTES); ?>"
             placeholder="ej.: facturacion@tu-dominio.com">
    </div>



    <hr class="form-divider">
       

    <h4>Logo, Cuenta bancaria y AutoFirma</h4>

    <div class="flex-gap">
      <div class="form-group flex-1">
        <label>Logo Actual</label>
        <div class="current-item-display">
          <?php if (!empty($settings['logoPath']) && file_exists($settings['logoPath'])): ?>
            <img src="<?php echo htmlspecialchars($settings['logoPath']); ?>" alt="Logo actual">
          <?php else: ?>
            <span>No hay logo subido.</span>
          <?php endif; ?>
        </div>
        <label for="logo" class="file-input-label">Subir/Cambiar Logo</label>
        <input type="file" id="logo" name="logo" class="file-input-hidden" accept="image/png, image/jpeg">
      </div>
      
  <div class="form-group">
      <label for="iban">IBAN (cuenta bancaria)</label>
      <input type="text" id="iban" name="iban" class="form-control"
             value="<?php echo htmlspecialchars($settings['iban'] ?? '', ENT_QUOTES); ?>"
             placeholder="ej.: ES66 1234 5678 9012 3456 7890"
             pattern="^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$"
             title="IBAN en formato internacional (2 letras país, 2 dígitos control, resto alfanumérico)"
             oninput="this.value=this.value.replace(/\s+/g,'').toUpperCase()">
    </div>

      <div class="form-group flex-1">
        <label>AutoFirma</label>
        <div id="settings-autofirma-status" class="current-item-display">Pendiente de comprobar.</div>
        <button type="button" id="settings-autofirma-check" class="btn" style="margin-top:0.5rem;">Detectar AutoFirma</button>
        <small class="help-text">FacturaFlow no almacena certificados personales. Firma siempre desde tu equipo.</small>
      </div>
    </div>

   
    
    	<hr class="form-divider">
	    <h4>FACeB2B y FACE</h4>

    <div class="form-group">
      <label for="dire_id">Identificador DIRe</label>
      <input type="text" id="dire_id" name="dire" class="form-control"
             inputmode="latin" pattern="[A-Z0-9\-\._]{3,32}"
             title="3–32 caracteres en mayúsculas, números o - . _"
             value="<?php echo htmlspecialchars($settings['dire'] ?? '', ENT_QUOTES); ?>"
             placeholder="p.ej. ES12345678 o ABC-0001">
      <small class="text-muted">
        Necesario para conectar con FACeB2B y FACE: recepción en tiempo real, envío de facturas
        emitidas y comunicación de <em>pagadas/rechazadas</em>.
      </small>
    </div>
	    
	    <div class="form-group">
	      <label>
		<input type="checkbox" name="faceb2b_confirm_download" value="1" 
		       <?php echo !empty($settings['faceb2b']['confirm_download']) ? 'checked' : ''; ?>>
		Confirmar descarga de facturas en FACeB2B
	      </label>
	      <small class="help-text">
		Activado: Al sincronizar, las facturas descargadas se marcarán como "leídas" en FACeB2B 
		y no volverán a aparecer. Desactívalo solo para pruebas.
	      </small>
	    </div>





   

    <div style="text-align: right; margin-top: 2rem;">
      <button type="submit" class="btn btn-primary">Guardar Cambios</button>
    </div>
  </form>

  <h2>Otros</h2>

  <div class="card" style="margin-top:1rem;">
    <h3>Conexión AEAT (pruebas)</h3>
    <p class="help-text">
      Entorno actual: <strong><?php echo htmlspecialchars((string)($settings['aeatEnv'] ?? 'test')); ?></strong>
    </p>
    <button type="button" id="aeat-test-btn" class="btn">Probar WSDL/Conexión</button>
    <div id="aeat-test-msg" class="help-text" style="margin-top:.75rem;"></div>
    <pre id="aeat-test-ops" style="display:none; max-height:240px; overflow:auto; background:#f8f8f8; border:1px solid #eee; padding:.5rem; border-radius:6px;"></pre>
  </div>

  <h3>Registro Verifactu <a href="index.php?page=settings&download_verifactu=1">[Descargar]</a></h3>

  <?php if (!empty($vfEntriesPage)): ?>
    <div class="table-wrap" style="overflow:auto;">
      <table class="table">
        <thead>
          <tr>
            <th>Fecha registro</th>
            <th>ID Factura</th>
            <th>Emisor</th>
            <th>Receptor</th>
            <th>Base (€)</th>
            <th>IVA (€)</th>
            <th>Total (€)</th>
            <th>Ops</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($vfEntriesPage as $row): ?>
            <tr>
              <td><?php echo htmlspecialchars($row['timestamp']); ?></td>
              <td>
                <a href="index.php?page=view_invoice&id=<?php echo urlencode($row['invoiceId']); ?>">
                  <?php echo htmlspecialchars($row['invoiceId']); ?>
                </a>
              </td>
              <td><?php echo htmlspecialchars($row['issuerNif']); ?></td>
              <td><?php echo htmlspecialchars($row['buyerNif']); ?></td>
              <td style="text-align:right;"><?php echo htmlspecialchars($row['base']); ?></td>
              <td style="text-align:right;"><?php echo htmlspecialchars($row['vat']); ?></td>
              <td style="text-align:right;"><?php echo htmlspecialchars($row['total']); ?></td>
              <td style="text-align:center;"><?php echo (int)$row['opsCount']; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if (($vfTotalPages ?? 1) > 1): ?>
      <div class="pager" style="display:flex; gap:.5rem; align-items:center;">
        <?php $q = $_GET; ?>
        <?php if (($vfPage ?? 1) > 1): ?>
          <?php $q['vf_page'] = $vfPage - 1; ?>
          <a class="btn btn-sm" href="index.php?<?php echo http_build_query($q); ?>">&laquo; Anterior</a>
        <?php endif; ?>
        <span>Página <?php echo (int)($vfPage ?? 1); ?> de <?php echo (int)($vfTotalPages ?? 1); ?></span>
        <?php if (($vfPage ?? 1) < ($vfTotalPages ?? 1)): ?>
          <?php $q['vf_page'] = $vfPage + 1; ?>
          <a class="btn btn-sm" href="index.php?<?php echo http_build_query($q); ?>">Siguiente &raquo;</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <p style="color:var(--text-light)">Aún no hay entradas en el registro.</p>
  <?php endif; ?>
</div>

<style>
  .flex-gap { display: flex; gap: 1.5rem; }
  .flex-1 { flex: 1; }
  .flex-2 { flex: 2; }
  .form-divider { border: none; border-top: 1px solid #e5e7eb; margin: 2.5rem 0; }

  .radio-group-styled { display: flex; gap: 1rem; }
  .radio-group-styled label {
    flex: 1; padding: 1rem; border: 1px solid var(--border-color);
    border-radius: 8px; cursor: pointer; transition: all .2s; text-align: center;
  }
  .radio-group-styled input[type="radio"] { display: none; }
  .radio-group-styled input[type="radio"]:checked + span { font-weight: bold; }
  .radio-group-styled label:has(input:checked) {
    border-color: var(--primary-color); background-color: #f0f5ff; box-shadow: 0 0 0 2px var(--primary-color-light);
  }

  .file-input-hidden { display: none; }
  .file-input-label {
    display: inline-block; padding: .6rem 1rem; background: var(--secondary-color);
    color: var(--text-dark); border-radius: 6px; cursor: pointer; text-align: center; margin-top: .5rem;
  }
  .current-item-display {
    height: 70px; display: flex; align-items: center; justify-content: center;
    border: 1px dashed var(--border-color); border-radius: 8px; padding: .5rem; color: var(--text-light);
  }
  .current-item-display img { max-height: 100%; max-width: 100%; }

  .msg-ok { background: #D1FAE5; color: #065F71; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; }

  .table { width:100%; border-collapse: collapse; }
  .table th, .table td { border-bottom: 1px solid #e5e7eb; padding:.55rem .6rem; }
  .table thead th { background:#f9fafb; text-align:left; font-weight:600; }
</style>

<script src="public/js/autofirma.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const companyFields     = document.getElementById('company-fields');
  const freelancerFields  = document.getElementById('freelancer-fields');
  const companyNameInput  = document.getElementById('companyName');
  const firstNameInput    = document.getElementById('firstName');
  const lastNameInput     = document.getElementById('lastName');
  const secondSurnameInput= document.getElementById('secondSurname');

  function toggleFields() {
    const selected = (document.querySelector('input[name="entityType"]:checked') || {}).value || 'company';
    if (selected === 'company') {
      companyFields.style.display = 'block';
      freelancerFields.style.display = 'none';
      companyNameInput.required = true;
      firstNameInput.required = false;
      lastNameInput.required = false;
      secondSurnameInput.required = false;
    } else {
      companyFields.style.display = 'none';
      freelancerFields.style.display = 'block';
      companyNameInput.required = false;
      firstNameInput.required = true;
      lastNameInput.required = true;
      secondSurnameInput.required = true;
    }
  }
  document.querySelectorAll('input[name="entityType"]').forEach(r => r.addEventListener('change', toggleFields));
  toggleFields();

  // Guardado AJAX
  document.getElementById('settings-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    fd.set('action', 'save_settings');

    try {
      const res = await fetch('index.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });

      const ct  = (res.headers.get('content-type')||'').toLowerCase();
      const raw = await res.text();
      let data  = null;
      if (ct.includes('application/json')) {
        try { data = JSON.parse(raw); } catch(e){}
      }

      if (!res.ok || !data || !data.success) {
        const msg = (data && data.message) ? data.message : ('HTTP '+res.status);
        alert('Error al guardar los datos: ' + msg);
        return;
      }

      alert('Datos guardados con éxito.');
      // recarga para refrescar vistas (logo nombre de archivo, etc.)
      location.href = 'index.php?page=settings&success=1';
    } catch (err) {
      alert('Ha ocurrido un error de red: ' + String(err.message || err));
    }
  });

  const autofirmaBtn = document.getElementById('settings-autofirma-check');
  const autofirmaStatus = document.getElementById('settings-autofirma-status');
  if (autofirmaBtn) {
    autofirmaBtn.addEventListener('click', async () => {
      if (!globalThis.AutofirmaClient || typeof AutofirmaClient.detect !== 'function') {
        if (autofirmaStatus) {
          autofirmaStatus.textContent = 'No se pudo cargar el módulo de AutoFirma.';
        }
        return;
      }
      autofirmaBtn.disabled = true;
      if (autofirmaStatus) {
        autofirmaStatus.textContent = 'Comprobando protocolo afirma://...';
      }
      try {
        const res = await AutofirmaClient.detect({ timeout: 2000 });
        if (autofirmaStatus) {
          autofirmaStatus.textContent = res && res.ok
            ? 'AutoFirma disponible (protocolo).'
            : (res && res.message ? res.message : 'Protocolo afirma:// no disponible. Abre AutoFirma y vuelve a intentar.');
        }
      } catch (err) {
        if (autofirmaStatus) {
          autofirmaStatus.textContent = err && err.message ? err.message : 'No se pudo comprobar AutoFirma.';
        }
      } finally {
        autofirmaBtn.disabled = false;
      }
    });
  }

  // AEAT test
  const btn  = document.getElementById('aeat-test-btn');
  const msg  = document.getElementById('aeat-test-msg');
  const pre  = document.getElementById('aeat-test-ops');
  if (btn) {
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      msg.textContent = 'Probando conexión y leyendo operaciones del WSDL...';
      pre.style.display = 'none';
      pre.textContent = '';
      try{
        const fd = new FormData();
        fd.set('action','aeat_test');
        const res = await fetch('index.php', {
          method:'POST', body: fd, credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const ct  = (res.headers.get('content-type')||'').toLowerCase();
        const raw = await res.text();
        let data  = null;
        if (ct.includes('application/json')) { try{ data = JSON.parse(raw);}catch(e){} }
        if (!res.ok || !data || !data.success){
          msg.innerHTML = '<span style="color:#b00020;">' + (data && data.message ? data.message : 'Error AEAT: HTTP '+res.status) + '</span>';
          if (data && data.operations) { pre.style.display='block'; pre.textContent = JSON.stringify(data.operations, null, 2); }
          btn.disabled=false; return;
        }
        msg.innerHTML = '<span style="color:#036703;">Conexión correcta. Operaciones publicadas por el WSDL:</span>';
        pre.style.display='block';
        pre.textContent = Array.isArray(data.operations) && data.operations.length
          ? data.operations.join('\n')
          : '(No se devolvieron operaciones)';
      }catch(err){
        msg.innerHTML = '<span style="color:#b00020;">' + String(err.message || err) + '</span>';
      }finally{
        btn.disabled = false;
      }
    });
  }
});
</script>
