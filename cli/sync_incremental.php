<?php
require __DIR__ . '/bootstrap.php';

echo "[SYNC] Incremental sync started\n";

// Buscar usuarios con Gmail conectado
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
    
    // Registrar inicio
    $stmt = $conn->prepare("
        INSERT INTO sync_runs (user_id, mode, started_at)
        VALUES (?, 'incremental', NOW())
    ");
    $stmt->execute([$userId]);
    $syncRunId = $conn->lastInsertId();

    // Token Gmail
    $stmt = $conn->prepare("
        SELECT * FROM google_gmail_tokens
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tokenRow) {
        die('Gmail no conectado');
    }

    try {
        $accessTokenString = refreshAccessToken($conn, $tokenRow);
    } catch (Exception $e) {
        error_log("TOKEN ERROR user={$userId}");
        continue;
    }

    if (empty($tokenRow['last_history_id'])) {
    error_log("SYNC_INCREMENTAL SKIPPED: user={$userId} has no historyId (initial sync required)");
    continue; // en CLI
    // return;  // en HTTP

    if ((int)$tokenRow['needs_initial_sync'] === 1) {
    error_log("SKIP incremental: initial sync pending user={$userId}");
    continue;
}
}

    $startHistoryId = $tokenRow['last_history_id'];

    error_log("HISTORY SYNC START from historyId=" . $startHistoryId);

    $url = 'https://gmail.googleapis.com/gmail/v1/users/me/history'
        . '?startHistoryId=' . urlencode($startHistoryId)
        . '&historyTypes=messageAdded'
        . '&historyTypes=labelAdded'
        . '&historyTypes=labelRemoved' //;
        . '&labelId=INBOX';

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

    error_log("HISTORY LIST HTTP CODE: " . $httpCode);
    error_log("HISTORY LIST RAW RESPONSE: " . $response);

    if ($httpCode !== 200) {

        // historyId demasiado viejo → reset seguro
        if ($httpCode === 404) {
            error_log("HISTORY ID TOO OLD → RESET REQUIRED");

            $stmt = $conn->prepare("
                UPDATE google_gmail_tokens
                SET last_history_id = NULL
                WHERE id = ?
            ");
            $stmt->execute([$tokenRow['id']]);

            die('HistoryId expirado. Reintentar.');
        }

        die('Error consultando Gmail History API');
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

    error_log("HISTORY NEW MESSAGES COUNT=" . count($messageIds));

    if (count($messageIds) === 0) {
        // Igual actualizamos last_history_id si vino
        if ($newHistoryId) {
            $stmt = $conn->prepare("
                UPDATE google_gmail_tokens
                SET last_history_id = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newHistoryId, $tokenRow['id']]);
            error_log("HISTORY UPDATED last_history_id=" . $newHistoryId);
        }

        // Cerrar sync_run (sin cambios)
        $stmt = $conn->prepare("
            UPDATE sync_runs
            SET ended_at = NOW(),
                threads_fetched = 0,
                messages_fetched = 0
            WHERE id = ?
        ");
        $stmt->execute([$syncRunId]);

        echo "SYNC INCREMENTAL COMPLETADO\n";
        echo "Threads nuevos: 0\n";
        echo "Mensajes nuevos: 0\n";

        exit;
    }

    // Procesar threads
    $threadsFetched = 0;
    $messagesFetched = 0;

    // Aplicar eventos de labels (archivado / eliminado)
    foreach ($labelEvents as $gmailMessageId => $events) {

        // Buscar email existente
        $stmt = $conn->prepare("
            SELECT id FROM emails
            WHERE user_id = ? 
                AND gmail_message_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $gmailMessageId]);
        $emailId = $stmt->fetchColumn();

        if (!$emailId) {
            continue; // aún no está en DB
        }

        // ARCHIVADO = INBOX removido
        if (!empty($events['removed']) && in_array('INBOX', $events['removed'], true)) {
            $conn->prepare("
                UPDATE emails
                SET is_inbox = 0
                WHERE id = ?
            ")->execute([$emailId]);

            error_log("EMAIL ARCHIVED msgId={$gmailMessageId}");
        }

        // ELIMINADO = TRASH agregado
        if (!empty($events['added']) && in_array('TRASH', $events['added'], true)) {
            $conn->prepare("
                UPDATE emails
                SET is_deleted = 1,
                    is_inbox = 0
                WHERE id = ?
            ")->execute([$emailId]);

            error_log("EMAIL DELETED msgId={$gmailMessageId}");
        }
    }

    foreach ($messageIds as $gmailMessageId) {

        // 1) Idempotencia: si ya está, saltar
        $stmt = $conn->prepare("
            SELECT id FROM emails
            WHERE user_id = ?
                AND gmail_message_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $gmailMessageId]);
        if ($stmt->fetchColumn()) {
            continue;
        }

        // 2) Traer mensaje FULL
        try {
            $msg = fetchGmailMessageFull($accessTokenString, $gmailMessageId);

            error_log("PAYLOAD STRUCTURE:");
            error_log(print_r($msg['payload'], true));

        } catch (Exception $e) {
            // No abortar toda la sync por 1 mensaje
            error_log("FETCH MESSAGE FAILED msgId={$gmailMessageId} err=" . $e->getMessage());
            continue;
        }

        if (empty($msg['threadId'])) {
            error_log("MESSAGE WITHOUT THREAD msgId=" . $gmailMessageId);
            continue;
        }

        $threadId = $msg['threadId'];

        // 3) Resolver thread en DB
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

        // 4) Persistir email
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
        $messageIdHeader = $headerMap['message-id'] ?? null;

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
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)
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
            1,
            0,
            $headerMap['message-id'] ?? null,
            $headerMap['references'] ?? null
        ]);

        $emailId = $conn->lastInsertId();
        
        // Reconciliar temporales "sent_*" vs real Gmail
        reconcileSentTempAgainstReal(
            $conn,
            (int)$userId,
            (int)$threadDbId,
            (int)$emailId,
            [
                'from_email'     => $fromEmail,
                'subject'        => $headerMap['subject'] ?? '',
                'body_text'      => $body['text'] ?? '',
                'internal_date'  => $internalDate,
                'rfc_message_id' => $messageIdHeader, 
            ]
        );

        // Buscar email temporal reconciliado
        $stmt = $conn->prepare("
            SELECT id
            FROM emails
            WHERE replaced_by = ?
            AND is_temporary = 1
            LIMIT 1
        ");
        $stmt->execute([$emailId]);
        $tempEmailId = $stmt->fetchColumn();

        if ($tempEmailId) {
            reconcileTempAttachmentsAgainstReal(
                $conn,
                (int)$tempEmailId,
                (int)$emailId
            );
        }

        // 5) Guardar headers
        $stmt = $conn->prepare("
            INSERT INTO email_headers (email_id, headers_json)
            VALUES (?, ?)
        ");
        $stmt->execute([
            $emailId,
            json_encode($headers, JSON_UNESCAPED_UNICODE)
        ]);

        // 6) Adjuntos (si hay)
        $attachments = extractAttachments($msg['payload'] ?? []);
        error_log("ATTACHMENTS FOUND: " . print_r($attachments, true));

        if (!empty($attachments)) {

            $baseDir = __DIR__ . "/../storage/users/{$userId}/threads/{$threadDbId}/attachments/{$emailId}";

            error_log("BASE DIR: " . $baseDir);

            if (!is_dir($baseDir)) {
                mkdir($baseDir, 0775, true);
            }

            foreach ($attachments as $att) {

                $stmt = $conn->prepare("
                    SELECT id FROM email_attachments
                    WHERE email_id = ? AND attachment_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$emailId, $att['attachment_id']]);
                if ($stmt->fetch()) {
                    continue;
                }

                if ($att['attachment_id']) {

                    $binary = downloadGmailAttachment(
                        $accessTokenString,
                        $gmailMessageId,
                        $att['attachment_id']
                    );

                } else {

                    // attachment viene inline
                    $binary = $att['inline_data'];
                }


                if ($binary === null) {
                    continue;
                }

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

    if ($newHistoryId) {

        $stmt = $conn->prepare("
            UPDATE google_gmail_tokens
            SET last_history_id = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $newHistoryId,
            $tokenRow['id']
        ]);

        error_log("HISTORY UPDATED last_history_id=" . $newHistoryId);
    }

    // Cerrar sync
    $stmt = $conn->prepare("
        UPDATE sync_runs
        SET ended_at = NOW(),
            threads_fetched = ?,
            messages_fetched = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $threadsFetched,
        $messagesFetched,
        $syncRunId
    ]);

    echo "[SYNC] Incremental sync finished\n";

    echo "[SYNC] User {$userId} finished\n";
}

echo "[SYNC] All users finished\n";