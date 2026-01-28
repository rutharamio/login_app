<?php
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/google_config.php';
require __DIR__ . '/../helpers/gmail_oauth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

if (!isset($_POST['thread_id'], $_POST['gmail_thread_id'], $_POST['message'])) {
    die('Datos incompletos.');
}

$userId        = (int) $_SESSION['user_id'];
$dbThreadId    = (int) $_POST['thread_id'];          // thread interno (email_threads.id)
$gmailThreadId = trim($_POST['gmail_thread_id']);    // Gmail thread id real (varchar)
$bodyText      = trim($_POST['message']);

$hasText  = $bodyText !== '';
$hasFiles = !empty($_FILES['attachments']['name'][0]);

if (!$hasText && !$hasFiles) {
    die('No se puede enviar un mensaje vacío sin adjuntos.');
}

/* 1) Verificar ownership del thread interno */
$stmt = $conn->prepare("
    SELECT id
    FROM email_threads
    WHERE id = ? AND user_id = ?
    LIMIT 1
");
$stmt->execute([$dbThreadId, $userId]);
if (!$stmt->fetchColumn()) {
    die('Thread no encontrado');
}

$stmt = $conn->prepare("
    SELECT *
    FROM google_gmail_tokens
    WHERE user_id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tokenRow) {
    die('Gmail no conectado.');
}

/* 2) Token Gmail */
if (!$tokenRow) {
    die('Gmail no conectado.');
}

try {
    $accessToken = refreshAccessToken($conn, $tokenRow);
} catch (Exception $e) {
    http_response_code(401);
    die('Token Gmail inválido. Reconectar Gmail.');
}

/* 3) Obtener destinatario y message-id del último email EXTERNO (para reply headers) */
$stmt = $conn->prepare("
    SELECT rfc_message_id, rfc_references, from_email
    FROM emails
    WHERE thread_id = ?
      AND user_id = ?
      AND from_email <> ?
      AND rfc_message_id IS NOT NULL
    ORDER BY internal_date DESC
    LIMIT 1
");
$stmt->execute([$dbThreadId, $userId, $_SESSION['email']]);
$threadInfo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$threadInfo) {
    die('No se encontró un email externo para responder (faltan datos en emails). Ejecutá sync.');
}

$to        = trim($threadInfo['from_email']);
$messageId = trim($threadInfo['rfc_message_id']);
$references = trim($threadInfo['rfc_references'] ?? '');

if ($gmailThreadId === '' || $messageId === '') {
    die('Faltan IDs de Gmail (thread/message). Ejecutá sync.');
}

/* 4) Subject */
$stmt = $conn->prepare("
    SELECT subject_original
    FROM emails
    WHERE thread_id = ?
      AND user_id = ?
    ORDER BY internal_date DESC
    LIMIT 1
");
$stmt->execute([$dbThreadId, $userId]);
$subject = $stmt->fetchColumn() ?: 'Sin asunto';
$subject = preg_replace('/^Re:\s*/i', '', $subject);

/* 5) Construir MIME */
$boundary  = '----=_Part_' . md5(time());
$fromEmail = $_SESSION['email'];

$referencesHeader = $references
    ? $references . ' ' . $messageId
    : $messageId;

$headersRaw =
    "From: $fromEmail\r\n" .
    "To: $to\r\n" .
    "Subject: Re: $subject\r\n" .
    "In-Reply-To: $messageId\r\n" .
    "References: $referencesHeader\r\n" .
    "MIME-Version: 1.0\r\n" .
    "Content-Type: multipart/mixed; boundary=\"$boundary\"";

$bodyRaw =
    "--$boundary\r\n" .
    "Content-Type: text/plain; charset=\"UTF-8\"\r\n\r\n" .
    ($hasText ? $bodyText : '(Adjunto)') . "\r\n";

/* Adjuntos */
if ($hasFiles) {
    foreach ($_FILES['attachments']['tmp_name'] as $i => $tmp) {
        if (!is_uploaded_file($tmp)) continue;

        $filename = basename($_FILES['attachments']['name'][$i]);
        $mime     = mime_content_type($tmp);
        $content  = chunk_split(base64_encode(file_get_contents($tmp)));

        $bodyRaw .=
            "--$boundary\r\n" .
            "Content-Type: $mime; name=\"$filename\"\r\n" .
            "Content-Disposition: attachment; filename=\"$filename\"\r\n" .
            "Content-Transfer-Encoding: base64\r\n\r\n" .
            $content . "\r\n";
    }
}

$bodyRaw .= "--$boundary--";

/* 6) Enviar a Gmail */
$rawMessage     = $headersRaw . "\r\n\r\n" . $bodyRaw;
$encodedMessage = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

$ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'raw'      => $encodedMessage,
        'threadId' => $gmailThreadId
    ])
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo "<h2>Error al enviar el mensaje</h2>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
    exit;
}

/* 7) Guardar en DB (temporal, el sync después traerá el gmail_message_id real) */
$stmt = $conn->prepare("
    INSERT INTO emails (
        user_id,
        thread_id,
        gmail_message_id,
        from_email,
        subject_original,
        snippet,
        body_text,
        internal_date,
        sent_at_local,
        is_read,
        is_deleted,
        is_temporary
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP(), 1, 0, 1
    )
");

$stmt->execute([
    $userId,
    $dbThreadId,
    'sent_' . uniqid(),
    $_SESSION['email'],
    'Re: ' . $subject,
    mb_substr($bodyText, 0, 120),
    $bodyText
]);

header('Location: thread_view.php?id=' . $dbThreadId);
exit;