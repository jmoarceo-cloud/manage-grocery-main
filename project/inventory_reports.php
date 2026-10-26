<?php
// ============================================================
// inventory_reports.php — Inventory Reports for Inventory Staff,
// Finance (read-only), and Admin. Includes:
//   1) Stock & Expiry Summary  — current stock level per product,
//      low/out-of-stock status, and its nearest-expiring batch.
//   2) Stock Movement History  — every restock / issuance / adjust
//      / sale / void / refund within a chosen date range.
// Date-scoped financial summary is Finance/Admin-only and uses the
// shared Finance statement helpers.
// ============================================================

require_once 'includes/config.php';
require_once 'includes/export.php';
$__reportRole = $_SESSION['role'] ?? '';
if ($__reportRole === 'finance') {
    requireLogin();
} else {
    requireInventoryStaff();
}

$date_from   = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to     = isset($_GET['date_to'])   && !empty($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-d');
foreach (['date_from', 'date_to'] as $dateField) {
    $dateValue = $$dateField;
    $dateObject = DateTime::createFromFormat('!Y-m-d', $dateValue);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $dateValue) {
        $$dateField = $dateField === 'date_from' ? date('Y-m-01') : date('Y-m-d');
    }
}
if ($date_from > $date_to) {
    $date_from = date('Y-m-01', strtotime($date_to));
}
$action_type = isset($_GET['action_type']) && !empty($_GET['action_type']) ? $_GET['action_type'] : '';
$staff_q     = isset($_GET['staff_q']) ? trim($_GET['staff_q']) : '';

$showFinancialSummary = in_array($__reportRole, ['admin', 'finance'], true);
$financial_summary = $showFinancialSummary
    ? getProcurementFinancialSummary($pdo, $hrmsPdo ?? null, $date_from, $date_to)
    : [];

$__validActionTypes = ['sale', 'void', 'refund', 'restock', 'adjust', 'issuance'];
if ($action_type !== '' && !in_array($action_type, $__validActionTypes, true)) {
    $action_type = '';
}

// --- Stock & Expiry Summary (current snapshot, not date-ranged) ---
$products = $pdo->query("SELECT id, product_name, category, stock, cost_price FROM products ORDER BY category ASC, product_name ASC")->fetchAll();

