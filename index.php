<?php
require __DIR__ . '/config/session.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

/* Si hay sesión, NO mostrar login */
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login</title>

    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<div class="app-container">
    <div class="card">

        <h2>Iniciar sesión</h2>

        <form method="post" action="auth/login.php">
            <label>Usuario o Email</label>
            <input type="text" name="usuario" required>

            <label>Contraseña</label>
            <input type="password" name="password" required>

            <div class="actions">
                <button type="submit" class="btn btn-primary">
                    Entrar
                </button>
            </div>
        </form>

        <hr>

        <?php if (isset($_GET['verify'])): ?>
            <?php if ($_GET['verify'] === 'success'): ?>
                <p class="success">Email verificado correctamente. Ya podés iniciar sesión.</p>
            <?php elseif ($_GET['verify'] === 'expired'): ?>
                <p class="error">El link de verificación expiró.</p>
            <?php elseif ($_GET['verify'] === 'invalid'): ?>
                <p class="error">Link de verificación inválido.</p>
            <?php elseif ($_GET['verify'] === 'already'): ?>
                <p class="info">Este email ya estaba verificado.</p>
            <?php endif; ?>
        <?php endif; ?>

        <form action="google_login.php" method="get">
            <button type="submit" class="btn btn-light">
                Continuar con Google
            </button>
        </form>

        <div class="actions" style="justify-content: flex-end;">
            <a href="register.php" class="btn btn-light">
                Registrarse
            </a>
        </div>

    </div>
</div>

</body>
</html>
