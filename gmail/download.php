<?php
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/db.php';

/* 1. Validar sesión */
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('No autorizado');
}

$userId = $_SESSION['user_id'];

/* 2. Validar parámetro */
$attachmentId = (int)($_GET['id'] ?? 0);
if ($attachmentId <= 0) {
    die('Parámetro inválido');
}

/* 3. Obtener adjunto + validar ownership */
$stmt = $conn->prepare("
    SELECT a.*, e.user_id
    FROM email_attachments a
    JOIN emails e ON e.id = a.email_id
    WHERE a.id = ?
      AND e.user_id = ?
    LIMIT 1
");
$stmt->execute([$attachmentId, $userId]);
$att = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$att) {
    die('Adjunto no encontrado');
}

/* 4. Verificar archivo físico */
$filePath = $att['saved_path'];
if (!is_file($filePath)) {
    die('Archivo no disponible');
}

/* 5. Enviar archivo */
header('Content-Description: File Transfer');
$inline = isset($_GET['inline']) && $_GET['inline'] == '1';

header('Content-Type: ' . ($att['mime_type'] ?: 'application/octet-stream'));
header(
    'Content-Disposition: ' .
    ($inline ? 'inline' : 'attachment') .
    '; filename="' . basename($att['filename']) . '"'
);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache');

readfile($filePath);
var_dump($filePath);
exit;