<?php
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/google_config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

if (
    !isset($_GET['code'], $_GET['state']) ||
    !isset($_SESSION['gmail_oauth_state']) ||
    $_GET['state'] !== $_SESSION['gmail_oauth_state']
) {
    unset($_SESSION['gmail_oauth_state']);
    header('Location: ../dashboard.php?gmail=error');
    exit;
}

unset($_SESSION['gmail_oauth_state']);

/* Intercambiar code por tokens */
$data = [
    'code' => $_GET['code'],
    'client_id' => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri' => GOOGLE_GMAIL_REDIRECT_URI,
    'grant_type' => 'authorization_code'
];

$ch = curl_init(GOOGLE_TOKEN_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($data)
]);
$response = curl_exec($ch);
curl_close($ch);

$token = json_decode($response, true);

if (!isset($token['access_token'], $token['expires_in'])) {
    header('Location: ../dashboard.php?gmail=error');
    exit;
}

$expiresAt = date('Y-m-d H:i:s', time() + (int)$token['expires_in']);

$stmt = $conn->prepare("
INSERT INTO google_gmail_tokens
(
    user_id,
    access_token,
    refresh_token,
    expires_at,
    scope,
    token_type,
    state,
    last_history_id,
    needs_initial_sync,
    created_at
)
VALUES
(
    ?, ?, ?, ?, ?, ?, 'active', NULL, 1, NOW()
)
ON DUPLICATE KEY UPDATE
    access_token = VALUES(access_token),
    refresh_token = VALUES(refresh_token),
    expires_at = VALUES(expires_at),
    scope = VALUES(scope),
    token_type = VALUES(token_type),
    state = 'active',
    needs_initial_sync = 1,
    last_history_id = NULL,
    updated_at = NOW()
");

$stmt->execute([
    $_SESSION['user_id'],
    $token['access_token'],
    $token['refresh_token'] ?? null,
    $expiresAt,
    $token['scope'] ?? 'https://www.googleapis.com/auth/gmail.modify',
    $token['token_type'] ?? 'Bearer'
]);

header('Location: ../dashboard.php?gmail=connected');
exit;
