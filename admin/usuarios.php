<?php
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/db.php';

/* Protección real */
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'admin') {
    header('Location: ../dashboard.php');
    exit;
}

/* Traer usuarios */
$sql = "SELECT id, usuario, email, rol, created_at
        FROM usuarios
        ORDER BY created_at DESC";
$stmt = $conn->query($sql);
$usuarios = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Administración de usuarios</title>

    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>

<h2>Administración de usuarios</h2>

<p>Usuario conectado: <strong><?php echo htmlspecialchars($_SESSION['usuario']); ?></strong></p>

<table border="1" cellpadding="6" cellspacing="0">
    <thead>
        <tr>
            <th>ID</th>
            <th>Usuario</th>
            <th>Email</th>
            <th>Rol</th>
            <th>Creado</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($usuarios as $u): ?>
            <tr>
                <td><?php echo $u['id']; ?></td>
                <td><?php echo htmlspecialchars($u['usuario']); ?></td>
                <td><?php echo htmlspecialchars($u['email']); ?></td>
                <td><?php echo $u['rol']; ?></td>
                <td><?php echo $u['created_at']; ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<hr>
<a href="../dashboard.php">Volver al dashboard</a>

</body>
</html>
