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
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pos = new PDO("mysql:host={$host};dbname={$posDb};charset=utf8mb4", $user, $pass, $options);
    $hrms = new PDO("mysql:host={$host};dbname={$hrmsDb};charset=utf8mb4", $user, $pass, $options);

    $checks = [
        'negative stock' => [$pos, "SELECT COUNT(*) FROM products WHERE stock < 0"],
        'duplicate non-null barcodes' => [$pos, "SELECT COUNT(*) FROM (SELECT barcode FROM products WHERE barcode IS NOT NULL GROUP BY barcode HAVING COUNT(*) > 1) duplicates"],
        'duplicate attendance dates' => [$hrms, "SELECT COUNT(*) FROM (SELECT employee_id, date FROM attendance GROUP BY employee_id, date HAVING COUNT(*) > 1) duplicates"],
        'received POs without AP' => [$pos, "SELECT COUNT(*) FROM purchase_orders po WHERE po.status = 'received' AND NOT EXISTS (SELECT 1 FROM accounts_payable ap WHERE ap.source_type = 'purchase_order' AND ap.source_id = po.id)"],
        'AP rows with missing PO' => [$pos, "SELECT COUNT(*) FROM accounts_payable ap LEFT JOIN purchase_orders po ON po.id = ap.source_id WHERE ap.source_type = 'purchase_order' AND po.id IS NULL"],
        'non-positive AP liabilities' => [$pos, "SELECT COUNT(*) FROM accounts_payable WHERE amount_owed <= 0"],
    ];

    $failures = [];
    foreach ($checks as $label => [$connection, $sql]) {
        if ((int) $connection->query($sql)->fetchColumn() !== 0) {
            $failures[] = $label;
        }
    }

    if ($failures) {
        fwrite(STDERR, "Business invariant audit failed:\n");
        foreach ($failures as $failure) {
            fwrite(STDERR, " - {$failure}\n");
        }
        exit(1);
    }

    echo "Business invariant audit passed: stock, barcode, attendance, PO/AP, and liability checks are consistent.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Business invariant audit could not complete: " . $exception->getMessage() . "\n");
    exit(1);
}
