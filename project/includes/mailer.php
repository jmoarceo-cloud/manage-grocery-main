<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// ========================================================
// mailer.php (POS side) — Email sending for Two-Factor Authentication
// ========================================================
// Mirrors HRMS/includes/mailer.php so this side can send OTP emails
// to Finance accounts without depending on files outside its own
// folder (keeps POS deployable on its own web root/server, separate
// from HRMS, same as every other shared-concept file in this app).
//
// Uses PHPMailer (includes/PHPMailer/) over SMTP — see
// HRMS/includes/mailer.php for the full Gmail App Password setup
// notes if these credentials ever need to be rotated.
// ========================================================

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
require_once __DIR__ . '/env.php';
loadEnv(__DIR__ . '/../.env');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!defined('SMTP_HOST')) {
    define('SMTP_HOST',       env('SMTP_HOST', 'smtp.gmail.com'));
    define('SMTP_PORT',       (int) env('SMTP_PORT', '587'));
    define('SMTP_USERNAME',   env('SMTP_USERNAME', ''));
    define('SMTP_PASSWORD',   env('SMTP_PASSWORD', ''));
    define('SMTP_FROM_EMAIL', env('SMTP_FROM_EMAIL', SMTP_USERNAME));
    define('SMTP_FROM_NAME',  env('SMTP_FROM_NAME', 'HCI GroceryOne — Security'));
    define('MAIL_DEBUG_MODE', false);
}

function sendSystemEmail($toEmail, $toName, $subject, $htmlBody, $fromName = null) {
    if (SMTP_USERNAME === '' || SMTP_PASSWORD === '') {
        error_log('System email not sent: SMTP credentials are missing.');
        return false;
    }
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('System email not sent: recipient address is invalid.');
        return false;
    }
    $smtpPassword = strtolower(SMTP_HOST) === 'smtp.gmail.com'
        ? preg_replace('/\s+/', '', SMTP_PASSWORD)
        : SMTP_PASSWORD;
    $mail = new PHPMailer(true);
    try {
        $mail->SMTPDebug  = MAIL_DEBUG_MODE ? 2 : 0;
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = $smtpPassword;
        $mail->SMTPSecure = SMTP_PORT === 465
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->Timeout   = 15;
        $mail->CharSet   = PHPMailer::CHARSET_UTF8;

        $mail->setFrom(SMTP_FROM_EMAIL, $fromName !== null ? $fromName : SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace('<br>', "\n", $htmlBody));

        $mail->send();
        return true;
    } catch (Throwable $e) {
        $detail = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
        error_log("System email delivery failed: " . $detail);
        return false;
    }
}

function emailWrapper($title, $bodyHtml, $brandName = 'HCI GroceryOne') {
    return '
    <div style="font-family: Arial, sans-serif; background:#F3F6F9; padding:32px;">
        <div style="max-width:520px; margin:0 auto; background:#ffffff; border-radius:16px; overflow:hidden; border:1px solid #DCE5EC;">
            <div style="background:#123C5C; padding:20px 28px;">
                <h2 style="color:#ffffff; margin:0; font-size:18px;">' . htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') . '</h2>
            </div>
            <div style="padding:28px; color:#17222E; font-size:14px; line-height:1.6;">
                <h3 style="color:#123C5C; margin-top:0;">' . $title . '</h3>
                ' . $bodyHtml . '
            </div>
            <div style="padding:16px 28px; background:#F3F6F9; color:#3D4C5C; font-size:12px;">
                This is an automated email from ' . htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') . '. If you did not request this, please contact your administrator.
            </div>
        </div>
    </div>';
}
