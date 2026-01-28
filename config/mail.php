<?php
require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendVerificationEmail(string $to, string $link): bool
{
    try {
        $mail = new PHPMailer(true);

        // SMTP Gmail
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'testsapplogin@gmail.com';          
        $mail->Password   = 'lgrq kumf mess hidp ';          
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('rutharamio@gmail.com', 'Login App');
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Verificá tu cuenta';

        $mail->Body = "
            <p>Gracias por registrarte.</p>
            <p>Hacé click en el siguiente enlace para verificar tu cuenta:</p>
            <p><a href='{$link}'>{$link}</a></p>
            <p>Este enlace vence en 24 horas.</p>
        ";

        $mail->AltBody = "Verificá tu cuenta: {$link}";

        $mail->send();
        return true;

    } catch (Exception $e) {
        return false;
    }
}
