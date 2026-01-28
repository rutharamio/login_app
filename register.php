<?php
$error = $_GET['error'] ?? '';
$verifyLink = $_GET['verify'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Crear cuenta</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<div class="app-container">
    <div class="card">

        <h2>Crear cuenta</h2>

        <?php if ($error === 'email'): ?>
            <p class="error">El email ya está registrado</p>
        <?php elseif ($error === 'datos'): ?>
            <p class="error">Completá todos los campos correctamente</p>
        <?php elseif ($error === 'password'): ?>
            <p class="error">Las contraseñas no coinciden</p>
        <?php endif; ?>

        <form method="post" action="auth/register.php" autocomplete="off">

            <label>Usuario</label>
            <input type="text" name="usuario" required>

            <label>Email</label>
            <input type="email" name="email" required>

            <label>Contraseña</label>
            <input type="password" name="password" required>

            <label>Confirmar contraseña</label>
            <input type="password" name="password2" required>

            <div class="actions">
                <button type="submit" class="btn btn-primary">
                    Registrarme
                </button>
            </div>

        </form>

        <?php if ($verifyLink): ?>
            <hr>
            <p class="muted"><strong>Modo desarrollo</strong></p>
            <p class="muted">Verificá tu email haciendo clic aquí:</p>
            <a href="<?= htmlspecialchars($verifyLink) ?>">
                Verificar email
            </a>
        <?php endif; ?>

        <hr>

        <div class="actions" style="justify-content: flex-end;">
            <a href="index.php" class="btn btn-light">
                Volver al login
            </a>
        </div>

    </div>
</div>

</body>
</html>
