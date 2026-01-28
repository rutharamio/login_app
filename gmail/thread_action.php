<?php
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/db.php';

/* 1. Seguridad básica */
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('No autorizado');
}

$userId = $_SESSION['user_id'];

/* 2. Validar POST */
$action   = $_POST['action'] ?? null;
$threadId = isset($_POST['thread_id']) ? (int) $_POST['thread_id'] : null;

$threadActions = ['archive', 'restore'];
$globalActions = ['empty_trash'];

if (
    !$action ||
    (
        in_array($action, $threadActions, true) &&
        (!$threadId || $threadId <= 0)
    ) ||
    (
        !in_array($action, $threadActions, true) &&
        !in_array($action, $globalActions, true)
    )
) {
    die('Acción inválida');
}

/* 3. Verificar ownership del thread */
if (in_array($action, ['archive', 'restore'], true)) {

    $stmt = $conn->prepare("
        SELECT id
        FROM email_threads
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$threadId, $userId]);

    if (!$stmt->fetchColumn()) {
        die('Thread no autorizado');
    }

}

// Marcar emails del thread como leídos
if (in_array($action, $threadActions, true)) {
    $stmt = $conn->prepare("
        UPDATE emails
        SET is_read = 1
        WHERE thread_id = ?
          AND user_id = ?
          AND is_read = 0
    ");
    $stmt->execute([$threadId, $userId]);
}

/* 4. Ejecutar acción */

if ($action === 'restore' && $threadId) {

    $stmt = $conn->prepare("
        UPDATE emails
        SET is_deleted = 0,
            is_inbox = 1
        WHERE thread_id = ?
          AND user_id = ?
          AND is_deleted = 1
    ");
    $stmt->execute([$threadId, $userId]);

    header('Location: inbox.php?view=deleted');
    exit;
}

if ($action === 'empty_trash') {

    $stmt = $conn->prepare("
        UPDATE emails
        SET is_deleted = 2,
            is_inbox = 0
        WHERE user_id = ?
          AND is_deleted = 1
    ");
    $stmt->execute([$userId]);

    header('Location: inbox.php?view=deleted');
    exit;
}


/* 5. Redirigir */
header('Location: inbox.php');
exit;
