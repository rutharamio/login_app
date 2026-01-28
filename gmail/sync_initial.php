<?php
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/google_config.php';
require __DIR__ . '/../lib/GmailService.php';

// SEGURIDAD: solo admin

if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Acceso denegado.');
}

$userId = $_SESSION['user_id'];

// REGISTRAR INICIO DE SYNC

$stmt = $conn->prepare("
    INSERT INTO sync_runs (user_id, mode, started_at)
    VALUES (?, 'initial', NOW())
");
$stmt->execute([$userId]);

$syncRunId = $conn->lastInsertId();

$threadsFetched  = 0;
$messagesFetched = 0;

try {

    $gmail = new GmailService($conn, $userId);

    $pageToken = null;

    do {
        $list = $gmail->listThreads($pageToken);

        foreach ($list['threads'] ?? [] as $t) {

            $thread = $gmail->getThread($t['id']);
            $messages = $thread['messages'] ?? [];
            if (!$messages) continue;

            $threadsFetched++;

            // THREAD

            $dates = array_map(
                fn($m) => date('Y-m-d H:i:s', ((int)$m['internalDate']) / 1000),
                $messages
            );

            sort($dates);

            $stmt = $conn->prepare("
                INSERT INTO email_threads
                (user_id, gmail_thread_id, subject, message_count,
                 first_message_at, last_message_at)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    message_count = VALUES(message_count),
                    last_message_at = VALUES(last_message_at)
            ");

            $subject = null;
            foreach ($messages[0]['payload']['headers'] ?? [] as $h) {
                if (strtolower($h['name']) === 'subject') {
                    $subject = $h['value'];
                }
            }

            $stmt->execute([
                $userId,
                $t['id'],
                $subject,
                count($messages),
                $dates[0],
                end($dates)
            ]);

            $threadId = $conn->lastInsertId();
            if (!$threadId) {
                $stmt = $conn->prepare("
                    SELECT id FROM email_threads
                    WHERE user_id = ? AND gmail_thread_id = ?
                ");
                $stmt->execute([$userId, $t['id']]);
                $threadId = $stmt->fetchColumn();
            }

            // MENSAJES

            foreach ($messages as $msg) {

                $messagesFetched++;

                $headers = [];
                foreach ($msg['payload']['headers'] ?? [] as $h) {
                    $headers[strtolower($h['name'])] = $h['value'];
                }

                $bodyText = null;
                $bodyHtml = null;

                $parts = $msg['payload']['parts'] ?? [];
                foreach ($parts as $p) {
                    if ($p['mimeType'] === 'text/plain' && !empty($p['body']['data'])) {
                        $bodyText = base64_decode(strtr($p['body']['data'], '-_', '+/'));
                    }
                    if ($p['mimeType'] === 'text/html' && !empty($p['body']['data'])) {
                        $bodyHtml = base64_decode(strtr($p['body']['data'], '-_', '+/'));
                    }
                }

                $stmt = $conn->prepare("
                    INSERT IGNORE INTO emails
                    (user_id, thread_id, gmail_message_id,
                     from_email, subject_original, snippet,
                     body_text, body_html, internal_date, has_attachments)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $userId,
                    $threadId,
                    $msg['id'],
                    $headers['from'] ?? '',
                    $headers['subject'] ?? '',
                    $msg['snippet'] ?? '',
                    $bodyText,
                    $bodyHtml,
                    date('Y-m-d H:i:s', ((int)$msg['internalDate']) / 1000),
                    !empty($msg['payload']['parts'])
                ]);

                $emailId = $conn->lastInsertId();
                if (!$emailId) continue;

                // HEADERS

                $stmt = $conn->prepare("
                    INSERT INTO email_headers
                    (email_id, headers_json, message_id_header,
                     in_reply_to, references_header, mailer)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $emailId,
                    json_encode($headers),
                    $headers['message-id'] ?? null,
                    $headers['in-reply-to'] ?? null,
                    $headers['references'] ?? null,
                    $headers['x-mailer'] ?? null
                ]);

                // MANEJO DE ADJUNTOS

                foreach ($msg['payload']['parts'] ?? [] as $p) {

                    if (empty($p['filename']) || empty($p['body']['attachmentId'])) {
                        continue;
                    }

                    $data = $gmail->getAttachment(
                        $msg['id'],
                        $p['body']['attachmentId']
                    );

                    $path = __DIR__ . "/../storage/attachments/$userId/$emailId";
                    if (!is_dir($path)) {
                        mkdir($path, 0770, true);
                    }

                    file_put_contents("$path/{$p['filename']}", $data);
                }
            }
        }

        $pageToken = $list['nextPageToken'] ?? null;

    } while ($pageToken);

    // FINALIZAR SYNC

    $stmt = $conn->prepare("
        UPDATE sync_runs
        SET ended_at = NOW(),
            threads_fetched = ?,
            messages_fetched = ?
        WHERE id = ?
    ");
    $stmt->execute([$threadsFetched, $messagesFetched, $syncRunId]);

    echo "SYNC INICIAL COMPLETADO<br>";
    echo "Threads: $threadsFetched<br>";
    echo "Mensajes: $messagesFetched<br>";

} catch (Exception $e) {

    $stmt = $conn->prepare("
        UPDATE sync_runs
        SET ended_at = NOW(),
            error_message = ?
        WHERE id = ?
    ");
    $stmt->execute([$e->getMessage(), $syncRunId]);

    http_response_code(500);
    echo "ERROR: " . htmlspecialchars($e->getMessage());
}
