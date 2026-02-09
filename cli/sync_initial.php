<?php 
error_log("[CRON] sync_initial started at " . date('Y-m-d H:i:s'));

require __DIR__ . '/bootstrap.php';

echo "[SYNC_INIT] Starting initial sync\n";

// Buscar usuarios que necesitan sync inicial
$stmt = $conn->query("
    SELECT user_id, id AS token_id
    FROM google_gmail_tokens
    WHERE needs_initial_sync = 1
");

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($users)) {
    echo "[SYNC_INIT] No users require initial sync\n";
    exit;
}

foreach ($users as $row) { 
    
    $userId  = (int)$row['user_id'];
    $tokenId = (int)$row['token_id'];
    echo "[SYNC_INIT] User {$userId} started\n";

    // Registrar sync_run
    $stmt = $conn->prepare("
        INSERT INTO sync_runs (user_id, mode, started_at)
        VALUES (?, 'initial', NOW())
    ");
    $stmt->execute([$userId]);
    $syncRunId = $conn->lastInsertId();

    $threadsFetched  = 0;
    $messagesFetched = 0;

    // Obtener token Gmail
    $stmt = $conn->prepare("
        SELECT * FROM google_gmail_tokens
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$tokenId]);
    $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tokenRow) {
        error_log("[SYNC_INIT] Token not found user={$userId}");
        continue;
    }

    try {
        $accessTokenString = refreshAccessToken($conn, $tokenRow);
    } catch (Exception $e) {
        error_log("[SYNC_INIT] Token error user={$userId}");
        continue;
    }

    $pageToken = null;

    do {
        $url = 'https://gmail.googleapis.com/gmail/v1/users/me/threads'
         . '?labelIds=INBOX'
         . '&maxResults=100'
         . ($pageToken ? '&pageToken=' . urlencode($pageToken) : '');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessTokenString
        ]
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $threads = $data['threads'] ?? [];
        if (empty($threads)) {
            continue;
        }
        $pageToken = $data['nextPageToken'] ?? null;

        foreach ($threads as $t) {

            $threadId = $t['id'];

            // Resolver thread en DB
            $stmt = $conn->prepare("
                SELECT id FROM email_threads
                WHERE user_id = ? AND gmail_thread_id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId, $threadId]);
            $threadDbId = $stmt->fetchColumn();

            if (!$threadDbId) {
                $stmt = $conn->prepare("
                    INSERT INTO email_threads (user_id, gmail_thread_id, created_at)
                    VALUES (?, ?, NOW())
                ");
                $stmt->execute([$userId, $threadId]);
                $threadDbId = $conn->lastInsertId();
                $threadsFetched++;
            }

            // Traer mensajes del thread
            $url = "https://gmail.googleapis.com/gmail/v1/users/me/threads/" . urlencode($threadId) . "?format=full";

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessTokenString
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                error_log("[SYNC_INIT] THREAD FETCH ERROR thread={$threadId}");
                continue;
            }

            $data = json_decode($response, true);
            $messages = $data['messages'] ?? [];

            foreach ($messages as $msg) {

                $gmailMessageId = $msg['id'] ?? null;
                if (!$gmailMessageId) continue;

                // Idempotencia
                $stmt = $conn->prepare("
                    SELECT id FROM emails
                    WHERE user_id = ? AND gmail_message_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$userId, $gmailMessageId]);
                if ($stmt->fetchColumn()) {
                    continue;
                }

                $messagesFetched++;

                $internalDate = !empty($msg['internalDate'])
                    ? date('Y-m-d H:i:s', ((int)$msg['internalDate']) / 1000)
                    : date('Y-m-d H:i:s');

                $headers = $msg['payload']['headers'] ?? [];
                $headerMap = [];
                foreach ($headers as $h) {
                    $headerMap[strtolower($h['name'])] = $h['value'];
                }

                $body = extractEmailBody($msg['payload'] ?? []);

                $fromParsed = parseEmailAndName($headerMap['from'] ?? '');
                $fromEmail  = $fromParsed['email'];
                $fromName   = $fromParsed['name'];

                // Insert email
                $stmt = $conn->prepare("
                    INSERT INTO emails
                    (
                        user_id,
                        gmail_message_id,
                        thread_id,
                        internal_date,
                        from_email,
                        from_name,
                        subject_original,
                        subject_limpio,
                        snippet,
                        body_text,
                        body_html,
                        size_bytes,
                        has_attachments,
                        is_inbox,
                        is_deleted,
                        rfc_message_id,
                        rfc_references
                    )
                    VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, 0, ?, ?)
                ");

                $stmt->execute([
                    $userId,
                    $gmailMessageId,
                    $threadDbId,
                    $internalDate,
                    $fromEmail,
                    $fromName ?? '',
                    $headerMap['subject'] ?? '',
                    $headerMap['subject'] ?? '',
                    $msg['snippet'] ?? '',
                    $body['text'],
                    $body['html'],
                    $msg['sizeEstimate'] ?? 0,
                    $headerMap['message-id'] ?? null,
                    $headerMap['references'] ?? null
                ]);

                $emailId = $conn->lastInsertId();

                // Guardar headers completos
                $stmt = $conn->prepare("
                    INSERT INTO email_headers (email_id, headers_json)
                    VALUES (?, ?)
                ");
                $stmt->execute([
                    $emailId,
                    json_encode($headers, JSON_UNESCAPED_UNICODE)
                ]);

                // Adjuntos
                $attachments = extractAttachments($msg['payload'] ?? []);

                if (!empty($attachments)) {

                    $baseDir = BASE_PATH . "/storage/users/{$userId}/threads/{$threadDbId}/attachments/{$emailId}";
                    if (!is_dir($baseDir)) {
                        mkdir($baseDir, 0775, true);
                    }

                    foreach ($attachments as $att) {

                        $binary = downloadGmailAttachment(
                            $accessTokenString,
                            $gmailMessageId,
                            $att['attachment_id']
                        );

                        if ($binary === null) continue;

                        $filePath = $baseDir . '/' . basename($att['filename']);
                        file_put_contents($filePath, $binary);

                        $hash = hash_file('sha256', $filePath);

                        $stmt = $conn->prepare("
                            INSERT INTO email_attachments
                            (email_id, filename, mime_type, size_bytes,
                            attachment_id, saved_path, sha256, downloaded_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $emailId,
                            $att['filename'],
                            $att['mime_type'],
                            $att['size_bytes'],
                            $att['attachment_id'],
                            $filePath,
                            $hash
                        ]);

                        $conn->prepare("
                            UPDATE emails
                            SET has_attachments = 1
                            WHERE id = ?
                        ")->execute([$emailId]);
                    }
                }
            } 
        } 
            
    } while ($pageToken);

    // Obtener historyId final
    $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/profile');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessTokenString
        ]
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $profile = json_decode($response, true);

    if (!empty($profile['historyId'])) {
        $stmt = $conn->prepare("

            UPDATE google_gmail_tokens
            SET
            last_history_id = ?,
            needs_initial_sync = 0,
            updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$profile['historyId'], $tokenId]);
    }

    // cerrar sync_run
    $stmt = $conn->prepare("
        UPDATE sync_runs
        SET ended_at = NOW(),
            threads_fetched = ?,
            messages_fetched = ?
        WHERE id = ?
    ");
    $stmt->execute([$threadsFetched, $messagesFetched, $syncRunId]);

    echo "[SYNC_INIT] User {$userId} finished\n";
}

error_log("[CRON] sync_initial finished");

