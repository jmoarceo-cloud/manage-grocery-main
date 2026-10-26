<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(dirname(__DIR__));
if (file_exists('.env.migration')) {
    throw new RuntimeException('Migration identity already configured. Refusing to replace credentials.');
}
$manifest = json_decode(file_get_contents('database/backups/safety-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if (! hash_equals($manifest['dump_sha256'], hash_file('sha256', $manifest['dump']))) {
    throw new RuntimeException('Original full-backup hash does not match.');
}
$parse = function (string $path): array {
    $result = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $match)) {
            $result[$match[1]] = trim($match[2], " \t\r\n\"'");
        }
    }

    return $result;
};
$legacy = $parse('project/.env');
$runtime = $parse('.env');
$source = file_get_contents('database/backups/root-mvc-20261026/app/Core/Database.php');
if (! preg_match('/new PDO\(\$dsn,\s*([\'"])(.*?)\1,\s*([\'"])(.*?)\3/', $source, $match)) {
    throw new RuntimeException('Protected migration bootstrap unavailable.');
}
$pdo = new PDO('mysql:host='.$runtime['DB_HOST'].';port='.$runtime['DB_PORT'].';charset=utf8mb4', $match[2], $match[4], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$migrationUser = 'groceryone_migrations';
$migrationPassword = bin2hex(random_bytes(24));
$exists = $pdo->prepare('SELECT COUNT(*) FROM mysql.user WHERE User=?');
$exists->execute([$migrationUser]);
if ((int) $exists->fetchColumn()) {
    throw new RuntimeException('Intended migration identity already exists.');
}
$identity = $pdo->quote($migrationUser)."@'localhost'";
$pdo->exec('CREATE USER '.$identity.' IDENTIFIED BY '.$pdo->quote($migrationPassword));
$pdo->exec('GRANT SELECT, INSERT, UPDATE, CREATE, ALTER, INDEX, REFERENCES ON `grocery_pos`.* TO '.$identity);
$pdo->exec('GRANT SELECT ON `hrms`.* TO '.$identity);
$environment = file_get_contents('.env');
$environment = preg_replace('/^DB_USERNAME=.*$/m', 'DB_USERNAME='.$migrationUser, $environment);
$environment = preg_replace('/^DB_PASSWORD=.*$/m', 'DB_PASSWORD='.$migrationPassword, $environment);
$environment .= "GROCERY_SCHEMA_ADOPTION=true\n";
file_put_contents('.env.migration', $environment);

// Restore access only for the already configured legacy local principals.
// Never change their passwords or permissions on other projects/databases.
$repaired = [];
foreach (['DB_USERNAME', 'BACKUP_DB_USERNAME', 'PUBLIC_DB_USERNAME'] as $key) {
    $user = $legacy[$key] ?? '';
    if ($user === '' || $user === $match[2]) {
        continue;
    }
    $check = $pdo->prepare("SELECT COUNT(*) FROM mysql.user WHERE User=? AND Host='localhost'");
    $check->execute([$user]);
    if ((int) $check->fetchColumn() !== 1) {
        continue;
    }
    $account = $pdo->quote($user)."@'localhost'";
    if ($key === 'DB_USERNAME') {
        foreach (['grocery_pos', 'hrms'] as $database) {
            $pdo->exec('GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON `'.$database.'`.* TO '.$account);
        }
    } elseif ($key === 'BACKUP_DB_USERNAME') {
        foreach (['grocery_pos', 'hrms'] as $database) {
            $pdo->exec('GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON `'.$database.'`.* TO '.$account);
        }
    } else {
        foreach (['products', 'product_details', 'categories', 'ai_cache'] as $table) {
            $pdo->exec('GRANT SELECT ON `grocery_pos`.`'.$table.'` TO '.$account);
        }
    }
    $repaired[] = $key;
}
$pipes = [];
$process = proc_open([PHP_BINARY, 'artisan', 'migrate', '--env=migration', '--force', '--no-interaction'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);
if ($exit !== 0) {
    throw new RuntimeException('Approved migration failed. Credentials/record-bearing diagnostics withheld; inspect migration source and scoped metadata.');
}
$comparison = [];
$allUnchanged = true;
foreach ($manifest['row_counts'] as $database => $tables) {
    foreach ($tables as $table => $before) {
        $after = (int) $pdo->query('SELECT COUNT(*) FROM `'.$database.'`.`'.$table.'`')->fetchColumn();
        $comparison[] = ['database' => $database, 'table' => $table, 'before' => $before, 'after' => $after, 'unchanged' => $before === $after];
        $allUnchanged = $allUnchanged && $before === $after;
    }
}
$result = ['date' => '2026-10-26', 'method' => 'In-place baseline validation and additive Laravel migration; no business-table import/recreation', 'framework_migration_exit' => $exit, 'original_backup_hash_matches' => true, 'original_tables_compared' => count($comparison), 'all_original_table_counts_unchanged' => $allUnchanged, 'original_rows' => array_sum(array_column($comparison, 'after')), 'added_pos_tables' => ['migrations', 'laravel_checkout_requests', 'application_audit_events'], 'legacy_principal_access_repaired' => $repaired, 'row_counts' => $comparison, 'limits' => 'Aggregate counts do not prove cell-by-cell equality. Source migration makes no changes to original business tables. No live sale, refund, payroll or retention purge was submitted.'];
file_put_contents('docs/audit/DATA_ADOPTION.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode(['migration' => 'completed', 'original_tables' => count($comparison), 'original_rows' => $result['original_rows'], 'all_original_counts_unchanged' => $allUnchanged, 'added_tables' => 3, 'legacy_principal_categories_repaired' => count($repaired), 'credentials' => 'private; not logged'], JSON_PRETTY_PRINT)."\n";
