<?php
require_once __DIR__ . '/includes/public_bootstrap.php';
require_once __DIR__ . '/includes/password_reset.php';
if (session_status() === PHP_SESSION_NONE)
    session_start();
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

if (empty($_SESSION['password_reset_request_csrf'])) {
    $_SESSION['password_reset_request_csrf'] = bin2hex(random_bytes(32));
}

$submitted = false;
$error = '';
$accountSystem = (string) ($_POST['account_system'] ?? $_GET['system'] ?? 'pos');
if (!in_array($accountSystem, ['pos', 'hrms'], true))
    $accountSystem = 'pos';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !hash_equals(
            (string) $_SESSION['password_reset_request_csrf'],
            (string) ($_POST['csrf'] ?? '')
        )
    ) {
        $error = 'This form expired. Refresh the page and try again.';
    } else {
        $_SESSION['password_reset_request_csrf'] = bin2hex(random_bytes(32));
    }
    $username = trim((string) ($_POST['username'] ?? ''));
    if ($error === '' && !preg_match('/^[A-Za-z0-9._@-]{1,254}$/', $username)) {
        $error = 'Enter the username or email used for your account.';
    } elseif ($error === '') {
        try {
            $pdo = public_write_pdo();
            ensurePublicPasswordResetRequestStorage($pdo);
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS rate_limit_public (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    ip_hash CHAR(64) NOT NULL,
                    route VARCHAR(80) NOT NULL,
                    hit_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_rate_limit_public_lookup (ip_hash, route, hit_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            if (!pub_rate_limit($pdo, 'password_reset_request', 5, 900)) {
                $error = 'Too many requests from this network. Please try again later.';
            } else {
                $accountPdo = $accountSystem === 'hrms' ? public_hrms_write_pdo() : $pdo;
                $lookup = filter_var($username, FILTER_VALIDATE_EMAIL)
                    ? $accountPdo->prepare('SELECT id, username, full_name, two_factor_email, is_active FROM users WHERE two_factor_email = ? LIMIT 1')
                    : $accountPdo->prepare('SELECT id, username, full_name, two_factor_email, is_active FROM users WHERE BINARY username = ? LIMIT 1');
                $lookup->execute([$username]);
                $user = $lookup->fetch();
                $stmt = $pdo->prepare('INSERT INTO password_reset_requests (username, account_system, user_id, ip_hash, user_agent) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([
                    $user['username'] ?? $username,
                    $accountSystem,
                    $user['id'] ?? null,
                    pub_ip_hash(),
                    substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]);
                $submitted = true;

                // Acknowledge eligible requests at the already-verified
                // account address. The reset link is still sent only after
                // Admin/HR approval, and the browser response stays generic
                // to avoid disclosing whether an account exists.
                $verifiedEmail = trim((string) ($user['two_factor_email'] ?? ''));
                if ($user && (int) $user['is_active'] === 1 && filter_var($verifiedEmail, FILTER_VALIDATE_EMAIL)) {
                    try {
                        require_once __DIR__ . '/includes/mailer.php';
                        $name = htmlspecialchars((string) ($user['full_name'] ?? 'User'), ENT_QUOTES, 'UTF-8');
                        $body = emailWrapper(
                            'Password reset request received',
                            '<p>Hello ' . $name . ',</p><p>Your password reset request has been received and is awaiting review by an Administrator or HR staff member. If approved, a one-time reset link will be sent to this verified email address.</p><p>If you did not make this request, contact your Administrator or HR staff member.</p>'
                        );
                        if (!sendSystemEmail($verifiedEmail, (string) ($user['full_name'] ?? 'User'), 'Password reset request received', $body)) {
                            error_log('Password reset request acknowledgement email could not be sent.');
                        }
                    } catch (Throwable $mailError) {
                        error_log('Password reset request acknowledgement email failed: ' . $mailError->getMessage());
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Password reset request failed: ' . $e->getMessage());
            $error = 'The request could not be recorded. Please contact an Administrator or HR staff member.';
        }
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Password reset help | HCI GroceryOne</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet"
        href="<?= htmlspecialchars(public_asset_url('assets/css/password-reset.css') . '?v=' . (filemtime(__DIR__ . '/assets/css/password-reset.css') ?: time()), ENT_QUOTES, 'UTF-8') ?>">
</head>

<body class="password-reset-page">
    <main class="password-reset-card">
        <a class="password-reset-brand" href="login.php">HCI GroceryOne <span>Account recovery</span></a>
        <div class="password-reset-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none">
                <path
                    d="M7 10V7a5 5 0 0110 0v3m-11 0h12a2 2 0 012 2v7a2 2 0 01-2 2H6a2 2 0 01-2-2v-7a2 2 0 012-2Zm5 4v3"
                    stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </div>
        <p class="password-reset-eyebrow">SECURE ACCOUNT RECOVERY</p>
        <h1 class="password-reset-title">Reset your password</h1>
        <p class="password-reset-copy">Submit your username or verified account email. An Administrator or HR staff
            member must review the request before a one-time reset link is sent.</p>
        <?php if ($submitted): ?>
            <p class="password-reset-notice" role="status">If the account is eligible, an Administrator or HR staff member
                will review the request before a reset link is sent to the verified account email.</p>
        <?php elseif ($error !== ''): ?>
            <p class="password-reset-notice password-reset-notice--error" role="alert"><?= public_escape($error) ?></p>
        <?php endif; ?>
        <?php if (!$submitted): ?>
            <form class="password-reset-form" method="post" action="password_reset_request.php">
                <input type="hidden" name="csrf" value="<?= public_escape($_SESSION['password_reset_request_csrf']) ?>">
                <input type="hidden" name="account_system" value="<?= public_escape($accountSystem) ?>">
                <label for="username">Username or email</label>
                <input id="username" name="username" type="text" maxlength="254" autocomplete="username"
                    placeholder="Enter your username or verified email" required>
                <button type="submit">Submit reset request</button>
            </form><?php endif; ?>
        <a class="password-reset-back" href="login.php">Back to login</a>
    </main>
</body>

</html>