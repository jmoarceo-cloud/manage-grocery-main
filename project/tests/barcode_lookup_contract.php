<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This check must run from the command line.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/includes/env.php';
loadEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

$root = dirname(__DIR__);
$source = file_get_contents($root . DIRECTORY_SEPARATOR . 'pos.php');
$productsSource = file_get_contents($root . DIRECTORY_SEPARATOR . 'products.php');
if ($source === false || $productsSource === false) {
    throw new RuntimeException('Could not read barcode workflow sources.');
}

$requiredSourceFragments = [
    "SELECT * FROM products WHERE barcode = ? AND is_active = 1 LIMIT 1",
    'getSellableStock($pdo, $product_id)',
    'is expired — not for sale.',
    'Barcode not recognized:',
];
foreach ($requiredSourceFragments as $fragment) {
    if (strpos($source, $fragment) === false) {
        throw new RuntimeException("POS barcode workflow is missing: {$fragment}");
    }
}
if (strpos($productsSource, "preg_match('/^[A-Za-z0-9._-]{3,64}$/', \$barcode)") === false) {
    throw new RuntimeException('Product barcode format validation is missing.');
}

$pdo = new PDO(
    'mysql:host=' . env('DB_HOST', 'localhost') . ';dbname=' . env('DB_NAME_POS', 'grocery_pos') . ';charset=utf8mb4',
    env('DB_USERNAME', ''),
    env('DB_PASSWORD', ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$indexStmt = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_products_barcode'");
$index = $indexStmt->fetch();
if (!$index || strtoupper((string) $index['Non_unique']) !== '0') {
    throw new RuntimeException('The unique barcode index is missing.');
}

$duplicateCount = (int) $pdo->query("
    SELECT COUNT(*) FROM (
        SELECT barcode
        FROM products
        WHERE barcode IS NOT NULL AND barcode <> ''
        GROUP BY barcode
        HAVING COUNT(*) > 1
    ) duplicates
")->fetchColumn();
if ($duplicateCount !== 0) {
    throw new RuntimeException('Duplicate populated barcodes detected.');
}
$missingCount = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE barcode IS NULL OR barcode = ''")->fetchColumn();
if ($missingCount !== 0) {
    throw new RuntimeException("Products without an assigned barcode detected: {$missingCount}.");
}
if (strpos($productsSource, 'generateInternalProductBarcode') === false) {
    throw new RuntimeException('New products are not assigned an internal POS barcode when no manufacturer barcode is supplied.');
}

$unknownStmt = $pdo->prepare('SELECT id FROM products WHERE barcode = ? AND is_active = 1 LIMIT 1');
$unknownStmt->execute(['__barcode_contract_unknown__']);
if ($unknownStmt->fetch()) {
    throw new RuntimeException('The unknown barcode sentinel unexpectedly exists.');
}

$populated = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE barcode IS NOT NULL AND barcode <> ''")->fetchColumn();
echo "Barcode lookup contract passed: unique index, active lookup, unknown handling, sellable-stock gate, and validation are wired.";
if ($populated === 0) {
    echo " No populated live barcodes exist yet, so a real scan was not simulated.";
}
echo PHP_EOL;
