<?php
/**
 * templates/register.php — Alta inicial del emisor
 * - Logo de cabecera: facturaflow.php (en el mismo nivel que index.php)
 * - IRPF solo para personas físicas (7% o 15%)
 * - NIF sin espacios ni guiones
 * - Certificado .p12/.pfx + contraseña (se guarda cifrada por backend)
 * - Envío robusto: AJAX con fallback a POST normal si el servidor no responde JSON
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Registro — Factura Flow</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="facturaflow.png" rel="icon" type="image/png"/>
  <style>
    :root{
      --bg1:#0ea5e9;  /* sky-500 */
      --bg2:#2563eb;  /* blue-600 */
      --card:#ffffff;
      --text:#0f172a;       /* slate-900 */
      --muted:#6b7280;      /* gray-500 */
      --primary:#111827;    /* gray-900 */
      --primary-contrast:#ffffff;
      --border:#e5e7eb;     /* gray-200 */
      --focus:#0ea5e9;
      --ok-bg:#e6ffed; --ok-text:#036703; --ok-border:#a6e8b0;
      --err-bg:#ffecec; --err-text:#8a0e0e; --err-border:#e8a6a6;
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0;
      font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", Ubuntu, Cantarell, "Apple Color Emoji","Segoe UI Emoji","Segoe UI Symbol";
      color:var(--text);
      background:radial-gradient(1200px 600px at 10% -10%, rgba(255,255,255,.25), rgba(255,255,255,0) 70%),
                 linear-gradient(135deg, var(--bg1), var(--bg2)) fixed;
    }

    /* CABECERA DE MARCA */
    .brandbar{
      max-width:1100px; margin:0 auto;
      padding:22px 16px 8px;
      display:flex; align-items:center; gap:14px; color:#fff;
    }
    .brandbar .logo-wrap{
      width:48px; height:48px; border-radius:14px;
      background:rgba(255,255,255,.18);
      display:grid; place-items:center; overflow:hidden;
      box-shadow: 0 10px 28px rgba(0,0,0,.18);
      border:1px solid rgba(255,255,255,.35);
      transform: translateZ(0);
    }
    .brandbar .logo-wrap img{
      width:100%; height:100%; object-fit:cover; display:block;
      transition: transform .25s ease;
    }
    .brandbar .logo-wrap:hover img{ transform: scale(1.05); }
    .brandbar .titles{ display:flex; flex-direction:column }
    .brandbar .title{ font-weight:800; font-size:22px; letter-spacing:.2px }
    .brandbar .subtitle{ font-size:12px; opacity:.95 }

    .wrap{
      max-width:1100px; margin:18px auto 40px; padding:0 16px;
    }
    .hero{
      color:#fff; margin-bottom:14px;
    }
    .hero h1{ margin:8px 0 6px; font-size:28px; line-height:1.2 }
    .hero p{ margin:0; opacity:.95 }

    .grid{
      display:grid; grid-template-columns: 1.2fr .8fr; gap:18px;
    }
    @media (max-width: 980px){
      .grid{ grid-template-columns: 1fr; }
    }

    .card{
      background:var(--card);
      border:1px solid var(--border);
      border-radius:14px;
      padding:18px;
      box-shadow:0 20px 50px rgba(2, 6, 23, .15);
    }

    fieldset{
      border:1px solid var(--border);
      border-radius:12px;
      padding:14px;
      margin:12px 0;
    }
    legend{
      padding:0 6px; font-weight:700; color:#111827;
    }

    .row{ display:flex; gap:12px; flex-wrap:wrap; }
    .col{ flex:1 1 220px; min-width: 200px }

    label{ display:block; font-weight:600; margin:8px 0 6px; }
    input[type="text"], input[type="password"], input[type="date"], input[type="file"], select, textarea{
      width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px;
      font-size:14px; background:#fff;
      transition:border .15s, box-shadow .15s;
    }
    input:focus, select:focus, textarea:focus{
      outline:none; border-color:var(--focus);
      box-shadow:0 0 0 4px rgba(14,165,233,.15);
    }
    .radio-row{ display:flex; gap:16px; align-items:center; flex-wrap:wrap; margin-top:6px }
    .radio-row label{ font-weight:500; display:flex; align-items:center; gap:8px; margin:0; }
    .help{ color:var(--muted); font-size:12px; margin-top:4px }

    .actions{ display:flex; justify-content:flex-end; gap:10px; margin-top:14px }
    .btn{
      background:var(--primary); color:var(--primary-contrast);
      border:none; border-radius:12px; padding:12px 16px; cursor:pointer; font-weight:700;
      box-shadow: 0 10px 20px rgba(17,24,39,.25); transition: transform .05s ease, box-shadow .2s;
    }
    .btn:hover{ transform: translateY(-1px); box-shadow: 0 16px 32px rgba(17,24,39,.33); }
    .btn[disabled]{ opacity:.6; cursor:not-allowed; transform:none; box-shadow:none }

    .alert{ border-radius:12px; padding:10px 12px; margin-top:12px; font-size:14px }
    .alert.ok{ background:var(--ok-bg); color:var(--ok-text); border:1px solid var(--ok-border) }
    .alert.err{ background:var(--err-bg); color:var(--err-text); border:1px solid var(--err-border) }

    .side-card{
      background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.25);
      border-radius:14px; padding:16px; backdrop-filter: blur(6px); color:#fff;
    }
    .side-list{ margin:0; padding-left:18px }
    .side-list li{ margin:6px 0 }

    .allies{
      margin-top:20px; text-align:center; color:#eaf6ff; font-size:13px;
    }
    .allies img{ margin-top:6px; width:min(520px, 100%); height:auto; border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,.2) }
  </style>
