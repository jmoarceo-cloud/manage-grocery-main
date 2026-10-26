<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This audit must run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/env.php';
loadEnv($root . DIRECTORY_SEPARATOR . '.env');

$failures = [];
$warnings = [];
$htaccess = file_get_contents($root . DIRECTORY_SEPARATOR . '.htaccess');
if ($htaccess === false) {
    $failures[] = 'Web-root protection file is unreadable.';
} else {
    foreach (['<FilesMatch "^\.">', '\.(env|ini|log|sql|dump|bak', 'RewriteRule'] as $fragment) {
        if (strpos($htaccess, $fragment) === false) {
            $failures[] = "Web-root protection is missing {$fragment}.";
        }
    }
}

$backupDir = (string) env('BACKUP_DIR', 'C:\\xampp\\private\\siaa-backups');
if (!is_dir($backupDir) || !is_readable($backupDir) || !is_writable($backupDir)) {
    $failures[] = 'Backup directory must exist and be readable/writable.';
} elseif (strpos(realpath($backupDir) ?: $backupDir, realpath($root) ?: $root) === 0) {
    $failures[] = 'Backup directory must be outside the public application root.';
}
$backupLog = $backupDir . DIRECTORY_SEPARATOR . 'backup_log.txt';
if (!is_file($backupLog) || !is_readable($backupLog)) {
    $failures[] = 'Backup log is missing or unreadable.';
}

$requiredDocs = [
    'backup/README.md',
    '.env.example',
    '.env.production.example',
    'tests/deployment_check.php',
    'tests/backup_health.php',
];
foreach ($requiredDocs as $relativePath) {
    $absolutePath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($absolutePath)) {
        $failures[] = "Missing operational artifact: {$relativePath}";
    } elseif (substr($relativePath, -4) === '.sql' && (!is_readable($absolutePath) || filesize($absolutePath) === 0)) {
        $failures[] = "Database export is unreadable or empty: {$relativePath}";
    }
}

try {
    $host = (string) env('DB_HOST', 'localhost');
    $user = (string) env('DB_USERNAME', '');
    $pass = (string) env('DB_PASSWORD', '');
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pos = new PDO("mysql:host={$host};dbname=" . env('DB_NAME_POS', 'grocery_pos') . ";charset=utf8mb4", $user, $pass, $options);
    $hrms = new PDO("mysql:host={$host};dbname=" . env('DB_NAME_HRMS', 'hrms') . ";charset=utf8mb4", $user, $pass, $options);

    $posAudit = (int) $pos->query('SELECT COUNT(*) FROM product_detail_audit')->fetchColumn();
    $hrmsAudit = (int) $hrms->query('SELECT COUNT(*) FROM hr_audit_log')->fetchColumn();
    if ($posAudit < 1 || $hrmsAudit < 1) {
        $warnings[] = "Audit tables exist but have low activity ({$posAudit} POS, {$hrmsAudit} HRMS rows).";
    }
    foreach (['product_detail_audit', 'hr_audit_log'] as $table) {
        $connection = $table === 'product_detail_audit' ? $pos : $hrms;
        $columns = array_map(static fn(array $row): string => (string) $row['Field'], $connection->query("SHOW COLUMNS FROM {$table}")->fetchAll());
        foreach (['action', 'created_at'] as $expected) {
            if (!in_array($expected, $columns, true) && !($table === 'product_detail_audit' && $expected === 'created_at' && in_array('changed_at', $columns, true))) {
                $failures[] = "{$table} is missing {$expected} timestamp/action coverage.";
            }
        }
    }
} catch (Throwable $exception) {
    $failures[] = 'Audit/database readiness check failed: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Operational readiness failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Operational readiness passed: audit tables, protected backup location, backup log, deployment artifacts, and web-root protections are present.\n";
foreach ($warnings as $warning) {
    echo "Warning: {$warning}\n";
}
