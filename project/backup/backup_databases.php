<?php
// =====================================================
// DATABASE BACKUP (Item 8 — Phase 3 reliability audit)
// ------------------------------------------------------
// Runs `mysqldump` against both grocery_pos and hrms and writes
// timestamped, gzip-compressed .sql.gz files into backup/files/, then
// deletes backups older than BACKUP_RETENTION_DAYS.
//
// This is a CLI-only script (php.exe, not the web server) meant to be
// triggered by Windows Task Scheduler — see backup/README.md for setup.
// It reuses the project's existing env.php loader (includes/env.php)
// so credentials come from the same .env file config.php already
// uses; nothing is hardcoded here.
// =====================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("This script can only be run from the command line (php.exe), not through a web browser.\n");
}

require_once __DIR__ . '/../includes/env.php';
loadEnv(__DIR__ . '/../.env');

function backupLog($message) {
    echo '[' . date('Y-m-d H:i:s') . "] $message\n";
}

$host     = env('DB_HOST', 'localhost');
$username = env('BACKUP_DB_USERNAME', '');
$password = env('BACKUP_DB_PASSWORD', '');
$dbPos    = env('DB_NAME_POS', 'grocery_pos');
$dbHrms   = env('DB_NAME_HRMS', 'hrms');

if ($username === '' || $password === '') {
    backupLog('ERROR: BACKUP_DB_USERNAME and BACKUP_DB_PASSWORD are required. Backup aborted.');
    exit(1);
}

// Path to mysqldump.exe. XAMPP's default location is used as a fallback,
// but this should be set explicitly in .env (MYSQLDUMP_PATH) since XAMPP
// can be installed anywhere.
$mysqldump = env('MYSQLDUMP_PATH', 'C:\\xampp\\mysql\\bin\\mysqldump.exe');

if (!is_file($mysqldump)) {
    // Not fatal yet — maybe it's on PATH and just not a literal file path
    // (e.g. plain "mysqldump" on Linux/mac dev machines). Only warn.
    backupLog("WARNING: mysqldump not found at '$mysqldump'. Will try running it as-is via PATH.");
}

$backupDir = env('BACKUP_DIR', 'C:\\xampp\\private\\siaa-backups');
if (!is_dir($backupDir)) {
    if (!mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
        backupLog("ERROR: could not create backup directory '$backupDir'.");
        exit(1);
    }
}

$retentionDays = (int) env('BACKUP_RETENTION_DAYS', 14);
if ($retentionDays < 1) {
    $retentionDays = 14;
}

$timestamp = date('Y-m-d_His');
$databases = [$dbPos, $dbHrms];
$hadError = false;

foreach ($databases as $dbName) {
    $outFile = $backupDir . DIRECTORY_SEPARATOR . "{$dbName}_{$timestamp}.sql.gz";

    backupLog("Backing up '$dbName' -> $outFile");

    // Credentials are passed via a temporary defaults-extra-file instead
    // of on the command line, so they never show up in `tasklist`,
    // Task Scheduler history, or shell history. The password is written
    // with escaping appropriate for an INI-style file.
    $defaultsFile = tempnam(sys_get_temp_dir(), 'dbbak_');
    $iniPassword  = str_replace(['\\', '"'], ['\\\\', '\\"'], $password);
    file_put_contents(
        $defaultsFile,
        "[client]\nhost=\"$host\"\nuser=\"$username\"\npassword=\"$iniPassword\"\n"
    );
    chmod($defaultsFile, 0600);

    $cmd = escapeshellarg($mysqldump)
        . ' --defaults-extra-file=' . escapeshellarg($defaultsFile)
        . ' --single-transaction --quick --routines --triggers --events '
        . escapeshellarg($dbName);

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptorSpec, $pipes);

    if (!is_resource($process)) {
        backupLog("ERROR: could not start mysqldump for '$dbName'.");
        @unlink($defaultsFile);
        $hadError = true;
        continue;
    }

    fclose($pipes[0]);

    $gz = gzopen($outFile, 'wb9');
    if ($gz === false) {
        backupLog("ERROR: could not open '$outFile' for writing.");
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        @unlink($defaultsFile);
        $hadError = true;
        continue;
    }

    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 1024 * 1024);
        if ($chunk !== false && $chunk !== '') {
            gzwrite($gz, $chunk);
        }
    }
    gzclose($gz);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    @unlink($defaultsFile); // clean up the temp credentials file immediately

    if ($exitCode !== 0) {
        backupLog("ERROR: mysqldump exited with code $exitCode for '$dbName'.");
        if (trim($stderr) !== '') {
            backupLog("mysqldump stderr: " . trim($stderr));
        }
        @unlink($outFile);
        $hadError = true;
        continue;
    }

    if (!is_file($outFile) || filesize($outFile) === 0) {
        backupLog("ERROR: backup file for '$dbName' is missing or empty.");
        $hadError = true;
        continue;
    }

    backupLog("OK: '$dbName' backed up (" . round(filesize($outFile) / 1024, 1) . " KB).");
}

// --- Rotation: delete backups older than BACKUP_RETENTION_DAYS ---
backupLog("Rotating backups older than $retentionDays day(s) in $backupDir ...");
$cutoff = time() - ($retentionDays * 86400);
$deleted = 0;
foreach (glob($backupDir . DIRECTORY_SEPARATOR . '*.sql.gz') as $file) {
    if (filemtime($file) < $cutoff) {
        if (@unlink($file)) {
            $deleted++;
        }
    }
}
backupLog("Rotation complete. Deleted $deleted old backup file(s).");

if ($hadError) {
    backupLog("Backup run finished WITH ERRORS. See messages above.");
    exit(1);
}

backupLog("Backup run finished successfully.");
exit(0);
