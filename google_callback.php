<?php
require __DIR__ . '/config/session.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/config/google_config.php';

// DEBUG TEMPORAL: quitar cuando funcione
error_log('google_callback GET: ' . print_r($_GET, true));
error_log('google_callback COOKIE: ' . print_r($_COOKIE, true));
error_log('google_callback SESSION: ' . print_r($_SESSION, true));

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

$data = [
    'code' => $_GET['code'],
    'client_id' => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'grant_type' => 'authorization_code'
];

$ch = curl_init(GOOGLE_TOKEN_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
$response = curl_exec($ch);
curl_close($ch);

$token = json_decode($response, true);

if (!isset($token['access_token'])) {
    echo '<pre>';
    echo "ERROR AL OBTENER TOKEN:\n\n";
    print_r($token);
    exit;
}

$ch = curl_init(GOOGLE_USERINFO_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token['access_token']
]);
$userInfo = curl_exec($ch);
curl_close($ch);

$googleUser = json_decode($userInfo, true);

if (!isset($googleUser['email'])) {
    header('Location: index.php?error=google');
    exit;
}

$sql = "SELECT id, usuario, email, rol
        FROM usuarios
        WHERE google_id = ? OR email = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->execute([$googleUser['id'], $googleUser['email']]);
$user = $stmt->fetch();

if (!$user) {
    $usuarioGoogle = $googleUser['given_name']
        ?? explode('@', $googleUser['email'])[0];

    $sql = "INSERT INTO usuarios (usuario, email, google_id, email_verified)
            VALUES (?, ?, ?, 1)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $usuarioGoogle,
        $googleUser['email'],
        $googleUser['id']
    ]);

    $userId = $conn->lastInsertId();
    $usuario = $usuarioGoogle;
    $email = $googleUser['email'];
} else {
    $userId = $user['id'];
    $usuario = $user['usuario'];
    $email = $user['email'];
}

session_regenerate_id(true);
$_SESSION['user_id'] = $userId;
$_SESSION['usuario'] = $usuario;
$_SESSION['email'] = $email;
$_SESSION['rol'] = $user['rol']??'user';
header('Location: dashboard.php');
exit;
