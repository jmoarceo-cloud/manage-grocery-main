<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This check must run from the command line.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/includes/env.php';
loadEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

$backupDir = env('BACKUP_DIR', 'C:\\xampp\\private\\siaa-backups');
$maxAgeHours = isset($argv[1]) && is_numeric($argv[1]) ? max(1, (float) $argv[1]) : 26.0;
$databases = [
    env('DB_NAME_POS', 'grocery_pos'),
    env('DB_NAME_HRMS', 'hrms'),
];
$failures = [];
$now = time();

if (!is_dir($backupDir)) {
    $failures[] = 'Backup directory does not exist';
} else {
    foreach ($databases as $database) {
        $files = glob($backupDir . DIRECTORY_SEPARATOR . $database . '_*.sql.gz') ?: [];
        $files = array_values(array_filter($files, 'is_file'));
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $latest = $files[0] ?? null;

        if ($latest === null) {
            $failures[] = "No backup found for {$database}";
            continue;
        }
        if ((int) filesize($latest) <= 0) {
            $failures[] = "Latest {$database} backup is empty";
            continue;
        }
        if (($now - (int) filemtime($latest)) > ($maxAgeHours * 3600)) {
            $failures[] = "Latest {$database} backup is older than {$maxAgeHours} hours";
        }
    }
}

$logPath = $backupDir . DIRECTORY_SEPARATOR . 'backup_log.txt';
if (is_file($logPath) && is_readable($logPath)) {
    $log = file_get_contents($logPath);
    if ($log !== false && preg_match('/Backup run finished WITH ERRORS|ERROR:/i', substr($log, -20000))) {
        $failures[] = 'Recent backup log contains an error';
    }
} else {
    $failures[] = 'Backup log is missing or unreadable';
}

if ($failures) {
    fwrite(STDERR, "Backup health check failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Backup health check passed: recent non-empty POS and HRMS backups are present.\n";
