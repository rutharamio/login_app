<?php
require __DIR__ . '/../../config/session.php';
require __DIR__ . '/../../config/db.php';

error_log('BULK_ACTION HIT');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('No autorizado');
}

$userId = $_SESSION['user_id'];
$action = $_POST['action'] ?? null;
$threadIds = $_POST['thread_ids'] ?? [];

if ($action !== 'delete' || empty($threadIds) || !is_array($threadIds)) {
    die('Acción inválida');
}

/* Sanitizar IDs */
$threadIds = array_map('intval', $threadIds);

/* Verificar ownership */
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

if (empty($validThreads)) {
    die('No hay threads válidos');
}

/* Marcar como eliminados */
$placeholders = implode(',', array_fill(0, count($validThreads), '?'));
$params = array_merge($validThreads, [$userId]);

$stmt = $conn->prepare("
    UPDATE emails
    SET is_deleted = 1,
        is_inbox = 0
    WHERE thread_id IN ($placeholders)
      AND user_id = ?
");
$stmt->execute($params);

header('Location: /login_app/gmail/inbox.php');
exit;