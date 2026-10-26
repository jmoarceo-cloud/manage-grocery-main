<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__);
$path = $root.'/storage/testing/mariadb-admin.json';
if (! is_file($path)) {
    throw new RuntimeException('Private test-server credentials are unavailable.');
}
$credentials = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$db = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', $credentials['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
$actual = strtolower(rtrim(str_replace('\\', '/', $db->query('SELECT @@datadir')->fetchColumn()), '/'));
$expected = strtolower(str_replace('\\', '/', realpath($root.'/storage/testing/mariadb')));
if ($actual !== $expected) {
    throw new RuntimeException('Refusing shutdown of a non-project server.');
}
$db->exec('SHUTDOWN');
echo "Project-local synthetic server shut down cleanly.\n";
