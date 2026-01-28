<?php
require __DIR__ . '/config/db.php';

$token = $_GET['token'] ?? '';

if ($token === '') {
    header('Location: index.php?verify=invalid');
    exit;
}

// Buscar usuario con token válido
$sql = "SELECT id, email_verified 
        FROM usuarios
        WHERE verification_token = ?
          AND verification_expires > NOW()
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: index.php?verify=expired');
    exit;
}

if ((int)$user['email_verified'] === 1) {
    header('Location: index.php?verify=already');
    exit;
}

// Activar cuenta 
$sql = "UPDATE usuarios
        SET email_verified = 1,
            verification_token = NULL,
            verification_expires = NULL
        WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$user['id']]);

header('Location: index.php?verify=success');
exit;