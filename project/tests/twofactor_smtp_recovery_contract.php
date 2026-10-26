<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This contract test must run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$twoFactor = file_get_contents($root . '/includes/twofactor.php');
$hrmsTwoFactor = file_get_contents($root . '/HRMS/includes/twofactor.php');
$mailer = file_get_contents($root . '/includes/mailer.php');
$hrmsMailer = file_get_contents($root . '/HRMS/includes/mailer.php');
$verify = file_get_contents($root . '/verify_2fa.php');
$setup = file_get_contents($root . '/setup_2fa.php');
if ($twoFactor === false || $hrmsTwoFactor === false || $mailer === false || $hrmsMailer === false || $verify === false || $setup === false) {
    throw new RuntimeException('Unable to read two-factor authentication source.');
}

$checks = [
    'SMTP failure keeps login pending and routes to secure verification' =>
        strpos($twoFactor, '\'mail_unavailable\' => !$mailSent') !== false
        && strpos($twoFactor, "header('Location: verify_2fa.php');") !== false,
    'Unsent OTP is removed and never presented as a usable code' =>
        strpos($twoFactor, "unset(\$_SESSION['tfa_otp_hash'], \$_SESSION['tfa_otp_expires']);") !== false
        && strpos($verify, '$mailUnavailable = !empty($pending[\'mail_unavailable\']);') !== false,
    'Saved one-time backup code is offered immediately during mail outage' =>
        strpos($verify, '<details class="backup-box" <?= $mailUnavailable ? \'open\' : \'\' ?>>') !== false
        && strpos($verify, 'twofaCheckAndConsumeBackupCode') !== false,
    'First-time enrollment never asks for a code when email was not sent' =>
        strpos($setup, 'if (twofaSendOtp($pdo, $pending[\'user_id\'], $email, $pending[\'full_name\']))') !== false
        && strpos($setup, 'No verification email was sent because SMTP credentials are missing.') !== false,
    'POS and HRMS login codes use Authentication OTP branding without changing other HR email branding' =>
        strpos($twoFactor, "emailWrapper('Verify it\\'s you', \$body, 'Authentication OTP')") !== false
        && strpos($twoFactor, "'Authentication OTP'") !== false
        && strpos($hrmsTwoFactor, 'function twofaEmailWrapper($bodyHtml)') !== false
        && strpos($hrmsTwoFactor, '<h2 style="color:#ffffff; margin:0; font-size:18px;">Authentication OTP</h2>') !== false
        && strpos($hrmsTwoFactor, 'This is an automated email from Authentication OTP.') !== false
        && strpos($hrmsTwoFactor, "twofaEmailWrapper(\$body)") !== false
        && strpos($hrmsTwoFactor, "'Authentication OTP'") !== false
        && strpos($mailer, "function emailWrapper(\$title, \$bodyHtml, \$brandName = 'HCI GroceryOne')") !== false
        && strpos($hrmsMailer, "function emailWrapper(\$title, \$bodyHtml, \$brandName = 'HR Department')") !== false,
];

$failures = [];
foreach ($checks as $label => $passed) {
    if (!$passed) {
        $failures[] = $label;
    }
}
if ($failures) {
    fwrite(STDERR, "2FA SMTP recovery contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "2FA SMTP recovery contract passed.\n";
