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
    $posSource = file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'pos.php');
    if ($posSource === false || substr_count($posSource, 'sale_id, change_type') < 4) {
        throw new RuntimeException('POS sale, void, and refund stock logs do not persist sale_id.');
    }
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
        'sale details reference existing sales' => "
            SELECT COUNT(*)
            FROM sales_details sd
            LEFT JOIN sales s ON s.id = sd.sale_id
            WHERE s.id IS NULL OR sd.quantity <= 0
        ",
        'new sale stock logs reference existing sales' => "
            SELECT COUNT(*)
            FROM stock_logs sl
            LEFT JOIN sales s ON s.id = sl.sale_id
            WHERE sl.change_type = 'sale'
              AND sl.sale_id IS NOT NULL
              AND (s.id IS NULL OR sl.quantity_change >= 0)
        ",
        'new void/refund logs reference existing sales' => "
            SELECT COUNT(*)
            FROM stock_logs sl
            LEFT JOIN sales s ON s.id = sl.sale_id
            WHERE sl.change_type IN ('void', 'refund')
              AND sl.sale_id IS NOT NULL
              AND (s.id IS NULL OR sl.quantity_change < 0)
        ",
        'completed sales have positive totals' => "
            SELECT COUNT(*)
            FROM sales
            WHERE status = 'completed' AND total_amount <= 0
        ",
        'refund and void logs do not reduce stock' => "
            SELECT COUNT(*)
            FROM stock_logs
            WHERE change_type IN ('void', 'refund') AND quantity_change < 0
        ",
    ];

    $failures = [];
    foreach ($checks as $label => $sql) {
        if ((int) $pdo->query($sql)->fetchColumn() !== 0) {
            $failures[] = $label;
        }
    }
    if ($failures) {
        fwrite(STDERR, "Checkout reconciliation failed:\n");
        foreach ($failures as $failure) {
            fwrite(STDERR, " - {$failure}\n");
        }
        exit(1);
    }

    $saleCount = (int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn();
    $detailCount = (int) $pdo->query('SELECT COUNT(*) FROM sales_details')->fetchColumn();
    $legacyLogs = (int) $pdo->query("SELECT COUNT(*) FROM stock_logs WHERE change_type IN ('sale', 'void', 'refund') AND sale_id IS NULL")->fetchColumn();
    echo "Checkout reconciliation passed: {$saleCount} sales and {$detailCount} sale lines have valid references and stock directions. Legacy unlinked stock logs: {$legacyLogs}.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Checkout reconciliation could not complete: " . $exception->getMessage() . "\n");
    exit(1);
}
