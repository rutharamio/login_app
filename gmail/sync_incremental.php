<?php

require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/google_config.php';
require __DIR__ . '/../helpers/reconciliation.php';
require __DIR__ . '/../helpers/gmail_oauth.php';

error_log("SYNC_INCREMENTAL HIT OK user_id=" . ($_SESSION['user_id'] ?? 'null'));

function extractEmailBody(array $payload): array
{
    $bodyText = '';
    $bodyHtml = '';

    $walk = function ($part) use (&$walk, &$bodyText, &$bodyHtml) {

        if (!is_array($part)) {
            return;
        }

        $mime = $part['mimeType'] ?? '';

        if ($mime === 'text/plain' && !empty($part['body']['data'])) {
            $bodyText .= base64_decode(strtr($part['body']['data'], '-_', '+/'));
        }

        if ($mime === 'text/html' && !empty($part['body']['data'])) {
            $bodyHtml .= base64_decode(strtr($part['body']['data'], '-_', '+/'));
        }

        if (!empty($part['parts'])) {
            foreach ($part['parts'] as $p) {
                $walk($p);
            }
        }
    };

    $walk($payload);

    return [
        'text' => trim($bodyText),
        'html' => trim($bodyHtml),
    ];
}

function extractAttachments(array $payload): array
{
    $attachments = [];

    $walk = function($part) use (&$attachments, &$walk) {
        if (!is_array($part)) return;

        if (!empty($part['filename']) && !empty($part['body']['attachmentId'])) {
            $attachments[] = [
                'filename'      => $part['filename'],
                'mime_type'     => $part['mimeType'] ?? 'application/octet-stream',
                'attachment_id' => $part['body']['attachmentId'],
                'size_bytes'    => $part['body']['size'] ?? 0
            ];
        }

        if (!empty($part['parts']) && is_array($part['parts'])) {
            foreach ($part['parts'] as $p) {
                $walk($p);
            }
        }
    };

    // payload puede tener parts o ser el part raíz
    $walk($payload);

    return $attachments;
}

function downloadGmailAttachment(
    string $accessToken,
    string $messageId,
    string $attachmentId
): ?string {

    $url = "https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}/attachments/{$attachmentId}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("GMAIL ATTACHMENT HTTP CODE: " . $httpCode);
    error_log("GMAIL ATTACHMENT RAW RESPONSE: " . $response);


    $data = json_decode($response, true);

    if (empty($data['data'])) {
        return null;
    }

    return base64_decode(strtr($data['data'], '-_', '+/'));
}

function fetchGmailMessageFull(string $accessToken, string $messageId): array
{
    $url = "https://gmail.googleapis.com/gmail/v1/users/me/messages/"
         . urlencode($messageId)
         . "?format=full";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("GMAIL MESSAGE FULL HTTP CODE: " . $httpCode . " msgId=" . $messageId);

    if ($httpCode !== 200) {
        error_log("GMAIL MESSAGE FULL RAW RESPONSE: " . $response);
        throw new Exception("No se pudo obtener mensaje full: $messageId");
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : [];
}

// Seguridad solo admins
if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Acceso denegado');
}

$userId = $_SESSION['user_id'];

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
    http_response_code(401);
    die('Token Gmail inválido');
}

if (empty($tokenRow['last_history_id'])) {

    error_log("HISTORY INIT: last_history_id is NULL, bootstrapping");

    $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/profile');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessTokenString
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("GMAIL PROFILE HTTP CODE: " . $httpCode);
    error_log("GMAIL PROFILE RAW RESPONSE: " . $response);

    if ($httpCode !== 200) {
        die('No se pudo obtener profile de Gmail');
    }

    $profile = json_decode($response, true);

    if (empty($profile['historyId'])) {
        die('Gmail profile sin historyId');
    }

    $stmt = $conn->prepare("
        UPDATE google_gmail_tokens
        SET last_history_id = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $profile['historyId'],
        $tokenRow['id']
    ]);

    error_log("HISTORY INIT OK historyId=" . $profile['historyId']);

    // IMPORTANTE:
    // en esta ejecución NO se hace sync incremental todavía
    echo "HISTORY INICIALIZADO. Ejecutar refresh nuevamente.\n";
    exit;
}

$startHistoryId = $tokenRow['last_history_id'];

error_log("HISTORY SYNC START from historyId=" . $startHistoryId);

$url = 'https://gmail.googleapis.com/gmail/v1/users/me/history'
     . '?startHistoryId=' . urlencode($startHistoryId)
     . '&historyTypes=messageAdded'
     . '&historyTypes=labelAdded'
     . '&historyTypes=labelRemoved'
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
            AND rfc_message_id = ?
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
            AND rfc_message_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId, $gmailMessageId]);
    if ($stmt->fetchColumn()) {
        continue;
    }

    // 2) Traer mensaje FULL
    try {
        $msg = fetchGmailMessageFull($accessTokenString, $gmailMessageId);
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
    $referencesHeader = $headerMap['references'] ?? null;

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
        $messageIdHeader,
        $referencesHeader
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

    if (!empty($attachments)) {

        $baseDir = __DIR__ . "/../storage/attachments/{$userId}/{$emailId}";
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

            $binary = downloadGmailAttachment(
                $accessTokenString,
                $gmailMessageId,
                $att['attachment_id']
            );

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

// Output
echo "SYNC INCREMENTAL COMPLETADO\n";
echo "Threads nuevos: $threadsFetched\n";
echo "Mensajes nuevos: $messagesFetched\n";