</head>
<body>

  <!-- CABECERA DE MARCA -->
  <div class="brandbar" role="banner" aria-label="Factura Flow">
    <div class="logo-wrap" aria-hidden="true">
      <img src="facturaflow.png" alt="Factura Flow">
    </div>
    <div class="titles">
      <div class="title">Factura Flow</div>
      <div class="subtitle">Emite, firma y cumple — fácil</div>
    </div>
  </div>

  <div class="wrap">
    <div class="hero" role="heading" aria-level="1">
      <h1>Alta inicial del emisor</h1>
      <p>Completa estos datos para empezar a facturar en minutos.</p>
    </div>

    <div class="grid">
      <!-- FORMULARIO -->
      <div class="card" role="region" aria-labelledby="form-title">
        <h2 id="form-title" style="margin:0 0 8px; font-size:18px;">Tus datos</h2>

        <form id="register-form" action="index.php" method="post" enctype="multipart/form-data" novalidate>
          <input type="hidden" name="action" value="register_user">

          <fieldset>
            <legend>Tipo de emisor</legend>
            <div class="radio-row">
              <label><input type="radio" name="entityType" value="company" checked> Empresa / Persona jurídica</label>
              <label><input type="radio" name="entityType" value="freelancer"> Persona física (autónomo/a)</label>
            </div>
          </fieldset>

          <fieldset id="company-box">
            <legend>Datos de la empresa</legend>
            <div class="row">
              <div class="col">
                <label for="companyName">Razón social</label>
                <input id="companyName" name="companyName" type="text" autocomplete="organization">
              </div>
            </div>
          </fieldset>

          <fieldset id="person-box" style="display:none;">
            <legend>Datos de la persona</legend>
            <div class="row">
              <div class="col">
                <label for="firstName">Nombre</label>
                <input id="firstName" name="firstName" type="text" autocomplete="given-name">
              </div>
              <div class="col">
                <label for="lastName">Primer apellido</label>
                <input id="lastName" name="lastName" type="text" autocomplete="family-name">
              </div>
              <div class="col">
                <label for="secondSurname">Segundo apellido</label>
                <input id="secondSurname" name="secondSurname" type="text" autocomplete="additional-name">
              </div>
            </div>
          </fieldset>

          <fieldset>
            <legend>Identificación y domicilio</legend>
            <div class="row">
              <div class="col">
                <label for="nif">NIF/CIF</label>
                <input id="nif" name="nif" required pattern="[A-Za-z0-9]+"
                       oninput="this.value=this.value.replace(/[\s-]/g,'').toUpperCase()"
                       placeholder="12345678Z o B12345678" aria-describedby="nifHelp">
                <div class="help" id="nifHelp">Sin espacios ni guiones.</div>
              </div>

              <div class="col" id="irpf-box" style="display:none;">
                <label for="irpfRate">IRPF (solo personas físicas)</label>
                <select id="irpfRate" name="irpfRate" disabled aria-describedby="irpfHelp">
                  <option value="">-- Selecciona 7% o 15% --</option>
                  <option value="7">7%</option>
                  <option value="15">15%</option>
                </select>
                <div class="help" id="irpfHelp">El IRPF solo aplica si eres persona física.</div>
              </div>
            </div>

            <div class="row">
              <div class="col">
                <label for="address">Dirección</label>
                <input id="address" name="address" required autocomplete="address-line1">
              </div>
            </div>
            <div class="row">
              
              <div class="col">
                <label for="town">Municipio</label>
                <input id="town" name="town" required autocomplete="address-level2">
              </div>
              
              <div class="col">
                <label for="province">Provincia</label>
                <input id="province" name="province" required autocomplete="address-level1">
              </div>
              <div class="col">
                <label for="postCode">Código Postal</label>
                <input id="postCode" name="postCode" required autocomplete="postal-code">
              </div>
            </div>
          </fieldset>
          
          <div class="form-group">
		  <label for="email">Email de facturación</label>
		  <input
		    type="email"
		    id="email"
		    name="email"
		    class="form-control"
		    placeholder="ej.: facturacion@tu-dominio.com"
		    inputmode="email"
		  >
		</div>

          

          <fieldset>
            <legend>Credenciales de acceso</legend>
            <div class="row">
              <div class="col">
                <label for="password">Contraseña</label>
                <input id="password" name="password" type="password" required autocomplete="new-password" minlength="6">
              </div>
              <div class="col">
                <label for="password_confirm">Repite la contraseña</label>
                <input id="password_confirm" name="password_confirm" type="password" required autocomplete="new-password" minlength="6">
              </div>
            </div>
            <div class="help">Usa al menos 6 caracteres.</div>
            
            <!-- ===== DIRe (FACeB2B) ===== -->
		  <div class="col">
		  <label for="dire_id">Identificador DIRe (FACeB2B)</label>
		  <input
		    type="text"
		    id="dire_id"
		    name="dire"
		    inputmode="latin"
		    pattern="[A-Z0-9\-\._]{3,32}"
		    title="3–32 caracteres en mayúsculas, números o - . _"
		    class="form-control"
		    placeholder="p.ej. ES12345678 o ABC-0001"
		    required
		  />
		  </div>
		  <div class="help">
		    Tu identificador en el Directorio DIRe para poder <strong>enviar/recibir</strong> mediante FACeB2B.
		  </div>
          </fieldset>
          
          <div class="form-group">
		  <label for="iban">IBAN (cuenta bancaria)</label>
		  <input
		    type="text"
		    id="iban"
		    name="iban"
		    class="form-control"
		    placeholder="ej.: ES66 1234 5678 9012 3456 7890"
		    pattern="^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$"
		    title="IBAN en formato internacional (2 letras país, 2 dígitos control, resto alfanumérico)"
		    oninput="this.value=this.value.replace(/\s+/g,'').toUpperCase()"
		  >
		</div>

          

          <fieldset>
            <legend>Certificado y logo</legend>
            <div class="row">
              <div class="col">
                <label for="certificate">Certificado (.p12 / .pfx)</label>
                <input id="certificate" name="certificate" type="file" accept=".p12,.pfx,application/x-pkcs12">
              </div>
              <div class="col">
                <label for="certPassword">Contraseña del certificado</label>
                <input id="certPassword" name="certPassword" type="password" autocomplete="new-password">
                <div class="help">Se almacenará cifrada.</div>
              </div>
            </div>
            <div class="row">
              <div class="col">
                <label for="logo">Logo (opcional)</label>
                <input id="logo" name="logo" type="file" accept="image/*">
              </div>
            </div>
          </fieldset>

          <div id="form-msg" aria-live="polite"></div>

          <div class="actions">
            <button id="submit-btn" class="btn" type="submit">Registrar y empezar</button>
          </div>
        </form>
      </div>

      <!-- LADO DERECHO: copy & confianza -->
      <div class="side-card" role="complementary" aria-label="Acerca de Factura Flow">
        <h3 style="margin-top:0; margin-bottom:8px;">Listo para Veri*Factu</h3>
        <ul class="side-list">
          <li>Emite y firma con tu certificado digital.</li>
          <li>Rectificativas y exportación Factura-e.</li>
          <li>Envío a AEAT.</li>
          <li>Integración con FACeB2B.</li>
          <li>Registro inmutable de Veri*Factu.</li>
        </ul>
        <p class="help" style="color:#e5efff; margin-top:12px;">
          Software creado en el rural.
        </p>
      </div>
    </div>

    <!-- Créditos/aliados -->
    <div class="allies">
      <div>Factura Flow es un proyecto nacido gracias a la colaboración de</div>
      <img src="aliados.png" alt="Aliados">
    </div>
  </div>

  <script>
  (function(){
    const form = document.getElementById('register-form');
    const msg  = document.getElementById('form-msg');
    const btn  = document.getElementById('submit-btn');

    const companyBox = document.getElementById('company-box');
    const personBox  = document.getElementById('person-box');
    const irpfBox    = document.getElementById('irpf-box');
    const irpfSel    = document.getElementById('irpfRate');

    function toggleType(){
      const type = (form.querySelector('input[name="entityType"]:checked')||{}).value || 'company';
      const isPF = (type === 'freelancer');

      companyBox.style.display = isPF ? 'none' : '';
      personBox.style.display  = isPF ? '' : 'none';

      if (form.companyName) form.companyName.required = !isPF;
      if (form.firstName)   form.firstName.required   = isPF;
      if (form.lastName)    form.lastName.required    = isPF;

      irpfBox.style.display  = isPF ? '' : 'none';
      irpfSel.disabled       = !isPF;
      irpfSel.required       = isPF;
      if (!isPF) irpfSel.value = '';
    }
    const typeRadios = document.querySelectorAll('input[name="entityType"]');
    typeRadios.forEach(r => r.addEventListener('change', toggleType));
    toggleType();

    function showError(text){
      msg.innerHTML = '<div class="alert err">'+(text||'Error')+'</div>';
    }
    function showOk(text){
      msg.innerHTML = '<div class="alert ok">'+(text||'Hecho')+'</div>';
    }

    async function onSubmit(e){
      e.preventDefault();

      // NIF limpio
      if (form.nif) form.nif.value = (form.nif.value||'').replace(/[\s-]/g,'').toUpperCase();

      // Validaciones mínimas
      if ((form.password.value||'').length < 6){
        return showError('La contraseña debe tener al menos 6 caracteres.');
      }
      if (form.password.value !== form.password_confirm.value){
        return showError('Las contraseñas no coinciden.');
      }

      // IRPF si PF
      const type = (form.querySelector('input[name="entityType"]:checked')||{}).value || 'company';
      if (type === 'freelancer'){
        const v = String(form.irpfRate.value||'');
        if (!['7','15'].includes(v)) return showError('Selecciona el IRPF (7% o 15%).');
      }

      btn.disabled = true;
      showOk('Enviando...');

      const fd = new FormData(form);
      fd.set('action','register_user');

      try{
        const res = await fetch('index.php', { method:'POST', body: fd, credentials:'same-origin' });
        const ct  = (res.headers.get('content-type')||'').toLowerCase();
        const raw = await res.text();

        if (!ct.includes('application/json')){
          // Fallback: envío normal si el backend no devolvió JSON
          form.removeEventListener('submit', onSubmit);
          form.submit();
          return;
        }

        let data = null;
        try { data = JSON.parse(raw); } catch(_){}

        if (!res.ok || !data || data.success !== true){
          const m = (data && data.message) ? data.message : ('Error del servidor. Código HTTP '+res.status);
          showError(m);
          btn.disabled = false;
          return;
        }

        // OK → redirigir
        window.location.href = data.redirect || 'index.php?page=dashboard';
      } catch (err){
        // Fallback final
        form.removeEventListener('submit', onSubmit);
        form.submit();
      }
    }

    form.addEventListener('submit', onSubmit);
  })();
  </script>
</body>
</html>

