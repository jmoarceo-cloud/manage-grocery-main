<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
chdir($root);
$date = '20261026';
$backupDirectory = $root.'/database/backups';
$sourceSnapshot = $root.'/project.bak.'.$date;
$mvcSnapshot = $backupDirectory.'/root-mvc-'.$date;
$dumpPath = $backupDirectory.'/legacy-20261026.sql';
foreach ([$sourceSnapshot, $mvcSnapshot, $dumpPath] as $target) {
    if (file_exists($target)) {
        throw new RuntimeException('Safety artifact already exists. Refusing to overwrite it.');
    }
}
if (! is_dir($backupDirectory)) {
    mkdir($backupDirectory, 0700, true);
}
file_put_contents($backupDirectory.'/.htaccess', "Require all denied\n");

// Parse configuration as text. Never execute either legacy bootstrap.
$environment = [];
foreach (file($root.'/project/.env', FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $value = trim($value);
    if (strlen($value) >= 2 && in_array($value[0], ["'", '"'], true) && $value[0] === $value[strlen($value) - 1]) {
        $value = substr($value, 1, -1);
    }
    $environment[trim($key)] = $value;
}
$source = file_get_contents($root.'/app/Core/Database.php');
if (! preg_match('/new PDO\(\$dsn,\s*([\'"])(.*?)\1,\s*([\'"])(.*?)\3/', $source, $match)) {
    throw new RuntimeException('Previously verified local migration connection unavailable.');
}
[$migrationUser, $migrationPassword] = [$match[2], $match[4]];
$host = $environment['DB_HOST'] ?? 'localhost';
$port = $environment['DB_PORT'] ?? '3306';
$databases = [$environment['DB_NAME_POS'], $environment['DB_NAME_HRMS']];
if ($databases !== ['grocery_pos', 'hrms']) {
    throw new RuntimeException('Database names differ from the approved audit.');
}
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $migrationUser, $migrationPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$counts = [];
foreach ($databases as $database) {
    $tables = $pdo->query('SHOW TABLES FROM `'.$database.'`')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (! preg_match('/\A[a-zA-Z0-9_]+\z/', $table)) {
            throw new RuntimeException('Unexpected table identifier.');
        }
        $counts[$database][$table] = (int) $pdo->query('SELECT COUNT(*) FROM `'.$database.'`.`'.$table.'`')->fetchColumn();
    }
}
$command = ['C:/xampp/mysql/bin/mysqldump.exe', '--no-defaults', '--single-transaction', '--quick', '--skip-lock-tables', '--routines', '--events', '--triggers', '--hex-blob', '--host='.$host, '--port='.$port, '--user='.$migrationUser, '--databases', ...$databases];
$pipes = [];
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $dumpPath, 'wb'], 2 => ['pipe', 'w']], $pipes, $root, array_merge(getenv(), ['MYSQL_PWD' => $migrationPassword]), ['bypass_shell' => true]);
fclose($pipes[0]);
$diagnostics = stream_get_contents($pipes[2]);
fclose($pipes[2]);
if (proc_close($process) !== 0 || filesize($dumpPath) < 1000) {
    throw new RuntimeException('Full backup failed; raw database diagnostics withheld. No source moves permitted.');
}

