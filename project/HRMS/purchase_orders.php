<?php
require_once __DIR__ . '/includes/config.php';

$isSupervisorRequest = ($purchaseOrderContext ?? 'hr') === 'supervisor';
if ($isSupervisorRequest) {
    requireSupervisorRole();
} else {
    requireHR();
}

$currentRole = (string) ($_SESSION['role'] ?? '');
if (!$isSupervisorRequest && !in_array($currentRole, ['hr', 'hr_manager'], true)) {
    redirect($currentRole === 'admin' ? 'admin_dashboard.php' : 'hr_manager_dashboard.php');
}

$pageError = '';
$pageSuccess = '';
$department = '';
$position = '';
$supplierOptions = [];
$purchaseOrders = [];
$posAvailable = $posPdo instanceof PDO;
$canSubmit = !$isSupervisorRequest || isSupervisorRole();
$supervisorEmployeeId = $isSupervisorRequest
    ? (isSupervisorRole() ? (int) ($_SESSION['employee_id'] ?? 0) : (int) dec_id($_GET['supervisor_id'] ?? ''))
    : 0;
$hrmsUserId = (int) ($_SESSION['user_id'] ?? 0);
$requesterHrmsUserId = $isSupervisorRequest && !$canSubmit ? 0 : $hrmsUserId;
$employeeId = $isSupervisorRequest ? $supervisorEmployeeId : (int) ($_SESSION['employee_id'] ?? 0);
$otherItemType = $isSupervisorRequest ? 'other_supervisor_related' : 'other_hr_related';
$requestRoleLabel = $isSupervisorRequest ? 'Supervisor' : 'HR';
$pageHeading = $isSupervisorRequest ? 'Supervisor Purchase Orders' : 'Purchase Orders';
$itemHeading = $isSupervisorRequest ? 'Supervisory operations items' : 'HR items to request';
$requestableItems = $isSupervisorRequest ? [
    'Team oversight and reporting' => [
        'Team schedule and shift-planning materials',
        'Attendance and timekeeping forms',
        'Shift handover and operations logbook',
        'Incident and workplace-safety forms',
        'Clipboards and document folders',
        'Pens, markers, and report stationery',
    ],
    'Team communication and coordination' => [
        'Two-way radio',
        'Radio earpiece or headset',
        'Rechargeable radio batteries',
        'Staff name badges and lanyards',
        'Notice board and schedule display supplies',
    ],
    'Floor supervision and safety' => [
        'High-visibility safety vest',
        'Flashlight',
        'Wet-floor warning sign',
        'Safety cones and barrier tape',
        'First-aid kit refill supplies',
    ],
    'POS and office supervision tools' => [
        'Desktop calculator',
        'Keyboard or mouse for the supervisor workstation',
        'Computer headset or webcam for operations coordination',
        'Lockable document storage box',
        'Printer ink or toner for reports',
    ],
] : [
    'Office stationery' => [
        'A4 bond paper',
        'Ballpoint pens',
        'Pencils and erasers',
        'Highlighters',
        'Whiteboard markers',
        'Notebooks and pads',
        'Sticky notes',
        'Envelopes',
        'Staples and paper clips',
    ],
    'Employee records and filing' => [
        'File folders',
        'Expanding folders',
        'Ring binders',
        'Document storage boxes',
        'Filing labels and dividers',
        'Lockable document cabinet',
    ],
    'Printing and employee IDs' => [
        'Printer ink or toner',
        'ID cards',
        'Lanyards',
        'ID card holders',
        'Certificate paper',
    ],
    'Recruitment and onboarding' => [
        'Application and interview forms',
        'Candidate assessment materials',
        'New-hire welcome kits',
        'Employee badges and name tags',
    ],
    'Training and employee engagement' => [
        'Training manuals and handouts',
        'Training notebooks',
        'Whiteboard and presentation supplies',
        'Employee event materials',
        'Recognition certificates and tokens',
    ],
    'HR office equipment' => [
        'Keyboard or mouse',
        'Computer headset or webcam',
        'Calculator',
        'USB storage device',
        'Desk organizer',
    ],
];
$requestableItemNames = [];
foreach ($requestableItems as $items) {
    foreach ($items as $itemName) {
        $requestableItemNames[$itemName] = true;
    }
}

