<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This check must run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$php = PHP_BINARY;
$checks = [
    'setup readiness' => 'tests/setup_readiness.php',
    'implementation roadmap' => 'tests/implementation_roadmap.php',
    'operational readiness' => 'tests/operational_readiness.php',
    'Part 6 feature gate' => 'tests/part6_feature_gate.php',
    'security smoke' => 'tests/security_smoke.php',
    'account audit' => 'tests/account_audit.php',
    'business invariants' => 'tests/business_invariants.php',
    'barcode lookup' => 'tests/barcode_lookup_contract.php',
    'module inventory' => 'tests/module_inventory_contract.php',
    'inventory reconciliation' => 'tests/inventory_reconciliation.php',
    'checkout reconciliation' => 'tests/checkout_reconciliation.php',
    'inventory request reconciliation' => 'tests/inventory_request_reconciliation.php',
    'POS/HRMS integration' => 'tests/integration_invariants.php',
    'backup health' => 'tests/backup_health.php',
];
$failures = [];

foreach ($checks as $label => $relativePath) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $command = escapeshellarg($php) . ' ' . escapeshellarg($path);
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    echo '[' . ($exitCode === 0 ? 'PASS' : 'FAIL') . "] {$label}\n";
    foreach ($output as $line) {
        echo "  {$line}\n";
    }
    if ($exitCode !== 0) {
        $failures[] = $label;
    }
}

if ($failures) {
    fwrite(STDERR, "Controlled-use check failed: " . implode(', ', $failures) . "\n");
    exit(1);
}

echo "Controlled-use check passed: security, accounts, business data, and backups are healthy.\n";
