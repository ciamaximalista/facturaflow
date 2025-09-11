<?php
// templates/login.php
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - FacturaFlow</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/png" href="facturaflow.png">
</head>
<body class="auth-body">
<div class="auth-container">
    <div class="auth-card">
        <header class="auth-header"><img src="facturaflow.png" alt="FacturaFlow Logo" class="auth-logo"><h1>Iniciar Sesión</h1><p>Accede a tu cuenta de FacturaFlow.</p></header>
        <form id="login-form" method="post">
            <input type="hidden" name="action" value="login_user">
            <div class="form-group"><label for="nif">NIF/CIF</label><input type="text" id="nif" name="nif" class="form-control" required></div>
            <div class="form-group"><label for="password">Contraseña</label><input type="password" id="password" name="password" class="form-control" required></div>
            <div id="login-msg" class="form-message" aria-live="polite"></div>
            <button type="submit" class="btn btn-primary btn-block">Entrar</button>
        </form>
    </div>
    <div class="auth-allies"><img src="aliados.png" alt="Logos de aliados"></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('login-form');
    const msgContainer = document.getElementById('login-msg');
    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        msgContainer.textContent = '';
        const submitButton = loginForm.querySelector('button[type="submit"]');
        submitButton.textContent = 'Entrando...';
        submitButton.disabled = true;
        try {
            const response = await fetch('index.php', { method: 'POST', body: new FormData(loginForm) });
            const result = await response.json();
            if (result.success) {
                window.location.href = 'index.php';
            } else {
                msgContainer.textContent = result.message || 'Credenciales incorrectas.';
                msgContainer.classList.add('error');
            }
        } catch (error) {
            msgContainer.textContent = 'Ha ocurrido un error de conexión.';
            msgContainer.classList.add('error');
        } finally {
            submitButton.textContent = 'Entrar';
            submitButton.disabled = false;
        }
    });
});
</script>
</body>
</html>