try {
    if ($employeeId > 0) {
        $departmentStmt = $pdo->prepare('SELECT department, position FROM employees WHERE employee_id = ? LIMIT 1');
        $departmentStmt->execute([$employeeId]);
        $employeeProfile = $departmentStmt->fetch(PDO::FETCH_ASSOC);
        if ($employeeProfile) {
            $department = trim((string) ($employeeProfile['department'] ?? ''));
            $position = trim((string) ($employeeProfile['position'] ?? ''));
        }
        if ($isSupervisorRequest && !$canSubmit && $employeeId > 0) {
            $requesterStmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ? AND role = 'supervisor' AND is_active = 1 ORDER BY id DESC LIMIT 1");
            $requesterStmt->execute([$employeeId]);
            $requesterHrmsUserId = (int) $requesterStmt->fetchColumn();
        }
    }
    if ($isSupervisorRequest && $canSubmit && ($hrmsUserId < 1 || $employeeId < 1)) {
        $pageError = 'Your Supervisor account is not linked to an HRMS employee profile. Contact Admin before submitting a purchase request.';
    } elseif ($isSupervisorRequest && !$canSubmit && ($employeeId < 1 || $requesterHrmsUserId < 1)) {
        $pageError = 'Select a Supervisor with a linked employee account to view their purchase requests.';
    } elseif ($isSupervisorRequest && $canSubmit && $position === '') {
        $pageError = 'Your position is missing from your HRMS employee profile. Contact Admin before submitting a position-based purchase request.';
    }
} catch (Throwable $e) {
    error_log($requestRoleLabel . ' purchase request profile lookup failed: ' . $e->getMessage());
    $pageError = 'Your department and position could not be loaded. Please contact HR before submitting a purchase request.';
}

if (!$posAvailable && $pageError === '') {
    $pageError = 'The purchasing system is unavailable right now. Please try again later.';
}

