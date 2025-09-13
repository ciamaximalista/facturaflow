<?php
if (empty($client)) {
  echo "<h2>Error</h2><p>El cliente no ha sido encontrado.</p>";
  return;
}
$etype = strtolower((string)($client->entityType ?? 'company'));
if (!in_array($etype, ['company','freelancer','public_admin'], true)) $etype = 'company';

// Compatibilidad nombres DIR3 antiguos
$dir3OC = (string)($client->dir3OC ?? $client->face_dir3_oc ?? $client->dir3_accounting ?? '');
$dir3OG = (string)($client->dir3OG ?? $client->face_dir3_og ?? $client->dir3_managing  ?? '');
$dir3UT = (string)($client->dir3UT ?? $client->face_dir3_ut ?? $client->dir3_processing?? '');
?>
<h2>Editar Cliente: <?php echo htmlspecialchars((string)($client->name ?? $client->firstName ?? '')); ?></h2>

<div class="card">
  <form id="edit-client-form">
    <input type="hidden" name="action" value="update_client">
    <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$client->id); ?>">
    <input type="hidden" name="entityType" id="entityTypeInput" value="<?php echo htmlspecialchars($etype); ?>">

    <!-- Tabs tipo de cliente -->
    <div class="form-group">
      <label>Tipo de Cliente</label>
      <div class="tabs" role="tablist">
        <button type="button" class="tab-btn" data-type="company">🏢 Empresa u Organización</button>
        <button type="button" class="tab-btn" data-type="freelancer">👤 Persona Física</button>
        <button type="button" class="tab-btn" data-type="public_admin">🏛 Administración Pública</button>
      </div>
    </div>

    <!-- Empresa / Org + también se usa el campo name para AAPP -->
    <div id="company-fields">
      <div class="form-group">
        <label for="name" id="name-label">Denominación / Razón Social</label>
        <input type="text" id="name" name="name" class="form-control"
               value="<?php echo htmlspecialchars((string)($client->name ?? '')); ?>">
      </div>
    </div>

    <!-- Persona física -->
    <div id="freelancer-fields">
      <div class="form-group">
        <label for="firstName">Nombre</label>
        <input type="text" id="firstName" name="firstName" class="form-control"
               value="<?php echo htmlspecialchars((string)($client->firstName ?? '')); ?>">
      </div>
      <div class="form-group">
        <label for="lastName">Primer Apellido</label>
        <input type="text" id="lastName" name="lastName" class="form-control"
               value="<?php echo htmlspecialchars((string)($client->lastName ?? '')); ?>">
      </div>
      <div class="form-group">
        <label for="secondSurname">Segundo Apellido</label>
        <input type="text" id="secondSurname" name="secondSurname" class="form-control"
               value="<?php echo htmlspecialchars((string)($client->secondSurname ?? '')); ?>">
      </div>
    </div>

    <!-- Administración Pública (FACE) -->
    <div id="public-admin-fields" style="display:none;">
      <p class="muted" style="margin:.25rem 0 1rem;">Para FACE son necesarios los códigos DIR3:</p>

      <div class="form-group">
        <label for="face_dir3_oc">DIR3 Oficina Contable (OC)</label>
        <input type="text" id="face_dir3_oc" name="dir3OC" class="form-control"
               value="<?php echo htmlspecialchars($dir3OC); ?>"
               pattern="[A-Z0-9.-]{3,32}" oninput="this.value=this.value.toUpperCase()">
      </div>
      <div class="form-group">
        <label for="face_dir3_og">DIR3 Órgano Gestor (OG)</label>
        <input type="text" id="face_dir3_og" name="dir3OG" class="form-control"
               value="<?php echo htmlspecialchars($dir3OG); ?>"
               pattern="[A-Z0-9.-]{3,32}" oninput="this.value=this.value.toUpperCase()">
      </div>
      <div class="form-group">
        <label for="face_dir3_ut">DIR3 Unidad Tramitadora (UT)</label>
        <input type="text" id="face_dir3_ut" name="dir3UT" class="form-control"
               value="<?php echo htmlspecialchars($dir3UT); ?>"
               pattern="[A-Z0-9.-]{3,32}" oninput="this.value=this.value.toUpperCase()">
      </div>
      <small class="help-text">Los tres códigos DIR3 son necesarios para FACe.</small>
    </div>

    <div class="form-group">
      <label for="nif">NIF/CIF</label>
      <input type="text" id="nif" name="nif" class="form-control"
             value="<?php echo htmlspecialchars((string)$client->nif); ?>"
             pattern="[A-Za-z0-9]+"
             oninput="this.value=this.value.replace(/[\s-]/g,'').toUpperCase()" required>
      <small class="help-text">Sin espacios ni guiones.</small>
    </div>

    <div class="form-group">
      <?php
        $cc = strtoupper((string)($client->countryCode ?? 'ESP'));
        $euSet = ['AUT','BEL','BGR','CYP','CZE','DEU','DNK','ESP','EST','FIN','FRA','GRC','HRV','HUN','IRL','ITA','LTU','LUX','LVA','MLT','NLD','POL','PRT','ROU','SVK','SVN','SWE'];
        $defaultResidency = ($cc==='ESP') ? 'resident_es' : (in_array($cc, $euSet, true) ? 'eu' : 'non_eu');
        $residency = (string)($client->residency ?? $defaultResidency);
      ?>
      <label for="residency">Residencia fiscal</label>
      <select id="residency" name="residency" class="form-control">
        <option value="resident_es" <?= ($residency==='resident_es'?'selected':'') ?>>Residente en España</option>
        <option value="eu" <?= ($residency==='eu'?'selected':'') ?>>Residente en la UE (no España)</option>
        <option value="non_eu" <?= ($residency==='non_eu'?'selected':'') ?>>No residente (fuera de la UE)</option>
      </select>
      <small class="help-text">Este dato se envía en el Facturae (ResidenceTypeCode).</small>
    </div>

    <!-- DIRe SOLO visible si NO es public_admin -->
    <div id="dire-block">
      <label for="dire">DIRe (FACeB2B)</label>
      <input type="text" id="dire" name="dire" class="form-control"
             value="<?php echo htmlspecialchars((string)($client->dire ?? '')); ?>"
             placeholder="ES111111110000"
             pattern="[A-Z0-9._-]{3,32}"
             oninput="this.value=this.value.toUpperCase()">
      <small>Si el cliente dispone de DIRe, introdúcelo para FACeB2B.</small>
    </div>

    <div class="form-group">
      <label for="address">Dirección</label>
      <input type="text" id="address" name="address" class="form-control"
             value="<?php echo htmlspecialchars((string)$client->address); ?>" required>
    </div>
    <div class="form-group">
      <label for="postCode">Cód. Postal</label>
      <input type="text" id="postCode" name="postCode" class="form-control"
             value="<?php echo htmlspecialchars((string)$client->postCode); ?>" required>
    </div>
    <div class="form-group">
      <label for="town">Ciudad</label>
      <input type="text" id="town" name="town" class="form-control"
             value="<?php echo htmlspecialchars((string)$client->town); ?>" required>
    </div>
    <div class="form-group">
      <label for="province">Provincia</label>
      <input type="text" id="province" name="province" class="form-control"
             value="<?php echo htmlspecialchars((string)$client->province); ?>" required>
    </div>
    <div class="form-group">
      <label for="countryCode">País</label>
      <input type="text" id="countryCode" name="countryCode" class="form-control"
             value="<?php echo htmlspecialchars((string)($client->countryCode ?: 'ESP')); ?>" required>
      <small class="help-text">Código país ISO 3166-1 alfa-3 (ej.: ESP, FRA, PRT)</small>
    </div>

    <div style="text-align:right;margin-top:2rem;display:flex;gap:.5rem;justify-content:flex-end;">
      <button type="button" class="btn" id="delete-client" style="background:#d9534f;color:#fff;">Borrar</button>
      <button type="submit" class="btn btn-primary">Guardar Cambios</button>
      <a href="index.php?page=clients" class="btn" style="background-color:var(--text-light);color:white;">Cancelar</a>
    </div>
  </form>
