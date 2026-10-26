<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This check must run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$recommendationSource = file_get_contents($root . DIRECTORY_SEPARATOR . 'stock_recommendations.php');
if ($recommendationSource === false) {
    throw new RuntimeException('Stock recommendation report is missing.');
}
foreach (['requireInventoryStaff()', 'DATE_SUB(NOW()', 'quantity_remaining', 'does not change stock'] as $fragment) {
    if (strpos($recommendationSource, $fragment) === false) {
        throw new RuntimeException("Stock recommendation safety contract is missing: {$fragment}");
    }
}

$optionalFeatures = [
    'public barcode scanner' => ['PUBLIC_SCANNER_ENABLED', 'PUBLIC_SCANNER_URL'],
    'kiosk mode' => ['KIOSK_MODE_ENABLED', 'KIOSK_ALLOWED_ORIGINS'],
    'AI product descriptions' => ['AI_PRODUCT_DETAILS_ENABLED', 'AI_PROVIDER_API_KEY'],
    'external integrations' => ['EXTERNAL_INTEGRATIONS_ENABLED', 'EXTERNAL_API_BASE_URL'],
];
$envPath = $root . DIRECTORY_SEPARATOR . '.env';
$env = is_file($envPath) ? (file_get_contents($envPath) ?: '') : '';
foreach ($optionalFeatures as $label => $keys) {
    foreach ($keys as $key) {
        if (preg_match('/^' . preg_quote($key, '/') . '\s*=\s*.+$/mi', $env)) {
            throw new RuntimeException("Unapproved optional feature configuration found: {$label} ({$key}).");
        }
    }
}

echo "Part 6 feature gate passed: stock recommendations are guarded and optional public/AI/external features remain disabled until approved configuration exists.\n";