$nearest_expiry_stmt = $pdo->query("
    SELECT product_id, MIN(expiry_date) AS nearest_expiry
    FROM product_batches
    WHERE quantity_remaining > 0 AND expiry_date IS NOT NULL
    GROUP BY product_id
");
$nearest_expiry_map = [];
foreach ($nearest_expiry_stmt->fetchAll() as $row) {
    $nearest_expiry_map[$row['product_id']] = $row['nearest_expiry'];
}

$total_products    = count($products);
$total_stock_units = 0;
$total_stock_value = 0.0;
$low_stock_count    = 0;
$out_of_stock_count = 0;
$expiring_soon_count = 0;
$expired_count       = 0;

foreach ($products as $p) {
    $total_stock_units += (int) $p['stock'];
    $total_stock_value += (int) $p['stock'] * (float) $p['cost_price'];
    if ((int) $p['stock'] === 0) {
        $out_of_stock_count++;
    } elseif ((int) $p['stock'] <= LOW_STOCK) {
        $low_stock_count++;
    }
    $nearest = $nearest_expiry_map[$p['id']] ?? null;
    if ($nearest) {
        $diff_days = (strtotime($nearest) - strtotime('today')) / 86400;
        if ($diff_days < 0) {
            $expired_count++;
        } elseif ($diff_days <= 30) {
            $expiring_soon_count++;
        }
    }
}

// --- Audit Trail / Stock Movement History (date-ranged, optionally
// filtered by action type and/or the staff member who performed it).
// Every inventory-affecting action anywhere in the system — restock,
// adjustment, issuance, PO receiving, sale, void, refund — writes a
// permanent row here (see stock_logs), so this table already *is* the
// Audit Trail for inventory activity, not a separate feature.
$__auditSql = "
    SELECT sl.*, p.product_name, p.category, u.full_name AS done_by
    FROM stock_logs sl
    JOIN products p ON p.id = sl.product_id
    JOIN users u    ON u.id = sl.user_id
    WHERE DATE(sl.date_created) BETWEEN :f AND :t
";
$__auditParams = [':f' => $date_from, ':t' => $date_to];
if ($action_type !== '') {
    $__auditSql .= " AND sl.change_type = :action_type";
    $__auditParams[':action_type'] = $action_type;
}
if ($staff_q !== '') {
    $__auditSql .= " AND u.full_name LIKE :staff_q";
    $__auditParams[':staff_q'] = '%' . $staff_q . '%';
}
$__auditSql .= " ORDER BY sl.date_created DESC";

$stmt = $pdo->prepare($__auditSql);
$stmt->execute($__auditParams);
$movement_history = $stmt->fetchAll();

if (isset($_GET['export_financial']) && in_array($_GET['export_financial'], ['xlsx', 'pdf'], true)) {
    if (!$showFinancialSummary) {
        http_response_code(403);
        exit('You do not have permission to export financial summaries.');
    }
    requireExportPostCsrf();
    $summaryHeaders = ['Metric', 'Amount'];
    $recognized = $financial_summary['recognized_expenses'];
    $outflows = $financial_summary['actual_outflows'];
    $summaryRows = [
        ['Recognized sales revenue', number_format((float) $financial_summary['recognized_income']['sales_revenue'], 2)],
        ['Recognized COGS', number_format((float) $recognized['cogs'], 2)],
        ['Accrued payroll (gross pay)', number_format((float) $recognized['payroll_accrued'], 2)],
        ['Approved operating bills (accrual)', number_format((float) $recognized['approved_operating_bills'], 2)],
        ['Net income (accrual basis)', number_format((float) $financial_summary['net_income'], 2)],
        ['Sales quantity without tracked COGS', (string) $financial_summary['uncosted_sales_quantity']],
        ['Cash from sales', number_format((float) $financial_summary['cash_from_sales'], 2)],
        ['Direct-cash restocking', number_format((float) $outflows['direct_cash_restock'], 2)],
        ['Supplier payments — PO/inventory procurement', number_format((float) $outflows['supplier_payments_for_procurement'], 2)],
        ['Payroll actually disbursed (net pay)', number_format((float) $outflows['payroll_disbursed'], 2)],
        ['Approved operating bills actually paid', number_format((float) $outflows['operating_bills_paid'], 2)],
        ['Total actual operating outflows', number_format((float) $outflows['total'], 2)],
        ['Net operating cash flow', number_format((float) $financial_summary['net_operating_cash'], 2)],
        ['Capital in (financing)', number_format((float) $financial_summary['capital_in'], 2)],
        ['Capital out (financing)', number_format((float) $financial_summary['capital_out'], 2)],
        ['Net change in cash', number_format((float) $financial_summary['net_change_in_cash'], 2)],
    ];
    $summaryMeta = [
        'Financial Summary',
        'Date range: ' . $date_from . ' to ' . $date_to,
        'Accrual expenses and actual cash outflows are shown separately and are not added together.',
        'Net income expenses: known COGS, gross payroll by pay-period end, and approved operating bills by approval date (due date fallback for legacy rows). Uncosted legacy sales quantities are disclosed separately.',
        'Actual payroll cash outflow uses net pay by payment date; procurement uses direct cash restocks and supplier payments by payment date.',
    ];
    if ($_GET['export_financial'] === 'xlsx') {
        exportToExcel('inventory_financial_summary', $summaryHeaders, $summaryRows, $summaryMeta);
    }
    exportToPDF('inventory_financial_summary', 'Financial Summary', $summaryHeaders, $summaryRows, $summaryMeta);
}

// --- Export of the audit trail above, same filters — CSV (legacy),
// Excel (.xlsx), and PDF all supported. The page role gate above also
// protects exports; financial summary exports have an additional
// Finance/Admin-only check.
if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'xlsx', 'pdf'], true)) {
    requireExportPostCsrf();

    $__exportHeaders = ['Type', 'Product', 'Category', 'Qty Change', 'Previous Stock', 'New Stock', 'Cost', 'Reason', 'By', 'Date'];
    $__exportRows = [];
    foreach ($movement_history as $log) {
        $__exportRows[] = [
            strtoupper($log['change_type']),
            $log['product_name'],
            $log['category'],
            $log['quantity_change'],
            $log['previous_stock'],
            $log['new_stock'],
            !empty($log['total_cost']) ? $log['total_cost'] : '',
            $log['reason'],
            $log['done_by'],
            $log['date_created'],
        ];
    }
    $__exportMeta = [
        'Stock Movement / Audit Trail',
        'Date range: ' . $date_from . ' to ' . $date_to . (($action_type !== '') ? ' | Action: ' . ucfirst($action_type) : '') . (($staff_q !== '') ? ' | Staff: ' . $staff_q : ''),
    ];

    if ($_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit_trail_' . $date_from . '_to_' . $date_to . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $__exportHeaders);
        foreach ($__exportRows as $r) { fputcsv($out, $r); }
        fclose($out);
        logExport('stock_movement_report', 'csv', count($__exportRows));
        exit();
    } elseif ($_GET['export'] === 'xlsx') {
        exportToExcel('stock_movement_report', $__exportHeaders, $__exportRows, $__exportMeta);
    } elseif ($_GET['export'] === 'pdf') {
        exportToPDF('stock_movement_report', 'Stock Movement Report', $__exportHeaders, $__exportRows, $__exportMeta);
    }
}

