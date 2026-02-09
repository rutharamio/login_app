<?php
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

$stmt = $conn->prepare("
    SELECT needs_initial_sync
    FROM google_gmail_tokens
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$_SESSION['user_id']]);

echo json_encode([
    'needs_initial_sync' => (int) $stmt->fetchColumn()
]);
