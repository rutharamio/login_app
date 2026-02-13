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

if (!isset($_POST['thread_id'], $_POST['gmail_thread_id'], $_POST['message'])) {
    exit('Datos incompletos.');
}

$userId        = (int) $_SESSION['user_id'];
$dbThreadId    = (int) $_POST['thread_id'];
$gmailThreadId = trim($_POST['gmail_thread_id']);
$bodyText      = trim($_POST['message']);
$fromEmail     = $_SESSION['email'] ?? '';

$attachments = normalizeAttachments($_FILES['attachments'] ?? []);
$hasText  = $bodyText !== '';
$hasFiles = !empty($_FILES['attachments']['name'][0]);

if (!$hasText && !$hasFiles) {
    exit('No se puede enviar un mensaje vacío sin adjuntos.');
}

//onwership del thread

$stmt = $conn->prepare("
    SELECT id
    FROM email_threads
    WHERE id = ? AND user_id = ?
    LIMIT 1
");
$stmt->execute([$dbThreadId, $userId]);

if (!$stmt->fetchColumn()) {
    exit('Thread no encontrado');
}

//ultimo email externo

$stmt = $conn->prepare("
    SELECT rfc_message_id, rfc_references, from_email, subject_original
    FROM emails
    WHERE thread_id = ?
      AND user_id = ?
      AND rfc_message_id IS NOT NULL
      AND (is_temporary IS NULL OR is_temporary = 0)
    ORDER BY COALESCE(internal_date, sent_at_local) DESC
    LIMIT 1
");
$stmt->execute([$dbThreadId, $userId]);
$threadInfo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$threadInfo) {
    exit('No se encontró email externo. Ejecutá sync.');
}

$to         = trim($threadInfo['from_email']);
$messageId  = trim($threadInfo['rfc_message_id']);
$references = trim($threadInfo['rfc_references'] ?? '');
$subject    = preg_replace('/^Re:\s*/i', '', $threadInfo['subject_original'] ?? '');

if ($gmailThreadId === '' || $messageId === '') {
    exit('IDs Gmail inválidos.');
}

$cleanText = trim(strip_tags($bodyText));
$cleanText = preg_replace('/\s+/', ' ', $cleanText);
$snippet   = mb_substr($cleanText, 0, 160);

//insertar email temp

$stmt = $conn->prepare("
    INSERT INTO emails (
        user_id,
        thread_id,
        gmail_message_id,
        from_email,
        to_email,
        subject_original,
        body_text,
        snippet,
        is_temporary,
        sent_at_local,
        has_attachments
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), ?)
");

$stmt->execute([
    $userId,
    $dbThreadId,
    'sent_' . uniqid(),
    $fromEmail,
    $to,
    'Re: ' . $subject,
    $bodyText,
    $snippet,
    $hasFiles ? 1 : 0
]);

$tempEmailId = $conn->lastInsertId();

//token

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
    exit('Token Gmail inválido. La conexion con Gmail expiró. Reconectá tu cuenta.');
}

//construir mime

$boundary = '----=_Part_' . md5(uniqid('', true));

$referencesHeader = $references
    ? $references . ' ' . $messageId
    : $messageId;

$headers =
    "From: {$fromEmail}\r\n" .
    "To: {$to}\r\n" .
    "Subject: Re: {$subject}\r\n" .
    "In-Reply-To: {$messageId}\r\n" .
    "References: {$referencesHeader}\r\n" .
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
        'raw'      => $encoded,
        'threadId' => $gmailThreadId
    ])
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

error_log('SEND RESPONSE: ' . $response);

if ($httpCode !== 200) {
    error_log("GMAIL REPLY ERROR: " . $response);
    exit('Error enviando respuesta.');
}

//confirmar email como enviado

$stmt = $conn->prepare("
    UPDATE emails
    SET
        is_temporary = 0,
        is_sent = 1,
        is_read = 1,
        internal_date = UTC_TIMESTAMP()
    WHERE id = ?
");
$stmt->execute([$tempEmailId]);

// guardar adjuntos

if ($hasFiles) {

    $baseDir = __DIR__ . "/../../storage/users/{$userId}/threads/{$dbThreadId}/attachments/{$tempEmailId}";
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

header('Location: /login_app/gmail/thread_view.php?id=' . $dbThreadId);
exit;
