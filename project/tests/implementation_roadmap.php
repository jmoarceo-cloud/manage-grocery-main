<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This audit must run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$contracts = [
    'security baseline' => [
        'file' => 'tests/security_smoke.php',
        'fragments' => ['CSRF', 'SESSION_SECURE', 'password_verify', 'session_regenerate_id'],
    ],
    'product details workflow' => [
        'file' => 'product_details_edit.php',
        'fragments' => ['requireInventoryStaff()', 'requireCSRF()', 'value="verify"', 'value="reject"', 'product_detail_audit'],
    ],
    'checkout transaction' => [
        'file' => 'pos.php',
        'fragments' => ['$pdo->beginTransaction()', '$pdo->commit()', '$pdo->rollBack()', 'sale_id'],
    ],
    'procurement/AP handoff' => [
        'file' => 'includes/config.php',
        'fragments' => ['recordPurchaseOrderReceipt', 'recordAccountsPayable', 'inTransaction()', 'rollBack()'],
    ],
    'payroll release protection' => [
        'file' => 'HRMS/payroll/save_record.php',
        'fragments' => ['approval_status', 'RuntimeException', 'inTransaction()'],
    ],
    'deployment checks' => [
        'file' => 'tests/deployment_check.php',
        'fragments' => ['APP_ENV', 'SESSION_SECURE', 'ATTENDANCE_LOCATION_MODE'],
    ],
    'backup and restore checks' => [
        'file' => 'tests/backup_health.php',
        'fragments' => ['BACKUP_DIR', 'backup', 'recent'],
    ],
];

$failures = [];
foreach ($contracts as $label => $contract) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $contract['file']);
    if (!is_file($path)) {
        $failures[] = "{$label}: missing {$contract['file']}";
        continue;
    }
    $source = file_get_contents($path);
    if ($source === false) {
        $failures[] = "{$label}: unreadable {$contract['file']}";
        continue;
    }
    foreach ($contract['fragments'] as $fragment) {
        if (strpos($source, $fragment) === false) {
            $failures[] = "{$label}: missing {$fragment}";
        }
    }
}

$documentation = file_get_contents($root . DIRECTORY_SEPARATOR . 'HCI_SYSTEM.md');
if (
    $documentation === false
    || strpos($documentation, '# HCI GroceryOne System Documentation') === false
    || strpos($documentation, '## Current implementation status') === false
) {
    $failures[] = 'current HCI GroceryOne system documentation is missing or incomplete';
}

if ($failures) {
    fwrite(STDERR, "Implementation roadmap audit failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Implementation roadmap audit passed: security, product workflow, transactions, procurement/AP, payroll, deployment, and backup controls are implemented and documented.\n";
