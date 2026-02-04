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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido');
}

if (empty($_POST['to']) || empty($_POST['subject']) || (!isset($_POST['message']))) {
die('Datos incompletos.');
}

$userId        = (int) $_SESSION['user_id'];        
$to = trim($_POST['to']);
$subject = trim($_POST['subject']);
$bodyText = trim($_POST['message']);
$attachments = normalizeAttachments($_FILES['attachments'] ?? []);
$hasText  = $bodyText !== '';
$hasFiles = !empty($_FILES['attachments']['name'][0]);
$fromEmail = $_SESSION['email'];

if (!$hasText && !$hasFiles) {
die('No se puede enviar un mensaje vacío sin adjuntos.');
}

// 3) Email TEMPORAL (DB)

$stmt = $conn->prepare("
    INSERT INTO emails (
        user_id,
        from_email,
        to_email,
        subject_original,
        body_text,
        is_temporary,
        sent_at_local,
        has_attachments
    ) VALUES (?, ?, ?, ?, ?, 1, NOW(), ?)
");

$stmt->execute([
    $userId,
    $fromEmail,
    $to,
    $subject,
    $bodyText,
    $hasFiles ? 1 : 0
]);

$tempEmailId = $conn->lastInsertId();

// Guardar ADJUNTOS TEMPORALES

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

// Tokens
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

try {
$accessToken = refreshAccessToken($conn, $tokenRow);
} catch (Exception $e) {
http_response_code(401);
die('Token Gmail inválido. Reconectar Gmail.');
}

//construccion

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
    
    $path = __DIR__ . "/../storage/users/{$userId}/threads/{$dbThreadId}/tmp_attachments/{$tempEmailId}/" . basename($att['name']);

    if (!is_file($path)) {
        error_log("ATTACHMENT NOT FOUND: {$path}");
        continue;
    }

    $content = chunk_split(
        base64_encode(file_get_contents($path))
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

if ($httpCode !== 200) {
    error_log("SEND FAIL: " . $response);
    exit('Error enviando correo');
}

//redirigir
header('Location: inbox.php');
exit;