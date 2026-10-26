<?php
// ============================================================
// financial_audit_log.php — Financial Audit Log (Finance + Admin).
//
// Isang chronological, filterable na view ng bawat financial-control
// desisyon sa buong system — sino ang nag-approve/reject/nag-record
// ng ano, at kailan. Hindi ito bagong table; sinasama lang dito (sa
// PHP layer, via getFinancialAuditLog() sa includes/config.php) ang
// existing decided_at/approved_by/atbp. na trail na nakakalat na sa
// Purchase Orders, Inventory Requests, POS/Product Discounts,
// Refunds, Supplier Payments, Capital Transactions, Budgets, at
// Payroll (HRMS) — para may iisang lugar na makita lahat, gamit para
// sa audit/tax coordination, imbes na pumunta nang hiwa-hiwalay sa
// bawat module.
//
// Para sa raw stock-movement audit (bawat restock/adjustment/void sa
// antas ng produkto), tingnan pa rin ang Inventory Reports — hindi
// ito doble dito dahil iba ang layunin: ito ay financial DECISIONS
// (may approval/rejection/payment na naka-attach), hindi bawat pisikal
// na galaw ng stock.
// ============================================================

require_once 'includes/config.php';
require_once 'includes/export.php';
requireFinance();

$date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to   = isset($_GET['date_to'])   && !empty($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-d');

$__categories = [
    'all'                => 'All Categories',
    'purchase_orders'    => 'Purchase Orders',
    'operating_expenses' => 'Operating Expenses',
    'inventory_requests' => 'Inventory Requests',
    'pos_discounts'      => 'POS Discounts',
    'product_discounts'  => 'Product Discounts',
    'refunds'            => 'Refunds',
    'supplier_payments'  => 'Supplier Payments',
    'capital'            => 'Capital',
    'budgets'            => 'Budgets',
    'payroll'            => 'Payroll',
];
$category = isset($_GET['category']) && array_key_exists($_GET['category'], $__categories) ? $_GET['category'] : 'all';

$auditLog = getFinancialAuditLog($pdo, $hrmsPdo, $date_from, $date_to, $category, 500);

// --- Export ---------------------------------------------------
if (isset($_GET['export']) && in_array($_GET['export'], ['xlsx', 'pdf'], true)) {
    $__h = ['Date/Time', 'Category', 'Event', 'Reference', 'Amount', 'Performed By', 'Notes'];
    $__r = [];
    foreach ($auditLog as $__row) {
        $__r[] = [
            date('M d, Y h:i A', strtotime($__row['ts'])),
            $__row['category'],
            $__row['event'],
            $__row['reference'] ?? '',
            $__row['amount'] !== null ? number_format((float) $__row['amount'], 2) : '',
            $__row['actor_name'] ?? 'Unknown',
            $__row['notes'] ?? '',
        ];
    }
    $__name  = 'financial_audit_log_' . $date_from . '_to_' . $date_to;
    $__title = 'Financial Audit Log';
    $__meta  = [
        $__title,
        'Period: ' . date('M d, Y', strtotime($date_from)) . ' — ' . date('M d, Y', strtotime($date_to)),
        'Category: ' . $__categories[$category],
    ];

    if ($_GET['export'] === 'xlsx') {
        exportToExcel($__name, $__h, $__r, $__meta);
    } else {
        exportToPDF($__name, $__title, $__h, $__r, $__meta);
    }
}

$__icon_audit_log = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6"/><path d="M9 5.5V9l2.5 1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 15l3 3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';

$__categoryBadgeColor = [
    'Purchase Orders'    => ['bg' => 'var(--mustard-pale)', 'fg' => '#7A5A1E'],
    'Inventory Requests' => ['bg' => 'var(--mustard-pale)', 'fg' => '#7A5A1E'],
    'POS Discounts'      => ['bg' => 'var(--red-pale)',     'fg' => 'var(--red-alert)'],
    'Product Discounts'  => ['bg' => 'var(--red-pale)',     'fg' => 'var(--red-alert)'],
    'Refunds'            => ['bg' => 'var(--red-pale)',     'fg' => 'var(--red-alert)'],
    'Supplier Payments'  => ['bg' => 'var(--cream)',        'fg' => 'var(--green-mid)'],
    'Capital'            => ['bg' => 'var(--cream)',        'fg' => 'var(--green-mid)'],
    'Budgets'            => ['bg' => 'var(--cream)',        'fg' => 'var(--ink-soft)'],
    'Payroll'            => ['bg' => 'var(--cream)',        'fg' => 'var(--ink-soft)'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Financial Audit Log — HCI GroceryOne</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<style>
.audit-filter-form {
    display: flex;
    align-items: flex-end;
    gap: 14px;
    flex-wrap: wrap;
    background: var(--white);
    border-radius: 14px;
    padding: 18px 22px;
    box-shadow: var(--shadow-card);
}
.audit-filter-form .field-group { margin: 0; }
.audit-filter-form label { font-size: 12px; font-weight: 700; color: var(--ink-soft); display: block; margin-bottom: 6px; }
.audit-filter-form input[type=date], .audit-filter-form select {
    padding: 10px 14px;
    border: 1.5px solid var(--line);
    border-radius: 10px;
    font-family: var(--font-body);
    font-size: 14px;
    color: var(--ink);
    background: var(--cream);
}
.audit-filter-form input[type=date]:focus, .audit-filter-form select:focus { outline: none; border-color: var(--green-mid); }

.audit-table { width: 100%; border-collapse: collapse; }
.audit-table th {
    text-align: left;
    padding: 11px 16px;
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    color: var(--ink-soft);
    border-bottom: 2px solid var(--line);
    white-space: nowrap;
}
.audit-table td {
    padding: 11px 16px;
    font-size: 13.5px;
    border-bottom: 1px solid var(--line);
    vertical-align: top;
}
.audit-table td.amount-col { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.audit-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}
.audit-event {
    display: inline-block;
    padding: 2px 9px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 700;
    background: var(--cream);
    color: var(--ink-soft);
    white-space: nowrap;
}
.audit-empty { text-align: center; padding: 48px 20px; color: var(--ink-soft); font-size: 14px; }
</style>
</head>
<body>
<div class="app-shell">

    <!-- SIDEBAR -->
    <?php $active = 'financial_audit_log'; include 'includes/sidebar.php'; ?>

    <!-- MAIN -->
    <div class="main-area">
        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_audit_log ?></div>
            <h1>Financial Audit Log</h1>
            <div class="topbar-user"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'F') ?><?php endif; ?></span>
                <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Finance') ?></span></div>
        </header>

        <main class="content">

            <form method="GET" action="financial_audit_log.php" class="audit-filter-form">
                <div class="field-group">
                    <label>Date From</label>
                    <input type="date" name="date_from" value="<?= clean($date_from) ?>">
                </div>
                <div class="field-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" value="<?= clean($date_to) ?>">
                </div>
                <div class="field-group">
                    <label>Category</label>
                    <select name="category">
                        <?php foreach ($__categories as $__key => $__label): ?>
                            <option value="<?= $__key ?>" <?= $category === $__key ? 'selected' : '' ?>><?= clean($__label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="action-btn" style="padding:10px 22px; font-size:14px">Filter</button>
                <a href="financial_audit_log.php" class="action-btn" style="padding:10px 22px; font-size:14px; background:var(--white); color:var(--ink-soft); border:1.5px solid var(--line); box-shadow:none;">Reset</a>
            </form>
            <?php $__qs = 'date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to) . '&category=' . urlencode($category); ?>
            <div style="display:flex; gap:8px; margin:12px 0 0;">
                <?= exportPostForm('financial_audit_log.php?' . $__qs . '&export=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                <?= exportPostForm('financial_audit_log.php?' . $__qs . '&export=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
            </div>

            <div class="panel" style="margin-top:16px;">
                <div class="panel-header" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                    <div>
                        <h2>Audit Trail</h2>
                        <span style="font-size:12.5px; color:var(--ink-soft);">
                            <?= date('M d, Y', strtotime($date_from)) ?> — <?= date('M d, Y', strtotime($date_to)) ?>
                            · <?= count($auditLog) ?> event<?= count($auditLog) === 1 ? '' : 's' ?><?= count($auditLog) >= 500 ? ' (showing latest 500 — narrow the date range or category to see more)' : '' ?>
                        </span>
                    </div>
                </div>

                <div class="panel-body panel-body--table" style="padding:0; overflow-x:auto;">
                    <?php if (empty($auditLog)): ?>
                        <div class="audit-empty">Walang financial-control events sa napiling range/category.</div>
                    <?php else: ?>
                        <table class="audit-table">
                            <thead>
                                <tr>
                                    <th>Date/Time</th>
                                    <th>Category</th>
                                    <th>Event</th>
                                    <th>Reference</th>
                                    <th style="text-align:right;">Amount</th>
                                    <th>Performed By</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($auditLog as $__row): ?>
                                    <?php $__badge = $__categoryBadgeColor[$__row['category']] ?? ['bg' => 'var(--cream)', 'fg' => 'var(--ink-soft)']; ?>
                                    <tr>
                                        <td style="white-space:nowrap; color:var(--ink-soft);"><?= date('M d, Y h:i A', strtotime($__row['ts'])) ?></td>
                                        <td><span class="audit-badge" style="background:<?= $__badge['bg'] ?>; color:<?= $__badge['fg'] ?>;"><?= clean($__row['category']) ?></span></td>
                                        <td><span class="audit-event"><?= clean($__row['event']) ?></span></td>
                                        <td><?= clean($__row['reference'] ?? '') ?></td>
                                        <td class="amount-col"><?= $__row['amount'] !== null ? peso((float) $__row['amount']) : '<span style="color:var(--ink-soft);">—</span>' ?></td>
                                        <td><?= clean($__row['actor_name'] ?? 'Unknown') ?></td>
                                        <td style="color:var(--ink-soft); max-width:260px;"><?= clean($__row['notes'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                <div class="statement-note" style="margin:16px 22px 20px; background:var(--cream); border-radius:12px; padding:12px 16px; font-size:13px; color:var(--ink-soft); line-height:1.55;">
                    ℹ️ Each row here comes from the existing decision trail of its respective module (Purchase Orders, Inventory Requests, POS/Product Discounts, Refunds, Supplier Payments, Capital Transactions, Budgets, and Payroll from HRMS). The underlying data has not been changed; it is simply consolidated and ordered by date for easier audit and tax review.
                    <br>For detailed stock movement (each restock, adjustment, or void at the product level), use <a href="inventory_reports.php" style="color:var(--green-mid); font-weight:700;">Inventory Reports</a>, which are kept separate because they track physical stock movements rather than financial-control decisions.
                </div>
            </div>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function confirmNav(e, url, opts) {
    e.preventDefault();
    Swal.fire(Object.assign({ showCancelButton: true, cancelButtonText: 'Cancel' }, opts)).then(function (result) {
        if (result.isConfirmed) window.location.href = url;
    });
    return false;
}
</script>
</body>
</html>