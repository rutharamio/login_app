<?php
require __DIR__ . '/config/session.php';
require __DIR__ . '/config/db.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$userId = $_SESSION['user_id'];

/* Ver si Gmail está conectado */

$stmt = $conn->prepare("
    select access_token, refresh_token, expires_at, state 
    from google_gmail_tokens
    where user_id = ?
    limit 1
");
$stmt->execute([$userId]);
$gmail = $stmt->fetch(PDO::FETCH_ASSOC);

$gmailState = 'not_connected';

if ($gmail) {
    if ($gmail['state'] !== 'active') {
        // Estado persistido manda
        $gmailState = 'expired';
    } elseif (empty($gmail['refresh_token'])) {
        // Fallback defensivo
        $gmailState = 'expired';
    } else {
        $gmailState = 'connected';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pestaña principal</title>

    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<div class="app-container">
    <div class="card">

        <h1>Bienvenidx, <?= htmlspecialchars($_SESSION['usuario']) ?></h1>
        <p class="muted">
            Email: <?= htmlspecialchars($_SESSION['email']) ?>
        </p>

        <div class="actions">

        <?php if ($gmailState === 'connected'): ?>
            <form action="gmail/inbox.php" method="get">
                <button type="submit" class="btn btn-primary">
                    Bandeja de entrada
                </button>
            </form>

        <?php elseif ($gmailState === 'expired'): ?>
            <div class="alert alert-warning">
                <strong>La conexión con Gmail expiró.</strong><br>
                Volvé a conectar tu cuenta.
            </div>

            <a href="/login_app/actions/gmail/connect.php" class="btn btn-primary">
                Conectar Gmail
            </a>

        <?php else: ?>
            <form action="/login_app/actions/gmail/connect.php" method="get">
                <button type="submit" class="btn btn-primary">
                    Conectar Gmail
                </button>
            </form>
        <?php endif; ?>

        <form action="auth/logout.php" method="post">
            <button type="submit" class="btn btn-light">
                Cerrar sesión
            </button>
        </form>

        </div>

        <?php if ($_SESSION['rol'] === 'admin'): ?>
            <hr>
            <h3>Panel de administración</h3>

            <div class="actions">
                <form action="admin/usuarios.php" method="get">
                    <button type="submit" class="btn btn-light">
                        Gestionar usuarios
                    </button>
                </form>
            </div>
        <?php endif; ?>

    </div>
</div>

</body>
</html>