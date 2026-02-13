<?php
require __DIR__ . '/bootstrap.php';

echo "[SYNC] Incremental sync started\n";

/* =========================
   1. Buscar usuarios activos
========================= */

$stmt = $conn->query("
    SELECT id
    FROM usuarios
    WHERE id IN (
        SELECT user_id FROM google_gmail_tokens
    )
");

$users = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($users)) {
    echo "[SYNC] No Gmail-connected users found\n";
    exit;
}

foreach ($users as $userId) {

    echo "[SYNC] User {$userId} started\n";

    $threadsFetched = 0;
    $messagesFetched = 0;

    //registrar sync_runc

    $stmt = $conn->prepare("
        INSERT INTO sync_runs (user_id, mode, started_at)
        VALUES (?, 'incremental', NOW())
    ");
    $stmt->execute([$userId]);
    $syncRunId = $conn->lastInsertId();

   // token
    $stmt = $conn->prepare("
        SELECT * FROM google_gmail_tokens
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tokenRow) {
        continue;
    }

    try {
        $accessTokenString = refreshAccessToken($conn, $tokenRow);
    } catch (Exception $e) {
        error_log("TOKEN ERROR user={$userId}");
        continue;
    }

    if (empty($tokenRow['last_history_id'])) {
        error_log("SKIP incremental: no historyId user={$userId}");
        continue;
    }

    if ((int)$tokenRow['needs_initial_sync'] === 1) {
        error_log("SKIP incremental: initial sync pending user={$userId}");
        continue;
    }

    // ver history id

    $startHistoryId = $tokenRow['last_history_id'];

    $url = 'https://gmail.googleapis.com/gmail/v1/users/me/history'
        . '?startHistoryId=' . urlencode($startHistoryId)
        . '&historyTypes=messageAdded'
        . '&historyTypes=labelAdded'
        . '&historyTypes=labelRemoved';

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

        if ($httpCode === 404) {
            $conn->prepare("
                UPDATE google_gmail_tokens
                SET last_history_id = NULL
                WHERE id = ?
            ")->execute([$tokenRow['id']]);
        }

        continue;
    }

    $data = json_decode($response, true);
    $history = $data['history'] ?? [];
    $newHistoryId = $data['historyId'] ?? null;

    $messageIds = [];
    $labelEvents = [];

    foreach ($history as $h) {

        if (!empty($h['messagesAdded'])) {
            foreach ($h['messagesAdded'] as $added) {
                $mid = $added['message']['id'] ?? null;
                if ($mid) {
                    $messageIds[] = $mid;
                }
            }
        }

        if (!empty($h['labelsAdded'])) {
            foreach ($h['labelsAdded'] as $la) {
                $mid = $la['message']['id'] ?? null;
                if (!$mid) continue;
                $labelEvents[$mid]['added'] =
                    array_merge($labelEvents[$mid]['added'] ?? [], $la['labelIds'] ?? []);
            }
        }

        if (!empty($h['labelsRemoved'])) {
            foreach ($h['labelsRemoved'] as $lr) {
                $mid = $lr['message']['id'] ?? null;
                if (!$mid) continue;
                $labelEvents[$mid]['removed'] =
                    array_merge($labelEvents[$mid]['removed'] ?? [], $lr['labelIds'] ?? []);
            }
        }
    }

    $messageIds = array_unique($messageIds);

    // cambios labels

    foreach ($labelEvents as $gmailMessageId => $events) {

        $stmt = $conn->prepare("
            SELECT id FROM emails
            WHERE user_id = ?
            AND gmail_message_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $gmailMessageId]);
        $emailId = $stmt->fetchColumn();

        if (!$emailId) continue;

        if (!empty($events['removed']) && in_array('INBOX', $events['removed'], true)) {
            $conn->prepare("
                UPDATE emails SET is_inbox = 0 WHERE id = ?
            ")->execute([$emailId]);
        }

        if (!empty($events['added']) && in_array('TRASH', $events['added'], true)) {
            $conn->prepare("
                UPDATE emails
                SET is_deleted = 1,
                    is_inbox = 0
                WHERE id = ?
            ")->execute([$emailId]);
        }
    }

    // procesar nuevos mensajes

    foreach ($messageIds as $gmailMessageId) {

        $stmt = $conn->prepare("
            SELECT id FROM emails
            WHERE user_id = ?
            AND gmail_message_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $gmailMessageId]);
        if ($stmt->fetchColumn()) continue;

        try {
            $msg = fetchGmailMessageFull($accessTokenString, $gmailMessageId);
        } catch (Exception $e) {
            continue;
        }

        if (in_array('DRAFT', $msg['labelIds'] ?? [], true)) {
            continue;
        }

        if (empty($msg['threadId'])) continue;

        $threadId = $msg['threadId'];

        $stmt = $conn->prepare("
            SELECT id FROM email_threads
            WHERE user_id = ?
            AND gmail_thread_id = ?
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

        $headers = $msg['payload']['headers'] ?? [];
        $headerMap = [];
        foreach ($headers as $h) {
            $headerMap[strtolower($h['name'])] = $h['value'];
        }

        $body = extractEmailBody($msg['payload'] ?? []);

        $internalDate = !empty($msg['internalDate'])
            ? date('Y-m-d H:i:s', ((int)$msg['internalDate']) / 1000)
            : date('Y-m-d H:i:s');

        $fromParsed = parseEmailAndName($headerMap['from'] ?? '');
        $fromEmail  = $fromParsed['email'] ?? '';
        $fromName   = $fromParsed['name'] ?? '';

        $labels = $msg['labelIds'] ?? [];

        $isInbox  = in_array('INBOX', $labels, true);
        $isSent   = in_array('SENT', $labels, true);
        $isTrash  = in_array('TRASH', $labels, true);
        $isUnread = in_array('UNREAD', $labels, true);
        $messageIdHeader = $headerMap['message-id'] ?? null;

        $emailId = null;

        // Intentar reconciliar por RFC primero

        if (!empty($messageIdHeader)) {

            $stmt = $conn->prepare("
                SELECT id
                FROM emails
                WHERE user_id = ?
                AND rfc_message_id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId, $messageIdHeader]);
            $existingId = $stmt->fetchColumn();

            if ($existingId) {

                $stmt = $conn->prepare("
                    UPDATE emails
                    SET
                        gmail_message_id = ?,
                        thread_id = ?,
                        internal_date = ?,
                        from_email = ?,
                        from_name = ?,
                        subject_original = ?,
                        subject_limpio = ?,
                        snippet = ?,
                        body_text = ?,
                        body_html = ?,
                        size_bytes = ?,
                        is_inbox = ?,
                        is_deleted = ?,
                        is_sent = ?,
                        is_read = ?,
                        is_temporary = 0
                    WHERE id = ?
                ");
                try { 
                $stmt->execute([
                    $gmailMessageId,
                    $threadDbId,
                    $internalDate,
                    $fromEmail,
                    $fromName,
                    $headerMap['subject'] ?? '',
                    $headerMap['subject'] ?? '',
                    $msg['snippet'] ?? '',
                    $body['text'] ?? '',
                    $body['html'] ?? '',
                    $msg['sizeEstimate'] ?? 0,
                    $isInbox ? 1 : 0,
                    $isTrash ? 1 : 0,
                    $isSent ? 1 : 0,
                    $isUnread ? 0 : 1,
                    $existingId
                ]);

                $emailId = $existingId;
                $messagesFetched++;
                }catch (PDOException $e) {

                if ($e->errorInfo[1] == 1062) {
                // Duplicate → ya existe por RFC
                
                $stmt = $conn->prepare("
                    SELECT id FROM emails
                    WHERE user_id = ?
                    AND rfc_message_id = ?
                    LIMIT 1
                ");
                
                $stmt->execute([$userId, $messageIdHeader]);
                
                $emailId = $stmt->fetchColumn();
            } else {
                throw $e;
            }
        }
            }
        }

        // Si NO hubo reconciliación → INSERT

        if (!$emailId) {

            $stmt = $conn->prepare("
                INSERT INTO emails
                (
                    user_id, gmail_message_id, thread_id,
                    internal_date, from_email, from_name,
                    subject_original, subject_limpio,
                    snippet, body_text, body_html,
                    size_bytes, has_attachments,
                    is_inbox, is_deleted, is_sent, is_read,
                    rfc_message_id, rfc_references
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $userId,
                $gmailMessageId,
                $threadDbId,
                $internalDate,
                $fromEmail,
                $fromName,
                $headerMap['subject'] ?? '',
                $headerMap['subject'] ?? '',
                $msg['snippet'] ?? '',
                $body['text'] ?? '',
                $body['html'] ?? '',
                $msg['sizeEstimate'] ?? 0,
                $isInbox ? 1 : 0,
                $isTrash ? 1 : 0,
                $isSent ? 1 : 0,
                $isUnread ? 0 : 1,
                $messageIdHeader,
                $headerMap['references'] ?? null
            ]);

            $emailId = $conn->lastInsertId();
            $messagesFetched++;

        }
        
        $stmt = $conn->prepare("
            SELECT id FROM email_headers
            WHERE email_id = ?
            LIMIT 1
        ");
        $stmt->execute([$emailId]);

        if (!$stmt->fetchColumn()) {

            $conn->prepare("
                INSERT INTO email_headers (email_id, headers_json)
                VALUES (?, ?)
            ")->execute([
                $emailId,
                json_encode($headers, JSON_UNESCAPED_UNICODE)
            ]);
        }
        
        // ADJUNTOS

        $attachments = extractAttachments($msg['payload'] ?? []);

        if (!empty($attachments)) {

            $baseDir = __DIR__ . "/../storage/users/{$userId}/threads/{$threadDbId}/attachments/{$emailId}";
            if (!is_dir($baseDir)) {
                mkdir($baseDir, 0775, true);
            }

            foreach ($attachments as $att) {

                $binary = $att['attachment_id']
                    ? downloadGmailAttachment($accessTokenString, $gmailMessageId, $att['attachment_id'])
                    : $att['inline_data'];

                if (!$binary) continue;

                $filePath = $baseDir . '/' . basename($att['filename']);
                file_put_contents($filePath, $binary);

                $conn->prepare("
                    INSERT INTO email_attachments
                    (email_id, filename, mime_type, size_bytes,
                     attachment_id, saved_path, sha256, downloaded_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ")->execute([
                    $emailId,
                    $att['filename'],
                    $att['mime_type'],
                    $att['size_bytes'],
                    $att['attachment_id'],
                    $filePath,
                    hash_file('sha256', $filePath)
                ]);

                $conn->prepare("
                    UPDATE emails SET has_attachments = 1 WHERE id = ?
                ")->execute([$emailId]);
            }
        }
    }

    // guardar history_id

    if ($newHistoryId) {
        $conn->prepare("
            UPDATE google_gmail_tokens
            SET last_history_id = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$newHistoryId, $tokenRow['id']]);
    }

    // cerrar sync_run

    $conn->prepare("
        UPDATE sync_runs
        SET ended_at = NOW(),
            threads_fetched = ?,
            messages_fetched = ?
        WHERE id = ?
    ")->execute([
        $threadsFetched,
        $messagesFetched,
        $syncRunId
    ]);

    echo "[SYNC] User {$userId} finished\n";
}

echo "[SYNC] All users finished\n";
