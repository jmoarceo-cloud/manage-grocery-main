<?php
// ============================================================
// purchase_orders.php — Purchase Order (PO) module.
//
// Status flow (see ensurePurchaseOrdersTables() in includes/config.php
// for the full column list):
//
//   draft -> pending_approval -> approved -> ordered -> partially_received -> received
//          (rejected, by Finance)                     (cancelled, by Admin/Inventory)
//
// Who does what (deliberately separated so no one role can skip a
// step or do another department's job):
//   - HR Staff: submit a department purchase request directly to Finance
//     from HRMS; every request starts Pending Approval.
//   - Admin / Inventory Staff: create the Draft for a department, pick the supplier
//     and items, submit for approval, mark it Ordered once approved,
//     and Receive the delivery (the ONLY step that ever touches
//     products.stock / product_batches — see finance.php).
//   - Restock requests: Finance approval creates the linked approved PO;
//     supplier e-signature moves it directly to Ordered, then Admin or
//     Inventory Staff receives it.
//   - Finance (+ Admin): approves or rejects the department PO's total cost —
//     see the finance_approve_po / finance_reject_po actions in
//     finance.php. Finance may export PO reports, but never creates,
//     edits, orders, receives, or cancels a PO here.
// ============================================================

require_once 'includes/config.php';
require_once 'includes/export.php';
requireLogin();

$__role = $_SESSION['role'] ?? '';
if (!in_array($__role, ['admin', 'inventory_staff', 'finance'], true)) {
    redirectToRoleHome();
}

// Finance can view (and approve/reject from finance.php) but never
// create, submit, order, receive, or cancel a PO from this page.
$canManage = in_array($__role, ['admin', 'inventory_staff'], true);
$restockOrderExists = "EXISTS (
    SELECT 1 FROM purchase_order_items restock_poi
    WHERE restock_poi.po_id = po.id AND restock_poi.inventory_request_id IS NOT NULL
)";
$hrmsPdo = $hrmsPdo ?? null;
$departmentOptions = [];
$poDepartment = 'Unassigned';
if ($hrmsPdo) {
    try {
        $departmentOptions = $hrmsPdo->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department <> '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
        $employeeDepartmentStmt = $hrmsPdo->prepare("SELECT department FROM employees WHERE employee_id = ? LIMIT 1");
        $employeeDepartmentStmt->execute([(int) ($_SESSION['employee_id'] ?? 0)]);
        $assignedDepartment = trim((string) $employeeDepartmentStmt->fetchColumn());
        if ($assignedDepartment !== '') {
            $poDepartment = $assignedDepartment;
        }
    } catch (PDOException $e) {
        error_log("Purchase order department lookup skipped: " . $e->getMessage());
    }
}

