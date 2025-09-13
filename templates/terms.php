<?php
// templates/terms.php — Condiciones de Uso (página pública)
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Condiciones de Uso — Factura Flow</title>
  <link href="facturaflow.png" rel="icon" type="image/png"/>
  <style>
    :root{ --bg1:#0ea5e9; --bg2:#2563eb; --card:#ffffff; --text:#0f172a; --border:#e5e7eb; }
    *{ box-sizing:border-box }
    body{ margin:0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; color:var(--text); background:radial-gradient(1200px 600px at 10% -10%, rgba(255,255,255,.25), rgba(255,255,255,0) 70%), linear-gradient(135deg, var(--bg1), var(--bg2)) fixed; }
    .brandbar{ max-width:1100px; margin:0 auto; padding:22px 16px 8px; display:flex; align-items:center; gap:14px; color:#fff; }
    .logo-wrap{ width:40px; height:40px; border-radius:12px; background:rgba(255,255,255,.18); display:grid; place-items:center; overflow:hidden; border:1px solid rgba(255,255,255,.35); box-shadow:0 10px 28px rgba(0,0,0,.18); }
    .logo-wrap img{ width:100%; height:100%; object-fit:cover; }
    .wrap{ max-width:1100px; margin:18px auto 40px; padding:0 16px; }
    .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:20px; box-shadow:0 20px 50px rgba(2, 6, 23, .15); }
    h1{ margin:8px 0 12px; color:#fff; font-size:26px }
    h2{ margin:20px 0 10px; font-size:18px }
    p,li{ line-height:1.55 }
    .muted{ color:#475569; font-size:13px }
    a{ color:#2563eb; text-decoration:none }
    a:hover{ text-decoration:underline }
    .back{ display:inline-block; margin-top:16px; }
  </style>
  <meta name="robots" content="noindex">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; object-src 'none'">
</head>
<body>
  <div class="brandbar" role="banner">
    <div class="logo-wrap" aria-hidden="true"><img src="facturaflow.png" alt="Factura Flow"></div>
    <div>
      <div style="font-weight:800; letter-spacing:.2px">Factura Flow</div>
      <div style="font-size:12px; opacity:.95">Condiciones de Uso</div>
    </div>
  </div>

  <div class="wrap">
    <h1>Condiciones de Uso</h1>
    <div class="card">
      <p class="muted">Versión: v1.0 — Fecha: <?= htmlspecialchars(gmdate('Y-m-d')) ?></p>

      <h2>1. Objeto y partes</h2>
      <p>Estas Condiciones de Uso regulan el acceso y utilización del software «Factura Flow» (el «Servicio») por parte del usuario que se registra y opera como emisor de facturas (el «Usuario»). La entidad titular o explotadora del Servicio actúa como <strong>empresa de servicios de facturación</strong> (la «ESF») a efectos meramente técnicos y de soporte de la herramienta.</p>

      <h2>2. Naturaleza del Servicio</h2>
      <ul>
        <li>El Servicio es una <strong>herramienta informática</strong> que ayuda a generar, firmar y gestionar facturas, incluida la interacción con terceros como AEAT (Veri*Factu), FACe/FACeB2B y directorios DIRe.</li>
        <li>La ESF <strong>no presta servicios profesionales fiscales, contables ni legales</strong>, ni actúa como <em>prestador cualificado de servicios de confianza</em> a efectos del Reglamento (UE) 910/2014 (<em>eIDAS</em>).</li>
        <li>La ESF <strong>no valida el contenido</strong> material, fiscal o jurídico de las facturas ni su adecuación a operaciones reales. La responsabilidad del contenido y su veracidad recae exclusivamente en el Usuario.</li>
      </ul>

      <h2>3. Marco legal aplicable (orientativo)</h2>
      <p>Sin carácter exhaustivo y a modo orientativo, el Usuario es el único responsable de cumplir con la normativa que le resulte aplicable, incluyendo, según su caso:</p>
      <ul>
        <li><strong>Ley 58/2003, General Tributaria</strong> y <strong>Reglamento de Facturación (RD 1619/2012)</strong>.</li>
        <li><strong>Ley 11/2021</strong> (medidas de prevención y lucha contra el fraude fiscal), en particular lo relativo a requisitos de software de facturación y sanciones.</li>
        <li><strong>RD 1007/2023</strong>, que aprueba el <strong>Reglamento de Requisitos de los Sistemas Informáticos de Facturación</strong> (VERI*FACTU), y sus normas de desarrollo.</li>
        <li><strong>Ley 18/2022</strong> («Crea y Crece») sobre facturación electrónica B2B, en lo que proceda y conforme entre en vigor su desarrollo reglamentario.</li>
        <li><strong>Reglamento (UE) 2016/679 (RGPD)</strong> y <strong>LOPDGDD 3/2018</strong> para el tratamiento de datos personales.</li>
        <li>Las <strong>condiciones de uso</strong> y <strong>especificaciones técnicas</strong> de plataformas de terceros (AEAT, FACe, FACeB2B, DIRe, etc.).</li>
      </ul>
      <p>La ESF no garantiza la adecuación del Servicio a <em>todos</em> los escenarios normativos o sectoriales del Usuario.</p>

      <h2>4. Obligaciones del Usuario</h2>
      <ul>
        <li><strong>Exactitud</strong>: introducir y mantener actualizados sus datos (NIF, razón social o nombre, domicilio, DIRe, DIR3, etc.) y los de sus clientes.</li>
        <li><strong>Contenido</strong>: asegurar que las facturas reflejan operaciones reales y cumplen la normativa fiscal y de facturación vigente.</li>
        <li><strong>Certificados</strong>: custodiar, mantener vigente y usar correctamente sus certificados electrónicos. El Usuario es responsable de sus claves y contraseñas.</li>
        <li><strong>Verificaciones</strong>: revisar los resultados de envío/registro y subsanar incidencias o rechazos notificados por terceros.</li>
        <li><strong>Copias de seguridad</strong>: mantener copias de seguridad independientes de la información y documentos generados o cargados.</li>
      </ul>

      <h2>5. Limitaciones del Servicio</h2>
      <ul>
        <li>El Servicio puede requerir conectividad con servicios de terceros. La ESF <strong>no controla</strong> la disponibilidad, cambios técnicos o respuestas de dichos servicios.</li>
        <li>Las funciones de firma, sellado o registro se realizan en base a la configuración y ficheros proporcionados por el Usuario. La ESF <strong>no garantiza</strong> la validez jurídica de firmas si el certificado del Usuario es inválido, caducado o se usa incorrectamente.</li>
        <li>La ESF puede actualizar el Servicio por motivos técnicos o legales. Ello puede implicar <strong>cambios de comportamiento</strong> o interrupciones razonables.</li>
      </ul>

      <h2>6. Responsabilidad</h2>
      <ul>
        <li>El Usuario asume toda responsabilidad por el uso del Servicio, <strong>exonerando</strong> a la ESF de cualquier reclamación derivada del contenido de las facturas, su envío o falta de envío, configuraciones erróneas o incumplimientos normativos.</li>
        <li>La ESF no responde por <strong>daños indirectos</strong>, lucro cesante, pérdida de datos, pérdida de negocio ni por sanciones administrativas o tributarias del Usuario.</li>
        <li>La <strong>responsabilidad total</strong> de la ESF, por cualquier causa, quedará en todo caso <strong>limitada</strong> al importe efectivamente satisfecho por el Usuario a la ESF por el Servicio en los 12 meses anteriores al hecho causante (o, si no hay precio, a 100 €).</li>
        <li>El Usuario se compromete a <strong>indemnizar</strong> a la ESF frente a reclamaciones de terceros derivadas del uso del Servicio por parte del Usuario en infracción de estas condiciones o de la ley.</li>
      </ul>

      <h2>7. Seguridad y datos</h2>
      <ul>
        <li>La ESF adopta medidas técnicas razonables, pero ningún sistema es totalmente seguro. El Usuario es responsable de <strong>controlar accesos</strong> y proteger sus credenciales y ficheros.</li>
        <li>El Servicio puede almacenar configuraciones y ficheros (p. ej., certificado y logo) en la instancia del Usuario. El cifrado de contraseñas de certificado puede depender de una clave local; el Usuario es responsable de su custodia.</li>
        <li>El tratamiento de datos personales, cuando proceda, se rige por la <a href="#" onclick="alert('La Política de Privacidad puede facilitarse por separado.'); return false;">Política de Privacidad</a>. El Usuario garantiza base jurídica suficiente para tratar datos de terceros.</li>
      </ul>

      <h2>8. Disponibilidad y soporte</h2>
      <ul>
        <li>El Servicio se presta «<strong>tal cual</strong>» sin garantías de disponibilidad continua ni adecuación a un propósito particular.</li>
        <li>La ESF podrá suspender temporalmente el Servicio por mantenimiento, seguridad o causas ajenas (fuerza mayor, caídas de terceros).</li>
      </ul>

      <h2>9. Cambios</h2>
      <p>La ESF podrá modificar estas Condiciones por razones legales, técnicas o de servicio. La versión vigente se publica en esta página. Si el Usuario no está de acuerdo con una modificación, deberá cesar el uso del Servicio.</p>

      <h2>10. Terminación</h2>
      <p>La ESF podrá resolver el acceso ante incumplimientos graves o uso ilícito del Servicio. El Usuario puede dejar de usar el Servicio en cualquier momento. Es responsabilidad del Usuario exportar o conservar sus datos antes de la terminación.</p>

      <h2>11. Ley aplicable y jurisdicción</h2>
      <p>Estas Condiciones se rigen por la <strong>ley española</strong>. Salvo normativa imperativa distinta, para la resolución de conflictos serán competentes los <strong>Juzgados y Tribunales de la ciudad sede de la ESF</strong>, con renuncia expresa a cualquier otro fuero que pudiera corresponder.</p>

      <h2>12. Contacto</h2>
      <p>Para dudas o soporte, contacte con la ESF por los canales habituales.</p>

      <p class="muted">Este documento no constituye asesoramiento legal. El Usuario debe recabar asesoramiento profesional propio cuando lo estime necesario.</p>

      <p class="back"><a href="index.php" title="Volver">← Volver</a></p>
    </div>
  </div>
</body>
</html>

