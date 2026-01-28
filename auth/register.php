<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../register.php');
    exit;
}

$usuario = trim($_POST['usuario'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$password2 = $_POST['password2'] ?? '';

if ($usuario === '' || $email === '' || $password === '' || $password2 === '') {
    header('Location: ../register.php?error=datos');
    exit;
}

if ($password !== $password2) {
    header('Location: ../register.php?error=password');
    exit;
}

/* Email único */
$stmt = $conn->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    header('Location: ../register.php?error=email');
    exit;
}

/* Crear usuario (NO verificado) */
$hash = password_hash($password, PASSWORD_DEFAULT);
$token = bin2hex(random_bytes(32)); // 64 chars
$expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

$sql = "INSERT INTO usuarios
        (usuario, email, password_hash, email_verified, verification_token, verification_expires)
        VALUES (?, ?, ?, 0, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->execute([$usuario, $email, $hash, $token, $expires]);

/* Enviar email real de verificación */
$verifyLink = "http://localhost/login_app/verify_email.php?token=" . urlencode($token);

if (!sendVerificationEmail($email, $verifyLink)) {
    header('Location: ../register.php?error=mail');
    exit;
}

header('Location: ../register.php?success=verify');
exit;