if ($posAvailable) {
    try {
        $poColumnRows = $posPdo->query('SHOW COLUMNS FROM purchase_orders')->fetchAll(PDO::FETCH_ASSOC);
        $poColumns = array_column($poColumnRows, 'Field');
        $poColumnDefinitions = [
            'requested_by_hrms_user_id' => 'INT DEFAULT NULL',
            'requester_signature' => 'LONGTEXT DEFAULT NULL',
            'requester_signature_at' => 'DATETIME DEFAULT NULL',
            'finance_signature' => 'LONGTEXT DEFAULT NULL',
            'finance_signature_at' => 'DATETIME DEFAULT NULL',
            'supplier_signature' => 'LONGTEXT DEFAULT NULL',
            'supplier_signature_at' => 'DATETIME DEFAULT NULL',
            'supplier_approval_token_hash' => 'CHAR(64) DEFAULT NULL',
            'supplier_approval_expires_at' => 'DATETIME DEFAULT NULL',
        ];
        foreach ($poColumnDefinitions as $column => $definition) {
            if (!in_array($column, $poColumns, true)) {
                $posPdo->exec("ALTER TABLE purchase_orders ADD COLUMN {$column} {$definition}");
            }
        }
        $statusColumn = $posPdo->query("SHOW COLUMNS FROM purchase_orders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($statusColumn) || strpos((string) ($statusColumn['Type'] ?? ''), "'pending_supplier'") === false) {
            $posPdo->exec("ALTER TABLE purchase_orders MODIFY status ENUM('draft','pending_approval','approved','pending_supplier','rejected','ordered','partially_received','received','cancelled') NOT NULL DEFAULT 'draft'");
        }
        $supplierOptions = $posPdo->query("SELECT id, company_name FROM suppliers WHERE status = 'active' ORDER BY company_name")->fetchAll();
    } catch (Throwable $e) {
        error_log($requestRoleLabel . ' purchase request setup failed: ' . $e->getMessage());
        $pageError = 'Purchase request information could not be loaded. Please contact Admin.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    if (!$canSubmit) {
        $pageError = 'Admin preview is read-only. Sign in as the Supervisor to submit a purchase request.';
    } elseif (!$posAvailable) {
        $pageError = 'The purchasing system is unavailable right now. Your request was not submitted.';
    } elseif ($department === '' || $department === 'Unassigned') {
        $pageError = 'Your HRMS employee profile needs an assigned department before you can submit a purchase request.';
    } elseif (!is_string($_POST['requester_signature'] ?? null) || !is_string($_POST['requester_signature_confirmed'] ?? null)) {
        $pageError = 'Your e-signature and confirmation are required to submit this purchase request.';
    } elseif (
        ($_POST['requester_signature_confirmed'] ?? '') !== '1'
        || !preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', (string) $_POST['requester_signature'])
        || ($signatureBytes = base64_decode(substr((string) $_POST['requester_signature'], 22), true)) === false
        || strlen($signatureBytes) > 500000
        || strlen($signatureBytes) <= 500
        || substr($signatureBytes, 0, 8) !== "\x89PNG\r\n\x1a\n"
    ) {
        $pageError = 'Draw your e-signature and confirm that you reviewed the request before submitting.';
    } elseif (!is_string($_POST['supplier_id'] ?? null) || !ctype_digit($_POST['supplier_id']) || !is_string($_POST['notes'] ?? '')) {
        $pageError = 'The supplier or request notes are invalid. Review the form and try again.';
    } else {
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $requesterSignature = (string) $_POST['requester_signature'];
        $itemTypes = $_POST['item_type'] ?? [];
        $otherItemNames = $_POST['item_other_name'] ?? [];
        $itemDetails = $_POST['item_detail'] ?? [];
        $quantities = $_POST['item_quantity'] ?? [];
        $unitCosts = $_POST['item_unit_cost'] ?? [];
        $items = [];

        if (!is_array($itemTypes) || !is_array($otherItemNames) || !is_array($itemDetails) || !is_array($quantities) || !is_array($unitCosts)) {
            $pageError = 'The item list is invalid. Review the form and try again.';
        } elseif (count($itemTypes) < 1 || count($itemTypes) > 30) {
            $pageError = 'Add between 1 and 30 items to a purchase request.';
        } elseif (mb_strlen($notes) > 500) {
            $pageError = 'Request notes must be 500 characters or fewer.';
        } elseif (
            count($otherItemNames) !== count($itemTypes)
            || count($itemDetails) !== count($itemTypes)
            || count($quantities) !== count($itemTypes)
            || count($unitCosts) !== count($itemTypes)
        ) {
            $pageError = 'The item list is incomplete. Review each requested item and try again.';
        } else {
            try {
                $supplierStmt = $posPdo->prepare("SELECT id, company_name FROM suppliers WHERE id = ? AND status = 'active' LIMIT 1");
                $supplierStmt->execute([$supplierId]);
                $supplier = $supplierStmt->fetch();
                if (!$supplier) {
                    throw new RuntimeException('Choose an active supplier.');
                }

                $rowCount = count($itemTypes);
                for ($i = 0; $i < $rowCount; $i++) {
                    foreach ([
                        $itemTypes[$i] ?? null,
                        $otherItemNames[$i] ?? null,
                        $itemDetails[$i] ?? null,
                        $quantities[$i] ?? null,
                        $unitCosts[$i] ?? null,
                    ] as $itemValue) {
                        if (!is_string($itemValue)) {
                            throw new RuntimeException('Every requested item must have valid form values. Review the item rows and try again.');
                        }
                    }
                    $itemType = trim((string) ($itemTypes[$i] ?? ''));
                    $name = $itemType === $otherItemType
                        ? trim((string) ($otherItemNames[$i] ?? ''))
                        : $itemType;
                    $detail = trim((string) ($itemDetails[$i] ?? ''));
                    $quantityValue = trim((string) ($quantities[$i] ?? ''));
                    $costValue = trim((string) ($unitCosts[$i] ?? ''));
                    if (
                        ($itemType !== $otherItemType && !isset($requestableItemNames[$itemType]))
                        || ($itemType === $otherItemType && ($name === '' || mb_strlen($name) > 150))
                    ) {
                        throw new RuntimeException('Choose an item from the ' . strtolower($requestRoleLabel) . ' request list. Use the Other option only when nothing on the list fits.');
                    }
                    if (mb_strlen($detail) > 120) {
                        throw new RuntimeException('Item specifications or purpose must be 120 characters or fewer.');
                    }
                    if (
                        !ctype_digit($quantityValue)
                        || (int) $quantityValue < 1
                        || (int) $quantityValue > 100000
                        || !is_numeric($costValue)
                        || !is_finite((float) $costValue)
                        || (float) $costValue < 0
                        || (float) $costValue > 99999999.99
                    ) {
                        throw new RuntimeException('Each item needs a quantity from 1 to 100,000 and a valid non-negative estimated unit cost.');
                    }
                    $itemDescription = $detail !== '' ? $name . ' — ' . $detail : $name;
                    if (mb_strlen($itemDescription) > 150) {
                        throw new RuntimeException('The item name and specifications together must be 150 characters or fewer.');
                    }
                    $subtotal = round((float) $costValue * (int) $quantityValue, 2);
                    if ($subtotal > 9999999999.99) {
                        throw new RuntimeException('An item total is too high. Reduce its quantity or estimated unit cost.');
                    }
                    $items[] = [
                        'item_name' => $itemDescription,
                        'quantity' => (int) $quantityValue,
                        'unit_cost' => round((float) $costValue, 2),
                        'subtotal' => $subtotal,
                    ];
                }

                $posPdo->beginTransaction();
                $poStmt = $posPdo->prepare("
                    INSERT INTO purchase_orders
                        (supplier_id, department, status, notes, created_by, created_by_name, requested_by_hrms_user_id, submitted_at)
                    VALUES (?, ?, 'pending_approval', ?, NULL, ?, ?, NOW())
                ");
                $poStmt->execute([
                    $supplierId,
                    $department,
                    $notes !== '' ? $notes : null,
                    $_SESSION['full_name'] ?? ($requestRoleLabel . ' requester'),
                    $hrmsUserId,
                ]);
                $poId = (int) $posPdo->lastInsertId();
                $poNumber = 'PO-' . str_pad((string) $poId, 6, '0', STR_PAD_LEFT);
                $posPdo->prepare('UPDATE purchase_orders SET po_number = ? WHERE id = ?')->execute([$poNumber, $poId]);
                $posPdo->prepare('UPDATE purchase_orders SET requester_signature = ?, requester_signature_at = NOW() WHERE id = ?')
                    ->execute([$requesterSignature, $poId]);

                $itemStmt = $posPdo->prepare("
                    INSERT INTO purchase_order_items (po_id, product_id, item_name, quantity_ordered, unit_cost, subtotal)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                foreach ($items as $item) {
                    $itemStmt->execute([
                        $poId,
                        null,
                        $item['item_name'],
                        $item['quantity'],
                        $item['unit_cost'],
                        $item['subtotal'],
                    ]);
                }
                $posPdo->commit();

                $notificationWarning = '';
                try {
                    $financeUsers = $posPdo->query("SELECT id FROM users WHERE role = 'finance' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
                    $notify = $posPdo->prepare("
                        INSERT INTO notifications (user_id, category, type, message, link_url, source_type, source_id)
                        VALUES (?, 'workflow', 'purchase_order', ?, 'finance.php#purchase-orders', 'purchase_order', ?)
                    ");
                    foreach ($financeUsers as $financeUserId) {
                        $notify->execute([
                            (int) $financeUserId,
                            $requestRoleLabel . ' purchase request ' . $poNumber . ' from ' . $department . ' is awaiting Finance approval.',
                            $poId,
                        ]);
                    }
                } catch (Throwable $e) {
                    error_log($requestRoleLabel . ' purchase request Finance notification failed for ' . $poNumber . ': ' . $e->getMessage());
                    $notificationWarning = ' Finance notifications could not be sent, but the request is in the Finance approval queue.';
                }
                $flashKey = $isSupervisorRequest ? 'supervisor_po_flash_success' : 'hr_po_flash_success';
                $_SESSION[$flashKey] = $poNumber . ' was submitted to Finance for approval with your e-signature.' . $notificationWarning;
                redirect($isSupervisorRequest ? 'supervisor_purchase_orders.php' : 'purchase_orders.php');
            } catch (Throwable $e) {
                if ($posPdo->inTransaction()) {
                    $posPdo->rollBack();
                }
                error_log($requestRoleLabel . ' purchase request submission failed: ' . $e->getMessage());
                $pageError = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'The purchase request could not be submitted. Please try again or contact Admin.';
            }
        }
    }
}

$flashKey = $isSupervisorRequest ? 'supervisor_po_flash_success' : 'hr_po_flash_success';
$pageSuccess = (string) ($_SESSION[$flashKey] ?? '');
unset($_SESSION[$flashKey]);

if ($posAvailable) {
    try {
        $ordersStmt = $posPdo->prepare("
            SELECT po.*, s.company_name,
                (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.po_id = po.id) AS item_count,
                (SELECT COALESCE(SUM(subtotal), 0) FROM purchase_order_items poi WHERE poi.po_id = po.id) AS total_amount
            FROM purchase_orders po
            JOIN suppliers s ON s.id = po.supplier_id
            WHERE po.requested_by_hrms_user_id = ?
            ORDER BY po.created_at DESC
            LIMIT 100
        ");
        $ordersStmt->execute([$requesterHrmsUserId]);
        $purchaseOrders = $ordersStmt->fetchAll();
        $itemDetails = $posPdo->prepare('SELECT item_name, quantity_ordered, unit_cost, subtotal FROM purchase_order_items WHERE po_id = ? ORDER BY id');
    } catch (Throwable $e) {
        error_log($requestRoleLabel . ' purchase request history lookup failed: ' . $e->getMessage());
        $pageError = 'Purchase request history could not be loaded.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= clean($pageHeading) ?> — HRMS</title>
    <link rel="stylesheet" href="../style.css?v=<?= (int) filemtime(__DIR__ . '/../style.css') ?>">
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>">
    <style>
        .main-content { width:100%; max-width:none; margin:0; padding:clamp(22px,2vw,32px) clamp(18px,2.2vw,36px) 48px; }
        .page-header { margin-bottom:20px; }
        .page-header h1 { color:var(--text-accent); font-family:var(--font-headline); font-size:clamp(1.4rem,2.2vw,2rem); }
        .page-header p { margin-top:7px; color:var(--ink-soft); font-size:.875rem; }
        .po-panel { margin-bottom:20px; padding:22px; border:1px solid var(--line); border-radius:var(--radius-lg); background:var(--white); box-shadow:var(--shadow-card); }
        .po-panel h2 { margin-bottom:8px; color:var(--text-accent); font-size:1.05rem; }
        .po-help { color:var(--ink-soft); font-size:.85rem; line-height:1.5; margin-bottom:18px; }
        .po-form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
        .po-field { display:grid; gap:6px; color:var(--ink); font-size:.8rem; font-weight:700; }
        .po-field input,.po-field select { min-width:0; width:100%; min-height:40px; padding:9px 11px; border:1px solid var(--line); border-radius:8px; background:var(--white); color:var(--ink); font:500 .85rem var(--font-body); }
        .po-field--full { grid-column:1/-1; }
        .po-item-row { display:grid; grid-template-columns:minmax(190px,1.1fr) minmax(240px,1.5fr) minmax(110px,.65fr) minmax(165px,1fr) auto; gap:10px; align-items:end; padding:14px 0; border-bottom:1px solid var(--line); }
        .po-item-row:first-child { border-top:1px solid var(--line); }
        .po-item-row .po-field { min-width:0; line-height:1.35; }
        .po-item-row .other-item-field[hidden] { display:none; }
        .po-signature-pad { display:block; width:100%; height:160px; margin-top:8px; border:1px dashed #93c5fd; border-radius:9px; background:#fff; touch-action:none; }
        .po-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:18px; }
        .po-button { min-height:40px; padding:9px 15px; border:1px solid transparent; border-radius:8px; background:var(--accent); color:var(--accent-foreground); font:700 .82rem var(--font-body); cursor:pointer; }
        .po-button--secondary { border-color:var(--line); background:var(--white); color:var(--text-accent); }
        .po-button--remove { min-height:36px; padding:7px 10px; border-color:var(--line); background:var(--white); color:#b91c1c; }
        .po-table-wrap { overflow-x:auto; }
        .po-table { width:100%; border-collapse:collapse; font-size:.82rem; }
        .po-table th,.po-table td { padding:11px 10px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
        .po-table th { color:var(--ink-soft); font-size:.69rem; letter-spacing:.04em; text-transform:uppercase; }
        .po-status { display:inline-block; padding:4px 8px; border-radius:999px; background:#fef3c7; color:#92400e; font-size:.7rem; font-weight:750; white-space:nowrap; }
        .po-status--approved,.po-status--ordered,.po-status--received { background:#dcfce7; color:#166534; }
        .po-status--pending_supplier,.po-status--pending_approval { background:#fef3c7; color:#92400e; }
        .po-status--rejected,.po-status--cancelled { background:#fee2e2; color:#b91c1c; }
        .po-alert { margin-bottom:16px; padding:12px 14px; border-radius:9px; font-size:.84rem; }
        .po-alert--error { background:#fee2e2; color:#991b1b; }
        .po-alert--success { background:#dcfce7; color:#166534; }
        .po-muted { color:var(--ink-soft); }
        .po-item-details { margin:8px 0 0; padding-left:18px; color:var(--ink-soft); }
        @media(max-width:1100px) { .po-item-row { grid-template-columns:repeat(2,minmax(0,1fr)); } .po-item-row .po-button--remove { justify-self:start; } }
        @media(max-width:600px) { .po-form-grid { grid-template-columns:1fr; } .po-field--full { grid-column:auto; } .po-panel { padding:16px; } .po-item-row { grid-template-columns:minmax(0,1fr); } }
    </style>
</head>
<body class="dashboard-page <?= $isSupervisorRequest ? 'dashboard-page--supervisor' : 'dashboard-page--hr-staff' ?>">
<div class="app-shell">
    <?php
    $activeNav = 'purchase_orders';
    if ($isSupervisorRequest) {
        include __DIR__ . '/includes/supervisor_sidebar.php';
    } else {
        include __DIR__ . '/includes/hr_sidebar.php';
    }
    ?>
    <div class="main-area">
        <header class="topbar"><h1><?= clean($pageHeading) ?></h1></header>
        <main class="main-content">
            <div class="page-header">
                <h1><?= clean($pageHeading) ?></h1>
                <p><?= $isSupervisorRequest
                    ? 'Request supplies that support your Supervisor role and team operations. Every request goes directly to Finance for approval.'
                    : 'Submit department purchase requests to Finance. Every request is sent for approval before anything is ordered.' ?></p>
            </div>
            <?php if ($pageError !== ''): ?><div class="po-alert po-alert--error"><?= clean($pageError) ?></div><?php endif; ?>
            <?php if ($pageSuccess !== ''): ?><div class="po-alert po-alert--success"><?= clean($pageSuccess) ?></div><?php endif; ?>
            <?php if ($canSubmit && ($department === '' || $department === 'Unassigned')): ?>
                <div class="po-alert po-alert--error">Your HRMS employee profile needs an assigned department before you can submit a purchase request.</div>
            <?php elseif ($canSubmit && $isSupervisorRequest && $position === ''): ?>
                <div class="po-alert po-alert--error">Your HRMS employee profile needs an assigned position before you can submit a position-based purchase request.</div>
            <?php elseif ($canSubmit && $posAvailable && $supplierOptions): ?>
                <section class="po-panel">
                    <h2>Submit a purchase request</h2>
                    <p class="po-help"><?= $isSupervisorRequest
                        ? 'Items are tailored to team oversight, store-floor coordination, safety, and POS/report supervision—not resale inventory. For products to stock or sell, use Inventory Requests instead. Finance reviews this purchase order before anything is ordered.'
                        : 'Choose from common HR needs: office stationery, employee filing, printing and IDs, recruitment and onboarding, training, and HR office equipment. Every request goes to Finance for approval. Choose “Other HR-related item” only when nothing listed fits.' ?></p>
                    <form method="post" id="hrPoForm">
                        <?php csrfField(); ?>
                        <div class="po-form-grid">
                            <label class="po-field">Supplier
                                <select name="supplier_id" id="supplierSelect" required>
                                    <option value="">Choose supplier</option>
                                    <?php foreach ($supplierOptions as $supplier): ?>
                                        <option value="<?= (int) $supplier['id'] ?>"><?= clean($supplier['company_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="po-field">Department
                                <input value="<?= clean($department) ?>" readonly>
                            </label>
                            <?php if ($isSupervisorRequest): ?>
                            <label class="po-field">Position
                                <input value="<?= clean($position) ?>" readonly>
                            </label>
                            <?php endif; ?>
                            <label class="po-field po-field--full">Request notes
                                <input name="notes" maxlength="500" placeholder="Reason for request, urgency, or delivery details">
                            </label>
                        </div>
                        <h3 style="margin:20px 0 10px;color:var(--text-accent);font-size:.9rem;"><?= clean($itemHeading) ?></h3>
                        <div id="poItemRows"></div>
                        <div style="margin-top:18px;padding:15px;border:1px solid var(--line);border-radius:10px;background:var(--cream);">
                            <strong>Requester e-signature</strong>
                            <p class="po-help" style="margin:5px 0 0;">Sign to confirm that the supplier, requested items, quantities, estimated costs, and request notes are accurate. Finance and the supplier will review this signed request and Formality Letter.</p>
                            <canvas class="po-signature-pad" id="requesterSignaturePad" width="1040" height="320" aria-label="Draw your requester e-signature"></canvas>
                            <input type="hidden" name="requester_signature" id="requesterSignature">
                            <label style="display:flex;gap:8px;align-items:flex-start;margin-top:10px;font-size:.82rem;line-height:1.45;">
                                <input type="checkbox" name="requester_signature_confirmed" value="1" required>
                                <span>I reviewed this purchase request and the information is accurate. I agree to submit it to Finance with my e-signature.</span>
                            </label>
                            <button type="button" class="po-button po-button--secondary" id="clearRequesterSignature" style="margin-top:10px;">Clear Signature</button>
                            <div id="requesterSignatureError" class="po-alert po-alert--error" role="alert" hidden style="margin:10px 0 0;">Draw your e-signature before submitting.</div>
                        </div>
                        <div class="po-actions">
                            <button type="button" class="po-button po-button--secondary" id="addItem">+ Add item</button>
                            <button type="submit" class="po-button">Submit to Finance for Approval</button>
                        </div>
                    </form>
                </section>
            <?php elseif ($canSubmit && !$supplierOptions && $posAvailable && $pageError === ''): ?>
                <div class="po-alert po-alert--error">There are no active suppliers available. Contact Admin to add a supplier before requesting a purchase.</div>
            <?php elseif (!$canSubmit): ?>
                <div class="po-alert po-muted">Admin preview is read-only. This page shows purchase requests submitted by the selected Supervisor; sign in as that Supervisor to submit a request.</div>
            <?php endif; ?>

            <section class="po-panel">
                <h2><?= $canSubmit ? 'My purchase requests' : 'Supervisor purchase requests' ?></h2>
                <p class="po-help">Track each request through Finance approval and the Purchase Order workflow.</p>
                <div class="po-table-wrap">
                    <table class="po-table">
                        <thead><tr><th>PO Number</th><th>Supplier</th><th><?= $isSupervisorRequest ? 'Items' : 'HR items' ?></th><th>Total estimate</th><th>Status</th><th>Submitted</th></tr></thead>
                        <tbody>
                        <?php if (!$purchaseOrders): ?>
                            <tr><td colspan="6" class="po-muted"><?= $canSubmit ? 'You have not submitted any purchase requests yet.' : 'The selected Supervisor has not submitted any purchase requests yet.' ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($purchaseOrders as $order): ?>
                                <?php $itemDetails->execute([(int) $order['id']]); $orderItems = $itemDetails->fetchAll(); ?>
                                <tr>
                                    <td><strong><?= clean($order['po_number']) ?></strong>
                                        <ul class="po-item-details"><?php foreach ($orderItems as $orderItem): ?><li><?= clean($orderItem['item_name']) ?> — <?= (int) $orderItem['quantity_ordered'] ?> × <?= peso($orderItem['unit_cost']) ?></li><?php endforeach; ?></ul>
                                        <?php if ($order['status'] === 'rejected' && !empty($order['rejection_reason'])): ?><div class="po-muted" style="margin-top:6px;">Finance note: <?= clean($order['rejection_reason']) ?></div><?php endif; ?>
                                    </td>
                                    <td><?= clean($order['company_name']) ?></td>
                                    <td><?= (int) $order['item_count'] ?></td>
                                    <td><?= peso($order['total_amount']) ?></td>
                                    <?php
                                    $requestStatusLabels = [
                                        'pending_approval' => 'Awaiting Finance approval',
                                        'pending_supplier' => 'Finance signed — awaiting supplier e-signature',
                                        'ordered' => 'Supplier approved — Admin receiving',
                                        'partially_received' => 'Partially received — received amount is in Finance payables',
                                        'received' => 'Received — payable is in Finance Accounts Payable',
                                    ];
                                    ?>
                                    <td><span class="po-status po-status--<?= clean($order['status']) ?>"><?= clean($requestStatusLabels[$order['status']] ?? ucwords(str_replace('_', ' ', $order['status']))) ?></span></td>
                                    <td><?= clean(date('M d, Y', strtotime($order['submitted_at'] ?? $order['created_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</div>
<template id="poItemTemplate">
    <div class="po-item-row">
        <label class="po-field"><?= $isSupervisorRequest ? 'Supervisor request item' : 'HR request item' ?>
            <select name="item_type[]" data-other-value="<?= clean($otherItemType) ?>" required>
                <option value="">Choose a <?= strtolower($requestRoleLabel) ?> item</option>
                <?php foreach ($requestableItems as $category => $items): ?>
                    <optgroup label="<?= clean($category) ?>">
                        <?php foreach ($items as $itemName): ?>
                            <option value="<?= clean($itemName) ?>"><?= clean($itemName) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
                <option value="<?= clean($otherItemType) ?>">Other <?= clean(strtolower($requestRoleLabel)) ?>-related item</option>
            </select>
        </label>
        <label class="po-field other-item-field" hidden>Other item name
            <input name="item_other_name[]" maxlength="150">
        </label>
        <label class="po-field">Specifications / purpose (optional)
            <input name="item_detail[]" maxlength="120" placeholder="Size, type, or why HR needs it">
        </label>
        <label class="po-field">Quantity
            <input name="item_quantity[]" type="number" min="1" step="1" required>
        </label>
        <label class="po-field">Estimated unit cost
            <input name="item_unit_cost[]" type="number" min="0" step="0.01" required>
        </label>
        <button type="button" class="po-button po-button--remove removeItem">Remove</button>
    </div>
</template>
<script>
(() => {
    const rows = document.getElementById('poItemRows');
    const template = document.getElementById('poItemTemplate');
    const form = document.getElementById('hrPoForm');
    if (!rows || !template || !form) return;

    const addRow = () => {
        const fragment = template.content.cloneNode(true);
        const row = fragment.querySelector('.po-item-row');
        const type = row.querySelector('[name="item_type[]"]');
        const otherField = row.querySelector('.other-item-field');
        const otherName = row.querySelector('[name="item_other_name[]"]');
        type.addEventListener('change', () => {
            const isOther = type.value === type.dataset.otherValue;
            otherField.hidden = !isOther;
            otherName.required = isOther;
        });
        row.querySelector('.removeItem').addEventListener('click', () => row.remove());
        rows.appendChild(fragment);
    };
    document.getElementById('addItem').addEventListener('click', addRow);
    addRow();

    const canvas = document.getElementById('requesterSignaturePad');
    const context = canvas.getContext('2d');
    const signatureInput = document.getElementById('requesterSignature');
    const signatureError = document.getElementById('requesterSignatureError');
    let drawing = false;
    let signed = false;
    context.strokeStyle = '#123c5c';
    context.lineWidth = 4;
    context.lineCap = 'round';
    const point = event => {
        const rect = canvas.getBoundingClientRect();
        return {
            x: (event.clientX - rect.left) * canvas.width / rect.width,
            y: (event.clientY - rect.top) * canvas.height / rect.height
        };
    };
    canvas.addEventListener('pointerdown', event => {
        event.preventDefault();
        drawing = true;
        signed = true;
        canvas.setPointerCapture(event.pointerId);
        const position = point(event);
        context.beginPath();
        context.moveTo(position.x, position.y);
    });
    canvas.addEventListener('pointermove', event => {
        if (!drawing) return;
        event.preventDefault();
        const position = point(event);
        context.lineTo(position.x, position.y);
        context.stroke();
        signatureInput.value = canvas.toDataURL('image/png');
        signatureError.hidden = true;
    });
    const stopDrawing = () => { drawing = false; };
    canvas.addEventListener('pointerup', stopDrawing);
    canvas.addEventListener('pointercancel', stopDrawing);
    document.getElementById('clearRequesterSignature').addEventListener('click', () => {
        context.clearRect(0, 0, canvas.width, canvas.height);
        signatureInput.value = '';
        signed = false;
    });
    form.addEventListener('submit', event => {
        if (!signed || !signatureInput.value) {
            event.preventDefault();
            signatureError.hidden = false;
            canvas.focus();
        }
    });
})();
</script>
<?php include __DIR__ . '/includes/sweetalert.php'; ?>
</body>
</html>