$success_message = $_SESSION['flash_success'] ?? '';
$error_message   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// =====================================================
// HANDLE FORM SUBMISSIONS
// =====================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $isExportRequest = isset($_GET['export']) && in_array($_GET['export'], ['xlsx', 'pdf'], true);
    $isDetailExportRequest = isset($_GET['export_po']) && in_array($_GET['export_po'], ['xlsx', 'pdf'], true);
    $isFinanceExportRequest = $__role === 'finance'
        && ($isExportRequest || $isDetailExportRequest)
        && !isset($_POST['action']);
    if (!$canManage && !$isFinanceExportRequest) {
        $_SESSION['flash_error'] = "You don't have permission to do that.";
        redirect('purchase_orders.php');
    }
    requireCSRF();
    $action = $_POST['action'] ?? '';

    // -------------------------------------------------
    // CREATE (always starts as Draft)
    // -------------------------------------------------
    if ($action == 'create') {
        $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
        $department = $__role === 'inventory_staff' ? $poDepartment : trim((string) ($_POST['department'] ?? 'Unassigned'));
        if ($department === '' || ($__role === 'inventory_staff' && $department === 'Unassigned')) {
            $department = $poDepartment;
        }
        if ($__role === 'inventory_staff' && $poDepartment === 'Unassigned') {
            $error_message = 'Your account has no assigned HRMS department. Ask Admin to update your employee record before creating a Purchase Order.';
        }
        if ($__role === 'admin' && !in_array($department, $departmentOptions, true)) {
            $department = 'Unassigned';
        }
        $notes       = trim($_POST['notes'] ?? '');
        $item_names  = $_POST['item_name'] ?? [];
        $item_pids   = $_POST['item_product_id'] ?? [];
        $item_qtys   = $_POST['item_quantity'] ?? [];
        $item_costs  = $_POST['item_unit_cost'] ?? [];
        $submitNow   = ($_POST['submit_now'] ?? '') === '1';

        // Validate supplier
        $supStmt = $pdo->prepare("SELECT id, company_name FROM suppliers WHERE id = ? AND status = 'active'");
        $supStmt->execute([$supplier_id]);
        $supplier = $supStmt->fetch();

        // Build + validate item rows
        $items = [];
        $rowCount = max(count($item_names), count($item_qtys));
        for ($i = 0; $i < $rowCount; $i++) {
            $name = trim($item_names[$i] ?? '');
            $pid  = trim((string) ($item_pids[$i] ?? ''));
            $qty  = (int) ($item_qtys[$i] ?? 0);
            $cost = trim((string) ($item_costs[$i] ?? ''));
            // A completely empty row (blank name, 0 qty) is just an
            // unused template row — skip it silently.
            if ($name === '' && $qty <= 0 && $cost === '') continue;

            $items[] = [
                'product_id' => $pid !== '' ? (int) $pid : null,
                'item_name'  => $name,
                'quantity'   => $qty,
                'unit_cost'  => $cost,
            ];
        }

        if (!$supplier) {
            $error_message = "Please select a valid, active supplier.";
        } elseif (empty($items)) {
            $error_message = "Please add at least one item to the Purchase Order.";
        } else {
            foreach ($items as $it) {
                if ($it['item_name'] === '') {
                    $error_message = "Every item needs a name (pick a product or type one in).";
                    break;
                }
                if ($it['quantity'] <= 0) {
                    $error_message = "Quantity must be a whole number greater than 0 for \"" . $it['item_name'] . "\".";
                    break;
                }
                if ($it['unit_cost'] === '' || !is_numeric($it['unit_cost']) || (float) $it['unit_cost'] < 0) {
                    $error_message = "Please enter a valid unit cost for \"" . $it['item_name'] . "\".";
                    break;
                }
            }
        }

        if (empty($error_message)) {
            try {
                $pdo->beginTransaction();

                $pdo->prepare("
                    INSERT INTO purchase_orders (supplier_id, department, status, notes, created_by, created_by_name, submitted_at)
                    VALUES (?, ?, 'draft', ?, ?, ?, NULL)
                ")->execute([
                    $supplier_id,
                    $department,
                    $notes ?: null,
                    currentPosUserId(),
                    $_SESSION['full_name'] ?? null,
                ]);
                $po_id = (int) $pdo->lastInsertId();
                assignPoNumber($pdo, $po_id);

                $itemStmt = $pdo->prepare("
                    INSERT INTO purchase_order_items (po_id, product_id, item_name, quantity_ordered, unit_cost, subtotal)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                foreach ($items as $it) {
                    $subtotal = round((float) $it['unit_cost'] * $it['quantity'], 2);
                    $itemStmt->execute([$po_id, $it['product_id'], $it['item_name'], $it['quantity'], (float) $it['unit_cost'], $subtotal]);
                }

                if ($submitNow) {
                    $pdo->prepare("UPDATE purchase_orders SET status = 'pending_approval', submitted_at = NOW() WHERE id = ?")->execute([$po_id]);
                }

                $pdo->commit();
                $_SESSION['flash_success'] = $submitNow
                    ? "Purchase Order created and submitted for Finance approval."
                    : "Purchase Order saved as Draft.";
                redirect('purchase_orders.php?po=' . enc_id($po_id));
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Create PO error: " . $e->getMessage());
                $error_message = "Could not save the Purchase Order. Please try again.";
            }
        }
        if (!empty($error_message)) {
            $_SESSION['flash_error'] = $error_message;
            redirect('purchase_orders.php?action=create');
        }
    }

    // -------------------------------------------------
    // SUBMIT FOR APPROVAL (Draft -> Pending Approval)
    // -------------------------------------------------
    elseif ($action == 'submit_for_approval') {
        $po_id = (int) ($_POST['po_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ? AND (? <> 'inventory_staff' OR department = ?)");
        $stmt->execute([$po_id, $__role, $poDepartment]);
        $po = $stmt->fetch();

        if (!$po) {
            $_SESSION['flash_error'] = "Purchase Order not found.";
        } elseif ($po['status'] !== 'draft') {
            $_SESSION['flash_error'] = "Only a Draft can be submitted for approval.";
        } else {
            $pdo->prepare("UPDATE purchase_orders SET status = 'pending_approval', submitted_at = NOW() WHERE id = ?")->execute([$po_id]);
            $_SESSION['flash_success'] = "Submitted for Finance approval.";
        }
        redirect('purchase_orders.php?po=' . enc_id($po_id));
    }

    // -------------------------------------------------
    // MARK AS ORDERED (Approved -> Ordered)
    // -------------------------------------------------
    elseif ($action == 'mark_ordered') {
        $po_id = (int) ($_POST['po_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT po.*,
                EXISTS(SELECT 1 FROM purchase_order_items poi WHERE poi.po_id = po.id AND poi.inventory_request_id IS NOT NULL) AS is_restock_order
            FROM purchase_orders po
            WHERE po.id = ? AND (? <> 'inventory_staff' OR (po.requested_by_hrms_user_id IS NULL AND po.department = ?))
        ");
        $stmt->execute([$po_id, $__role, $poDepartment]);
        $po = $stmt->fetch();

        if (!$po) {
            $_SESSION['flash_error'] = "Purchase Order not found.";
        } elseif (!empty($po['requested_by_hrms_user_id']) && $__role !== 'admin') {
            $_SESSION['flash_error'] = "HR and Supervisor Purchase Orders move to Admin only after the supplier e-signs the Formality Letter.";
        } elseif ((int) $po['is_restock_order'] === 1) {
            $_SESSION['flash_error'] = "Restock Purchase Orders move to Orders automatically after the supplier e-signs the Finance-approved order.";
        } elseif ($po['status'] !== 'approved') {
            $_SESSION['flash_error'] = "Only an Approved Purchase Order can be marked as Ordered.";
        } else {
            $pdo->prepare("UPDATE purchase_orders SET status = 'ordered', ordered_at = NOW() WHERE id = ?")->execute([$po_id]);
            $_SESSION['flash_success'] = "Marked as Ordered. Once the delivery arrives, use \"Receive Items\" below to update stock.";
        }
        redirect('purchase_orders.php?po=' . enc_id($po_id));
    }

    // -------------------------------------------------
    // RECEIVE ITEMS (Ordered / Partially Received -> Partially Received / Received)
    // — the ONLY action that ever touches products.stock.
    // -------------------------------------------------
    elseif ($action == 'receive') {
        $po_id = (int) ($_POST['po_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT po.* FROM purchase_orders po
            WHERE po.id = ?
              AND (? <> 'inventory_staff' OR (po.requested_by_hrms_user_id IS NULL AND (po.department = ? OR $restockOrderExists)))
        ");
        $stmt->execute([$po_id, $__role, $poDepartment]);
        $po = $stmt->fetch();

        $receiveQtys = $_POST['receive_qty'] ?? []; // [purchase_order_items.id => qty]

        if (!$po) {
            $_SESSION['flash_error'] = "Purchase Order not found.";
        } elseif (!empty($po['requested_by_hrms_user_id']) && $__role !== 'admin') {
            $_SESSION['flash_error'] = "Only Admin can receive HR and Supervisor Purchase Orders.";
        } elseif (!in_array($po['status'], ['ordered', 'partially_received'], true)) {
            $_SESSION['flash_error'] = "Only an Ordered (or Partially Received) Purchase Order can be received.";
        } else {
            $receipts = [];
            foreach ($receiveQtys as $itemId => $qty) {
                $receipts[(int) $itemId] = (int) $qty;
            }
            $result = recordPurchaseOrderReceipt($pdo, $po_id, $receipts, currentPosUserId(), 'Purchase Order receiving', [
                'note' => trim((string) ($_POST['receipt_note'] ?? '')) ?: null,
                'received_by_name' => $_SESSION['full_name'] ?? null,
            ]);
            if ($result['ok']) {
                $_SESSION['flash_success'] = $result['status'] === 'received'
                    ? "All items received. Stock, Accounts Payable, and the system-generated receipt have been recorded. Finance has been notified to record the supplier invoice before payment."
                    : "Received items recorded. Stock, Accounts Payable, and the system-generated receipt have been updated; Finance has been notified to record the supplier invoice. Some items are still pending.";
            } else {
                $_SESSION['flash_error'] = $result['message'];
            }
        }
        redirect('purchase_orders.php?po=' . enc_id($po_id));
    }

    // -------------------------------------------------
    // CANCEL — only before any receiving has happened, since stock
    // may have already moved once receiving starts.
    // -------------------------------------------------
    elseif ($action == 'cancel') {
        $po_id = (int) ($_POST['po_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ? AND (? <> 'inventory_staff' OR department = ?)");
        $stmt->execute([$po_id, $__role, $poDepartment]);
        $po = $stmt->fetch();

        if (!$po) {
            $_SESSION['flash_error'] = "Purchase Order not found.";
        } elseif (!in_array($po['status'], ['draft', 'pending_approval', 'approved', 'ordered'], true)) {
            $_SESSION['flash_error'] = "This Purchase Order can no longer be cancelled (items have already been received).";
        } else {
            $pdo->prepare("UPDATE purchase_orders SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW() WHERE id = ?")
                ->execute([currentPosUserId(), $po_id]);
            $_SESSION['flash_success'] = "Purchase Order cancelled.";
        }
        redirect('purchase_orders.php?po=' . enc_id($po_id));
    }
}

// =====================================================
// SHOW CREATE FORM?
// =====================================================
$show_create_form = $canManage && isset($_GET['action']) && $_GET['action'] == 'create';
$active_suppliers  = $canManage ? getActiveSuppliers($pdo) : [];
$active_products   = [];
if ($canManage) {
    try {
        $active_products = $pdo->query("SELECT id, product_name, cost_price, supplier_id FROM products WHERE is_active = 1 ORDER BY product_name ASC")->fetchAll();
    } catch (Exception $e) {}
}

// =====================================================
// SHOW A SINGLE PO'S DETAIL?
// =====================================================
$po_detail = null;
$po_items  = [];
$po_receipts = [];
if (isset($_GET['po'])) {
    $stmt = $pdo->prepare("
        SELECT po.*, s.company_name, s.contact_person, s.contact_number, s.email AS supplier_email
            , (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.po_id = po.id AND poi.inventory_request_id IS NOT NULL) AS restock_request_count
            , (SELECT COUNT(*) FROM purchase_order_items poi JOIN inventory_requests ir ON ir.request_id = poi.inventory_request_id WHERE poi.po_id = po.id AND ir.supplier_signature IS NOT NULL) AS supplier_signed_count
            , (SELECT MAX(ir.supplier_signature_at) FROM purchase_order_items poi JOIN inventory_requests ir ON ir.request_id = poi.inventory_request_id WHERE poi.po_id = po.id) AS supplier_signed_at
        FROM purchase_orders po
        JOIN suppliers s ON s.id = po.supplier_id
        WHERE po.id = ? " . ($__role === 'inventory_staff' ? "AND po.requested_by_hrms_user_id IS NULL AND (po.department = ? OR $restockOrderExists)" : "") . "
    ");
    $detailParams = [dec_id($_GET['po'])];
    if ($__role === 'inventory_staff') {
        $detailParams[] = $poDepartment;
    }
    $stmt->execute($detailParams);
    $po_detail = $stmt->fetch();

    if ($po_detail) {
        $itemStmt = $pdo->prepare("SELECT * FROM purchase_order_items WHERE po_id = ? ORDER BY id ASC");
        $itemStmt->execute([$po_detail['id']]);
        $po_items = $itemStmt->fetchAll();
        $receiptStmt = $pdo->prepare("SELECT * FROM purchase_order_receipts WHERE po_id = ? ORDER BY received_at DESC, id DESC");
        $receiptStmt->execute([$po_detail['id']]);
        $po_receipts = $receiptStmt->fetchAll();
    }
}

// =====================================================
// LIST + STATUS FILTER
// =====================================================
$status_labels = [
    'draft'              => 'Draft',
    'pending_approval'   => 'Pending Approval',
    'approved'           => 'Approved',
    'pending_supplier'   => 'Awaiting Supplier E-signature',
    'rejected'           => 'Rejected',
    'ordered'            => 'Ordered',
    'partially_received' => 'Partially Received',
    'received'           => 'Received',
    'cancelled'          => 'Cancelled',
];
$status_colors = [
    'draft'              => 'neutral',
    'pending_approval'   => 'mustard',
    'approved'           => 'green',
    'pending_supplier'   => 'mustard',
    'rejected'           => 'red',
    'ordered'            => 'mustard',
    'partially_received' => 'mustard',
    'received'           => 'green',
    'cancelled'          => 'red',
];

$filter_status = trim($_GET['status'] ?? '');
$search        = trim($_GET['search'] ?? '');
$date_from = trim((string) ($_GET['date_from'] ?? ''));
$date_to = trim((string) ($_GET['date_to'] ?? ''));
foreach (['date_from', 'date_to'] as $dateField) {
    if ($$dateField !== '') {
        $dateObject = DateTime::createFromFormat('!Y-m-d', $$dateField);
        if (!$dateObject || $dateObject->format('Y-m-d') !== $$dateField) {
            $$dateField = '';
        }
    }
}
if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
    $date_from = $date_to;
}
$poReportScope = ($filter_status !== '' ? 'Status: ' . ($status_labels[$filter_status] ?? $filter_status) : 'All statuses')
    . ($search !== '' ? ' | Search: ' . $search : '')
    . (($date_from || $date_to) ? ' | Date: ' . ($date_from ?: '-') . ' to ' . ($date_to ?: '-') : '');

$where  = [];
$params = [];
if ($__role === 'inventory_staff') {
    $where[] = "po.requested_by_hrms_user_id IS NULL AND (po.department = ? OR $restockOrderExists)";
    $params[] = $poDepartment;
}
if ($filter_status !== '' && isset($status_labels[$filter_status])) {
    $where[]  = "po.status = ?";
    $params[] = $filter_status;
}
if ($search !== '') {
    $where[]  = "(po.po_number LIKE ? OR s.company_name LIKE ?)";
    $like     = '%' . $search . '%';
    $params[] = $like; $params[] = $like;
}
if ($date_from !== '') {
    $where[] = "DATE(po.created_at) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where[] = "DATE(po.created_at) <= ?";
    $params[] = $date_to;
}
$whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

$listStmt = $pdo->prepare("
    SELECT po.*, s.company_name,
        (SELECT COALESCE(SUM(subtotal), 0) FROM purchase_order_items WHERE po_id = po.id) AS total_amount,
        (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.id) AS item_count,
        (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.po_id = po.id AND poi.inventory_request_id IS NOT NULL) AS restock_request_count,
        (SELECT COUNT(*) FROM purchase_order_items poi JOIN inventory_requests ir ON ir.request_id = poi.inventory_request_id WHERE poi.po_id = po.id AND ir.supplier_signature IS NOT NULL) AS supplier_signed_count
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    $whereSql
    ORDER BY po.created_at DESC
    LIMIT 200
");
$listStmt->execute($params);
$purchase_orders = $listStmt->fetchAll();

// Keep approved commitments separate from every PO that is not approved.
// Only approved, ordered, partially received, and received POs count toward
// the approved overall total; drafts, pending approvals, rejections, and
// cancellations remain visible in a separate excluded section.
// Role scope reminder: ? <> 'inventory_staff' OR po.department = ? OR $restockOrderExists
$approvedPoStatuses = ['approved', 'ordered', 'partially_received', 'received'];
$approvedPurchaseOrders = [];
$notApprovedPurchaseOrders = [];
$approvedTotal = 0.0;
$notApprovedTotal = 0.0;
foreach ($purchase_orders as $po) {
    $amount = (float) $po['total_amount'];
    if (in_array($po['status'], $approvedPoStatuses, true)) {
        $approvedPurchaseOrders[] = $po;
        $approvedTotal += $amount;
    } else {
        $notApprovedPurchaseOrders[] = $po;
        $notApprovedTotal += $amount;
    }
}

// --- Export the (filtered) PO list — same $where/$params as the list
// above, so an export never contains rows the on-screen table
// wouldn't also show for this role.
if (isset($_GET['export']) && in_array($_GET['export'], ['xlsx', 'pdf'], true)) {
    $__poHeaders = ['PO #', 'Department', 'Supplier', 'Status', 'Items', 'Total (PHP)', 'Created By', 'Created At'];
    $__poRows = [];
    foreach ([
        ['APPROVED', $approvedPurchaseOrders, $approvedTotal],
        ['NOT APPROVED (EXCLUDED)', $notApprovedPurchaseOrders, $notApprovedTotal],
    ] as [$groupLabel, $sectionOrders, $sectionTotal]) {
        $__poRows[] = [$groupLabel, '', '', '', '', '', '', ''];
        foreach ($sectionOrders as $po) {
            $__poRows[] = [
                $po['po_number'],
                $po['department'] ?? 'Unassigned',
                $po['company_name'],
                $status_labels[$po['status']] ?? $po['status'],
                (int) $po['item_count'],
                round((float) $po['total_amount'], 2),
                $po['created_by_name'] ?? '',
                $po['created_at'],
            ];
        }
        $__poRows[] = [$groupLabel . ' SUBTOTAL', '', '', '', count($sectionOrders), round($sectionTotal, 2), '', ''];
    }
    $__poMeta = [
        'Purchase Orders',
        'Overall approved total (approved, ordered, partially received, received): PHP ' . number_format($approvedTotal, 2),
        'Approved purchase orders: ' . count($approvedPurchaseOrders),
        'Not approved total (excluded from approved total): PHP ' . number_format($notApprovedTotal, 2),
        'Not approved purchase orders: ' . count($notApprovedPurchaseOrders),
        ($filter_status !== '' ? 'Status: ' . ($status_labels[$filter_status] ?? $filter_status) : 'All statuses')
            . ($search !== '' ? ' | Search: ' . $search : '')
            . (($date_from || $date_to) ? ' | Date: ' . ($date_from ?: '-') . ' to ' . ($date_to ?: '-') : ''),
    ];

    if ($_GET['export'] === 'xlsx') {
        exportToExcel('purchase_orders', $__poHeaders, $__poRows, $__poMeta);
    } else {
        exportToPDF('purchase_orders', 'Purchase Orders', $__poHeaders, $__poRows, $__poMeta);
    }
}

// --- Export a single PO's detail (the view someone lands on via
// ?po=...) as a formal-looking one-page PDF/Excel, e.g. to email to
// the supplier or file for records.
if ($po_detail && isset($_GET['export_po']) && in_array($_GET['export_po'], ['xlsx', 'pdf'], true)) {
    $__poiHeaders = ['Item', 'Quantity', 'Unit Cost', 'Subtotal'];
    $__poiRows = [];
    foreach ($po_items as $it) {
        $__poiRows[] = [
            $it['item_name'],
            $it['quantity'],
            number_format((float) $it['unit_cost'], 2),
            number_format((float) $it['subtotal'], 2),
        ];
    }
    $__poiMeta = [
        'Purchase Order: ' . $po_detail['po_number'],
        'Supplier: ' . $po_detail['company_name'] . (!empty($po_detail['contact_person']) ? ' (' . $po_detail['contact_person'] . ')' : ''),
        'Department: ' . ($po_detail['department'] ?? 'Unassigned'),
        'Status: ' . ($status_labels[$po_detail['status']] ?? $po_detail['status']),
        'Created: ' . $po_detail['created_at'],
        'Submitted: ' . ($po_detail['submitted_at'] ?? '—'),
        'Approved: ' . ($po_detail['approved_at'] ?? '—'),
        'Ordered: ' . ($po_detail['ordered_at'] ?? '—'),
        'Received: ' . ($po_detail['received_at'] ?? '—'),
        'Total: ₱' . number_format((float) array_sum(array_column($po_items, 'subtotal')), 2),
    ];

    if ($_GET['export_po'] === 'xlsx') {
        exportToExcel('po_' . $po_detail['po_number'], $__poiHeaders, $__poiRows, $__poiMeta);
    } else {
        exportToPDF('po_' . $po_detail['po_number'], 'Purchase Order ' . $po_detail['po_number'], $__poiHeaders, $__poiRows, $__poiMeta);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Purchase Orders — HCI GroceryOne</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.po-status-badge {
    display: inline-flex;
    align-items: center;
    font-size: 12px;
    font-weight: 700;
    border-radius: 999px;
    padding: 4px 10px;
    white-space: nowrap;
    line-height: 1.5;
}
.po-status-badge--neutral { background: var(--cream); color: var(--ink-soft); }
.po-status-badge--green   { background: var(--green-pale); color: var(--text-accent); }
.po-status-badge--mustard { background: var(--mustard-pale); color: #7A5A1E; }
.po-status-badge--red     { background: var(--red-pale); color: #9c3a23; }
html[data-theme="dark"] .po-status-badge--mustard { color: var(--mustard); }

.po-detail-card {
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    overflow: hidden;
    background: var(--white);
    box-shadow: var(--shadow-card);
}
.po-detail-card .panel-header {
    background: linear-gradient(135deg, #f8fbff, #edf5ff);
    border-bottom: 1px solid var(--line);
}
.po-detail-title {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.po-detail-title h2 {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
    color: var(--text-accent);
}
.po-detail-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.po-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 14px;
}
.po-meta-item {
    background: var(--cream);
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 12px 14px;
    font-size: 13px;
    color: var(--ink);
}
.po-meta-item span.label {
    display: block;
    margin-bottom: 6px;
    color: var(--ink-soft);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.po-detail-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    background: var(--white);
}
.po-detail-table th {
    background: #0d3d5d;
    color: var(--white);
    text-align: left;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
    padding: 14px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.18);
}
.po-detail-table th:last-child,
.po-detail-table td:last-child {
    text-align: right;
}
.po-detail-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--line);
    font-size: 14px;
    color: var(--ink);
}
.po-detail-table tr:last-child td {
    border-bottom: none;
}
.po-detail-table .total-row td {
    background: var(--cream);
    font-weight: 800;
    color: var(--ink);
}
.receipt-preview {
    width: 100%;
    height: min(780px, 78vh);
    min-height: 520px;
    margin-top: 14px;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: #fff;
}
.receipt-history-list {
    display: grid;
    gap: 12px;
}
.receipt-history-card {
    padding: 16px;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: var(--white);
}
.receipt-history-meta {
    display: grid;
    grid-template-columns: minmax(150px, 1fr) minmax(160px, 1fr) minmax(120px, 1fr) auto;
    gap: 14px;
    align-items: center;
}
.receipt-history-value {
    min-width: 0;
    color: var(--ink);
    font-size: 13px;
    overflow-wrap: anywhere;
}
.receipt-history-value .label {
    display: block;
    margin-bottom: 4px;
    color: var(--ink-soft);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .05em;
    text-transform: uppercase;
}
.receipt-history-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
}
.receipt-preview-toggle {
    margin-top: 14px;
    border-top: 1px solid var(--line);
    padding-top: 12px;
}
.receipt-preview-toggle summary {
    display: inline-flex;
    align-items: center;
    min-height: 36px;
    color: var(--text-accent);
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
}
@media(max-width:800px) {
    .receipt-history-meta {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .receipt-history-actions {
        justify-content: flex-start;
    }
}
@media(max-width:520px) {
    .receipt-history-card {
        padding: 12px;
    }
    .receipt-history-meta {
        grid-template-columns: 1fr;
        gap: 10px;
    }
    .receipt-preview {
        min-height: 420px;
        height: 65vh;
    }
}

.filter-pills { display: flex; gap: 6px; flex-wrap: wrap; }
.filter-pill {
    padding: 7px 13px;
    border-radius: 999px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--ink-soft);
    text-decoration: none;
    border: 1.5px solid var(--line);
}
.filter-pill.is-active { background: var(--green-pale); color: var(--text-accent); border-color: var(--green-mid); }

.po-items-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
.po-items-table th, .po-items-table td { text-align: left; padding: 9px 12px; border-bottom: 1px solid var(--line); font-size: 13.5px; }
.po-items-table th { color: var(--ink-soft); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .02em; }
.po-items-table input.receive-input { width: 90px; padding: 7px 9px; border: 1.5px solid var(--line); border-radius: 8px; font-size: 13.5px; }

.po-meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 4px; }
.po-meta-item { font-size: 13px; }
.po-meta-item span.label { display:block; color: var(--ink-soft); font-size: 11.5px; text-transform: uppercase; letter-spacing:.03em; margin-bottom: 2px; }

.item-row-grid {
    display: grid;
    grid-template-columns: 2.2fr 1fr 1fr 1fr auto;
    gap: 10px;
    align-items: end;
    margin-bottom: 10px;
}
@media (max-width: 900px) { .item-row-grid { grid-template-columns: 1fr 1fr; } }
@media print {
    .sidebar, .topbar, .po-detail-actions, .action-btn, .link-btn, form, .toolbar,
    .filter-pills { display: none !important; }
    .main-area { margin: 0 !important; padding: 0 !important; }
    .content { box-shadow: none !important; border: 0 !important; padding: 0 !important; }
    .po-print-report { display: block !important; border: 0 !important; box-shadow: none !important; padding: 0 !important; }
    .po-list-page .content > .panel:not(.po-print-report) { display: none !important; }
    .po-detail-page .po-detail-card { display: block !important; box-shadow: none !important; border: 0 !important; }
    .po-print-report h1 { margin: 0 0 6px; color: #123C5C !important; font-size: 20px; }
    .po-print-report .po-print-meta { margin-bottom: 14px; color: #475569; font-size: 10px; }
    .po-print-report h2 { margin: 14px 0 6px; color: #123C5C !important; font-size: 13px; }
    .po-print-report table { width: 100%; border-collapse: collapse; font-size: 9px; }
    .po-print-report th, .po-print-report td { border: 1px solid #cbd5e1; padding: 5px; text-align: left; }
    .po-print-report th { background: #eaf0f6 !important; color: #123C5C !important; }
    .po-print-report .po-print-total td { font-weight: 700; background: #f1f5f9 !important; }
}
.po-print-report { display:none; }
</style>
</head>
<body class="<?= $po_detail ? 'po-detail-page' : 'po-list-page' ?>">
<div class="app-shell">

    <!-- SIDEBAR -->
    <?php $active = 'purchase_orders'; include 'includes/sidebar.php'; ?>

    <!-- MAIN -->
    <div class="main-area">

        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_purchase_orders ?></div>
            <h1>Purchase Orders</h1>
            <div class="topbar-user">
                <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'A') ?><?php endif; ?></span>
                <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'User') ?></span>
            </div>
        </header>

        <main class="content">

            <?php if (!empty($success_message)): ?>
                <script>
                Swal.fire({ icon: 'success', title: <?= json_encode(strip_tags($success_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 2800, timerProgressBar: true });
                </script>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <script>
                Swal.fire({ icon: 'error', title: <?= json_encode(clean($error_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 3400, timerProgressBar: true });
                </script>
            <?php endif; ?>

            <?php if (!$canManage): ?>
                <div class="alert alert--warning" style="margin-bottom:16px;">
                    View-only: Finance approves or rejects Purchase Orders from the Finance page. Creating, ordering, receiving, and cancelling are handled by Admin / Inventory Staff.
                </div>
            <?php endif; ?>

            <?php if ($po_detail): ?>
                <!-- ================= PO DETAIL VIEW ================= -->
                <div class="panel po-detail-card">
                    <div class="panel-header">
                        <div class="po-detail-title">
                            <h2><?= clean($po_detail['po_number']) ?></h2>
                            <span class="po-status-badge po-status-badge--<?= $status_colors[$po_detail['status']] ?? 'neutral' ?>"><?= clean($status_labels[$po_detail['status']] ?? $po_detail['status']) ?></span>
                        </div>
                        <div class="po-detail-actions">
                            <?= exportPostForm('purchase_orders.php?po=' . rawurlencode((string) $_GET['po']) . '&export_po=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                            <?= exportPostForm('purchase_orders.php?po=' . rawurlencode((string) $_GET['po']) . '&export_po=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
                            <button type="button" class="action-btn action-btn--small action-btn--outline" onclick="window.print()">Print</button>
                            <a href="purchase_orders.php" class="action-btn action-btn--secondary action-btn--small">&larr; Back to list</a>
                        </div>
                    </div>
                    <div class="panel-body">
                        <div class="po-meta-grid">
                            <div class="po-meta-item"><span class="label">Supplier</span><?= clean($po_detail['company_name']) ?></div>
                            <div class="po-meta-item"><span class="label">Department</span><?= clean($po_detail['department'] ?? 'Unassigned') ?></div>
                            <div class="po-meta-item"><span class="label">Supplier Contact</span><?= clean($po_detail['contact_person'] ?: '—') ?><?= !empty($po_detail['contact_number']) ? ' &middot; ' . clean($po_detail['contact_number']) : '' ?></div>
                            <div class="po-meta-item"><span class="label">Created By</span><?= clean($po_detail['created_by_name'] ?? '—') ?> &middot; <?= clean(date('M d, Y', strtotime($po_detail['created_at']))) ?></div>
                            <?php if (!empty($po_detail['submitted_at'])): ?>
                                <div class="po-meta-item"><span class="label">Submitted</span><?= clean(date('M d, Y g:i A', strtotime($po_detail['submitted_at']))) ?></div>
                            <?php endif; ?>
                            <?php if ($po_detail['status'] === 'approved' || $po_detail['status'] === 'ordered' || $po_detail['status'] === 'partially_received' || $po_detail['status'] === 'received'): ?>
                                <div class="po-meta-item"><span class="label">Approved</span><?= !empty($po_detail['approved_at']) ? clean(date('M d, Y g:i A', strtotime($po_detail['approved_at']))) : '—' ?></div>
                            <?php endif; ?>
                            <?php if ((int) ($po_detail['restock_request_count'] ?? 0) > 0): ?>
                                <div class="po-meta-item"><span class="label">Supplier approval</span>
                                    <?php if ((int) $po_detail['supplier_signed_count'] === (int) $po_detail['restock_request_count']): ?>
                                        Approved<?= !empty($po_detail['supplier_signed_at']) ? ' · ' . clean(date('M d, Y g:i A', strtotime($po_detail['supplier_signed_at']))) : '' ?>
                                    <?php else: ?>
                                        Awaiting supplier e-signature
                                    <?php endif; ?>
                                </div>
                                <?php foreach ($po_items as $poItem): ?>
                                    <?php if (!empty($poItem['inventory_request_id'])): ?>
                                        <div class="po-meta-item"><span class="label">Formality Letter</span><a href="procurement_letter.php?request_id=<?= enc_id((int) $poItem['inventory_request_id']) ?>" target="_blank" rel="noopener">View signed request letter</a></div>
                                        <?php break; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="po-meta-item"><span class="label">Inventory request</span>Not linked to an inventory request</div>
                            <?php endif; ?>
                            <?php if (!empty($po_detail['requested_by_hrms_user_id'])): ?>
                                <div class="po-meta-item"><span class="label">Formality Letter</span><a href="purchase_order_letter.php?po_id=<?= rawurlencode(enc_id((int) $po_detail['id'])) ?>" target="_blank" rel="noopener">View signed Formality Letter</a></div>
                            <?php endif; ?>
                            <?php if ($po_detail['status'] === 'rejected'): ?>
                                <div class="po-meta-item"><span class="label">Rejected</span><?= !empty($po_detail['rejected_at']) ? clean(date('M d, Y g:i A', strtotime($po_detail['rejected_at']))) : '—' ?></div>
                            <?php endif; ?>
                            <?php if (!empty($po_detail['ordered_at'])): ?>
                                <div class="po-meta-item"><span class="label">Ordered</span><?= clean(date('M d, Y g:i A', strtotime($po_detail['ordered_at']))) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($po_detail['received_at'])): ?>
                                <div class="po-meta-item"><span class="label">Fully Received</span><?= clean(date('M d, Y g:i A', strtotime($po_detail['received_at']))) ?></div>
                            <?php endif; ?>
                            <?php if ($po_detail['status'] === 'cancelled'): ?>
                                <div class="po-meta-item"><span class="label">Cancelled</span><?= !empty($po_detail['cancelled_at']) ? clean(date('M d, Y g:i A', strtotime($po_detail['cancelled_at']))) : '—' ?></div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($po_detail['notes'])): ?>
                            <div class="finance-req-notes" style="margin-top:6px;"><?= nl2br(clean($po_detail['notes'])) ?></div>
                        <?php endif; ?>

                        <?php if ($po_detail['status'] === 'rejected' && !empty($po_detail['rejection_reason'])): ?>
                            <div class="alert alert--error" style="margin-top:12px;">Rejection reason: <?= clean($po_detail['rejection_reason']) ?></div>
                        <?php endif; ?>

                        <?php if ($canManage && in_array($po_detail['status'], ['ordered', 'partially_received'], true)): ?>
                            <form method="POST" action="purchase_orders.php" id="receiveForm">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="receive">
                                <input type="hidden" name="po_id" value="<?= (int) $po_detail['id'] ?>">
                            </form>
                            <div class="po-meta-grid" style="margin-top:14px;">
                                <div class="po-meta-item"><span class="label">Receipt</span>The system automatically generates a receipt from the quantities you record.</div>
                                <label class="po-meta-item"><span class="label">Receipt note (optional)</span>
                                    <input type="text" name="receipt_note" form="receiveForm" maxlength="500" class="field-input">
                                </label>
                            </div>
                        <?php endif; ?>

                        <table class="po-detail-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Ordered Qty</th>
                                    <th>Received Qty</th>
                                    <th>Unit Cost</th>
                                    <th>Subtotal</th>
                                    <?php if ($canManage && in_array($po_detail['status'], ['ordered', 'partially_received'], true)): ?>
                                        <th>Receive Now</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $grand_total = 0; ?>
                                <?php foreach ($po_items as $item): ?>
                                    <?php
                                        $remaining = (int) $item['quantity_ordered'] - (int) $item['quantity_received'];
                                        $grand_total += (float) $item['subtotal'];
                                    ?>
                                    <tr>
                                        <td><?= clean($item['item_name']) ?><?= empty($item['product_id']) ? ' <span style="font-size:11.5px;color:var(--ink-soft)">(not yet in catalog)</span>' : '' ?>
                                            <?php if (!empty($item['inventory_request_id'])): ?><span style="display:block;font-size:11px;color:var(--ink-soft);">Restock request #<?= (int) $item['inventory_request_id'] ?></span><?php endif; ?>
                                        </td>
                                        <td><?= (int) $item['quantity_ordered'] ?></td>
                                        <td><?= (int) $item['quantity_received'] ?></td>
                                        <td><?= peso($item['unit_cost']) ?></td>
                                        <td><?= peso($item['subtotal']) ?></td>
                                        <?php if ($canManage && in_array($po_detail['status'], ['ordered', 'partially_received'], true)): ?>
                                            <td>
                                                <?php if ($remaining > 0): ?>
                                                    <input type="number" class="receive-input" form="receiveForm" name="receive_qty[<?= (int) $item['id'] ?>]" min="0" max="<?= $remaining ?>" placeholder="0 / <?= $remaining ?>">
                                                <?php else: ?>
                                                    <span style="color:var(--green-mid); font-weight:700; font-size:12.5px;">✓ Complete</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="total-row">
                                    <td colspan="4" style="text-align:right; font-weight:700;">Total</td>
                                    <td style="font-weight:700;"><?= peso($grand_total) ?></td>
                                    <?php if ($canManage && in_array($po_detail['status'], ['ordered', 'partially_received'], true)): ?><td></td><?php endif; ?>
                                </tr>
                            </tbody>
                        </table>

                        <div class="panel" style="margin-top:18px;">
                            <div class="panel-header"><h3 style="margin:0;">Receipt History</h3></div>
                            <div class="panel-body">
                                <?php if (!$po_receipts): ?>
                                    <p style="color:var(--ink-soft); margin:0;">No delivery receipts have been recorded yet.</p>
                                <?php else: ?>
                                    <div class="receipt-history-list">
                                        <?php foreach ($po_receipts as $receiptIndex => $receipt): ?>
                                            <article class="receipt-history-card">
                                                <div class="receipt-history-meta">
                                                    <div class="receipt-history-value">
                                                        <span class="label">Received at</span>
                                                        <?= clean(date('M d, Y g:i A', strtotime($receipt['received_at']))) ?>
                                                    </div>
                                                    <div class="receipt-history-value">
                                                        <span class="label">Received by</span>
                                                        <?= clean($receipt['received_by_name'] ?: ('User #' . $receipt['received_by'])) ?>
                                                    </div>
                                                    <div class="receipt-history-value">
                                                        <span class="label">Note</span>
                                                        <?= clean($receipt['note'] ?: '—') ?>
                                                    </div>
                                                    <div class="receipt-history-actions">
                                                        <a class="action-btn action-btn--small action-btn--secondary"
                                                           href="purchase_order_receipt.php?id=<?= (int) $receipt['id'] ?>"
                                                           target="_blank" rel="noopener">Open / Print</a>
                                                    </div>
                                                </div>
                                                <?php if (($receipt['receipt_file_path'] ?? '') === 'AUTO_GENERATED'): ?>
                                                    <details class="receipt-preview-toggle" <?= $receiptIndex === 0 ? 'open' : '' ?>>
                                                        <summary><?= $receiptIndex === 0 ? 'Receipt preview' : 'Preview receipt quantities' ?></summary>
                                                        <iframe
                                                            class="receipt-preview"
                                                            src="purchase_order_receipt.php?id=<?= (int) $receipt['id'] ?>"
                                                            title="Goods receiving receipt <?= (int) $receipt['id'] ?>"
                                                            loading="lazy"></iframe>
                                                    </details>
                                                <?php else: ?>
                                                    <p style="margin:12px 0 0;font-size:12px;color:var(--ink-soft);">
                                                        <?= clean($receipt['original_file_name'] ?: 'Uploaded delivery document') ?>
                                                    </p>
                                                <?php endif; ?>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- ACTIONS -->
                        <?php if ($canManage): ?>
                        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:20px;">
                            <?php if ($po_detail['status'] === 'draft'): ?>
                                <form method="POST" action="purchase_orders.php" class="inline-form"
                                      onsubmit="return confirmAction(event, this, {icon:'question', title:'Submit for Approval?', text:'Send this Purchase Order to Finance for approval?', confirmButtonText:'Yes, submit', confirmButtonColor:'#123C5C'});">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="submit_for_approval">
                                    <input type="hidden" name="po_id" value="<?= (int) $po_detail['id'] ?>">
                                    <button type="submit" class="action-btn">Submit for Approval</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($po_detail['status'] === 'approved' && (int) ($po_detail['restock_request_count'] ?? 0) === 0): ?>
                                <form method="POST" action="purchase_orders.php" class="inline-form"
                                      onsubmit="return confirmAction(event, this, {icon:'question', title:'Mark as Ordered?', text:'Confirm that this Purchase Order has been placed with the supplier.', confirmButtonText:'Yes, mark Ordered', confirmButtonColor:'#123C5C'});">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="mark_ordered">
                                    <input type="hidden" name="po_id" value="<?= (int) $po_detail['id'] ?>">
                                    <button type="submit" class="action-btn">Mark as Ordered</button>
                                </form>
                            <?php endif; ?>

                            <?php if (in_array($po_detail['status'], ['ordered', 'partially_received'], true)): ?>
                                <button type="button" class="action-btn" onclick="return confirmReceive();">Receive Items</button>
                            <?php endif; ?>

                            <?php if (in_array($po_detail['status'], ['draft', 'pending_approval', 'approved', 'ordered'], true)): ?>
                                <form method="POST" action="purchase_orders.php" class="inline-form"
                                      onsubmit="return confirmAction(event, this, {icon:'warning', title:'Cancel Purchase Order?', text:'Cancel ' + <?= htmlspecialchars(json_encode((string) $po_detail['po_number'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?> + '? This cannot be undone.', confirmButtonText:'Yes, cancel', confirmButtonColor:'#C8472D'});">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="po_id" value="<?= (int) $po_detail['id'] ?>">
                                    <button type="submit" class="action-btn action-btn--secondary" style="color:var(--red-alert); border-color:var(--red-pale);">Cancel Purchase Order</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>
                <!-- ================= LIST VIEW ================= -->

                <!-- STATUS FILTER PILLS -->
                <div class="filter-pills" style="margin-bottom:14px;">
                    <a href="purchase_orders.php" class="filter-pill <?= $filter_status === '' ? 'is-active' : '' ?>">All</a>
                    <?php foreach ($status_labels as $key => $label): ?>
                        <a href="purchase_orders.php?status=<?= $key ?>" class="filter-pill <?= $filter_status === $key ? 'is-active' : '' ?>"><?= $label ?></a>
                    <?php endforeach; ?>
                </div>

                <div class="toolbar">
                    <form method="GET" action="purchase_orders.php" class="search-form" id="poFilterForm">
                        <?php if ($filter_status !== ''): ?><input type="hidden" name="status" value="<?= clean($filter_status) ?>"><?php endif; ?>
                        <input type="text" name="search" id="poSearchInput" placeholder="Search PO # or supplier..." value="<?= clean($search) ?>" class="field-input" style="width:240px;" autocomplete="off">
                        <label class="field-label" style="margin:0;">From <input type="date" name="date_from" value="<?= clean($date_from) ?>" class="field-input"></label>
                        <label class="field-label" style="margin:0;">To <input type="date" name="date_to" value="<?= clean($date_to) ?>" class="field-input"></label>
                        <button type="submit" class="action-btn action-btn--small">Search</button>
                    </form>
                    <?php if ($canManage): ?>
                        <a href="purchase_orders.php?action=create" class="action-btn">+ Create Purchase Order</a>
                    <?php endif; ?>
                </div>

                <section class="panel po-print-report" aria-label="Printable purchase order summary">
                    <h1>Purchase Order Summary</h1>
                    <div class="po-print-meta">
                        <div><?= clean($poReportScope) ?></div>
                        <div>Generated <?= clean(date('M d, Y g:i A')) ?></div>
                    </div>
                    <h2>Approved Purchase Orders</h2>
                    <p>Overall approved total: <strong>PHP <?= number_format($approvedTotal, 2) ?></strong></p>
                    <table>
                        <thead><tr><th>PO Number</th><th>Department</th><th>Supplier</th><th>Status</th><th>Items</th><th>Total (PHP)</th><th>Created</th></tr></thead>
                        <tbody>
                        <?php if (!$approvedPurchaseOrders): ?>
                            <tr><td colspan="7">No approved purchase orders in this filtered report.</td></tr>
                        <?php else: ?>
                            <?php foreach ($approvedPurchaseOrders as $po): ?>
                                <tr>
                                    <td><?= clean($po['po_number']) ?></td>
                                    <td><?= clean($po['department'] ?? 'Unassigned') ?></td>
                                    <td><?= clean($po['company_name']) ?></td>
                                    <td><?= clean($status_labels[$po['status']] ?? $po['status']) ?></td>
                                    <td><?= (int) $po['item_count'] ?></td>
                                    <td><?= number_format((float) $po['total_amount'], 2) ?></td>
                                    <td><?= clean(date('M d, Y', strtotime($po['created_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="po-print-total"><td colspan="5">Approved total</td><td colspan="2">PHP <?= number_format($approvedTotal, 2) ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <h2>Not Approved - Excluded from Approved Total</h2>
                    <p>Separate total: <strong>PHP <?= number_format($notApprovedTotal, 2) ?></strong></p>
                    <table>
                        <thead><tr><th>PO Number</th><th>Department</th><th>Supplier</th><th>Status</th><th>Items</th><th>Total (PHP)</th><th>Created</th></tr></thead>
                        <tbody>
                        <?php if (!$notApprovedPurchaseOrders): ?>
                            <tr><td colspan="7">No not-approved purchase orders in this filtered report.</td></tr>
                        <?php else: ?>
                            <?php foreach ($notApprovedPurchaseOrders as $po): ?>
                                <tr>
                                    <td><?= clean($po['po_number']) ?></td>
                                    <td><?= clean($po['department'] ?? 'Unassigned') ?></td>
                                    <td><?= clean($po['company_name']) ?></td>
                                    <td><?= clean($status_labels[$po['status']] ?? $po['status']) ?></td>
                                    <td><?= (int) $po['item_count'] ?></td>
                                    <td><?= number_format((float) $po['total_amount'], 2) ?></td>
                                    <td><?= clean(date('M d, Y', strtotime($po['created_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="po-print-total"><td colspan="5">Not approved total (excluded)</td><td colspan="2">PHP <?= number_format($notApprovedTotal, 2) ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </section>

                <!-- CREATE FORM -->
                <?php if ($show_create_form): ?>
                <div class="panel">
                    <div class="panel-header"><h2>Create Purchase Order</h2></div>
                    <div class="panel-body">
                        <?php if (empty($active_suppliers)): ?>
                            <div class="alert alert--warning">No active suppliers yet. <a href="suppliers.php?action=add">Add a supplier</a> first.</div>
                        <?php else: ?>
                        <form method="POST" action="purchase_orders.php?action=create" id="createPoForm">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="create">
                            <input type="hidden" name="submit_now" id="submitNowField" value="0">

                            <div class="form-grid" style="margin-bottom:16px;">
                                <div class="field-group">
                                    <label class="field-label">Supplier <span style="color:var(--red-alert)">*</span></label>
                                    <select name="supplier_id" id="poSupplierSelect" class="field-input" required onchange="onSupplierChange()">
                                        <option value="">-- Select Supplier --</option>
                                        <?php foreach ($active_suppliers as $sup): ?>
                                            <option value="<?= (int) $sup['id'] ?>"><?= clean($sup['company_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="field-hint">Picking a supplier will only show their products in the Item list below.</span>
                                </div>
                                <div class="field-group">
                                    <label class="field-label">Department</label>
                                    <?php if ($__role === 'inventory_staff'): ?>
                                        <input type="text" class="field-input" value="<?= clean($poDepartment) ?>" readonly>
                                        <input type="hidden" name="department" value="<?= clean($poDepartment) ?>">
                                    <?php else: ?>
                                        <select name="department" class="field-input">
                                            <?php foreach ($departmentOptions as $departmentOption): ?>
                                                <option value="<?= clean($departmentOption) ?>" <?= $departmentOption === $poDepartment ? 'selected' : '' ?>><?= clean($departmentOption) ?></option>
                                            <?php endforeach; ?>
                                            <option value="Unassigned" <?= $poDepartment === 'Unassigned' ? 'selected' : '' ?>>Unassigned</option>
                                        </select>
                                    <?php endif; ?>
                                </div>
                                <div class="field-group field-group--full">
                                    <label class="field-label">Notes <span style="color:var(--ink-soft); font-weight:400">(optional)</span></label>
                                    <input type="text" name="notes" class="field-input" placeholder="e.g. Weekly restock order">
                                </div>
                            </div>

                            <h3 style="font-size:14px; margin:18px 0 8px;">Items</h3>
                            <div id="itemRows"></div>
                            <button type="button" class="action-btn action-btn--secondary action-btn--small" onclick="addItemRow()">+ Add Item</button>

                            <div style="display:flex; gap:10px; margin-top:22px;">
                                <button type="button" class="action-btn action-btn--secondary" onclick="submitPo(false)">Save as Draft</button>
                                <button type="button" class="action-btn" onclick="submitPo(true)">Save &amp; Submit for Approval</button>
                                <a href="purchase_orders.php" class="action-btn action-btn--secondary">Cancel</a>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- PO TABLE -->
                <div class="panel">
                    <div class="panel-header" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                        <h2>Purchase Orders (<?= count($purchase_orders) ?>)</h2>
                        <?php $__poExportQs = 'status=' . urlencode($filter_status) . '&search=' . urlencode($search) . '&date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to); ?>
                        <div class="po-export-actions" style="display:flex; gap:8px; align-items:center;">
                            <?= exportPostForm('purchase_orders.php?' . $__poExportQs . '&export=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                            <?= exportPostForm('purchase_orders.php?' . $__poExportQs . '&export=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
                            <button type="button" class="action-btn action-btn--secondary action-btn--small" onclick="window.print()">Print</button>
                        </div>
                    </div>
                    <div class="panel-body panel-body--table">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>PO Number</th>
                                    <th>Department</th>
                                    <th>Supplier</th>
                                    <th>Items</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($purchase_orders)): ?>
                                    <tr><td colspan="8" class="empty-row">No Purchase Orders yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($purchase_orders as $po): ?>
                                    <tr>
                                        <td style="font-weight:600"><?= clean($po['po_number']) ?></td>
                                        <td><?= clean($po['department'] ?? 'Unassigned') ?></td>
                                        <td><?= clean($po['company_name']) ?></td>
                                        <td><?= (int) $po['item_count'] ?></td>
                                        <td><?= peso($po['total_amount']) ?></td>
                                        <td>
                                            <span class="po-status-badge po-status-badge--<?= $status_colors[$po['status']] ?? 'neutral' ?>"><?= clean($status_labels[$po['status']] ?? $po['status']) ?></span>
                                            <?php if ((int) $po['restock_request_count'] > 0): ?>
                                                <span style="display:block;font-size:11px;color:var(--ink-soft);margin-top:4px;">Supplier <?= (int) $po['supplier_signed_count'] === (int) $po['restock_request_count'] ? 'approved' : 'signature pending' ?></span>
                                            <?php else: ?>
                                                <span style="display:block;font-size:11px;color:var(--ink-soft);margin-top:4px;">Not linked to an inventory request</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= clean(date('M d, Y', strtotime($po['created_at']))) ?></td>
                                        <td class="actions-cell">
                                            <a href="purchase_orders.php?po=<?= enc_id($po['id']) ?>" class="link-btn" title="View" aria-label="View"><i class="fa-regular fa-eye"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<!-- Template row for the dynamic item list (Create form) -->
<template id="itemRowTemplate">
    <div class="item-row-grid item-row">
        <div class="field-group">
            <label class="field-label">Item</label>
            <select class="field-input item-product-select" onchange="onItemProductChange(this)">
                <option value="">-- Type new item below --</option>
                <?php foreach ($active_products as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" data-cost="<?= (float) $p['cost_price'] ?>" data-name="<?= clean($p['product_name']) ?>" data-supplier="<?= !empty($p['supplier_id']) ? (int) $p['supplier_id'] : '' ?>"><?= clean($p['product_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" class="item-product-id-field" name="item_product_id[]">
            <input type="text" class="field-input item-name-field" name="item_name[]" placeholder="Or type a new item name" style="margin-top:6px;">
        </div>
        <div class="field-group">
            <label class="field-label">Quantity</label>
            <input type="number" class="field-input" name="item_quantity[]" min="1" placeholder="0">
        </div>
        <div class="field-group">
            <label class="field-label">Unit Cost (₱)</label>
            <input type="number" class="field-input item-cost-field" name="item_unit_cost[]" step="0.01" min="0" placeholder="0.00">
        </div>
        <div class="field-group">
            <label class="field-label">&nbsp;</label>
            <span class="field-hint" style="padding-top:8px;">&nbsp;</span>
        </div>
        <div>
            <button type="button" class="link-btn link-btn--danger" title="Remove" onclick="removeItemRow(this)"><i class="fa-regular fa-trash-can"></i></button>
        </div>
    </div>
</template>

<script>
function confirmNav(e, url, opts) {
    e.preventDefault();
    Swal.fire(Object.assign({ showCancelButton: true, cancelButtonText: 'Cancel' }, opts)).then(function (result) {
        if (result.isConfirmed) window.location.href = url;
    });
    return false;
}
function confirmAction(e, form, opts) {
    e.preventDefault();
    Swal.fire(Object.assign({ showCancelButton: true, cancelButtonText: 'Cancel' }, opts)).then(function (result) {
        if (result.isConfirmed) form.submit();
    });
    return false;
}
function confirmReceive() {
    Swal.fire({
        title: 'Confirm Receiving?',
        text: 'This will update stock for every item you entered a received quantity for.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, receive',
        confirmButtonColor: '#123C5C',
    }).then(function (result) {
        if (result.isConfirmed) document.getElementById('receiveForm').submit();
    });
    return false;
}

// -------- Dynamic item rows (Create form) --------
function addItemRow() {
    var tpl = document.getElementById('itemRowTemplate');
    var clone = tpl.content.cloneNode(true);
    document.getElementById('itemRows').appendChild(clone);
    // If a supplier is already selected, the new row should only offer that supplier's products.
    var supplierSelect = document.getElementById('poSupplierSelect');
    if (supplierSelect && supplierSelect.value) {
        filterProductOptionsBySupplier(supplierSelect.value);
    }
}
function removeItemRow(btn) {
    var row = btn.closest('.item-row');
    if (row) row.remove();
}

// Show/hide each item-row's product options depending on the selected
// supplier — only that supplier's products stay visible. `skipSelect`
// (optional) leaves one row's own dropdown untouched, used right after
// that row is what triggered the supplier change (see onItemProductChange).
function filterProductOptionsBySupplier(supplierId, skipSelect) {
    var selects = document.querySelectorAll('.item-product-select');
    selects.forEach(function (select) {
        if (skipSelect && select === skipSelect) return;
        Array.prototype.forEach.call(select.options, function (opt) {
            if (opt.value === '') { opt.hidden = false; return; } // keep "type new item" option
            var optSupplier = opt.getAttribute('data-supplier') || '';
            opt.hidden = !!supplierId && optSupplier !== String(supplierId);
        });
        // If this row's current selection no longer belongs to the chosen
        // supplier, clear it instead of silently keeping a hidden option.
        var selectedOpt = select.options[select.selectedIndex];
        if (selectedOpt && selectedOpt.value && selectedOpt.hidden) {
            select.value = '';
            onItemProductChange(select);
        }
    });
}

// Supplier dropdown changed -> filter every row's product list to that supplier.
function onSupplierChange() {
    var supplierSelect = document.getElementById('poSupplierSelect');
    filterProductOptionsBySupplier(supplierSelect ? supplierSelect.value : '');
}

// A row's product dropdown changed -> fill in name/cost, and if the
// product is linked to a supplier, auto-select that supplier above
// (and re-filter the other rows to match).
function onItemProductChange(select) {
    var row = select.closest('.item-row');
    if (!row) return;
    var opt = select.options[select.selectedIndex];
    var idField   = row.querySelector('.item-product-id-field');
    var nameField = row.querySelector('.item-name-field');
    var costField = row.querySelector('.item-cost-field');
    if (opt && opt.value) {
        idField.value = opt.value;
        nameField.value = opt.getAttribute('data-name') || '';
        nameField.readOnly = true;
        if (costField && !costField.value) costField.value = opt.getAttribute('data-cost') || '';

        var productSupplier = opt.getAttribute('data-supplier') || '';
        var supplierSelect = document.getElementById('poSupplierSelect');
        if (productSupplier && supplierSelect && supplierSelect.value !== productSupplier) {
            var hasMatch = Array.prototype.some.call(supplierSelect.options, function (o) {
                return o.value === productSupplier;
            });
            if (hasMatch) {
                supplierSelect.value = productSupplier;
                filterProductOptionsBySupplier(productSupplier, select);
            }
        }
    } else {
        idField.value = '';
        nameField.value = '';
        nameField.readOnly = false;
    }
}
function submitPo(submitNow) {
    var rows = document.querySelectorAll('#itemRows .item-row');
    if (rows.length === 0) {
        Swal.fire({ icon: 'warning', title: 'Add at least one item first.', toast: true, position: 'top-end', showConfirmButton: false, timer: 2600 });
        return;
    }
    document.getElementById('submitNowField').value = submitNow ? '1' : '0';
    var opts = submitNow
        ? { icon: 'question', title: 'Submit for Approval?', text: 'This Purchase Order will be sent to Finance for approval.', confirmButtonText: 'Yes, submit', confirmButtonColor: '#123C5C' }
        : { icon: 'question', title: 'Save as Draft?', text: 'You can submit it for approval later from its detail page.', confirmButtonText: 'Yes, save', confirmButtonColor: '#123C5C' };
    Swal.fire(Object.assign({ showCancelButton: true, cancelButtonText: 'Cancel' }, opts)).then(function (result) {
        if (result.isConfirmed) document.getElementById('createPoForm').submit();
    });
}

document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('itemRows')) { addItemRow(); }
});
</script>

<script>
(function () {
    var input = document.getElementById('poSearchInput');
    if (!input) return;
    var timer = null;
    input.addEventListener('input', function () {
        if (timer) clearTimeout(timer);
        timer = setTimeout(function () { input.form.submit(); }, 450);
    });
})();
</script>
</body>
</html>