</div>

<style>
.tabs{display:flex;gap:.5rem;margin:.25rem 0 .75rem;}
.tab-btn{padding:.5rem .75rem;border:1px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer}
.tab-btn.active{border-color:var(--primary-color);background:#f0f5ff;font-weight:600}
.muted{color:#666;font-size:.9rem}
</style>

<script>
(function(){
  const form = document.getElementById('edit-client-form');
  if(!form) return;

  const etInput = document.getElementById('entityTypeInput');
  const tabs = Array.from(document.querySelectorAll('.tab-btn'));
  const companyBox = document.getElementById('company-fields');
  const freeBox    = document.getElementById('freelancer-fields');
  const paBox      = document.getElementById('public-admin-fields');
  const direBox    = document.getElementById('dire-block');
  const nameInput  = document.getElementById('name');
  const fnInput    = document.getElementById('firstName');
  const lnInput    = document.getElementById('lastName');
  const nameLabel  = document.getElementById('name-label');

  function setActive(type){
    etInput.value = type;
    tabs.forEach(b => b.classList.toggle('active', b.dataset.type===type));

    const isAdmin = type==='public_admin';
    const isFree  = type==='freelancer';
    const isComp  = type==='company';

    companyBox.style.display = (isComp || isAdmin) ? 'block':'none';
    freeBox.style.display    = isFree ? 'block':'none';
    paBox.style.display      = isAdmin ? 'block':'none';
    direBox.style.display    = isAdmin ? 'none':'block';

    // required dinámicos
    if (nameInput){
      if (isAdmin || isComp) nameInput.setAttribute('required','required');
      else nameInput.removeAttribute('required');
    }
    if (fnInput){
      if (isFree) fnInput.setAttribute('required','required');
      else fnInput.removeAttribute('required');
    }
    if (lnInput){
      if (isFree) lnInput.setAttribute('required','required');
      else lnInput.removeAttribute('required');
    }

    if (nameLabel) nameLabel.textContent = isAdmin ? 'Denominación (AAPP)' : 'Denominación / Razón Social';
  }

  // Inicialización
  setActive(etInput.value || 'company');
  tabs.forEach(b => b.addEventListener('click', () => setActive(b.dataset.type)));

  // Guardar
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);

    // Normaliza countryCode y NIF
    const cc = (fd.get('countryCode')||'').toString().toUpperCase();
    fd.set('countryCode', cc && cc!=='ES' ? cc : 'ESP');
    const nif = (fd.get('nif')||'').toString().replace(/[\s-]+/g,'').toUpperCase();
    fd.set('nif', nif);

    // Si es persona física, compón name para el servidor (igual que en add)
    const et = (fd.get('entityType')||'company').toString();
    if (et==='freelancer'){
      const full = [fd.get('firstName')||'', fd.get('lastName')||'', fd.get('secondSurname')||'']
                    .map(x => (x||'').toString().trim()).filter(Boolean).join(' ');
      if (full) fd.set('name', full);
    }

    try{
      const res = await fetch('index.php', { method:'POST', body:fd, credentials:'same-origin' });
      const ct  = (res.headers.get('content-type')||'').toLowerCase();
      const raw = await res.text();
      const json = ct.includes('application/json') ? JSON.parse(raw) : null;

      if (!json || !res.ok){
        alert('Error al actualizar el cliente:\n' + (raw || 'Respuesta no válida'));
        return;
      }
      if (json.success){
        alert('Cliente actualizado con éxito.');
        location.href = 'index.php?page=clients';
      } else {
        alert('Error: ' + (json.message || 'Causa desconocida.'));
      }
    } catch(err){
      alert('Ha ocurrido un error de red.');
    }
  });

  // Autoajuste país según residencia
  const selResidency = document.getElementById('residency');
  const inpCountry   = document.getElementById('countryCode');
  if (selResidency && inpCountry) {
    selResidency.addEventListener('change', () => {
      const v = selResidency.value;
      if (v === 'resident_es') inpCountry.value = 'ESP';
    });
  }

  // Borrar
  document.getElementById('delete-client')?.addEventListener('click', async () => {
    if (!confirm('¿Seguro que deseas borrar este cliente?')) return;
    const fd = new FormData();
    fd.set('action','delete_client');
    fd.set('id', '<?php echo htmlspecialchars((string)$client->id); ?>');

    try{
      const res = await fetch('index.php', { method:'POST', body:fd, credentials:'same-origin' });
      const ct  = (res.headers.get('content-type')||'').toLowerCase();
      const raw = await res.text();
      const json = ct.includes('application/json') ? JSON.parse(raw) : null;
      if (!json || !res.ok || !json.success){
        alert('No se pudo borrar:\n' + (json?.message || raw || 'Error desconocido'));
        return;
      }
      location.href = 'index.php?page=clients';
    } catch(e){
      alert('Error de red al borrar.');
    }
  });
})();
</script>
