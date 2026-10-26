<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This check must run from the command line.\n");
    exit(1);
}

$envPath = $argv[1] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
if (!is_file($envPath) || !is_readable($envPath)) {
    fwrite(STDERR, "Environment file not found or unreadable: {$envPath}\n");
    exit(1);
}

$values = [];
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $values[trim($key)] = trim($value, " \t\"'");
}

$failures = [];
$required = [
    'APP_ENV', 'DB_HOST', 'DB_USERNAME', 'DB_PASSWORD', 'DB_NAME_POS',
    'DB_NAME_HRMS', 'SESSION_SECURE', 'ID_CRYPT_KEY', 'BACKUP_DB_USERNAME',
    'BACKUP_DB_PASSWORD', 'ATTENDANCE_LOCATION_MODE',
];
foreach ($required as $key) {
    if (($values[$key] ?? '') === '') {
        $failures[] = "{$key} is missing";
    }
}

$appEnv = strtolower((string) ($values['APP_ENV'] ?? ''));
if (!in_array($appEnv, ['local', 'development', 'staging', 'production'], true)) {
    $failures[] = 'APP_ENV must be local, development, staging, or production';
}
if ($appEnv === 'production' && filter_var($values['SESSION_SECURE'] ?? '', FILTER_VALIDATE_BOOLEAN) !== true) {
    $failures[] = 'SESSION_SECURE must be true in production';
}
if (($values['ATTENDANCE_LOCATION_MODE'] ?? '') !== 'exact') {
    $failures[] = 'ATTENDANCE_LOCATION_MODE must be exact';
}
if (strcasecmp($values['DB_USERNAME'] ?? '', 'root') === 0) {
    $failures[] = 'DB_USERNAME must not be root';
}
if (strcasecmp($values['BACKUP_DB_USERNAME'] ?? '', $values['DB_USERNAME'] ?? '') === 0) {
    $failures[] = 'BACKUP_DB_USERNAME must be separate from DB_USERNAME';
}
if (strlen($values['ID_CRYPT_KEY'] ?? '') < 32) {
    $failures[] = 'ID_CRYPT_KEY must contain at least 32 characters';
}
foreach (['DB_PASSWORD', 'BACKUP_DB_PASSWORD'] as $key) {
    $value = $values[$key] ?? '';
    if ($value === '' || preg_match('/^(change-this|replace-with|use-a-unique|your-)/i', $value)) {
        $failures[] = "{$key} still contains a placeholder";
    }
}

if ($failures) {
    fwrite(STDERR, "Deployment configuration check failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Deployment configuration check passed. Secrets were not displayed.\n";
