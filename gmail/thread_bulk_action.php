<?php
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('No autorizado');
}

$userId = $_SESSION['user_id'];

if (
    empty($_POST['threads']) ||
    !is_array($_POST['threads']) ||
    empty($_POST['action'])
) {
    die('Parámetros inválidos');
}

$action = $_POST['action'];
$threadIds = array_map('intval', $_POST['threads']);

if (!in_array($action, ['archive', 'delete'], true)) {
    die('Acción inválida');
}

/* Seguridad: ownership */
$placeholders = implode(',', array_fill(0, count($threadIds), '?'));

$params = array_merge($threadIds, [$userId]);

$stmt = $conn->prepare("
    SELECT id
    FROM email_threads
    WHERE id IN ($placeholders)
      AND user_id = ?
");
$stmt->execute($params);
$validThreads = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (count($validThreads) === 0) {
    die('Threads no válidos');
}

/* Acción */
if ($action === 'archive') {
    $stmt = $conn->prepare("
        UPDATE emails
        SET is_inbox = 0
        WHERE thread_id IN ($placeholders)
          AND user_id = ?
    ");
}

if ($action === 'delete') {
    $stmt = $conn->prepare("
        UPDATE emails
        SET is_deleted = 1,
            is_inbox = 0
        WHERE thread_id IN ($placeholders)
          AND user_id = ?
    ");
}

$stmt->execute($params);

/* Volver */
header('Location: inbox.php');
exit;