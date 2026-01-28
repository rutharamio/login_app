<?php
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

$usuario = trim($_POST['usuario'] ?? '');
$password = $_POST['password'] ?? '';

if ($usuario === '' || $password === '') {
    header('Location: ../index.php?error=datos');
    exit;
}

$sql = "SELECT id, usuario, email, password_hash, email_verified, rol
        FROM usuarios
        WHERE usuario = ? OR email = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->execute([$usuario, $usuario]);
$user = $stmt->fetch();

/* Credenciales incorrectas */
if (!$user || !$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
    header('Location: ../index.php?error=credenciales');
    exit;
}

/* Email NO verificado */
if ((int)$user['email_verified'] !== 1) {
    header('Location: ../index.php?error=verificacion');
    exit;
}

/* LOGIN OK */
session_regenerate_id(true);

$_SESSION['user_id'] = $user['id'];
$_SESSION['usuario'] = $user['usuario'];
$_SESSION['email'] = $user['email'];
$_SESSION['rol'] = $user['rol'];

//header('Location: ../dashboard.php');
header('Location: /login_app/dashboard.php');
exit;
