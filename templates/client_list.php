<?php /** templates/client_list.php */ ?>
<!-- Título removido por solicitud -->

<div class="layout-container" style="display:flex;gap:2rem;align-items:flex-start;">

  <!-- ALTA -->
  <div class="card form-card" style="flex:1;">
    <h3>Añadir Nuevo Cliente</h3>

    <form id="add-client-form" action="index.php" method="post">
      <input type="hidden" name="action" value="add_client">

      <div class="form-group">
        <label>Tipo de Cliente</label>
        <div class="radio-group-styled">
          <label><input type="radio" name="entityType" value="freelancer"> <span>👤<br>Persona Física</span></label>
          <label><input type="radio" name="entityType" value="company" checked> <span>🏢<br>Empresa u Organización</span></label>
          <label><input type="radio" name="entityType" value="public_admin"> <span>🏛️<br>Adm. Pública</span></label>
        </div>
      </div>

      <!-- Denominación / Nombre visible también para AAPP -->
      <div id="company-fields">
        <div class="form-group">
          <label for="name" id="name-label">Denominación / Razón Social</label>
          <input type="text" id="name" name="name" class="form-control" required placeholder="p. ej. Ayuntamiento de … / Consejería de …">
        </div>
      </div>

      <!-- Persona física -->
      <div id="freelancer-fields" style="display:none;">
        <div class="form-group"><label for="firstName">Nombre</label><input type="text" id="firstName" name="firstName" class="form-control"></div>
        <div class="form-group"><label for="lastName">Primer Apellido</label><input type="text" id="lastName" name="lastName" class="form-control"></div>
        <div class="form-group"><label for="secondSurname">Segundo Apellido</label><input type="text" id="secondSurname" name="secondSurname" class="form-control"></div>
      </div>

      <!-- Administración Pública (FACE) -->
      <div id="public-admin-fields-add" style="display:none;">
        <p class="muted" style="margin:.25rem 0 1rem;">Para FACE son necesarios los códigos DIR3:</p>

        <div class="form-group">
          <label for="face_dir3_oc_add">DIR3 OC (Oficina Contable)</label>
          <input type="text" id="face_dir3_oc_add" name="dir3OC" class="form-control"
                 placeholder="L01110001" pattern="[A-Z0-9]{3,15}"
                 oninput="this.value=this.value.replace(/\s/g,'').toUpperCase()">
        </div>

        <div class="form-group">
          <label for="face_dir3_og_add">DIR3 OG (Órgano Gestor)</label>
          <input type="text" id="face_dir3_og_add" name="dir3OG" class="form-control"
                 placeholder="A01007373" pattern="[A-Z0-9]{3,15}"
                 oninput="this.value=this.value.replace(/\s/g,'').toUpperCase()">
        </div>

        <div class="form-group">
          <label for="face_dir3_ut_add">DIR3 UT (Unidad Tramitadora)</label>
          <input type="text" id="face_dir3_ut_add" name="dir3UT" class="form-control"
                 placeholder="GE0012345" pattern="[A-Z0-9]{3,15}"
                 oninput="this.value=this.value.replace(/\s/g,'').toUpperCase()">
        </div>

        <details style="margin:.5rem 0;">
          <summary style="cursor:pointer;">Campos FACE opcionales</summary>
          <div class="form-group" style="margin-top:.5rem;">
            <label for="face_expedient">Expediente (opcional)</label>
            <input type="text" id="face_expedient" name="face_expedient" class="form-control" pattern="[A-Za-z0-9_./-]{0,64}">
          </div>
          <div class="form-group">
            <label for="face_contract_ref">Contrato (opcional)</label>
            <input type="text" id="face_contract_ref" name="face_contract_ref" class="form-control" pattern="[A-Za-z0-9_./-]{0,64}">
          </div>
        </details>
      </div>

      <div class="form-group">
        <label for="nif">NIF/CIF</label>
        <input type="text" id="nif" name="nif" class="form-control"
               required pattern="[A-Za-z0-9]+"
               oninput="this.value=this.value.replace(/[\s-]/g,'').toUpperCase()">
        <small class="help-text">Sin espacios ni guiones.</small>
      </div>

      <!-- DIRe solo para empresa/persona, NO para AAPP -->
      <div id="dire-block-add" class="form-group">
        <label for="dire">DIRe (FACeB2B)</label>
        <input type="text" id="dire" name="dire" class="form-control"
               placeholder="ES111111110000"
               pattern="[A-Z0-9._-]{3,32}"
               oninput="this.value=this.value.toUpperCase()">
        <small class="help-text">Si el cliente dispone de DIRe, introdúcelo para FACeB2B.</small>
      </div>

      <div class="form-group"><label for="address">Dirección</label><input type="text" id="address" name="address" class="form-control" required></div>
      <div class="form-group"><label for="town">Ciudad</label><input type="text" id="town" name="town" class="form-control" required></div>
      <div class="form-group"><label for="province">Provincia</label><input type="text" id="province" name="province" class="form-control" required></div>
      <div class="form-group"><label for="postCode">Cód. Postal</label><input type="text" id="postCode" name="postCode" class="form-control" required></div>

      <div class="form-group">
        <label for="residency">Residencia fiscal</label>
        <select id="residency" name="residency" class="form-control">
          <option value="resident_es" selected>Residente en España</option>
          <option value="eu">Residente en la UE (no España)</option>
          <option value="non_eu">No residente (fuera de la UE)</option>
        </select>
        <small class="help-text">Se trasladará a Facturae como ResidenceTypeCode.</small>
      </div>

      <div class="form-group">
        <label for="countryCode">País</label>
        <input type="text" id="countryCode" name="countryCode" class="form-control" value="ESP" required>
        <small class="help-text">Código país ISO 3166-1 alfa-3 (ej.: ESP, FRA, PRT)</small>
      </div>

      <button type="submit" class="btn btn-primary" onclick="return window.__submitAddClient && __submitAddClient(event)">Guardar Cliente</button>
    </form>
  </div>

  <!-- LISTADO -->
  <div class="card list-card" style="flex:2;">
    <h3>Clientes Existentes</h3>
    <?php
      // Paginación: 16 por página
      $perPage = 16;
      $pageParam = 'p';
      $currPage = max(1, (int)($_GET[$pageParam] ?? 1));
      $all = is_array($clients) ? $clients : (is_iterable($clients) ? iterator_to_array($clients) : []);
      $all = array_values($all);
      $total = count($all);
      $totalPages = max(1, (int)ceil($total / $perPage));
      if ($currPage > $totalPages) { $currPage = $totalPages; }
      $pageItems = array_slice($all, ($currPage - 1) * $perPage, $perPage);
    ?>
    <table>
      <thead>
        <tr>
          <th>Nombre</th>
          <th>NIF/CIF</th>
          <th>Residencia</th>
          <th>Domicilio</th>
          <th style="text-align:center;">DIRe</th>
          <th style="text-align:center;">DIR3</th>
          <th style="text-align:center;">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pageItems)): ?>
          <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text-light);">No hay clientes.</td></tr>
        <?php else: foreach ($pageItems as $client):
          $dire = trim((string)($client->dire ?? ''));
          $hasDire = $dire !== '';
          $oc = trim((string)($client->dir3OC ?? $client->dir3_accounting ?? ''));
          $og = trim((string)($client->dir3OG ?? $client->dir3_managing ?? ''));
          $ut = trim((string)($client->dir3UT ?? $client->dir3_processing ?? ''));
          $hasDir3 = ($oc !== '' && $og !== '' && $ut !== '');
          $dir3Title = 'DIR3: '.($oc? "OC=$oc":"OC=—").' · '.($og? "OG=$og":"OG=—").' · '.($ut? "UT=$ut":"UT=—");
        ?>
          <tr>
            <td><?= htmlspecialchars((string)($client->name ?? $client->firstName ?? '')) ?></td>
            <td><?= htmlspecialchars((string)$client->nif) ?></td>
            <?php
              $res = (string)($client->residency ?? '');
              $lbl = $res==='resident_es' ? 'ES' : ($res==='eu' ? 'UE' : ($res==='non_eu' ? 'No UE' : '—'));
            ?>
            <td><?= htmlspecialchars($lbl) ?></td>
            <td><?= htmlspecialchars((string)$client->address) ?><br><?= htmlspecialchars((string)$client->postCode) ?> <?= htmlspecialchars((string)$client->town) ?></td>
            <td class="dire-dot" style="text-align:center;" title="<?= $hasDire ? ('DIRe: '.htmlspecialchars($dire)) : 'Sin DIRe' ?>"><span class="dot <?= $hasDire ? 'green' : 'grey' ?>"></span></td>
            <td class="dir3-dot" style="text-align:center;" title="<?= htmlspecialchars($dir3Title) ?>"><span class="dot <?= $hasDir3 ? 'green' : 'grey' ?>"></span></td>
            <td style="text-align:center;"><a href="index.php?page=edit_client&id=<?= urlencode((string)$client->id) ?>" class="btn">Editar</a></td>
          </tr>
        <?php endforeach; endif; ?>
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
          $blocks = [];
          if ($totalPages <= 12) { $blocks[] = [1,$totalPages]; $useDots=false; }
          else { $blocks[] = [1,8]; $blocks[] = [$totalPages-3,$totalPages]; $useDots=true; }
          foreach ($blocks as $idx=>$range) {
            [$a,$b] = $range;
            if ($idx>0 && $useDots) echo '<span style="opacity:.6;">…</span>';
            for ($n=$a; $n<=$b; $n++) {
              $q[$pageParam] = $n;
              if ($n === $currPage) echo '<span class="btn btn-sm" style="pointer-events:none; font-weight:600;">'.(int)$n.'</span>';
              else echo '<a class="btn btn-sm" href="index.php?'.htmlspecialchars(http_build_query($q)).'">'.(int)$n.'</a>';
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
</div>

<style>
/* Botones base celestes (consistentes con recibidas/enviadas) */
.btn{ display:inline-block; padding:.4rem .7rem; border-radius:.4rem; background:#e6f4ff; color:#0b74c4; text-decoration:none; border:1px solid #b3dbff; cursor:pointer; }
.btn:hover{ background:#dbeeff; }
.radio-group-styled{display:flex;gap:.5rem}
.radio-group-styled label{flex:1;padding:.75rem;border:1px solid #eee;border-radius:6px;cursor:pointer;text-align:center;transition:.2s}
.radio-group-styled input{display:none}
.radio-group-styled input:checked+span{font-weight:700}
.radio-group-styled label:has(input:checked){border-color:var(--primary-color);background:#f0f5ff}
.dot{display:inline-block;width:10px;height:10px;border-radius:50%;background:#cfcfcf}
.dot.green{background:#2ecc71}
.dot.grey{background:#d0d0d0}
.muted{color:#666;font-size:.9rem}
</style>

<script>
// --- UI ---
(function(){
  function $(q,ctx){ return (ctx||document).querySelector(q); }
  function $$ (q,ctx){ return Array.prototype.slice.call((ctx||document).querySelectorAll(q)); }

  function toggleBlocks(){
    var form = $('#add-client-form'); if(!form) return;
    var type = (form.querySelector('input[name="entityType"]:checked')||{}).value || 'company';
    var isAdmin = type==='public_admin', isFree=type==='freelancer';

    $('#freelancer-fields').style.display     = isFree ? 'block':'none';
    $('#public-admin-fields-add').style.display = isAdmin ? 'block':'none';
    $('#dire-block-add').style.display        = isAdmin ? 'none':'block';

    // required dinámicos
    var name = $('#name'), fn=$('#firstName'), ln=$('#lastName'), sn=$('#secondSurname');
    if (name) { if (isAdmin || !isFree) name.setAttribute('required','required'); else name.removeAttribute('required'); }
    if (fn)   { if (isFree) fn.setAttribute('required','required'); else fn.removeAttribute('required'); }
    if (ln)   { if (isFree) ln.setAttribute('required','required'); else ln.removeAttribute('required'); }
    if (sn)   { if (isFree) sn.removeAttribute('required'); } // no obligatorio

    var nameLabel = $('#name-label');
    if (nameLabel) nameLabel.textContent = isAdmin ? 'Denominación (AAPP)' : 'Denominación / Razón Social';
  }

  function bind(){
    var form = $('#add-client-form'); if(!form) return;
    $$('#add-client-form input[name="entityType"]').forEach(r => r.addEventListener('change', toggleBlocks));
    toggleBlocks();

    // listener principal
    form.addEventListener('submit', function(ev){
      ev.preventDefault();
      window.__submitAddClient && window.__submitAddClient(ev);
    });
  }

  if (document.readyState==='loading') document.addEventListener('DOMContentLoaded', bind, {once:true});
  else bind();
})();

// --- SUBMIT (AJAX con fallback) ---
window.__submitAddClient = function(ev){
  var form = document.getElementById('add-client-form');
  if (!form) return false;

  // Valida con API nativa
  if (form.checkValidity && !form.checkValidity()){
    if (form.reportValidity) form.reportValidity();
    return false;
  }

  var fd = new FormData(form);

  // Normaliza NIF/country
  var nif = (fd.get('nif')||'').toString().replace(/[\s-]+/g,'').toUpperCase();
  fd.set('nif', nif);
  var cc = ((fd.get('countryCode')||'')+'').toUpperCase();
  fd.set('countryCode', cc && cc!=='ES' ? cc : 'ESP');

  // Si es freelancer, compón 'name' por si el server lo necesita
  var et = (fd.get('entityType')||'company')+'';
  if (et==='freelancer'){
    var full = [fd.get('firstName')||'', fd.get('lastName')||'', fd.get('secondSurname')||''].map(x=> (x||'').toString().trim()).filter(Boolean).join(' ');
    if (full) fd.set('name', full);
  }

  fetch('index.php', { method:'POST', body:fd, credentials:'same-origin' })
    .then(res => Promise.all([res.ok, (res.headers.get('content-type')||'').toLowerCase(), res.text()]))
    .then(([ok, ct, text]) => {
      var json = ct.includes('application/json') ? JSON.parse(text) : null;
      if(!ok || !json){
        alert('Error al añadir el cliente:\n' + (text || 'Respuesta no válida'));
        // Fallback: envío normal para no dejar el botón “muerto”
        form.submit();
        return;
      }
      if(json.success){
        alert('Cliente añadido con éxito.');
        location.reload();
      }else{
        alert('Error: ' + (json.message || 'Causa desconocida.'));
      }
    })
    .catch(err => {
      alert('Error de red: ' + (err && err.message ? err.message : err));
      form.submit(); // última red de seguridad
    });

  return false;
};
</script>
