<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This check must run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$modules = [
    'products' => [
        'file' => 'products.php',
        'guards' => ['requireInventoryStaff()'],
        'state_change' => ['requireCSRF()', '$_SERVER[\'REQUEST_METHOD\']'],
    ],
    'product details' => [
        'file' => 'product_details_edit.php',
        'guards' => ['requireInventoryStaff()'],
        'state_change' => ['requireCSRF()', '$_SERVER[\'REQUEST_METHOD\']'],
    ],
    'shelf labels' => [
        'file' => 'shelf_labels.php',
        'guards' => ['requireInventoryStaff()'],
        'state_change' => ['requireCSRF()', '$_SERVER[\'REQUEST_METHOD\']'],
    ],
    'inventory' => [
        'file' => 'inventory.php',
        'guards' => ['requireInventoryStaff()'],
        'state_change' => ['requireCSRF()', '$_SERVER[\'REQUEST_METHOD\']'],
    ],
    'POS' => [
        'file' => 'pos.php',
        'guards' => ['requireCashier()'],
        'state_change' => ['$_SERVER[\'REQUEST_METHOD\']'],
    ],
    'purchase orders' => [
        'file' => 'purchase_orders.php',
        'guards' => ['requireLogin()', 'in_array($__role, [\'admin\', \'inventory_staff\', \'finance\'], true)'],
        'state_change' => ['requireCSRF()', '$_SERVER[\'REQUEST_METHOD\']'],
    ],
    'accounts payable' => [
        'file' => 'accounts_payable.php',
        'guards' => ['requireAccountsPayableManager()'],
        'state_change' => ['requireCSRF()', '$_SERVER[\'REQUEST_METHOD\']'],
    ],
];

$failures = [];
foreach ($modules as $label => $definition) {
    $path = $root . DIRECTORY_SEPARATOR . $definition['file'];
    if (!is_file($path)) {
        $failures[] = "{$label}: missing {$definition['file']}";
        continue;
    }
    $source = file_get_contents($path);
    if ($source === false) {
        $failures[] = "{$label}: could not read {$definition['file']}";
        continue;
    }
    foreach (array_merge($definition['guards'], $definition['state_change']) as $fragment) {
        if (strpos($source, $fragment) === false) {
            $failures[] = "{$label}: missing contract {$fragment}";
        }
    }
}

$sidebarPath = $root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'sidebar.php';
$sidebarSource = is_file($sidebarPath) ? file_get_contents($sidebarPath) : false;
$inventoryMenuStart = is_string($sidebarSource)
    ? strpos($sidebarSource, "elseif (\$__role === 'inventory_staff')")
    : false;
$financeMenuStart = is_string($sidebarSource)
    ? strpos($sidebarSource, "elseif (\$__role === 'finance')", $inventoryMenuStart === false ? 0 : $inventoryMenuStart)
    : false;
if ($inventoryMenuStart === false
    || $financeMenuStart === false
    || strpos(substr($sidebarSource, $inventoryMenuStart, $financeMenuStart - $inventoryMenuStart), "__sidebarNavLink('shelf_labels.php'") === false) {
    $failures[] = 'inventory staff: missing Shelf Labels navigation link';
}

if ($failures) {
    fwrite(STDERR, "Module inventory contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Module inventory contract passed: product, inventory, POS, procurement, and finance entry points are present and guarded.\n";
