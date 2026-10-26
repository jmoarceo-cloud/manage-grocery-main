<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || isset($_SERVER['HTTP_HOST'])) {
    http_response_code(404);
    exit("Not found.\n");
}

require_once __DIR__ . '/../includes/env.php';
loadEnv(__DIR__ . '/../.env');

$host = (string) env('DB_HOST', 'localhost');
$database = (string) env('DB_NAME_POS', 'grocery_pos');
$username = (string) env('DB_USERNAME', '');
$password = (string) env('DB_PASSWORD', '');
if ($username === '' || preg_match('/^[a-zA-Z0-9_]+$/', $database) !== 1) {
    fwrite(STDERR, "POS database credentials are not configured in project/.env.\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:host={$host};dbname={$database};charset=utf8mb4",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

fwrite(STDOUT, "POS username to recover: ");
$targetUsername = trim((string) fgets(STDIN));
if ($targetUsername === '') {
    fwrite(STDERR, "A username is required.\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "SELECT id, username, full_name, role, is_active, two_factor_enabled, two_factor_backup_codes
         FROM users WHERE BINARY username = ? LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([$targetUsername]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['is_active'] !== 1 || (int) $user['two_factor_enabled'] !== 1) {
        throw new RuntimeException('Account was not found, is inactive, or does not have POS 2FA enabled.');
    }
    if (!in_array($user['role'], ['cashier', 'inventory_staff', 'finance'], true)) {
        throw new RuntimeException('This tool only recovers POS staff accounts.');
    }

    $codes = [];
    if (!empty($user['two_factor_backup_codes'])) {
        $codes = json_decode((string) $user['two_factor_backup_codes'], true);
        if (!is_array($codes) || array_filter($codes, 'is_string') !== $codes) {
            throw new RuntimeException('Stored recovery codes are malformed; no changes were made.');
        }
    }

    fwrite(STDOUT, "Account: {$user['full_name']} ({$user['role']}, {$user['username']})\n");
    fwrite(STDOUT, "This will add one single-use recovery code and clear only this account's 2FA lockout.\n");
    fwrite(STDOUT, "Run this only as an authorized system administrator on the trusted server. Type RECOVER {$user['username']} to continue: ");
    $confirmation = trim((string) fgets(STDIN));
    if (!hash_equals('RECOVER ' . $user['username'], $confirmation)) {
        throw new RuntimeException('Confirmation did not match; no changes were made.');
    }

    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $part = static function () use ($alphabet): string {
        $value = '';
        for ($i = 0; $i < 4; $i++) {
            $value .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $value;
    };
    $recoveryCode = $part() . '-' . $part();
    $codes[] = password_hash($recoveryCode, PASSWORD_DEFAULT);

    $update = $pdo->prepare(
        'UPDATE users
         SET two_factor_backup_codes = ?, two_factor_failed_attempts = 0, two_factor_locked_until = NULL
         WHERE id = ? AND is_active = 1 AND two_factor_enabled = 1'
    );
    $update->execute([json_encode(array_values($codes), JSON_THROW_ON_ERROR), (int) $user['id']]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('Account changed during recovery; no recovery code was issued.');
    }
    $pdo->commit();

    fwrite(STDOUT, "\nSingle-use recovery code (shown once): {$recoveryCode}\n");
    fwrite(STDOUT, "Enter it on the 2FA screen now. Store it securely; it cannot be retrieved again.\n");
    error_log('POS 2FA recovery code issued by CLI for username ' . $user['username'] . ' (role ' . $user['role'] . ').');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
