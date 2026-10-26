<?php
require_once __DIR__ . '/includes/public_bootstrap.php';
require_once __DIR__ . '/includes/password_reset.php';
if (session_status() === PHP_SESSION_NONE)
    session_start();
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$pdo = public_write_pdo();
$record = findPasswordReset($pdo, $token);
$requestedSystem = (string) ($_POST['system'] ?? $_GET['system'] ?? 'pos');
$accountSystem = in_array($record['account_system'] ?? $requestedSystem, ['pos', 'hrms'], true)
    ? ($record['account_system'] ?? $requestedSystem)
    : 'pos';
$loginUrl = $accountSystem === 'hrms' ? 'HRMS/login.php' : 'login.php';
$requestUrl = 'password_reset_request.php' . ($accountSystem === 'hrms' ? '?system=hrms' : '');
$error = '';
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) ($_SESSION['password_reset_csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        $error = 'The reset link is invalid or has expired.';
    } elseif (!$record) {
        $error = 'The reset link is invalid or has expired.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $error = 'Use at least 8 characters, including a letter and a number.';
        } elseif (!hash_equals($password, $confirm)) {
            $error = 'Passwords do not match.';
        } else {
            $accountPdo = $accountSystem === 'hrms' ? public_hrms_write_pdo() : $pdo;
            try {
                $pdo->beginTransaction();
                if ($accountPdo !== $pdo)
                    $accountPdo->beginTransaction();
                $consume = $pdo->prepare("
                    UPDATE password_reset_requests
                    SET used_at = NOW(), status = 'handled', handled_at = NOW(), token_hash = NULL
                    WHERE request_id = ? AND token_hash = ? AND status = 'approved'
                      AND email_sent_at IS NOT NULL AND token_expires_at > NOW()
                      AND used_at IS NULL
                ");
                $consume->execute([(int) $record['request_id'], passwordResetTokenHash($token)]);
                if ($consume->rowCount() !== 1) {
                    throw new RuntimeException('Reset token was already used or expired.');
                }
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                if ($accountSystem === 'hrms') {
                    $stmt = $accountPdo->prepare("UPDATE users SET password = ?, force_logout = 1, failed_attempts = 0, locked_until = NULL WHERE id = ? AND is_active = 1");
                } else {
                    $stmt = $accountPdo->prepare("UPDATE users SET password = ?, force_logout = 1, failed_attempts = 0, is_locked = 0 WHERE id = ? AND is_active = 1");
                }
                $stmt->execute([$newHash, (int) $record['user_id']]);
                if ($stmt->rowCount() !== 1)
                    throw new RuntimeException('Account unavailable.');

                if ($accountSystem === 'pos')
                    try {
                        $host = env('DB_HOST', 'localhost');
                        $dbUser = env('DB_USERNAME', '');
                        $dbPass = env('DB_PASSWORD', '');
                        $hrmsName = env('DB_NAME_HRMS', 'hrms');
                        if ($dbUser !== '') {
                            $hrmsPdo = new PDO("mysql:host={$host};dbname={$hrmsName};charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                            $hrmsPdo->prepare("UPDATE users SET password = ?, force_logout = 1, failed_attempts = 0, locked_until = NULL WHERE username = ?")
                                ->execute([$newHash, $record['username']]);
                        }
                    } catch (Throwable $e) {
                        error_log('HRMS password sync failed during reset: ' . $e->getMessage());
                    }

                $pdo->prepare("UPDATE password_reset_requests SET token_hash = NULL WHERE user_id = ? AND account_system = ? AND request_id <> ? AND status = 'approved' AND used_at IS NULL")
                    ->execute([(int) $record['user_id'], $accountSystem, (int) $record['request_id']]);
                if ($accountPdo !== $pdo)
                    $accountPdo->commit();
                $pdo->commit();
                $done = true;
            } catch (Throwable $e) {
                if ($pdo->inTransaction())
                    $pdo->rollBack();
                if ($accountPdo !== $pdo && $accountPdo->inTransaction())
                    $accountPdo->rollBack();
                error_log('Password reset completion failed: ' . $e->getMessage());
                $error = 'The reset could not be completed. Please request a new link.';
            }
        }
    }
}
if (empty($_SESSION['password_reset_csrf']))
    $_SESSION['password_reset_csrf'] = bin2hex(random_bytes(32));
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Choose a new password | HCI GroceryOne</title>
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
        <h1 class="password-reset-title">Choose a new password</h1>
        <?php if ($done): ?>
            <p class="password-reset-notice" role="status">Your password was changed. You can now sign in with your new
                password.</p>
            <a class="password-reset-back" href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>">Back to login</a>
        <?php elseif ($error !== ''): ?>
            <p class="password-reset-notice password-reset-notice--error" role="alert"><?= public_escape($error) ?></p>
        <?php elseif (!$record): ?>
            <p class="password-reset-notice password-reset-notice--error" role="alert">This reset link is invalid or has
                expired. Request a new link from the login page.</p>
            <a class="password-reset-back" href="<?= htmlspecialchars($requestUrl, ENT_QUOTES, 'UTF-8') ?>">Request a new
                link</a>
        <?php else: ?>
            <p class="password-reset-copy">Use at least 8 characters, including a letter and a number. This one-time link
                expires 30 minutes after it was sent.</p>
            <form class="password-reset-form" method="post">
                <input type="hidden" name="token" value="<?= public_escape($token) ?>">
                <input type="hidden" name="system" value="<?= public_escape($accountSystem) ?>">
                <input type="hidden" name="csrf" value="<?= public_escape($_SESSION['password_reset_csrf']) ?>">
                <label for="password">New password</label>
                <div class="password-reset-input-wrap">
                    <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
                    <button class="password-reset-visibility" type="button" data-password-target="password"
                        aria-label="Show new password" title="Show password" aria-pressed="false">
                        <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="M1.5 10S4.5 4 10 4s8.5 6 8.5 6-3 6-8.5 6-8.5-6-8.5-6z" stroke="currentColor"
                                stroke-width="1.5" stroke-linejoin="round" />
                            <circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5" />
                        </svg>
                    </button>
                </div>
                <label for="password_confirm">Confirm new password</label>
                <div class="password-reset-input-wrap">
                    <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password"
                        minlength="8" required>
                    <button class="password-reset-visibility" type="button" data-password-target="password_confirm"
                        aria-label="Show confirmation password" title="Show password" aria-pressed="false">
                        <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="M1.5 10S4.5 4 10 4s8.5 6 8.5 6-3 6-8.5 6-8.5-6-8.5-6z" stroke="currentColor"
                                stroke-width="1.5" stroke-linejoin="round" />
                            <circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5" />
                        </svg>
                    </button>
                </div>
                <button type="submit">Set new password</button>
            </form>
        <?php endif; ?>
    </main>
    <script>
        document.querySelectorAll('.password-reset-visibility').forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                var input = document.getElementById(toggle.getAttribute('data-password-target'));
                if (!input) return;
                var showPassword = input.type === 'password';
                input.type = showPassword ? 'text' : 'password';
                toggle.setAttribute('aria-pressed', showPassword ? 'true' : 'false');
                toggle.setAttribute('aria-label', (showPassword ? 'Hide ' : 'Show ') + (input.id === 'password' ? 'new password' : 'confirmation password'));
                toggle.title = showPassword ? 'Hide password' : 'Show password';
                toggle.querySelector('svg').innerHTML = showPassword
                    ? '<path d="M2 2l16 16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M1.5 10S4.5 4 10 4s8.5 6 8.5 6-3 6-8.5 6-8.5-6-8.5-6z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/>'
                    : '<path d="M1.5 10S4.5 4 10 4s8.5 6 8.5 6-3 6-8.5 6-8.5-6-8.5-6z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/>';
            });
        });
    </script>
</body>

</html>