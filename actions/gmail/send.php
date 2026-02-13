<?php
require __DIR__ . '/../../config/session.php';
require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../config/google_config.php';
require __DIR__ . '/../../helpers/gmail_oauth.php';
require __DIR__ . '/../../helpers/attachments.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido');
}

if (empty($_POST['to']) || empty($_POST['subject']) || !isset($_POST['message'])) {
    exit('Datos incompletos.');
}

$userId    = (int) $_SESSION['user_id'];
$fromEmail = $_SESSION['email'];
$to        = trim($_POST['to']);
$subject   = trim($_POST['subject']);
$bodyText  = trim($_POST['message']);

$attachments = normalizeAttachments($_FILES['attachments'] ?? []);
$hasText  = $bodyText !== '';
$hasFiles = !empty($_FILES['attachments']['name'][0]);

if (!$hasText && !$hasFiles) {
    exit('No se puede enviar un mensaje vacío sin adjuntos.');
}


$cleanText = trim(strip_tags($bodyText));
$cleanText = preg_replace('/\s+/', ' ', $cleanText);
$snippet   = mb_substr($cleanText, 0, 160);

//insertar email temp sin thread

$stmt = $conn->prepare("
    INSERT INTO emails (
        user_id,
        from_email,
        to_email,
        subject_original,
        body_text,
        snippet,
        is_temporary,
        sent_at_local,
        has_attachments
    ) VALUES (?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), ?)
");

$stmt->execute([
    $userId,
    $fromEmail,
    $to,
    $subject,
    $bodyText,
    $snippet,
    $hasFiles ? 1 : 0
]);

$tempEmailId = $conn->lastInsertId();

// obtener token

$stmt = $conn->prepare("
    SELECT * FROM google_gmail_tokens
    WHERE user_id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tokenRow) {
    exit('Gmail no conectado.');
}

if ($tokenRow['state'] !== 'active') {
    header('Location: /login_app/gmail/inbox.php?gmail_expired=1');
    exit;
}

try {
    $accessToken = getValidAccessToken($conn, $userId);
} catch (Exception $e) {
        // limpiar email temporal si se creó
    if (isset($tempEmailId)) {
        $stmt = $conn->prepare("DELETE FROM emails WHERE id = ?");
        $stmt->execute([$tempEmailId]);
    }
    http_response_code(401);
    exit('Token Gmail inválido. La conexión con Gmail expiró. Reconectá tu cuenta.');
}

//construir MIME

$boundary = '----=_Part_' . md5(uniqid('', true));

$headers =
    "From: {$fromEmail}\r\n" .
    "To: {$to}\r\n" .
    "Subject: {$subject}\r\n" .
    "MIME-Version: 1.0\r\n" .
    "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

$body =
    "--{$boundary}\r\n" .
    "Content-Type: text/plain; charset=UTF-8\r\n\r\n" .
    ($hasText ? $bodyText : '(Adjunto)') . "\r\n";

foreach ($attachments as $att) {

    $content = chunk_split(
        base64_encode(file_get_contents($att['tmp_name']))
    );

    $body .=
        "--{$boundary}\r\n" .
        "Content-Type: {$att['type']}; name=\"{$att['name']}\"\r\n" .
        "Content-Disposition: attachment; filename=\"{$att['name']}\"\r\n" .
        "Content-Transfer-Encoding: base64\r\n\r\n" .
        $content . "\r\n";
}

$body .= "--{$boundary}--";

$rawMessage = $headers . "\r\n\r\n" . $body;
$encoded    = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');
//enviar a gmail

$ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'raw' => $encoded
    ])
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

error_log('SEND RESPONSE: ' . $response);

if ($httpCode !== 200) {
    error_log("SEND FAIL: " . $response);
    exit('Error enviando correo.');
}

$data = json_decode($response, true);
$gmailMessageId = $data['id'] ?? null;
$gmailThreadId  = $data['threadId'] ?? null;

if (!$gmailMessageId || !$gmailThreadId) {
    exit('Respuesta inválida de Gmail.');
}

//crear o buscar thread

$stmt = $conn->prepare("
    SELECT id FROM email_threads
    WHERE user_id = ? AND gmail_thread_id = ?
    LIMIT 1
");
$stmt->execute([$userId, $gmailThreadId]);
$threadDbId = $stmt->fetchColumn();

if (!$threadDbId) {
    $stmt = $conn->prepare("
        INSERT INTO email_threads (user_id, gmail_thread_id, created_at)
        VALUES (?, ?, NOW())
    ");
    $stmt->execute([$userId, $gmailThreadId]);
    $threadDbId = $conn->lastInsertId();
}

//actualizar temp

$stmt = $conn->prepare("
    UPDATE emails
    SET
        gmail_message_id = ?,
        thread_id = ?,
        is_temporary = 0,
        is_sent = 1,
        is_inbox = 0,
        internal_date = UTC_TIMESTAMP(),
        is_read = 1
    WHERE id = ?
");

$stmt->execute([
    $gmailMessageId,
    $threadDbId,
    $tempEmailId
]);

// Obtener Message-ID real desde Gmail (RFC)
$metaUrl = "https://gmail.googleapis.com/gmail/v1/users/me/messages/{$gmailMessageId}?format=metadata&metadataHeaders=Message-ID";

$metaCh = curl_init($metaUrl);
curl_setopt_array($metaCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken
    ]
]);

$metaResponse = curl_exec($metaCh);
$metaHttpCode = curl_getinfo($metaCh, CURLINFO_HTTP_CODE);
curl_close($metaCh);

if ($metaHttpCode === 200) {

    $metaData = json_decode($metaResponse, true);
    $realMessageId = null;

    if (!empty($metaData['payload']['headers'])) {
        foreach ($metaData['payload']['headers'] as $header) {
            if (strtolower($header['name']) === 'message-id') {
                $realMessageId = $header['value'];
                break;
            }
        }
    }

    if ($realMessageId) {
        $stmt = $conn->prepare("
            UPDATE emails
            SET rfc_message_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$realMessageId, $tempEmailId]);
    }
}


// guardar adjuntos con thread

if ($hasFiles) {

    $baseDir = __DIR__ . "/../../storage/users/{$userId}/threads/{$threadDbId}/attachments/{$tempEmailId}";
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0775, true);
    }

    foreach ($attachments as $att) {

        $safeName = basename($att['name']);
        $filePath = $baseDir . '/' . $safeName;

        move_uploaded_file($att['tmp_name'], $filePath);

        $stmt = $conn->prepare("
            INSERT INTO email_attachments (
                email_id,
                filename,
                mime_type,
                size_bytes,
                attachment_id,
                saved_path,
                downloaded_at
            ) VALUES (?, ?, ?, ?, NULL, ?, NOW())
        ");

        $stmt->execute([
            $tempEmailId,
            $safeName,
            $att['type'],
            $att['size'],
            $filePath
        ]);
    }
}

// redirigir

header('Location: /login_app/gmail/thread_view.php?id=' . $threadDbId);
exit;
