<?php
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/google_config.php';
require __DIR__ . '/../helpers/gmail_oauth.php';
require __DIR__ . '/../helpers/attachments.php';

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
$fromEmail     = $_SESSION['email'] ?? '';
$attachments = normalizeAttachments($_FILES['attachments'] ?? []);
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
    SELECT rfc_message_id, rfc_references, from_email, subject_original
    FROM emails
    WHERE thread_id = ?
      AND user_id = ?
      AND from_email <> ?
      AND rfc_message_id IS NOT NULL
      AND is_deleted IS NOT NULL
      AND (is_temporary IS NULL OR is_temporary = 0)
    ORDER BY COALESCE (internal_date, sent_at_local) DESC
    LIMIT 1
");
$stmt->execute([$dbThreadId, $userId, $fromEmail]);
$threadInfo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$threadInfo) {
    die('No se encontró un email externo para responder (faltan datos en emails). Ejecutá sync.');
}

$to        = trim($threadInfo['from_email']);
$messageId = trim($threadInfo['rfc_message_id']);
$references = trim($threadInfo['rfc_references'] ?? '');
$subject    = preg_replace('/^Re:\s*/i', '', $threadInfo['subject_original'] ?? '');

if ($gmailThreadId === '' || $messageId === '') {
    die('Faltan IDs de Gmail (thread/message). Ejecutá sync.');
}

//email temporal

$stmt = $conn->prepare("
    INSERT INTO emails (
        user_id,
        thread_id,
        gmail_message_id,
        from_email,
        to_email,
        subject_original,
        body_text,
        is_temporary,
        sent_at_local,
        has_attachments
    ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), ?)
");
$stmt->execute([
    $userId,
    $dbThreadId,
    'sent_'.uniqid(),
    $fromEmail,
    $to,
    'Re: ' . $subject,
    $bodyText,
    $hasFiles ? 1 : 0
]);

$tempEmailId = $conn->lastInsertId();

/* 4) Guardar ADJUNTOS TEMPORALES */
if ($hasFiles) {

    $baseDir = __DIR__ . "/../storage/users/{$userId}/threads/{$dbThreadId}/tmp_attachments/{$tempEmailId}";
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

/* 5) Token Gmail */
$stmt = $conn->prepare("
    SELECT * FROM google_gmail_tokens
    WHERE user_id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tokenRow) {
    die('Gmail no conectado.');
}

try {
    $accessToken = refreshAccessToken($conn, $tokenRow);
} catch (Exception $e) {
    http_response_code(401);
    die('Token Gmail inválido.');
}

/* 6) Construir MIME */
$boundary = '----=_Part_' . md5(time());

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

foreach ($attachments as $att) {
    // $content = chunk_split(base64_encode(file_get_contents($att['tmp_name'])));
    $content = chunk_split(base64_encode(file_get_contents($filePath)));

    $bodyRaw .=
        "--$boundary\r\n" .
        "Content-Type: {$att['type']}; name=\"{$att['name']}\"\r\n" .
        "Content-Disposition: attachment; filename=\"{$att['name']}\"\r\n" .
        "Content-Transfer-Encoding: base64\r\n\r\n" .
        $content . "\r\n";
}

$bodyRaw .= "--$boundary--";

$rawMessage     = $headersRaw . "\r\n\r\n" . $bodyRaw;
$encodedMessage = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

/* 7) Enviar a Gmail */
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
    error_log("GMAIL REPLY ERROR: " . $response);
    die('Error enviando respuesta.');
}

header('Location: thread_view.php?id=' . $dbThreadId);
exit;