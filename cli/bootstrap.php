<?php
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit('CLI only');
}   //error 403 es por falta de permisos

define ('BASE_PATH', realpath(__DIR__ . '/..'));

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/config/db.php';
require BASE_PATH . '/config/google_config.php';
require BASE_PATH . '/config/mail.php';

date_default_timezone_set('UTC');  // por?

require BASE_PATH . '/lib/GmailService.php';
require BASE_PATH . '/helpers/gmail_oauth.php';
require BASE_PATH . '/helpers/reconciliation.php';
require BASE_PATH . '/helpers/attachments.php';
require BASE_PATH . '/helpers/gmail_message.php';

echo "[BOOTSTRAP] CLI ready\n";
