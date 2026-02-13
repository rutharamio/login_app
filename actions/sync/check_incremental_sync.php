<?php
require __DIR__ . '/../../config/session.php';
require __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM sync_runs
    WHERE user_id = ?
      AND mode = 'incremental'
      AND started_at > NOW() - INTERVAL 3 MINUTE
      AND ended_at IS NULL
");
$stmt->execute([$_SESSION['user_id']]);

echo json_encode([
    'running' => (int) $stmt->fetchColumn()
]);
