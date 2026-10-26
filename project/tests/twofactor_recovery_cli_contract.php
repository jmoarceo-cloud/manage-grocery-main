<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This contract test must run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$recovery = file_get_contents($root . '/tools/recover_pos_2fa.php');
$verify = file_get_contents($root . '/verify_2fa.php');
if ($recovery === false || $verify === false) {
    throw new RuntimeException('Unable to read 2FA recovery source.');
}

$checks = [
    'Recovery can only execute from a trusted CLI' =>
        strpos($recovery, "PHP_SAPI !== 'cli'") !== false
        && strpos($recovery, 'isset($_SERVER[\'HTTP_HOST\'])') !== false,
    'Recovery refuses inactive accounts and does not disable 2FA' =>
        strpos($recovery, '(int) $user[\'is_active\'] !== 1') !== false
        && strpos($recovery, '(int) $user[\'two_factor_enabled\'] !== 1') !== false
        && strpos($recovery, 'two_factor_enabled = 1') !== false,
    'Recovery is confirmed, single-use, and stores only a password hash' =>
        strpos($recovery, 'hash_equals(\'RECOVER \' . $user[\'username\'], $confirmation)') !== false
        && strpos($recovery, 'password_hash($recoveryCode, PASSWORD_DEFAULT)') !== false,
    'Recovery screen provides the authorized administrator path and avoids failed resend CTA' =>
        strpos($verify, 'recover_pos_2fa.php') !== false
        && strpos($verify, '<?php if (!$mailUnavailable): ?>') !== false,
];

$failures = [];
foreach ($checks as $label => $passed) {
    if (!$passed) {
        $failures[] = $label;
    }
}
if ($failures) {
    fwrite(STDERR, "2FA recovery CLI contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "2FA recovery CLI contract passed.\n";
