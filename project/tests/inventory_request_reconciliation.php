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
$database = env('DB_NAME_POS', 'grocery_pos');
if ($user === '' || $pass === '') {
    fwrite(STDERR, "DB_USERNAME and DB_PASSWORD are required.\n");
    exit(1);
}

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$database};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $checks = [
        'request quantities are positive' => "SELECT COUNT(*) FROM inventory_requests WHERE quantity <= 0",
        'fulfilled requests have decision metadata' => "
            SELECT COUNT(*) FROM inventory_requests
            WHERE status = 'fulfilled' AND (decided_by IS NULL OR decided_at IS NULL)
        ",
        'fulfilled requests have matching AP rows' => "
            SELECT COUNT(*)
            FROM inventory_requests ir
            WHERE ir.status = 'fulfilled'
              AND ir.supplier_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM accounts_payable ap
                  WHERE ap.source_type = 'inventory_request'
                    AND ap.source_id = ir.request_id
              )
        ",
        'inventory request AP rows are unique' => "
            SELECT COUNT(*) FROM (
                SELECT source_id
                FROM accounts_payable
                WHERE source_type = 'inventory_request'
                GROUP BY source_id
                HAVING COUNT(*) > 1
            ) duplicates
        ",
        'linked request products exist' => "
            SELECT COUNT(*)
            FROM inventory_requests ir
            LEFT JOIN products p ON p.id = ir.product_id
            WHERE ir.product_id IS NOT NULL AND p.id IS NULL
        ",
    ];

    $failures = [];
    foreach ($checks as $label => $sql) {
        if ((int) $pdo->query($sql)->fetchColumn() !== 0) {
            $failures[] = $label;
        }
    }
    if ($failures) {
        fwrite(STDERR, "Inventory request reconciliation failed:\n");
        foreach ($failures as $failure) {
            fwrite(STDERR, " - {$failure}\n");
        }
        exit(1);
    }

    $legacyFulfilled = (int) $pdo->query("
        SELECT COUNT(*) FROM inventory_requests
        WHERE status = 'fulfilled' AND (received_by IS NULL OR received_at IS NULL)
    ")->fetchColumn();
    $total = (int) $pdo->query("SELECT COUNT(*) FROM inventory_requests")->fetchColumn();
    echo "Inventory request reconciliation passed: {$total} requests have valid quantities, references, fulfillment decisions, and AP uniqueness. Legacy fulfilled rows without receipt metadata: {$legacyFulfilled}.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Inventory request reconciliation could not complete: " . $exception->getMessage() . "\n");
    exit(1);
}
