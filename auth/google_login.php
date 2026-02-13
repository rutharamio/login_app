<?php
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/google_config.php';

$_SESSION['oauth_state'] = bin2hex(random_bytes(16));

$params = [
    'client_id' => GOOGLE_CLIENT_ID,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'access_type' => 'offline',
    'state' => $_SESSION['oauth_state'],
    'prompt' => 'consent'
];

$url = GOOGLE_AUTH_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
header('Location: ' . $url);
exit;
