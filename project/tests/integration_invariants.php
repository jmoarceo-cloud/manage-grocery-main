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

    $hrmsRows = $hrms->query("
        SELECT u.employee_id, u.role AS hrms_role, u.is_active AS hrms_active,
               e.position, e.status AS employee_status
        FROM users u
        JOIN employees e ON e.employee_id = u.employee_id
        WHERE e.position IN ('Cashier', 'Inventory Staff', 'Accountant')
    ")->fetchAll();
    $hrmsByEmployee = [];
    foreach ($hrmsRows as $row) {
        $hrmsByEmployee[(int) $row['employee_id']] = $row;
    }

    $posRows = $pos->query("
        SELECT employee_id, role, is_active, synced_from_hrms
        FROM users
        WHERE synced_from_hrms = 1
    ")->fetchAll();
    $posByEmployee = [];
    foreach ($posRows as $row) {
        $posByEmployee[(int) $row['employee_id']] = $row;
    }

    $issues = [];
    $expectedPosRoles = [
        'Cashier' => 'cashier',
        'Inventory Staff' => 'inventory_staff',
        'Accountant' => 'finance',
    ];

    foreach ($posRows as $row) {
        $employeeId = (int) $row['employee_id'];
        $hrmsRow = $hrmsByEmployee[$employeeId] ?? null;
        if (!$hrmsRow) {
            $issues[] = "POS employee {$employeeId} has no matching HRMS identity";
            continue;
        }
        $eligible = (int) $hrmsRow['hrms_active'] === 1
            && $hrmsRow['hrms_role'] === 'employee'
            && !in_array($hrmsRow['employee_status'], ['resigned', 'terminated'], true)
            && isset($expectedPosRoles[$hrmsRow['position']]);
        $expectedRole = $expectedPosRoles[$hrmsRow['position']] ?? null;
        if ($eligible && $row['role'] !== $expectedRole) {
            $issues[] = "POS employee {$employeeId} role does not match HRMS position";
        }
        if (!$eligible && (int) $row['is_active'] === 1) {
            $issues[] = "POS employee {$employeeId} remains active although HRMS marks access ineligible";
        }
    }

    foreach ($hrmsRows as $row) {
        $employeeId = (int) $row['employee_id'];
        $eligible = (int) $row['hrms_active'] === 1
            && $row['hrms_role'] === 'employee'
            && !in_array($row['employee_status'], ['resigned', 'terminated'], true);
        if ($eligible && !isset($posByEmployee[$employeeId])) {
            $issues[] = "Eligible HRMS employee {$employeeId} has no synced POS account";
        }
    }

    if ($issues) {
        fwrite(STDERR, "POS/HRMS integration audit failed:\n");
        foreach ($issues as $issue) {
            fwrite(STDERR, " - {$issue}\n");
        }
        exit(1);
    }

    echo "POS/HRMS integration audit passed: HRMS identities and eligible POS access are consistent.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "POS/HRMS integration audit could not complete: " . $exception->getMessage() . "\n");
    exit(1);
}
