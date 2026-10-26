<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// =====================================================================
// twofactor.php (HRMS side) — Email OTP two-factor authentication
// ---------------------------------------------------------------------
// Used for the Admin role, which logs in via HRMS/login.php.
// (Finance's mirror of this file is includes/twofactor.php on the POS
// side, since Finance logs in there instead — see that file for the
// same logic applied to the POS `users` table/session.)
//
// FLOW (called from login.php, AFTER password_verify() succeeds and
// BEFORE any of the normal "logged in" session keys are set):
//
//   1. twofaRequired($user)              -> true/false
//   2. if required:
//        twofaStartLogin($pdo, $user, 'finance_dashboard.php')
//        -> stores a "pending" login in the session (NOT a real
//           logged-in session yet) and redirects to either
//           setup_2fa.php (first time, no email on file) or
//           verify_2fa.php (send OTP, ask to enter code)
//   3. verify_2fa.php / setup_2fa.php call twofaCompleteLogin() once
//      the code checks out, which finally sets $_SESSION['user_id']
//      etc. and redirects to the real destination.
//
// Nothing in requireLogin()/requireFinance()/etc. treats a "pending"
// 2FA session as logged in — those only check $_SESSION['user_id'],
// which is deliberately NOT set until step 3 succeeds. So a half-done
// 2FA login grants zero access to anything.
// =====================================================================

require_once __DIR__ . '/mailer.php';

function twofaRequired($user)
{
    // All HRMS-side login roles now require a login verification code
    // (previously Admin only).
    return in_array($user['role'], ['admin', 'hr', 'hr_manager', 'supervisor', 'accountant', 'employee'], true);
}

function twofaGenerateCode()
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function twofaGenerateBackupCodes($count = 8)
{
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        // XXXX-XXXX style, easy to read/type, hard to guess (base32-ish
        // alphabet minus ambiguous chars 0/O/1/I).
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $part = function () use ($alphabet) {
            $s = '';
            for ($j = 0; $j < 4; $j++)
                $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            return $s;
        };
        $codes[] = $part() . '-' . $part();
    }
    return $codes;
}

function twofaEmailWrapper($bodyHtml)
{
    return '
    <div style="font-family: Arial, sans-serif; background:#F3F6F9; padding:32px;">
        <div style="max-width:520px; margin:0 auto; background:#ffffff; border-radius:16px; overflow:hidden; border:1px solid #DCE5EC;">
            <div style="background:#123C5C; padding:20px 28px;">
                <h2 style="color:#ffffff; margin:0; font-size:18px;">Authentication OTP</h2>
            </div>
            <div style="padding:28px; color:#17222E; font-size:14px; line-height:1.6;">
                <h3 style="color:#123C5C; margin-top:0;">Verify it\'s you</h3>
                ' . $bodyHtml . '
            </div>
            <div style="padding:16px 28px; background:#F3F6F9; color:#3D4C5C; font-size:12px;">
                This is an automated email from Authentication OTP. Please do not reply to this email.
            </div>
        </div>
    </div>';
}

// Starts (or restarts) a pending 2FA login. Sends the OTP email if the
// account already has a verified 2FA email on file; otherwise routes
// to first-time setup instead.
function twofaStartLogin($pdo, $user, $redirectAfter, $pathPrefix = '')
{
    if (twofaIsLocked($pdo, (int) $user['id'])) {
        $_SESSION['tfa_mail_error'] = 'Too many incorrect verification codes. Please wait 15 minutes before trying again.';
        header('Location: ' . $pathPrefix . 'login.php?mfa_locked=1');
        exit;
    }

    if (
        empty($user['two_factor_enabled'])
        || empty($user['two_factor_email'])
        || !filter_var($user['two_factor_email'], FILTER_VALIDATE_EMAIL)
    ) {
        // First time this account has hit the 2FA gate — needs to
        // register a destination email before we can send anything.
        $_SESSION['tfa_pending'] = [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'] ?? $user['username'],
            'role' => $user['role'],
            'stage' => 'need_setup',
            'redirect' => $redirectAfter,
            'started_at' => time(),
        ];
        header('Location: ' . $pathPrefix . 'setup_2fa.php');
        exit;
    }

    if (!twofaSendOtp($pdo, $user['id'], $user['two_factor_email'], $user['full_name'] ?? $user['username'])) {
        $_SESSION['tfa_mail_error'] = 'We could not send the verification code. Check the configured Gmail SMTP account and try again.';
        header('Location: ' . $pathPrefix . 'login.php?mail_failed=1');
        exit;
    }

    $_SESSION['tfa_pending'] = [
        'user_id' => $user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'] ?? $user['username'],
        'role' => $user['role'],
        'stage' => 'otp',
        'redirect' => $redirectAfter,
        'started_at' => time(),
    ];
    header('Location: ' . $pathPrefix . 'verify_2fa.php');
    exit;
}

// Generates + emails a fresh OTP and stores its hash/expiry in the
// session (never the plaintext code — only the hash is kept).
function twofaSendOtp($pdo, $userId, $toEmail, $toName)
{
    $code = twofaGenerateCode();

    $_SESSION['tfa_otp_hash'] = password_hash($code, PASSWORD_DEFAULT);
    $_SESSION['tfa_otp_expires'] = time() + (10 * 60); // 10 minutes
    $_SESSION['tfa_otp_resend_after'] = time() + 45; // basic resend cooldown

    $body = '<p>Hi ' . htmlspecialchars($toName) . ',</p>' .
        '<p>Your verification code is:</p>' .
        '<p style="font-size:32px; font-weight:800; letter-spacing:6px; color:#123C5C; margin:16px 0;">' . $code . '</p>' .
        '<p>This code expires in 10 minutes. If you did not try to log in, you can safely ignore this email — your account is still protected.</p>';

    return sendSystemEmail(
        $toEmail,
        $toName,
        'Authentication OTP',
        twofaEmailWrapper($body),
        null,
        'Authentication OTP'
    );
}

