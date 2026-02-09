<?php
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

$userId = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("
    UPDATE google_gmail_tokens
    SET needs_initial_sync = 1
    WHERE user_id = ? AND needs_initial_sync = 0
");
$stmt->execute([$userId]);

header ('Content-Type: application/json'); 
echo json_encode(['status' => 'queued']);   

header('Location: ../dashboard.php?sync=queued');
exit;

