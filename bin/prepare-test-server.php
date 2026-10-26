<?php

// Provision a newly initialized, project-local MariaDB instance. Never the shared server.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';
$root = dirname(__DIR__);
$env = Dotenv\Dotenv::createArrayBacked($root, '.env.testing')->load();
$nonce = $env['TEST_TARGET_NONCE'] ?? '';
if (! preg_match('/\A[a-f0-9]{10}\z/', $nonce)) {
    throw new RuntimeException('Invalid synthetic nonce.');
}
$credentialPath = $root.'/storage/testing/mariadb-admin.json';
$credential = is_file($credentialPath) ? json_decode(file_get_contents($credentialPath), true, 512, JSON_THROW_ON_ERROR) : ['password' => ''];
$db = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', $credential['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
$expected = strtolower(str_replace('\\', '/', realpath($root.'/storage/testing/mariadb')));
$actual = strtolower(rtrim(str_replace('\\', '/', $db->query('SELECT @@datadir')->fetchColumn()), '/'));
if ($actual !== $expected) {
    throw new RuntimeException('Server data directory is not the project-local test instance.');
}
foreach (['pos' => 'DB_NAME_POS', 'hrms' => 'DB_NAME_HRMS'] as $domain => $key) {
    $name = 'groceryone_test_'.$nonce.'_'.$domain;
    if (($env[$key] ?? '') !== $name) {
        throw new RuntimeException('Synthetic name mismatch.');
    }
    $db->exec('CREATE DATABASE IF NOT EXISTS `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $tables = $db->query('SHOW TABLES FROM `'.$name.'`')->fetchAll(PDO::FETCH_COLUMN);
    if ($tables === []) {
        $db->exec('CREATE TABLE `'.$name.'`.groceryone_test_target (nonce VARCHAR(40) PRIMARY KEY)');
        $db->exec('INSERT INTO `'.$name.'`.groceryone_test_target VALUES ('.$db->quote($nonce).')');
    } elseif ($db->query('SELECT nonce FROM `'.$name.'`.groceryone_test_target')->fetchColumn() !== $nonce) {
        throw new RuntimeException('Existing test marker mismatch.');
    }
}
$username = $env['DB_USERNAME'];
if (! preg_match('/\A[a-z0-9_]+\z/', $username)) {
    throw new RuntimeException('Unexpected test account.');
}
$account = $db->quote($username)."@'localhost'";
$db->exec('CREATE USER IF NOT EXISTS '.$account.' IDENTIFIED BY '.$db->quote($env['DB_PASSWORD']));
foreach (['pos', 'hrms'] as $domain) {
    $db->exec('GRANT ALL PRIVILEGES ON `groceryone_test_'.$nonce.'_'.$domain.'`.* TO '.$account);
}
if ($credential['password'] === '') {
    $credential['password'] = bin2hex(random_bytes(24));
    file_put_contents($credentialPath, json_encode($credential, JSON_THROW_ON_ERROR));
    $db->exec("ALTER USER 'root'@'localhost' IDENTIFIED BY ".$db->quote($credential['password']));
}
$text = file_get_contents($root.'/.env.testing');
$text = preg_replace('/^DB_PORT=.*$/m', 'DB_PORT=3307', $text, -1, $count);
if (! $count) {
    $text .= "\nDB_PORT=3307\n";
}
$text = preg_replace('/^DB_HOST=.*$/m', 'DB_HOST=127.0.0.1', $text);
file_put_contents($root.'/.env.testing', $text);
foreach (['storage/framework/cache/testing/data', 'storage/framework/sessions/testing', 'storage/framework/views/testing'] as $directory) {
    if (! is_dir($root.'/'.$directory)) {
        mkdir($root.'/'.$directory, 0700, true);
    }
}
echo "Verified project-local test server; marked synthetic pair provisioned.\n";
