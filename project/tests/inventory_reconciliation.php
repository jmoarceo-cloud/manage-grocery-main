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
        'product stock matches remaining batches' => "
            SELECT COUNT(*)
            FROM products p
            LEFT JOIN (
                SELECT product_id, SUM(quantity_remaining) AS remaining
                FROM product_batches
                GROUP BY product_id
            ) b ON b.product_id = p.id
            -- Products without batches are legacy/unbatched inventory and
            -- intentionally use products.stock as their source of truth.
            WHERE b.product_id IS NOT NULL
              AND p.stock <> COALESCE(b.remaining, 0)
        ",
        'batch quantities are within received quantities' => "
            SELECT COUNT(*)
            FROM product_batches
            WHERE quantity_remaining < 0 OR quantity_remaining > quantity_received
        ",
        'stock log arithmetic is consistent' => "
            SELECT COUNT(*)
            FROM stock_logs
            WHERE new_stock < 0
               OR quantity_change <> (new_stock - previous_stock)
        ",
        'sale batch consumption is not above sale quantity' => "
            SELECT COUNT(*)
            FROM (
                SELECT sd.id, sd.quantity, COALESCE(SUM(sbc.quantity), 0) AS consumed
                FROM sales_details sd
                LEFT JOIN sale_batch_consumption sbc ON sbc.sales_detail_id = sd.id
                GROUP BY sd.id, sd.quantity
                HAVING consumed > sd.quantity
            ) overconsumed
        ",
        'sale batch consumption references existing records' => "
            SELECT COUNT(*)
            FROM sale_batch_consumption sbc
            LEFT JOIN sales_details sd ON sd.id = sbc.sales_detail_id
            LEFT JOIN product_batches b ON b.id = sbc.batch_id
            WHERE sd.id IS NULL OR (sbc.batch_id IS NOT NULL AND b.id IS NULL)
        ",
    ];

    $failures = [];
    foreach ($checks as $label => $sql) {
        if ((int) $pdo->query($sql)->fetchColumn() !== 0) {
            $failures[] = $label;
        }
    }
    if ($failures) {
        fwrite(STDERR, "Inventory reconciliation failed:\n");
        foreach ($failures as $failure) {
            fwrite(STDERR, " - {$failure}\n");
        }
        exit(1);
    }

    $productCount = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $batchCount = (int) $pdo->query('SELECT COUNT(*) FROM product_batches')->fetchColumn();
    $logCount = (int) $pdo->query('SELECT COUNT(*) FROM stock_logs')->fetchColumn();
    echo "Inventory reconciliation passed: {$productCount} products, {$batchCount} batches, and {$logCount} stock logs are internally consistent.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Inventory reconciliation could not complete: " . $exception->getMessage() . "\n");
    exit(1);
}
