<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

function passwordResetTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function ensurePublicPasswordResetRequestStorage(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_reset_requests (
            request_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(254) NOT NULL,
            account_system ENUM('pos','hrms') NOT NULL DEFAULT 'pos',
            user_id INT DEFAULT NULL,
            ip_hash CHAR(64) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            status ENUM('pending','approved','handled','dismissed') NOT NULL DEFAULT 'pending',
            handled_by INT DEFAULT NULL,
            handled_at DATETIME DEFAULT NULL,
            approved_by INT DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            token_hash CHAR(64) DEFAULT NULL,
            token_expires_at DATETIME DEFAULT NULL,
            email_sent_at DATETIME DEFAULT NULL,
            used_at DATETIME DEFAULT NULL,
            notes VARCHAR(500) DEFAULT NULL,
            requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (request_id),
            KEY idx_password_reset_status (status, requested_at),
            KEY idx_password_reset_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $usernameColumn = $pdo->query("SHOW COLUMNS FROM password_reset_requests LIKE 'username'")->fetch();
    if ($usernameColumn && preg_match('/^varchar\((\d+)\)$/i', (string) $usernameColumn['Type'], $match) && (int) $match[1] < 254) {
        $pdo->exec('ALTER TABLE password_reset_requests MODIFY username VARCHAR(254) NOT NULL');
    }
    $systemColumn = $pdo->query("SHOW COLUMNS FROM password_reset_requests LIKE 'account_system'")->fetch();
    if (!$systemColumn) {
        $pdo->exec("ALTER TABLE password_reset_requests ADD COLUMN account_system ENUM('pos','hrms') NOT NULL DEFAULT 'pos' AFTER username");
    }
}

function passwordResetBaseUrl(): string
{
    $configured = trim((string) env('PASSWORD_RESET_URL', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    if (basename($path) === 'HRMS')
        $path = rtrim(dirname($path), '/\\');
    $host = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
    if (!preg_match('/^(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\])(?::\d{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    return $scheme . '://' . $host . $path;
}

function issuePasswordResetToken(PDO $pdo, array $user, int $requestId, string $accountSystem = 'pos'): bool
{
    if (!in_array($accountSystem, ['pos', 'hrms'], true))
        return false;
    $email = trim((string) ($user['two_factor_email'] ?? ''));
    if ((int) ($user['is_active'] ?? 0) !== 1 || (int) ($user['id'] ?? 0) <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $rawToken = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 1800);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE password_reset_requests SET token_hash = NULL, token_expires_at = NULL WHERE user_id = ? AND account_system = ? AND status = 'approved'")
            ->execute([(int) $user['id'], $accountSystem]);
        $approve = $pdo->prepare("UPDATE password_reset_requests SET account_system = ?, status = ?, token_hash = ?, token_expires_at = ?, approved_by = ?, approved_at = NOW() WHERE request_id = ? AND status = 'pending'");
        $approve->execute([$accountSystem, 'approved', passwordResetTokenHash($rawToken), $expires, (int) ($_SESSION['user_id'] ?? 0), $requestId]);
        if ($approve->rowCount() !== 1) {
            throw new RuntimeException('Request is no longer pending.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        error_log('Password reset token issue failed: ' . $e->getMessage());
        return false;
    }

    require_once __DIR__ . '/mailer.php';
    $url = passwordResetBaseUrl() . '/password_reset.php?system=' . rawurlencode($accountSystem) . '&token=' . rawurlencode($rawToken);
    $body = emailWrapper('Password reset requested', '<p>Use the button below to choose a new password. This link expires in 30 minutes and can be used once.</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 18px;background:#1d4ed8;color:#fff;text-decoration:none;border-radius:8px;">Reset password</a></p><p>If you did not request this, contact your Administrator or HR staff member.</p>');
    $sent = sendSystemEmail($email, (string) ($user['full_name'] ?? 'User'), 'Password reset link', $body);
    if (!$sent) {
        $pdo->prepare("UPDATE password_reset_requests SET status = 'pending', token_hash = NULL, token_expires_at = NULL, approved_by = NULL, approved_at = NULL WHERE request_id = ?")
            ->execute([$requestId]);
    } else {
        $pdo->prepare('UPDATE password_reset_requests SET email_sent_at = NOW() WHERE request_id = ?')->execute([$requestId]);
    }
    return $sent;
}

function findPasswordReset(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token))
        return null;
    $stmt = $pdo->prepare("SELECT r.* FROM password_reset_requests r WHERE r.token_hash = ? AND r.status = 'approved' AND r.email_sent_at IS NOT NULL AND r.token_expires_at > NOW() AND r.used_at IS NULL LIMIT 1");
    $stmt->execute([passwordResetTokenHash($token)]);
    $record = $stmt->fetch();
    if (!$record || empty($record['user_id']))
        return null;
    $accountSystem = $record['account_system'] ?? 'pos';
    if (!in_array($accountSystem, ['pos', 'hrms'], true))
        return null;
    $accountPdo = $accountSystem === 'hrms' ? public_hrms_write_pdo() : $pdo;
    $user = $accountPdo->prepare('SELECT id, username, is_active FROM users WHERE id = ? LIMIT 1');
    $user->execute([(int) $record['user_id']]);
    $account = $user->fetch();
    return $account ? array_merge($record, $account, ['account_system' => $accountSystem]) : null;
}
