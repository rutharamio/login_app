<?php
require __DIR__ . '/../../config/session.php';
require __DIR__ . '/../../config/google_config.php';

/* Debe estar logueado */
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

/* CSRF state */
$_SESSION['gmail_oauth_state'] = bin2hex(random_bytes(16));

$params = [
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_GMAIL_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'https://www.googleapis.com/auth/gmail.modify',
    'access_type'   => 'offline',
    'prompt'        => 'consent',
    'state'         => $_SESSION['gmail_oauth_state']
];

$url = GOOGLE_GMAIL_AUTH_URL . '?' . http_build_query($params);
header('Location: ' . $url);
exit;