function twofaIsLocked($pdo, $userId): bool
{
    try {
        $stmt = $pdo->prepare('SELECT two_factor_locked_until FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $userId]);
        $lockedUntil = $stmt->fetchColumn();
        if ($lockedUntil === false)
            return true;
        if ($lockedUntil && strtotime((string) $lockedUntil) > time())
            return true;
        if ($lockedUntil) {
            $pdo->prepare('UPDATE users SET two_factor_failed_attempts = 0, two_factor_locked_until = NULL WHERE id = ? AND two_factor_locked_until <= NOW()')
                ->execute([(int) $userId]);
        }
        return false;
    } catch (Throwable $e) {
        error_log('2FA lockout status lookup failed: ' . $e->getMessage());
        return true;
    }
}

function twofaRecordFailedAttempt($pdo, $userId): bool
{
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT two_factor_failed_attempts, two_factor_locked_until FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([(int) $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row)
            throw new RuntimeException('2FA account was not found.');

        $lockedUntil = $row['two_factor_locked_until'];
        if ($lockedUntil && strtotime((string) $lockedUntil) > time()) {
            $pdo->rollBack();
            return true;
        }

        $attempts = $lockedUntil ? 0 : (int) $row['two_factor_failed_attempts'];
        $attempts++;
        $newLock = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
        $pdo->prepare('UPDATE users SET two_factor_failed_attempts = ?, two_factor_locked_until = ? WHERE id = ?')
            ->execute([$attempts, $newLock, (int) $userId]);
        $pdo->commit();
        return $attempts >= 5;
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        error_log('2FA failed-attempt update failed: ' . $e->getMessage());
        return true;
    }
}

function twofaClearFailedAttempts($pdo, $userId): void
{
    try {
        $pdo->prepare('UPDATE users SET two_factor_failed_attempts = 0, two_factor_locked_until = NULL WHERE id = ?')
            ->execute([(int) $userId]);
    } catch (Throwable $e) {
        error_log('2FA failed-attempt reset failed: ' . $e->getMessage());
    }
}

// Checks the session-stored OTP but persists wrong attempts per account,
// so a new browser session or resent code cannot reset the five-try limit.
function twofaCheckOtp($pdo, $userId, $inputCode)
{
    if (empty($_SESSION['tfa_otp_hash']) || empty($_SESSION['tfa_otp_expires'])) {
        return ['ok' => false, 'reason' => 'expired'];
    }
    if (time() > $_SESSION['tfa_otp_expires']) {
        return ['ok' => false, 'reason' => 'expired'];
    }
    if (twofaIsLocked($pdo, (int) $userId)) {
        return ['ok' => false, 'reason' => 'too_many_attempts'];
    }
    if (
        preg_match('/^\d{6}$/D', trim((string) $inputCode)) !== 1
        || !password_verify(trim((string) $inputCode), $_SESSION['tfa_otp_hash'])
    ) {
        return [
            'ok' => false,
            'reason' => twofaRecordFailedAttempt($pdo, (int) $userId) ? 'too_many_attempts' : 'incorrect',
        ];
    }
    twofaClearFailedAttempts($pdo, (int) $userId);
    return ['ok' => true];
}

// Checks a backup/recovery code against the account's stored hashed
// list, and — if valid — removes it (one-time use) and returns true.
function twofaCheckAndConsumeBackupCode($pdo, $userId, $inputCode)
{
    if (twofaIsLocked($pdo, (int) $userId))
        return false;

    $stmt = $pdo->prepare("SELECT two_factor_backup_codes FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $raw = $stmt->fetchColumn();
    if (!$raw)
        return false;

    $hashes = json_decode($raw, true);
    if (!is_array($hashes))
        return false;

    $inputCode = strtoupper(trim($inputCode));
    foreach ($hashes as $i => $hash) {
        if (password_verify($inputCode, $hash)) {
            unset($hashes[$i]);
            $pdo->prepare("UPDATE users SET two_factor_backup_codes = ? WHERE id = ?")
                ->execute([json_encode(array_values($hashes)), $userId]);
            twofaClearFailedAttempts($pdo, (int) $userId);
            return true;
        }
    }
    twofaRecordFailedAttempt($pdo, (int) $userId);
    return false;
}

// Masks the local part of an email for on-screen display (keeps the
// domain fully visible so it's still checkable, but doesn't print the
// full address where someone glancing at the screen could read it).
// e.g. "juandelacruz@gmail.com" -> "ju**********@gmail.com"
function twofaMaskEmail($email)
{
    if (!$email || strpos($email, '@') === false) {
        return $email;
    }
    [$local, $domain] = explode('@', $email, 2);
    $visible = mb_substr($local, 0, min(2, mb_strlen($local)));
    $masked = $visible . str_repeat('*', max(mb_strlen($local) - mb_strlen($visible), 3));
    return $masked . '@' . $domain;
}

// Called once OTP/backup verification succeeds. Clears the pending-2FA
// session markers so they can't be reused, and returns the pending
// login info the caller (verify_2fa.php) needs to finish setting up
// the real session + redirect.
function twofaClearPending()
{
    unset($_SESSION['tfa_otp_hash'], $_SESSION['tfa_otp_expires'], $_SESSION['tfa_otp_attempts'], $_SESSION['tfa_otp_resend_after'], $_SESSION['tfa_backup_attempts']);
}