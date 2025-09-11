<?php
/**
 * templates/welcome_message.php
 * Muestra un mensaje de bienvenida e instrucciones la primera vez que se accede.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'close_welcome') {
    $_SESSION['welcome_message_shown'] = true;
    header('Location: index.php?page=dashboard');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>¡Bienvenido a FacturaFlow!</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-color: #f4f7fa;
            margin: 0;
        }
        .welcome-card {
            background: #fff;
            padding: 3rem;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            text-align: left;
            max-width: 600px;
            width: 90%;
        }
        
       .welcome-card ol li {
       	   margin-bottom: 1rem;
       }
       
       .welcome-card strong {
       	   background-color:DeepSkyBlue;
       	   color:white;s
       }

        .welcome-card h1, h2, h3, h4 {
            color: var(--primary-color);
            margin-bottom: 1.2rem;
            text-align:center
        }
        .welcome-card p {
            color: var(--text-light);
            line-height: 1.6;
            margin-bottom: 2rem;
        }
    </style>
</head>
<body>
    <div class="welcome-card">
        <h1>¿Por dónde empiezo?</h1>
             <ol> 
                     <li>Registra al cliente al que enviarás la factura en <strong>Clientes</strong></li> 
                     <li>Registra los productos o servicios que vayas a facturarle, con su precio y su % de IVA en <strong>Productos</strong></li> 
             </ol> 


             <h2> Y... ¡nada más!</h2> 
             <h3> ¡¡Ya puedes emitir tu factura en <strong>Crear Factura</strong>!!</h3>
        <br>
        <form method="post">
            <input type="hidden" name="action" value="close_welcome">
            <button type="submit" class="btn btn-primary">¡Entendido, empezar a facturar!</button>
        </form>
    </div>
</body>
</html>