// --- Export of the Stock & Expiry Summary (the top section of this
// page) — separate from the audit trail export above since it's a
// different table/shape (current snapshot, not date-ranged).
if (isset($_GET['export_summary']) && in_array($_GET['export_summary'], ['xlsx', 'pdf'], true)) {
    requireExportPostCsrf();
    $__summaryHeaders = ['Product', 'Category', 'Stock', 'Cost Price', 'Stock Value', 'Nearest Expiry', 'Status'];
    $__summaryRows = [];
    foreach ($products as $p) {
        $nearest = $nearest_expiry_map[$p['id']] ?? null;
        $status = 'OK';
        if ((int) $p['stock'] === 0) {
            $status = 'Out of stock';
        } elseif ((int) $p['stock'] <= LOW_STOCK) {
            $status = 'Low stock';
        }
        if ($nearest) {
            $diff_days = (strtotime($nearest) - strtotime('today')) / 86400;
            if ($diff_days < 0) {
                $status .= ' / Expired';
            } elseif ($diff_days <= 30) {
                $status .= ' / Expiring soon';
            }
        }
        $__summaryRows[] = [
            $p['product_name'],
            $p['category'],
            $p['stock'],
            number_format((float) $p['cost_price'], 2),
            number_format((int) $p['stock'] * (float) $p['cost_price'], 2),
            $nearest ?: '—',
            $status,
        ];
    }
    $__summaryMeta = ['Stock & Expiry Summary', 'As of ' . date('F j, Y g:i A')];

    if ($_GET['export_summary'] === 'xlsx') {
        exportToExcel('stock_expiry_summary', $__summaryHeaders, $__summaryRows, $__summaryMeta);
    } else {
        exportToPDF('stock_expiry_summary', 'Stock & Expiry Summary', $__summaryHeaders, $__summaryRows, $__summaryMeta);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventory Reports — HCI GroceryOne</title>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<style>
.finance-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
@media (max-width: 800px) { .finance-grid { grid-template-columns: 1fr; } }
.finance-card {
    background: var(--white);
    border: 1.5px solid var(--line);
    border-radius: 16px;
    padding: 18px 20px;
}
.finance-card .f-label {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--ink-soft);
    text-transform: uppercase;
    letter-spacing: .03em;
    display: flex;
    align-items: center;
    gap: 6px;
}
.finance-card .f-value {
    font-family: var(--font-display);
    font-size: 24px;
    font-weight: 800;
    margin-top: 6px;
}
.finance-card .f-note {
    font-size: 11.5px;
    color: var(--ink-soft);
    margin-top: 4px;
}
.finance-grid--reports { grid-template-columns: repeat(4, 1fr); }
@media (max-width: 900px) { .finance-grid--reports { grid-template-columns: repeat(2, 1fr); } }
.finance-grid--financial-summary { grid-template-columns: repeat(3, minmax(0, 1fr)); }
@media (max-width: 800px) { .finance-grid--financial-summary { grid-template-columns: 1fr; } }
.stock-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
.stock-badge.in  { background: #e6f4ea; color: #1a7a3b; }
.stock-badge.low { background: var(--mustard-pale); color: #8a5c10; }
html[data-theme="dark"] .stock-badge.in  { background: var(--green-pale); color: var(--green-leaf); }
html[data-theme="dark"] .stock-badge.low { color: var(--mustard); }
.stock-badge.out { background: var(--red-pale); color: var(--red-alert); }
.change-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; font-weight: 700; }
.change-badge.restock  { background: #e6f4ea; color: #1a7a3b; }
.change-badge.sale     { background: #e8eeff; color: #2d4ecf; }
.change-badge.adjust   { background: var(--mustard-pale); color: #8a5c10; }
.change-badge.void     { background: #f3e8ff; color: #7b2fbe; }
.change-badge.refund   { background: #f3e8ff; color: #7b2fbe; }
.change-badge.issuance { background: #fde8e8; color: #b8391f; }
html[data-theme="dark"] .change-badge.restock  { background: var(--green-pale); color: var(--green-leaf); }
html[data-theme="dark"] .change-badge.sale     { background: #182449; color: #8fa4ff; }
html[data-theme="dark"] .change-badge.adjust   { color: var(--mustard); }
html[data-theme="dark"] .change-badge.void,
html[data-theme="dark"] .change-badge.refund   { background: #2a1a40; color: #c79bf0; }
html[data-theme="dark"] .change-badge.issuance { background: var(--red-pale); color: var(--red-alert); }
.filter-toolbar { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
.filter-toolbar .field-group { margin-bottom: 0; }
@media print { .sidebar, .topbar, .action-btn, form, .filter-toolbar { display: none !important; } .main-area { margin: 0 !important; } }
</style>
</head>
<body>
<div class="app-shell">

    <?php $active = 'inventory_reports'; include 'includes/sidebar.php'; ?>

    <div class="main-area">
        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_inv_reports ?></div>
            <h1>Inventory Reports</h1>
            <div class="topbar-user">
                <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'A') ?><?php endif; ?></span>
                <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Administrator') ?></span>
            </div>
        </header>

        <main class="content">

            <!-- SUMMARY -->
            <section class="finance-grid finance-grid--reports">
                <div class="finance-card">
                    <span class="f-label">Total Stock Value</span>
                    <div class="f-value" style="color: var(--text-accent)"><?= peso($total_stock_value) ?></div>
                    <div class="f-note"><?= $total_products ?> products, <?= $total_stock_units ?> units on hand</div>
                </div>
                <div class="finance-card">
                    <span class="f-label">Low Stock</span>
                    <div class="f-value" style="color:var(--mustard)"><?= $low_stock_count ?></div>
                    <div class="f-note">At or below <?= LOW_STOCK ?> pcs</div>
                </div>
                <div class="finance-card">
                    <span class="f-label">Out of Stock</span>
                    <div class="f-value" style="color:var(--red-alert)"><?= $out_of_stock_count ?></div>
                    <div class="f-note">0 pcs on hand</div>
                </div>
                <div class="finance-card">
                    <span class="f-label">Expiring / Expired Batches</span>
                    <div class="f-value" style="color:var(--red-alert)"><?= $expiring_soon_count + $expired_count ?></div>
                    <div class="f-note"><?= $expiring_soon_count ?> expiring within 30d, <?= $expired_count ?> already expired</div>
                </div>
            </section>

            <?php if ($showFinancialSummary): ?>
            <section class="panel" style="margin-bottom:24px;">
                <div class="panel-header" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                    <div>
                        <h2 style="margin:0;">Monthly Income, Expenses &amp; Net</h2>
                        <p style="margin:4px 0 0; color:var(--ink-soft); font-size:12.5px;">Finance statement helpers for <?= clean($date_from) ?> through <?= clean($date_to) ?>. Expenses include COGS and payroll tracked by the existing finance model.</p>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <?= exportPostForm('inventory_reports.php?date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to) . '&export_financial=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                        <?= exportPostForm('inventory_reports.php?date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to) . '&export_financial=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
                        <button type="button" class="action-btn action-btn--small action-btn--outline" onclick="window.print()">Print</button>
                    </div>
                </div>
                <div class="panel-body">
                    <h3>Recognized income statement (accrual basis)</h3>
                    <div class="finance-grid finance-grid--financial-summary" style="margin:0 0 16px;">
                        <div class="finance-card"><span class="f-label">Net Sales</span><div class="f-value" style="color:var(--text-accent)"><?= peso($financial_summary['recognized_income']['sales_revenue']) ?></div></div>
                        <div class="finance-card"><span class="f-label">Cost of Goods Sold</span><div class="f-value"><?= peso($financial_summary['recognized_expenses']['cogs']) ?></div></div>
                        <div class="finance-card"><span class="f-label">Sales Units Without Tracked Cost</span><div class="f-value"><?= number_format((int) $financial_summary['uncosted_sales_quantity']) ?></div><div class="f-note">Legacy sales without batch-cost records; excluded from numeric COGS.</div></div>
                        <div class="finance-card"><span class="f-label">Payroll Accrued (Gross)</span><div class="f-value"><?= peso($financial_summary['recognized_expenses']['payroll_accrued']) ?></div></div>
                        <div class="finance-card"><span class="f-label">Approved Operating Bills</span><div class="f-value"><?= peso($financial_summary['recognized_expenses']['approved_operating_bills']) ?></div></div>
                        <div class="finance-card"><span class="f-label">Total Recognized Expenses</span><div class="f-value" style="color:var(--red-alert)"><?= peso($financial_summary['recognized_expenses']['total']) ?></div></div>
                        <div class="finance-card"><span class="f-label">Net Income</span><div class="f-value" style="color:<?= $financial_summary['net_income'] < 0 ? 'var(--red-alert)' : 'var(--green-mid)' ?>"><?= peso($financial_summary['net_income']) ?></div></div>
                    </div>
                    <h3>Actual cash activity (cash basis)</h3>
                    <div class="finance-grid finance-grid--financial-summary" style="margin:0 0 12px;">
                        <div class="finance-card"><span class="f-label">Cash from Sales</span><div class="f-value"><?= peso($financial_summary['cash_from_sales']) ?></div></div>
                        <div class="finance-card"><span class="f-label">Direct Cash Restocking</span><div class="f-value"><?= peso($financial_summary['actual_outflows']['direct_cash_restock']) ?></div></div>
                        <div class="finance-card"><span class="f-label">Supplier Payments (PO/Inventory)</span><div class="f-value"><?= peso($financial_summary['actual_outflows']['supplier_payments_for_procurement']) ?></div></div>
                        <div class="finance-card"><span class="f-label">Payroll Disbursed (Net)</span><div class="f-value"><?= peso($financial_summary['actual_outflows']['payroll_disbursed']) ?></div></div>
                        <div class="finance-card"><span class="f-label">Operating Bills Paid</span><div class="f-value"><?= peso($financial_summary['actual_outflows']['operating_bills_paid']) ?></div></div>
                        <div class="finance-card"><span class="f-label">Total Actual Operating Outflows</span><div class="f-value" style="color:var(--red-alert)"><?= peso($financial_summary['actual_outflows']['total']) ?></div></div>
                        <div class="finance-card"><span class="f-label">Net Operating Cash</span><div class="f-value"><?= peso($financial_summary['net_operating_cash']) ?></div></div>
                        <div class="finance-card"><span class="f-label">Capital In / Out</span><div class="f-value"><?= peso($financial_summary['capital_in']) ?> / <?= peso($financial_summary['capital_out']) ?></div><div class="f-note">Financing flows; excluded from net income.</div></div>
                        <div class="finance-card"><span class="f-label">Net Change in Cash</span><div class="f-value" style="color:<?= $financial_summary['net_change_in_cash'] < 0 ? 'var(--red-alert)' : 'var(--green-mid)' ?>"><?= peso($financial_summary['net_change_in_cash']) ?></div></div>
                    </div>
                    <p class="f-note" style="line-height:1.6;">
                        Accrual expenses and payments are separate views; do not add them together. Sales are net of recorded refunds.
                        Payroll accrual uses approved gross pay by pay-period end, while cash payroll uses paid net pay by payment date.
                        Approved operating bills are accrued on approval date (due-date fallback for legacy records); paid bills are supplier payments.
                        PO/inventory supplier payments are cash outflows when paid, not again when received. No tax, interest, fixed-asset purchase,
                        or unrecorded cash-spend detail is tracked by these sources; current cash position is not a historical balance.
                    </p>
                </div>
            </section>
            <?php endif; ?>

            <!-- STOCK & EXPIRY SUMMARY -->
            <div class="panel" style="margin-bottom:24px;">
                <div class="panel-header" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                    <h2>Stock &amp; Expiry Summary</h2>
                    <div style="display:flex; gap:8px;">
                        <?= exportPostForm('inventory_reports.php?export_summary=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                        <?= exportPostForm('inventory_reports.php?export_summary=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
                    </div>
                </div>
                <div class="panel-body panel-body--table">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Stock</th>
                                <th>Status</th>
                                <th>Nearest Batch Expiry</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($products)): ?>
                                <tr><td colspan="5" class="empty-row">No products yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($products as $p): ?>
                                    <?php
                                    $stock = (int) $p['stock'];
                                    if ($stock === 0) {
                                        $status_badge = '<span class="stock-badge out">Out of Stock</span>';
                                    } elseif ($stock <= LOW_STOCK) {
                                        $status_badge = '<span class="stock-badge low">Low Stock</span>';
                                    } else {
                                        $status_badge = '<span class="stock-badge in">In Stock</span>';
                                    }
                                    $nearest = $nearest_expiry_map[$p['id']] ?? null;
                                    $exp_info = getExpiryStatus($nearest);
                                    ?>
                                    <tr>
                                        <td style="font-weight:600"><?= clean($p['product_name']) ?></td>
                                        <td style="color:var(--ink-soft)"><?= clean($p['category']) ?></td>
                                        <td style="font-weight:700"><?= $stock ?> pcs</td>
                                        <td><?= $status_badge ?></td>
                                        <td>
                                            <?php if ($exp_info): ?>
                                                <span style="color:<?= $exp_info['color'] ?>; font-weight:600"><?= $exp_info['icon'] ?> <?= $exp_info['label'] ?></span>
                                            <?php else: ?>
                                                <span style="color:var(--ink-soft)">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- AUDIT TRAIL (Stock Movement History) -->
            <div class="panel">
                <div class="panel-header">
                    <h2>Audit Trail <span style="font-weight:500; color:var(--ink-soft); font-size:14px;">— Stock Movement History</span></h2>
                    <p style="margin:4px 0 0; font-size:12.5px; color:var(--ink-soft);">
                        Every stock adjustment, addition, received PO delivery, issuance, sale, void, and refund is recorded here automatically and can't be edited or deleted.
                    </p>
                </div>
                <div class="panel-body" style="padding: 20px 20px 0;">
                    <form method="GET" action="inventory_reports.php" class="filter-toolbar">
                        <div class="field-group">
                            <label class="field-label">From</label>
                            <input type="date" name="date_from" class="field-input" value="<?= clean($date_from) ?>">
                        </div>
                        <div class="field-group">
                            <label class="field-label">To</label>
                            <input type="date" name="date_to" class="field-input" value="<?= clean($date_to) ?>">
                        </div>
                        <div class="field-group">
                            <label class="field-label">Action</label>
                            <select name="action_type" class="field-input">
                                <option value="">All actions</option>
                                <?php foreach ($__validActionTypes as $__at): ?>
                                    <option value="<?= $__at ?>" <?= $action_type === $__at ? 'selected' : '' ?>><?= ucfirst($__at) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label">Staff</label>
                            <input type="text" name="staff_q" class="field-input" placeholder="Search by name" value="<?= clean($staff_q) ?>">
                        </div>
                        <button type="submit" class="action-btn action-btn--small">Filter</button>
                        <?php $__exportQs = 'date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to) . '&action_type=' . urlencode($action_type) . '&staff_q=' . urlencode($staff_q); ?>
                    </form>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
                        <?= exportPostForm('inventory_reports.php?' . $__exportQs . '&export=csv', 'Export CSV', 'action-btn action-btn--small action-btn--outline') ?>
                        <?= exportPostForm('inventory_reports.php?' . $__exportQs . '&export=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                        <?= exportPostForm('inventory_reports.php?' . $__exportQs . '&export=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
                    </div>
                </div>
                <div class="panel-body panel-body--table">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Product</th>
                                <th>Change</th>
                                <th>Before → After</th>
                                <th>Cost</th>
                                <th>Reason</th>
                                <th>By</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($movement_history)): ?>
                                <tr><td colspan="8" class="empty-row">No audit trail records match this filter.</td></tr>
                            <?php else: ?>
                                <?php foreach ($movement_history as $log): ?>
                                    <?php $sign = $log['quantity_change'] >= 0 ? '+' : ''; ?>
                                    <tr>
                                        <td><span class="change-badge <?= clean($log['change_type']) ?>"><?= strtoupper($log['change_type']) ?></span></td>
                                        <td style="font-weight:600"><?= clean($log['product_name']) ?></td>
                                        <td style="font-weight:700; color:<?= $log['quantity_change'] >= 0 ? 'var(--green-mid)' : 'var(--red-alert)' ?>">
                                            <?= $sign . $log['quantity_change'] ?> pcs
                                        </td>
                                        <td style="color:var(--ink-soft); font-size:13px"><?= $log['previous_stock'] ?> → <?= $log['new_stock'] ?></td>
                                        <td style="font-size:13px; font-weight:600; color:var(--red-alert)">
                                            <?= !empty($log['total_cost']) ? peso($log['total_cost']) : '—' ?>
                                        </td>
                                        <td style="font-size:13px"><?= clean($log['reason']) ?></td>
                                        <td style="font-size:13px"><?= clean($log['done_by']) ?></td>
                                        <td style="font-size:13px; color:var(--ink-soft)"><?= date('M d, Y h:i A', strtotime($log['date_created'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>
</div>
<script>
function confirmNav(e, url, opts) {
    e.preventDefault();
    Swal.fire(Object.assign({
        showCancelButton: true,
        cancelButtonText: 'Cancel'
    }, opts)).then(function (result) {
        if (result.isConfirmed) window.location.href = url;
    });
    return false;
}
</script>
</body>
</html>