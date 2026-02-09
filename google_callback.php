<?php
require __DIR__ . '/config/session.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/config/google_config.php';

if (
    !isset($_GET['state']) ||
    !isset($_SESSION['oauth_state']) ||
    $_GET['state'] !== $_SESSION['oauth_state']
) {
    header('Location: index.php?error=google');
    exit;
}

unset($_SESSION['oauth_state']);

if (!isset($_GET['code'])) {
    header('Location: index.php?error=google');
    exit;
}

/* Intercambiar code por token */
$data = [
    'code' => $_GET['code'],
    'client_id' => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
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

if (!isset($token['access_token'])) {
    header('Location: index.php?error=google');
    exit;
}

/* Obtener info del usuario */
$ch = curl_init(GOOGLE_USERINFO_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token['access_token']
    ]
]);
$userInfo = curl_exec($ch);
curl_close($ch);

$googleUser = json_decode($userInfo, true);

if (!isset($googleUser['email'])) {
    header('Location: index.php?error=google');
    exit;
}

/* Crear o buscar usuario */
$stmt = $conn->prepare("
    SELECT id, usuario, email, rol
    FROM usuarios
    WHERE google_id = ? OR email = ?
    LIMIT 1
");
$stmt->execute([$googleUser['id'], $googleUser['email']]);
$user = $stmt->fetch();

if (!$user) {
    $stmt = $conn->prepare("
        INSERT INTO usuarios (usuario, email, google_id, email_verified)
        VALUES (?, ?, ?, 1)
    ");
    $stmt->execute([
        $googleUser['given_name'] ?? explode('@', $googleUser['email'])[0],
        $googleUser['email'],
        $googleUser['id']
    ]);

    $userId = $conn->lastInsertId();
    $rol = 'user';
} else {
    $userId = $user['id'];
    $rol = $user['rol'];
}

/* Sesión */
session_regenerate_id(true);
$_SESSION['user_id'] = $userId;
$_SESSION['rol'] = $rol;
$_SESSION['email'] = $googleUser['email'];

header('Location: dashboard.php');
exit;
