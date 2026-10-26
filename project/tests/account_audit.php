<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This audit must run from the command line.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/includes/env.php';
loadEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

$host = env('DB_HOST', 'localhost');
$user = env('DB_USERNAME', '');
$pass = env('DB_PASSWORD', '');
$posDb = env('DB_NAME_POS', 'grocery_pos');
$hrmsDb = env('DB_NAME_HRMS', 'hrms');

if ($user === '' || $pass === '') {
    fwrite(STDERR, "DB_USERNAME and DB_PASSWORD are required.\n");
    exit(1);
}

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$posDb};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $hrmsPdo = new PDO(
        "mysql:host={$host};dbname={$hrmsDb};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $pattern = '^(test|demo|default|guest|admin123|password|user[0-9]*)$';
    $find = static function (PDO $connection, string $database, string $sql) use ($pattern): array {
        $stmt = $connection->prepare($sql);
        $stmt->execute([$pattern]);
        return array_map(
            static fn(array $row): string => $database . '.' . $row['username'],
            $stmt->fetchAll()
        );
    };

    $posMatches = $find(
        $pdo,
        $posDb,
        "SELECT username FROM users WHERE is_active = 1 AND username REGEXP ?"
    );
    $hrmsMatches = $find(
        $hrmsPdo,
        $hrmsDb,
        "SELECT username FROM users WHERE is_active = 1 AND username REGEXP ?"
    );
    $matches = array_merge($posMatches, $hrmsMatches);

    if ($matches) {
        fwrite(STDERR, "Active default/test-like accounts require review:\n");
        foreach ($matches as $match) {
            fwrite(STDERR, " - {$match}\n");
        }
        exit(1);
    }

    echo "Account audit passed: no active obvious default/test usernames found.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Account audit could not complete: " . $exception->getMessage() . "\n");
    exit(1);
}
