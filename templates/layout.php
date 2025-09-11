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
            <a href="index.php?page=invoices" class="<?php echo ($page == 'invoices' || $page == 'view_invoice') ? 'active' : ''; ?>">Facturas emitidas</a>           
            <a href="index.php?page=received">Facturas recibidas</a>
            <a href="index.php?page=clients" class="<?php echo ($page == 'clients' || $page == 'edit_client') ? 'active' : ''; ?>">Clientes</a>
            <a href="index.php?page=products" class="<?php echo ($page == 'products' || $page == 'edit_product') ? 'active' : ''; ?>">Productos</a>
            <a href="index.php?page=settings" class="<?php echo ($page == 'settings') ? 'active' : ''; ?>">Mis Datos</a>
        </nav>
        
        <div class="sidebar-footer">
            <div style="font-size:8px; margin-bottom: 1rem;">
                Facturaflow es un proyecto producto de la colaboración de:
                <img src="aliados.png" style="max-width:100%; margin-top: 0.5rem;" />
                <?php if (!empty($settings['logoPath']) && file_exists($settings['logoPath'])): ?>
                <img src="<?php echo htmlspecialchars($settings['logoPath']); ?>" class="logoempresa" alt="Logo de empresa">
            <?php endif; ?>
            </div>
            <form method="post" action="index.php" style="margin:0;">
                <input type="hidden" name="action" value="logout_user">
                <button type="submit" class="btn btn-outline-danger btn-sm">Salir</button>
            </form>
        </div>
    </div>

    <div class="main-content">
        <div class="container">
            <?php if ($page !== 'dashboard'): ?>

         

                <div style="margin-bottom: 1.5rem;">
                    <a href="index.php?page=dashboard">&larr; Volver al Panel</a>
                </div>
            <?php endif; ?>
            <?php echo $content; ?>
        </div>
    </div>
    
</body>
</html>
<script>
// El script de inactividad no se toca
</script>
