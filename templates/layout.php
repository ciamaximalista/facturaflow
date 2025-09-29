<?php
// Carga la configuración para asegurar que $settings['logoPath'] siempre esté disponible.
$settingsFile = __DIR__ . '/../data/config.json';
$settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FacturaFlow</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/png" href="facturaflow.png">
</head>
<style>
    /* === INICIO: SOLUCIÓN DEFINITIVA DE LAYOUT === */

    html, body {
        margin: 0;
        padding: 0;
        height: 100vh; /* Ocupa el 100% de la altura de la ventana */
        overflow: hidden; /* Evita el scroll en la página principal */
        font-family: sans-serif; /* Un fallback por si style.css no lo define */
    }

    body {
        display: flex; /* Convierte el body en un contenedor flexible */
    }

    .sidebar {
        width: 250px; /* Ancho fijo para la barra lateral */
        flex-shrink: 0; /* Evita que la barra lateral se encoja */
        background-color: #f8f9fa; /* Color de fondo para distinguirla */
        display: flex;
        flex-direction: column; /* Organiza el contenido del sidebar en una columna */
        padding: 1rem;
        box-sizing: border-box;
    }

    .main-content {
        flex-grow: 1; /* Ocupa todo el espacio restante */
        overflow-y: auto; /* AÑADE EL SCROLL SOLO A ESTA ÁREA CUANDO SEA NECESARIO */
        padding: 2rem;
        box-sizing: border-box;
    }

    .sidebar-header-logos {
        text-align: center;
        margin-bottom: 2rem;
    }

    .sidebar-header-logos img {
        max-width: 160px;
        object-fit: contain;
        display: inline-block;
        margin: 0 0.5rem;
    }
    

    .logoempresa {
        max-height: 40px;
        margin: 0 0.5rem;
    }

    /* Logo de usuario flotante arriba a la derecha */
    .user-logo-float {
        position: fixed;
        top: 10px;
        right: 12px;
        max-height: 44px;
        z-index: 1000;
        background: #fff;
        border-radius: 6px;
        padding: 4px;
        box-shadow: 0 6px 18px rgba(0,0,0,0.12);
    }
    .user-logo-float:hover {
        box-shadow: 0 8px 22px rgba(0,0,0,0.15);
    }

    /* Botón Salir flotante abajo a la derecha */
    .logout-float {
        position: fixed;
        right: 14px;
        bottom: 14px;
        z-index: 1000;
    }
    .logout-float .btn {
        background: #ef4444;
        color: #fff;
        border: 1px solid #ef4444;
        padding: .6rem .9rem;
        border-radius: .5rem;
        box-shadow: 0 6px 18px rgba(239,68,68,.25);
        cursor: pointer;
    }
    .logout-float .btn:hover { opacity: .95; }

    .sidebar nav {
        flex-grow: 1; /* Empuja el footer hacia abajo */
    }

    .sidebar-footer {
        margin-top: auto; /* Empuja este bloque al final del sidebar */
        text-align: center;
    }

    /* === FIN: SOLUCIÓN DEFINITIVA DE LAYOUT === */

    /* Otros estilos de la aplicación */
    .is-cancelled { color:#888; }
    .is-cancelled a { color:#888; text-decoration:line-through; }
    .muted { font-size:0.9em; color:#777; }
</style>
<body>
    <div class="sidebar">
        <div class="sidebar-header-logos">
            <a href="index.php?page=dashboard">
                <img src="facturaflow.png" alt="FacturaFlow Logo">
            </a>
           
        </div>

        <nav>
            <a href="index.php?page=dashboard" class="<?php echo ($page == 'dashboard') ? 'active' : ''; ?>">Panel</a>
            <a href="index.php?page=create_invoice" class="<?php echo ($page == 'create_invoice') ? 'active' : ''; ?>">Crear Factura</a>
            <a href="index.php?page=invoices" class="<?php echo ($page == 'invoice_list' || $page == 'view_invoice') ? 'active' : ''; ?>">Facturas emitidas</a>
            <a href="index.php?page=received" class="<?php echo ($page == 'received' || $page == 'received_view') ? 'active' : ''; ?>">Facturas recibidas</a>
            <a href="index.php?page=clients" class="<?php echo ($page == 'clients' || $page == 'edit_client') ? 'active' : ''; ?>">Clientes</a>
            <a href="index.php?page=products" class="<?php echo ($page == 'products' || $page == 'edit_product') ? 'active' : ''; ?>">Productos</a>
            <a href="index.php?page=settings" class="<?php echo ($page == 'settings') ? 'active' : ''; ?>">Mis Datos</a>
        </nav>
        
        <div class="sidebar-footer">
            <div style="font-size:10px; margin-top: .75rem;">
                <div style="margin-bottom:.5rem;">
                  <a href="index.php?page=terms" style="font-size:11px; color:#2563eb; text-decoration:none;">Condiciones de Uso</a>
                </div>
                <div style="opacity:.7; margin-bottom:.35rem;">FacturaFlow es resultado de la cooperación de</div>
                <img src="aliados.png" alt="Aliados" style="max-width:100%; height:auto; display:block;" />
            </div>
        </div>
    </div>

    <div class="main-content">
        <div class="container">
            <?php echo $content; ?>
        </div>
    </div>
    
    <?php if (!empty($settings['logoPath']) && file_exists($settings['logoPath'])): ?>
      <a href="index.php?page=settings" title="Mis Datos">
        <img src="<?php echo htmlspecialchars($settings['logoPath']); ?>" class="user-logo-float" alt="Logo de empresa">
      </a>
    <?php endif; ?>

    <form class="logout-float" method="post" action="index.php" title="Salir">
        <input type="hidden" name="action" value="logout_user">
        <button type="submit" class="btn">Salir</button>
    </form>
    
</body>
<script>
// Marca como activa la opción de menú de la página actual
(function(){
  var curr = "<?php echo htmlspecialchars($page ?? ''); ?>";
  var links = document.querySelectorAll('.sidebar nav a');
  links.forEach(function(a){
    var href = a.getAttribute('href') || '';
    var m = href.match(/page=([^&]+)/);
    if (m && m[1] === curr) a.classList.add('active');
  });
})();
</script>
</html>
