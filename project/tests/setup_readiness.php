<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This check must run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/env.php';
loadEnv($root . DIRECTORY_SEPARATOR . '.env');

$failures = [];
$warnings = [];
$requiredExtensions = ['PDO', 'pdo_mysql', 'mbstring', 'fileinfo', 'openssl', 'json'];
foreach ($requiredExtensions as $extension) {
    if (!extension_loaded($extension)) {
        $failures[] = "Missing PHP extension: {$extension}";
    }
}
if (PHP_VERSION_ID < 80000) {
    $failures[] = 'PHP 8.0 or newer is required.';
}

$envRequired = ['APP_ENV', 'APP_URL', 'DB_HOST', 'DB_USERNAME', 'DB_NAME_POS', 'DB_NAME_HRMS', 'ID_CRYPT_KEY'];
foreach ($envRequired as $key) {
    if (trim((string) env($key, '')) === '') {
        $failures[] = "Missing environment setting: {$key}";
    }
}
$appEnv = strtolower((string) env('APP_ENV', ''));
if (!in_array($appEnv, ['local', 'development', 'staging', 'production'], true)) {
    $failures[] = 'APP_ENV must be local, development, staging, or production.';
}
$dbUser = (string) env('DB_USERNAME', '');
if (in_array(strtolower($dbUser), ['root', 'admin'], true)) {
    $failures[] = 'Application database credentials must not use root or admin.';
}
$backupUser = (string) env('BACKUP_DB_USERNAME', '');
if ($backupUser === '' || strcasecmp($backupUser, $dbUser) === 0) {
    $failures[] = 'Dedicated backup database credentials are required and must differ from application credentials.';
}
$key = (string) env('ID_CRYPT_KEY', '');
if (strlen($key) < 32 || preg_match('/^(replace|change|your|secret|test)/i', $key)) {
    $failures[] = 'ID_CRYPT_KEY must be a non-placeholder value of at least 32 bytes.';
}
if ($appEnv === 'production' && strtolower((string) env('SESSION_SECURE', '')) !== 'true') {
    $failures[] = 'SESSION_SECURE=true is required in production.';
}
if ($appEnv === 'production' && strtolower((string) env('ATTENDANCE_LOCATION_MODE', '')) !== 'exact') {
    $failures[] = 'ATTENDANCE_LOCATION_MODE=exact is required in production.';
}

if (!is_file($root . DIRECTORY_SEPARATOR . '.htaccess')) {
    $failures[] = 'Web-root .htaccess protection is missing.';
}
foreach (['.env', '.env.example', 'backup', 'uploads'] as $protected) {
    if (!file_exists($root . DIRECTORY_SEPARATOR . $protected)) {
        $warnings[] = "Expected deployment path is absent: {$protected}";
    }
}

try {
    $host = (string) env('DB_HOST', 'localhost');
    $password = (string) env('DB_PASSWORD', '');
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pos = new PDO("mysql:host={$host};dbname=" . env('DB_NAME_POS', 'grocery_pos') . ";charset=utf8mb4", $dbUser, $password, $options);
    $hrms = new PDO("mysql:host={$host};dbname=" . env('DB_NAME_HRMS', 'hrms') . ";charset=utf8mb4", $dbUser, $password, $options);
    foreach (['POS' => $pos, 'HRMS' => $hrms] as $label => $connection) {
        $charset = (string) $connection->query('SELECT @@character_set_database')->fetchColumn();
        if (strtolower($charset) !== 'utf8mb4') {
            $failures[] = "{$label} database must use utf8mb4; found {$charset}.";
        }
    }
    $posTables = (int) $pos->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
    $hrmsTables = (int) $hrms->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
    if ($posTables < 10 || $hrmsTables < 10) {
        $failures[] = "Expected initialized POS/HRMS schemas; found {$posTables}/{$hrmsTables} tables.";
    }
} catch (Throwable $exception) {
    $failures[] = 'Database connectivity/schema check failed: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Setup readiness failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo 'Setup readiness passed: PHP ' . PHP_VERSION . ', required extensions, environment policy, UTF-8 databases, schema presence, and deployment protections are ready.' . PHP_EOL;
foreach ($warnings as $warning) {
    echo "Warning: {$warning}" . PHP_EOL;
}