$copyTree = function (string $source, string $destination) use (&$copyTree): int {
    if (! is_dir($destination)) {
        mkdir($destination, 0700, true);
    }
    $count = 0;
    foreach (new DirectoryIterator($source) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        $target = $destination.'/'.$entry->getFilename();
        if ($entry->isLink()) {
            throw new RuntimeException('Source snapshot contains a link; refusing traversal.');
        }
        if ($entry->isDir()) {
            $count += $copyTree($entry->getPathname(), $target);
        } else {
            if (! copy($entry->getPathname(), $target) || ! hash_equals(hash_file('sha256', $entry->getPathname()), hash_file('sha256', $target))) {
                throw new RuntimeException('Source snapshot verification failed.');
            }
            $count++;
        }
    }

    return $count;
};
$legacyFiles = $copyTree($root.'/project', $sourceSnapshot);
mkdir($mvcSnapshot, 0700, true);
$mvcFiles = 0;
foreach (['app', 'config', 'public', 'bin', 'tests', 'database/migrations'] as $path) {
    $mvcFiles += $copyTree($root.'/'.$path, $mvcSnapshot.'/'.$path);
}
foreach (['composer.json', '.env.example', '.htaccess', '.gitignore', 'phpstan.neon'] as $path) {
    copy($root.'/'.$path, $mvcSnapshot.'/'.$path);
}
$manifest = ['date' => '2026-10-26', 'dump' => 'database/backups/legacy-20261026.sql', 'dump_bytes' => filesize($dumpPath), 'dump_sha256' => hash_file('sha256', $dumpPath), 'databases' => $databases, 'row_counts' => $counts, 'legacy_files_verified' => $legacyFiles, 'mvc_files_verified' => $mvcFiles, 'restore_test' => 'not yet performed; application tests must use synthetic records'];
file_put_contents($backupDirectory.'/safety-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

// Separate least-privilege web identity; never copy privileged credentials to Laravel.
$appUser = 'groceryone_laravel';
$appPassword = bin2hex(random_bytes(24));
$exists = $pdo->prepare('SELECT COUNT(*) FROM mysql.user WHERE User = ?');
$exists->execute([$appUser]);
if ((int) $exists->fetchColumn() !== 0) {
    throw new RuntimeException('Intended new app identity already exists; refusing to alter its password. Backups are preserved.');
}
$account = $pdo->quote($appUser)."@'localhost'";
$pdo->exec('CREATE USER '.$account.' IDENTIFIED BY '.$pdo->quote($appPassword));
foreach ($databases as $database) {
    $pdo->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON `'.$database.'`.* TO '.$account);
}
$env = "APP_NAME=GroceryOne\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=false\nAPP_URL=http://localhost/manage-grocery-main/public\nAPP_TIMEZONE=Asia/Manila\nDB_CONNECTION=pos\nDB_HOST=$host\nDB_PORT=$port\nDB_USERNAME=$appUser\nDB_PASSWORD=$appPassword\nDB_NAME_POS=grocery_pos\nDB_NAME_HRMS=hrms\nSESSION_DRIVER=file\nSESSION_LIFETIME=30\nSESSION_COOKIE=groceryone_laravel_session\nSESSION_PATH=/manage-grocery-main/public\nCACHE_STORE=file\nQUEUE_CONNECTION=sync\nMAIL_MAILER=array\nLOG_LEVEL=warning\nLOG_CHANNEL=single\n";
file_put_contents($root.'/.env', $env);

// Dedicated empty isolated databases and identity, never a restore of real records.
$nonce = bin2hex(random_bytes(5));
$testUser = 'go_test_'.$nonce;
$testPassword = bin2hex(random_bytes(24));
$testAccount = $pdo->quote($testUser)."@'localhost'";
$pdo->exec('CREATE USER '.$testAccount.' IDENTIFIED BY '.$pdo->quote($testPassword));
$testNames = [];
foreach (['pos', 'hrms'] as $domain) {
    $name = 'groceryone_test_'.$nonce.'_'.$domain;
    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
    $check->execute([$name]);
    if ((int) $check->fetchColumn() !== 0) {
        throw new RuntimeException('Test target unexpectedly exists; refusing reuse.');
    }
    $pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('GRANT ALL PRIVILEGES ON `'.$name.'`.* TO '.$testAccount);
    $pdo->exec('CREATE TABLE `'.$name.'`.`groceryone_test_target` (nonce VARCHAR(40) PRIMARY KEY)');
    $pdo->prepare('INSERT INTO `'.$name.'`.`groceryone_test_target` (nonce) VALUES (?)')->execute([$nonce]);
    $testNames[$domain] = $name;
}
$testEnv = str_replace(['APP_ENV=local', "DB_USERNAME=$appUser", "DB_PASSWORD=$appPassword", 'DB_NAME_POS=grocery_pos', 'DB_NAME_HRMS=hrms'], ['APP_ENV=testing', "DB_USERNAME=$testUser", "DB_PASSWORD=$testPassword", 'DB_NAME_POS='.$testNames['pos'], 'DB_NAME_HRMS='.$testNames['hrms']], $env)."TEST_TARGET_NONCE=$nonce\n";
file_put_contents($root.'/.env.testing', $testEnv);
echo json_encode(['full_backup' => 'created; hash in protected manifest', 'backup_bytes' => filesize($dumpPath), 'legacy_files_verified' => $legacyFiles, 'mvc_files_verified' => $mvcFiles, 'tables_preserved' => count($counts['grocery_pos']) + count($counts['hrms']), 'web_account' => 'new CRUD-only identity configured privately', 'isolated_targets' => 'new empty marked POS/HRMS databases; synthetic fixtures only'], JSON_PRETTY_PRINT)."\n";
