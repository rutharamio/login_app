<?php
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/google_config.php';

/* Debe estar logueado */
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

/* Validaciones básicas */
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

/* 1. Intercambiar code por tokens */
$data = [
    'code'          => $_GET['code'],
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => GOOGLE_GMAIL_REDIRECT_URI,
    'grant_type'    => 'authorization_code'
];

$ch = curl_init(GOOGLE_TOKEN_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
$response = curl_exec($ch);
curl_close($ch);

$token = json_decode($response, true);

if (!isset($token['access_token'], $token['expires_in'])) {
    header('Location: ../dashboard.php?gmail=error');
    exit;
}

/* 2. Calcular expiración */
$expiresAt = date('Y-m-d H:i:s', time() + (int)$token['expires_in']);

/* 3. Insertar o actualizar tokens */
$sql = "
INSERT INTO google_gmail_tokens
    (user_id, access_token, refresh_token, expires_at, scope, token_type, state)
VALUES
    (?, ?, ?, ?, ?, ?, 'active')
ON DUPLICATE KEY UPDATE
    access_token = VALUES(access_token),
    refresh_token = VALUES(refresh_token),
    expires_at = VALUES(expires_at),
    scope = VALUES(scope),
    token_type = VALUES(token_type),
    state = 'active'
";

$stmt = $conn->prepare($sql);
$stmt->execute([
    $_SESSION['user_id'],
    $token['access_token'],
    $token['refresh_token'],
    $expiresAt,
    $token['scope'] ?? 'https://www.googleapis.com/auth/gmail.modify',
    $token['token_type'] ?? 'Bearer'
]);

/* 4. Volver al dashboard */
header('Location: ../dashboard.php?gmail=connected');
exit;
