<?php
// ============================================================
// finance_dashboard.php — Landing page / analytics for the
// Finance role (Admin can view it too, same as the other role
// dashboards). Read-only snapshot only:
//   - Pending financial approvals (counts, broken down by type)
//   - Purchase-related expenses (Purchase Order spend)
//   - Approved expenses (received/approved POs)
//   - Payment / expense summaries (capital ledger, restock cost)
//   - Budget-related info (Business Cash Position)
//
// All actual approve/reject actions stay on finance.php — this
// page never writes to the database, it only reads for display,
// mirroring the same split already used between
// inventory_dashboard.php (read-only) and inventory.php (actions).
// ============================================================

require_once 'includes/config.php';
require_once 'includes/export.php';
requireFinance();

// --- Pending financial approvals, broken down by type (all four
// approval queues Finance is responsible for — same sources as
// finance.php's own panels) ---
$pending_restock_count = (int) $pdo->query("
    SELECT COUNT(*) FROM inventory_requests WHERE status = 'pending_finance'
")->fetchColumn();

$pending_po_count = (int) $pdo->query("
    SELECT COUNT(*) FROM purchase_orders WHERE status = 'pending_approval'
")->fetchColumn();

$pending_discount_count = 0;
try {
    $pending_discount_count = (int) $pdo->query("
        SELECT COUNT(*) FROM discount_requests WHERE status = 'pending'
    ")->fetchColumn();
} catch (Exception $e) {}

$pending_product_discount_count = 0;
try {
    $pending_product_discount_count = (int) $pdo->query("
        SELECT COUNT(*) FROM product_discount_requests WHERE status = 'pending'
    ")->fetchColumn();
} catch (Exception $e) {}

$total_pending_approvals = $pending_restock_count + $pending_po_count + $pending_discount_count + $pending_product_discount_count;

// --- Approved PO commitments not yet received ---
// Received quantities become Accounts Payable below; this figure
// follows the remaining unreceived portion so commitments move out
// of this total and into actual liabilities as deliveries arrive.
$poCommitmentSummary = getUnreceivedPurchaseOrderCommitments($pdo);
$po_committed_alltime = $poCommitmentSummary['total'];

// This-month PO spend (based on when items were actually received —
// the point real money/stock changed hands), for a "current period"
// figure alongside the all-time one above.
$po_received_this_month = (float) $pdo->query("
    SELECT COALESCE(SUM(poi.unit_cost * poi.quantity_received), 0)
    FROM purchase_order_items poi
    JOIN purchase_orders po ON po.id = poi.po_id
    WHERE po.status IN ('partially_received','received')
      AND po.updated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
")->fetchColumn();

// --- Approved expenses: POs that made it all the way to Received,
// counted + valued, this month vs all-time ---
$po_received_count_month = (int) $pdo->query("
    SELECT COUNT(*) FROM purchase_orders
    WHERE status = 'received' AND received_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
")->fetchColumn();

$po_status_counts_raw = $pdo->query("
    SELECT status, COUNT(*) AS c FROM purchase_orders GROUP BY status
")->fetchAll();
$po_status_counts = [];
foreach ($po_status_counts_raw as $row) {
    $po_status_counts[$row['status']] = (int) $row['c'];
}
$po_status_labels = [
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

// --- Payment / expense summary: restock cost (all sources, see
// getTotalRestockCost()) + recent capital/owner transactions ---
$total_restock_cost = getTotalRestockCost($pdo);

$recent_capital = [];
try {
    $recent_capital = $pdo->query("SELECT * FROM capital_transactions ORDER BY created_at DESC LIMIT 6")->fetchAll();
} catch (Exception $e) {}

// --- Budget-related info: Business Cash Position (all-time) ---
$cash_position = getBusinessCashPosition($pdo, $hrmsPdo);

// --- Accounts Payable outstanding total (all suppliers, unpaid +
// partial) — see getAccountsPayableAging() in includes/config.php.
$ap_outstanding_total = 0.0;
try {
    $ap_outstanding_total = getAccountsPayableAging($pdo)['total_outstanding'];
} catch (Exception $e) {
    error_log("finance_dashboard.php AP aging skipped: " . $e->getMessage());
}

// --- Recent Purchase Orders (any status), for a quick glance ---
$recent_pos = $pdo->query("
    SELECT po.*, s.company_name,
        (SELECT COALESCE(SUM(subtotal), 0) FROM purchase_order_items WHERE po_id = po.id) AS total_amount
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    ORDER BY po.created_at DESC
    LIMIT 6
")->fetchAll();

// --- Export the analytics snapshot above (KPIs + PO status counts +
// recent POs) as a single report. Gated by the same requireFinance()
// check at the top of this file.
if (isset($_GET['export']) && in_array($_GET['export'], ['xlsx', 'pdf'], true)) {
    $__h = ['Metric', 'Value'];
    $__r = [
        ['Total Pending Approvals', $total_pending_approvals],
        ['  - Pending Restock Requests', $pending_restock_count],
        ['  - Pending Purchase Orders', $pending_po_count],
        ['  - Pending Discount Requests', $pending_discount_count],
        ['  - Pending Product Discount Requests', $pending_product_discount_count],
        ['PO Commitments Awaiting Receipt', number_format($po_committed_alltime, 2)],
        ['PO Received This Month', number_format($po_received_this_month, 2)],
        ['POs Received This Month (count)', $po_received_count_month],
        ['Total Restock Cost (All Sources)', number_format($total_restock_cost, 2)],
        ['Accounts Payable Outstanding', number_format($ap_outstanding_total, 2)],
        ['Sales Revenue (All-Time)', number_format((float) $cash_position['sales_revenue'], 2)],
        ['Restock Cash Paid (All-Time)', number_format((float) $cash_position['restock_cost'], 2)],
        ['Operating Expenses Paid (All-Time)', number_format((float) $cash_position['operating_expenses_paid'], 2)],
        ['Capital In (All-Time)', number_format((float) $cash_position['capital_in'], 2)],
        ['Capital Out (All-Time)', number_format((float) $cash_position['capital_out'], 2)],
        ['Payroll Released (All-Time)', number_format((float) $cash_position['payroll_released'], 2)],
        ['Business Cash Position', !empty($cash_position['payroll_data_available']) ? number_format((float) $cash_position['available_cash'], 2) : 'Unavailable'],
    ];
    foreach ($po_status_labels as $key => $label) {
        $__r[] = ['PO Status — ' . $label, $po_status_counts[$key] ?? 0];
    }
    $__r[] = ['', ''];
    $__r[] = ['Recent Purchase Orders', ''];
    foreach ($recent_pos as $po) {
        $__r[] = ['  ' . $po['po_number'] . ' — ' . $po['company_name'], number_format((float) $po['total_amount'], 2) . ' (' . ucfirst(str_replace('_', ' ', $po['status'])) . ')'];
    }

    $__meta = ['Finance Dashboard — Analytics Snapshot', 'As of ' . date('F j, Y g:i A')];

    if ($_GET['export'] === 'xlsx') {
        exportToExcel('finance_dashboard_analytics', $__h, $__r, $__meta);
    } else {
        exportToPDF('finance_dashboard_analytics', 'Finance Dashboard — Analytics Snapshot', $__h, $__r, $__meta);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — HCI GroceryOne</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.stat-card.accent-green   { border-left: 4px solid var(--green-mid); }
.stat-card.accent-red     { border-left: 4px solid var(--red-alert); }
.stat-card.accent-mustard { border-left: 4px solid var(--mustard); }
.stat-card.accent-leaf    { border-left: 4px solid var(--green-leaf); }
body.dashboard-page--finance .main-area > .content {
    width: 100%;
    max-width: none;
    box-sizing: border-box;
    margin-inline: 0;
    padding-inline: clamp(20px, 2.2vw, 36px);
}
body.dashboard-page--finance .dashboard-footer {
    width: 100%;
    max-width: none;
    box-sizing: border-box;
}
/* This page shows 5 stat cards (the other 4 dashboards on this
   sidebar keep 4 — see style.css's shared .stat-grid) — a scoped
   override here instead of touching the shared 4-col rule. */
.stat-grid--5 { grid-template-columns: repeat(5, 1fr); }
@media (max-width: 1100px) { .stat-grid--5 { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 600px)  { .stat-grid--5 { grid-template-columns: 1fr; } }
@media (max-width: 600px) {
    body.dashboard-page--finance .main-area > .content {
        padding-inline: 16px;
    }
}

.dash-two-col {
    display: grid;
    grid-template-columns: 1.1fr 0.9fr;
    gap: 20px;
    margin-bottom: 20px;
}
@media (max-width: 900px) { .dash-two-col { grid-template-columns: 1fr; } }

.approval-list { list-style: none; margin: 0; padding: 0; }
.approval-list li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px 0;
    border-bottom: 1px solid var(--line);
    font-size: 13.5px;
}
.approval-list li:last-child { border-bottom: none; }
.approval-list .al-name { font-weight: 600; }
.approval-list .al-sub { color: var(--ink-soft); font-size: 12px; }
.approval-count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 26px;
    padding: 0 8px;
    border-radius: 999px;
    background: var(--mustard-pale);
    color: #7A5A1E;
    font-weight: 700;
    font-size: 13px;
}
html[data-theme="dark"] .approval-count-badge { color: var(--mustard); }
.approval-count-badge.zero { background: var(--cream); color: var(--ink-soft); }

.po-status-pill-row { display: flex; flex-wrap: wrap; gap: 10px; padding: 4px 22px 20px; }
.po-status-pill {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--cream);
    border: 1.5px solid var(--line);
    border-radius: 12px;
    padding: 8px 14px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--ink-soft);
}
.po-status-pill b { color: var(--ink); font-size: 15px; font-family: var(--font-display); }

.po-status-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 700;
    white-space: nowrap;
}
.po-status-badge--neutral { background: var(--cream); color: var(--ink-soft); }
.po-status-badge--green   { background: var(--green-pale); color: var(--text-accent); }
.po-status-badge--mustard { background: var(--mustard-pale); color: #7A5A1E; }
.po-status-badge--red     { background: var(--red-pale); color: #9c3a23; }
html[data-theme="dark"] .po-status-badge--mustard { color: var(--mustard); }
/* Groups the export buttons + user info as ONE right-aligned unit so
   there's only a single margin-left:auto pushing them (the export
   buttons used to carry their own margin-left:auto right next to
   .topbar-user's, and two auto-margins on the same flex row split the
   free space between them instead of pushing everything to the
   right cleanly). flex-wrap keeps things from overlapping/clipping
   on narrower windows instead of forcing a single fixed-width row. */
.topbar-actions {
    margin-left: auto;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
}
/* .topbar-user still carries its own margin-left:auto from the shared
   stylesheet (it's meant to self-push when it's the only thing in the
   topbar). Here it's sitting inside .topbar-actions alongside the
   export buttons, so that auto-margin has no room to do anything —
   the buttons end up jammed right against the avatar. Cancel it and
   use a real divider + gap instead so the export controls read as
   their own group, separate from the profile chip. */
.topbar-actions .topbar-user {
    margin-left: 0;
    padding-left: 18px;
    border-left: 1px solid var(--line);
}
.export-group {
    display: flex;
    align-items: center;
    gap: 8px;
}
.export-group .action-btn--outline i { margin-right: 6px; color: var(--green-mid); }
@media (max-width: 640px) {
    .topbar-actions { margin-left: 0; width: 100%; justify-content: flex-end; }
}
/* Export buttons now live as a page-level toolbar at the top of the
   content area (not the header) — the header stays a simple title
   bar with just the profile chip, and the export actions sit closer
   to the data they act on. */
.content-toolbar {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 16px;
}
@media (max-width: 640px) {
    .content-toolbar { justify-content: stretch; }
    .content-toolbar .export-group { width: 100%; }
    .content-toolbar .export-group .action-btn { flex: 1; justify-content: center; }
}
</style>
<link rel="stylesheet" href="assets/css/dashboard.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
</head>
<body class="dashboard-page dashboard-page--finance">

<div class="app-shell">

    <?php $active = 'finance_dashboard'; include 'includes/sidebar.php'; ?>

    <div class="main-area">

        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_dashboard ?></div>
            <h1>Dashboard</h1>
            <div class="topbar-actions">
                <div class="topbar-user">
                    <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'F') ?><?php endif; ?></span>
                    <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Finance') ?></span>
                </div>
            </div>
        </header>

        <main class="content">
            <section class="dashboard-hero" aria-labelledby="dashboard-greeting">
                <div class="dashboard-hero__copy">
                    <span class="dashboard-hero__label">Finance Officer</span>
                    <h2 id="dashboard-greeting"><?= date('G') < 12 ? 'Good morning' : (date('G') < 18 ? 'Good afternoon' : 'Good evening') ?>, <?= clean($_SESSION['full_name'] ?? 'Finance') ?>.</h2>
                    <p>Cash flow, expenses, and reports you can trust.</p>
                </div>
                <a class="dashboard-primary-action" href="finance.php">Review approvals <span aria-hidden="true">→</span></a>
            </section>

            <!-- STAT CARDS -->
            <section class="stat-grid stat-grid--5">
                <div class="stat-card accent-mustard">
                    <span class="stat-label">Pending Approvals</span>
                    <span class="stat-value"><?= $total_pending_approvals ?></span>
                </div>
                <a href="accounts_payable.php#po-commitments" class="stat-card accent-red" style="text-decoration:none;">
                    <span class="stat-label">PO Commitments (awaiting receipt)</span>
                    <span class="stat-value"><?= peso($po_committed_alltime) ?></span>
                    <span style="font-size:12px; color:var(--ink-soft);">Not yet payable; view in Accounts Payable →</span>
                </a>
                <div class="stat-card accent-green">
                    <span class="stat-label">PO Expenses Received (this month)</span>
                    <span class="stat-value"><?= peso($po_received_this_month) ?></span>
                </div>
                <a href="accounts_payable.php" class="stat-card accent-mustard" style="text-decoration:none;">
                    <span class="stat-label">Accounts Payable Outstanding</span>
                    <span class="stat-value" style="<?= $ap_outstanding_total > 0 ? 'color:var(--red-alert)' : '' ?>"><?= peso($ap_outstanding_total) ?></span>
                </a>
                <div class="stat-card accent-leaf">
                    <span class="stat-label">Business Cash Position</span>
                    <span class="stat-value" style="<?= !empty($cash_position['payroll_data_available']) && $cash_position['available_cash'] < 0 ? 'color:var(--red-alert)' : '' ?>"><?= !empty($cash_position['payroll_data_available']) ? peso($cash_position['available_cash']) : 'Unavailable' ?></span>
                </div>
            </section>

            <?php if (empty($cash_position['payroll_data_available'])): ?>
                <div style="margin:16px 0; background:var(--red-pale); color:var(--red-alert); border:1px solid var(--line); border-radius:12px; padding:12px 16px; font-size:13.5px; line-height:1.5;">
                    ⚠️ Available cash cannot be verified because HRMS payroll records are unavailable. Cash payments must wait until payroll data can be checked.
                </div>
            <?php elseif ((float) $cash_position['available_cash'] < 0): ?>
                <div style="margin:16px 0; background:var(--red-pale); color:var(--red-alert); border:1px solid var(--line); border-radius:12px; padding:12px 16px; font-size:13.5px; line-height:1.5;">
                    ⚠️ Business Cash Position is negative by <?= peso($cash_position['cash_shortfall']) ?>. Outstanding bills remain liabilities. Add capital on <a href="finance.php#business-capital" style="font-weight:700; color:var(--text-accent);">Finance</a> before releasing more cash.
                </div>
            <?php elseif ((float) $ap_outstanding_total > 0 && (float) $cash_position['available_cash'] < (float) $ap_outstanding_total): ?>
                <?php $apFundingGap = (float) $ap_outstanding_total - (float) $cash_position['available_cash']; ?>
                <div style="margin:16px 0; background:var(--mustard-pale); color:var(--ink); border:1px solid var(--line); border-radius:12px; padding:12px 16px; font-size:13.5px; line-height:1.5;">
                    ⚠️ Outstanding payables exceed available cash by <?= peso($apFundingGap) ?>. Unpaid amounts remain liabilities. Add capital before settling the full balance on <a href="finance.php#business-capital" style="font-weight:700; color:var(--text-accent);">Finance</a>, or record a partial payment within available cash.
                </div>
            <?php endif; ?>

            <?php include 'includes/announcements_panel.php'; ?>

            <!-- EXPORT TOOLBAR (lives right below Announcements, above the rest of the page content it exports) -->
            <div class="content-toolbar">
                <div class="export-group">
                    <?= exportPostForm('finance_dashboard.php?export=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                    <?= exportPostForm('finance_dashboard.php?export=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
                </div>
            </div>

            <div class="dash-two-col">
                <!-- PENDING FINANCIAL APPROVALS -->
                <div class="panel">
                    <div class="panel-header">
                        <h2>Pending Financial Approvals</h2>
                        <a href="finance.php" style="font-size:12.5px; font-weight:600; color: var(--text-accent);">Review &amp; act →</a>
                    </div>
                    <div class="panel-body" style="padding: 4px 22px 20px;">
                        <ul class="approval-list">
                            <li>
                                <div>
                                    <div class="al-name">Restock Requests</div>
                                    <div class="al-sub">Estimated cost validated by Inventory Staff, awaiting your approval</div>
                                </div>
                                <span class="approval-count-badge <?= $pending_restock_count === 0 ? 'zero' : '' ?>"><?= $pending_restock_count ?></span>
                            </li>
                            <li>
                                <div>
                                    <div class="al-name">Purchase Orders</div>
                                    <div class="al-sub">Submitted by Inventory Staff / Admin, awaiting financial approval</div>
                                </div>
                                <span class="approval-count-badge <?= $pending_po_count === 0 ? 'zero' : '' ?>"><?= $pending_po_count ?></span>
                            </li>
                            <li>
                                <div>
                                    <div class="al-name">Discount Requests</div>
                                    <div class="al-sub">Manual (percent/fixed) discounts from the POS register</div>
                                </div>
                                <span class="approval-count-badge <?= $pending_discount_count === 0 ? 'zero' : '' ?>"><?= $pending_discount_count ?></span>
                            </li>
                            <li>
                                <div>
                                    <div class="al-name">Product Discount Changes</div>
                                    <div class="al-sub">Standing discount edits requested from Products</div>
                                </div>
                                <span class="approval-count-badge <?= $pending_product_discount_count === 0 ? 'zero' : '' ?>"><?= $pending_product_discount_count ?></span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- PAYMENT / EXPENSE SUMMARY -->
                <div class="panel">
                    <div class="panel-header">
                        <h2>Payment / Expense Summary</h2>
                        <a href="finance.php" style="font-size:12.5px; font-weight:600; color: var(--text-accent);">Full ledger →</a>
                    </div>
                    <div class="panel-body" style="padding: 4px 22px 20px;">
                        <ul class="approval-list">
                            <li>
                                <div>
                                    <div class="al-name">Total Restock Cost</div>
                                    <div class="al-sub">All-time — direct restocks + committed/approved requests</div>
                                </div>
                                <span style="font-weight:700; color:var(--red-alert)"><?= peso($total_restock_cost) ?></span>
                            </li>
                            <li>
                                <div>
                                    <div class="al-name">Capital In (all-time)</div>
                                    <div class="al-sub">Owner cash added to the business</div>
                                </div>
                                <span style="font-weight:700; color:var(--green-mid)"><?= peso($cash_position['capital_in']) ?></span>
                            </li>
                            <li>
                                <div>
                                    <div class="al-name">Capital Out (all-time)</div>
                                    <div class="al-sub">Owner withdrawals</div>
                                </div>
                                <span style="font-weight:700; color:var(--red-alert)"><?= peso($cash_position['capital_out']) ?></span>
                            </li>
                            <li>
                                <div>
                                    <div class="al-name">Payroll Released (all-time, HRMS)</div>
                                    <div class="al-sub">Approved payroll runs already paid out</div>
                                </div>
                                <span style="font-weight:700;"><?= peso($cash_position['payroll_released']) ?></span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- PURCHASE ORDER SUMMARY -->
            <div class="panel">
                <div class="panel-header">
                    <h2>Purchase Order Summary</h2>
                    <a href="purchase_orders.php" style="font-size:12.5px; font-weight:600; color: var(--text-accent);">View all →</a>
                </div>
                <div class="po-status-pill-row">
                    <?php foreach ($po_status_labels as $key => $label): ?>
                        <div class="po-status-pill"><b><?= $po_status_counts[$key] ?? 0 ?></b> <?= clean($label) ?></div>
                    <?php endforeach; ?>
                </div>
                <div class="panel-body panel-body--table" style="padding-top:0;">
                    <table class="data-table data-table--simple">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>Supplier</th>
                                <th>Status</th>
                                <th>Total</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_pos)): ?>
                                <tr><td colspan="5" class="empty-row">No Purchase Orders yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_pos as $po):
                                    $colorMap = [
                                        'draft' => 'neutral', 'pending_approval' => 'mustard', 'approved' => 'green', 'pending_supplier' => 'mustard',
                                        'rejected' => 'red', 'ordered' => 'mustard', 'partially_received' => 'mustard',
                                        'received' => 'green', 'cancelled' => 'red',
                                    ];
                                ?>
                                    <tr>
                                        <td style="font-weight:600"><?= clean($po['po_number'] ?: ('#' . $po['id'])) ?></td>
                                        <td><?= clean($po['company_name']) ?></td>
                                        <td><span class="po-status-badge po-status-badge--<?= $colorMap[$po['status']] ?? 'neutral' ?>"><?= clean($po_status_labels[$po['status']] ?? $po['status']) ?></span></td>
                                        <td><?= peso($po['total_amount']) ?></td>
                                        <td style="font-size:13px; color:var(--ink-soft)"><?= date('M d, Y', strtotime($po['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- RECENT CAPITAL / OWNER TRANSACTIONS -->
            <div class="panel">
                <div class="panel-header">
                    <h2>Recent Capital / Owner Transactions</h2>
                    <a href="finance.php" style="font-size:12.5px; font-weight:600; color: var(--text-accent);">View full ledger →</a>
                </div>
                <div class="panel-body panel-body--table">
                    <table class="data-table data-table--simple">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_capital)): ?>
                                <tr><td colspan="4" class="empty-row">No capital transactions recorded yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_capital as $c): ?>
                                    <tr>
                                        <td style="font-size:13px; color:var(--ink-soft)"><?= date('M d, Y', strtotime($c['created_at'])) ?></td>
                                        <td style="font-weight:600; color:<?= $c['type'] === 'capital_in' ? 'var(--green-mid)' : 'var(--red-alert)' ?>"><?= $c['type'] === 'capital_in' ? 'Capital In' : 'Capital Out' ?></td>
                                        <td><?= peso($c['amount']) ?></td>
                                        <td style="font-size:13px"><?= clean($c['reason']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <section class="quick-actions">
                <a href="finance.php" class="action-btn">Go to Approvals</a>
                <a href="purchase_orders.php" class="action-btn">View Purchase Orders</a>
            </section>

            <footer class="dashboard-footer">
                <span>Last updated <?= date('M j, Y g:i A') ?></span>
                <a href="financial_audit_log.php">View audit log →</a>
                <button type="button" class="theme-toggle-btn dashboard-footer-theme-toggle" data-theme-storage-key="siaa_theme_<?= clean((string) ($_SESSION['user_id'] ?? 'guest')) ?>" aria-label="Switch light/dark mode">
                    <svg class="tt-icon-light" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="4" stroke="currentColor" stroke-width="1.6"/><path d="M10 2.5v2M10 15.5v2M17.5 10h-2M4.5 10h-2M15.3 4.7l-1.4 1.4M6.1 13.9l-1.4 1.4M15.3 15.3l-1.4-1.4M6.1 6.1 4.7 4.7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                    <svg class="tt-icon-dark" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M17 11.3A7 7 0 0 1 8.7 3 7 7 0 1 0 17 11.3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                    <span>Dark mode</span>
                </button>
            </footer>

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