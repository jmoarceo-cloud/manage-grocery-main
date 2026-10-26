<?php
require_once 'includes/config.php';
requireAdmin();

$stmt = $pdo->query("SELECT COUNT(*) FROM products");
$total_products = $stmt->fetchColumn();

// Kasama na ang 'partial_refunded' — pero ang na-refund lang na parte
// ang ikinakaltas, hindi ang buong transaction (see reports.php note).
$stmt = $pdo->query("
    SELECT SUM(s.total_amount - COALESCE(sr.refunded_amount, 0))
    FROM sales s
    LEFT JOIN (SELECT sale_id, SUM(amount) AS refunded_amount FROM sale_refunds GROUP BY sale_id) sr ON sr.sale_id = s.id
    WHERE DATE(s.transaction_date) = CURDATE() AND s.status IN ('completed', 'partial_refunded')
");
$today_sales = $stmt->fetchColumn() ?? 0;

$stmt = $pdo->query("SELECT COUNT(*) FROM sales WHERE DATE(transaction_date) = CURDATE() AND status IN ('completed', 'partial_refunded')");
$today_transactions = $stmt->fetchColumn();

// Removed "AND stock > 0" so out-of-stock products (stock = 0) are also counted as low stock
$stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE stock <= :low_stock");
$stmt->execute([':low_stock' => LOW_STOCK]);
$low_stock_count = $stmt->fetchColumn();

// "Recent Transactions" — completed sales lang, para hindi kasama ang
// mga na-void/na-refund na na (may sarili nang badge/screen ang mga
// iyon sa Sales Report / Shift Report) sa pinaka-madaling nakikita
// nating figure sa Dashboard.
$stmt = $pdo->query("
    SELECT sales.id, sales.total_amount, users.full_name AS cashier_name
    FROM sales
    JOIN users ON users.id = sales.cashier_id
    WHERE sales.status = 'completed'
    ORDER BY sales.transaction_date DESC
    LIMIT 5
");
$recent_transactions = $stmt->fetchAll();

// Items bought per transaction, for the "Items" column in Recent Transactions.
$recent_txn_items = [];
if (!empty($recent_transactions)) {
    $recentIds = array_column($recent_transactions, 'id');
    $ph = implode(',', array_fill(0, count($recentIds), '?'));
    $stmt = $pdo->prepare("
        SELECT sd.sale_id, sd.quantity, p.product_name
        FROM sales_details sd
        JOIN products p ON p.id = sd.product_id
        WHERE sd.sale_id IN ($ph)
        ORDER BY sd.id ASC
    ");
    $stmt->execute($recentIds);
    foreach ($stmt->fetchAll() as $row) {
        $recent_txn_items[$row['sale_id']][] = $row['product_name'] . ' (x' . (int) $row['quantity'] . ')';
    }
}

// Removed "AND stock > 0" so out-of-stock products also appear in the low stock list
$stmt = $pdo->prepare("SELECT product_name, stock FROM products WHERE stock <= :low_stock ORDER BY stock ASC LIMIT 5");
$stmt->execute([':low_stock' => LOW_STOCK]);
$low_stock_products = $stmt->fetchAll();

checkLowStock($pdo);

// --- Pending Approvals (across every queue Admin can also act on,
// same four sources as finance.php / finance_dashboard.php) ---
$pending_restock_count = (int) $pdo->query("SELECT COUNT(*) FROM inventory_requests WHERE status = 'pending_finance'")->fetchColumn();
$pending_po_count = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'pending_approval'")->fetchColumn();
$pending_discount_count = 0;
try {
    $pending_discount_count = (int) $pdo->query("SELECT COUNT(*) FROM discount_requests WHERE status = 'pending'")->fetchColumn();
} catch (Exception $e) {
}
$pending_product_discount_count = 0;
try {
    $pending_product_discount_count = (int) $pdo->query("SELECT COUNT(*) FROM product_discount_requests WHERE status = 'pending'")->fetchColumn();
} catch (Exception $e) {
}
$total_pending_approvals = $pending_restock_count + $pending_po_count + $pending_discount_count + $pending_product_discount_count;

// --- Purchase Order Summary (counts by status) ---
$po_status_counts_raw = $pdo->query("SELECT status, COUNT(*) AS c FROM purchase_orders GROUP BY status")->fetchAll();
$po_status_counts = [];
foreach ($po_status_counts_raw as $row) {
    $po_status_counts[$row['status']] = (int) $row['c'];
}
$po_status_labels = [
    'draft' => 'Draft',
    'pending_approval' => 'Pending Approval',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'ordered' => 'Ordered',
    'partially_received' => 'Partially Received',
    'received' => 'Received',
    'cancelled' => 'Cancelled',
];

// --- Financial overview (all-time Business Cash Position — same
// figure Finance sees, kept read-only/high-level here) ---
$cash_position = getBusinessCashPosition($pdo, $hrmsPdo);

// --- User & System Activity Summary: latest activity across
// inventory (stock_logs) and the Purchase Order workflow, merged
// and sorted, newest first — a lightweight cross-module audit view. ---
$recent_stock_activity = $pdo->query("
    SELECT sl.date_created AS activity_date, u.full_name AS actor,
           CONCAT(UPPER(sl.change_type), ': ', p.product_name) AS activity_desc
    FROM stock_logs sl
    JOIN products p ON p.id = sl.product_id
    JOIN users u ON u.id = sl.user_id
    ORDER BY sl.date_created DESC
    LIMIT 6
")->fetchAll();

$recent_po_activity = $pdo->query("
    SELECT po.updated_at AS activity_date,
           COALESCE(po.created_by_name, 'System') AS actor,
           CONCAT('PO ', COALESCE(po.po_number, CONCAT('#', po.id)), ' — ', UPPER(po.status)) AS activity_desc
    FROM purchase_orders po
    ORDER BY po.updated_at DESC
    LIMIT 6
")->fetchAll();

$system_activity = array_merge($recent_stock_activity, $recent_po_activity);
usort($system_activity, function ($a, $b) {
    return strtotime($b['activity_date']) <=> strtotime($a['activity_date']); });
$system_activity = array_slice($system_activity, 0, 8);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — HCI GroceryOne</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet"
        href="<?= htmlspecialchars(systemBaseUrl() . '/style.css?v=' . (@filemtime(__DIR__ . '/style.css') ?: time()), ENT_QUOTES, 'UTF-8') ?>">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .badge-out-of-stock {
            display: inline-block;
            background: var(--red-pale);
            color: var(--red-alert);
            font-size: 12px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 999px;
            white-space: nowrap;
        }
    </style>
    <link rel="stylesheet"
        href="assets/css/dashboard.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
</head>

<body class="dashboard-page dashboard-page--administrator">

    <div class="app-shell">

        <?php $active = 'dashboard';
        include 'includes/sidebar.php'; ?>

        <div class="main-area">

            <header class="topbar">
                <div class="topbar-icon"><?= $__icon_dashboard ?></div>
                <h1>Dashboard</h1>
                <div class="topbar-user">
                    <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img
                                src="<?= htmlspecialchars(systemBaseUrl() . '/uploads/avatars/' . rawurlencode(basename((string) $_SESSION['profile_picture'])), ENT_QUOTES, 'UTF-8') ?>"
                                alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'A') ?><?php endif; ?></span>
                    <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Administrator') ?></span>
                </div>
            </header>

            <main class="content">
                <section class="dashboard-hero" aria-labelledby="dashboard-greeting">
                    <div class="dashboard-hero__copy">
                        <span class="dashboard-hero__label">Administrator</span>
                        <h2 id="dashboard-greeting">
                            <?= date('G') < 12 ? 'Good morning' : (date('G') < 18 ? 'Good afternoon' : 'Good evening') ?>,
                            <?= clean($_SESSION['full_name'] ?? 'Administrator') ?>.</h2>
                        <p>Full oversight of the system, users, and audit trail.</p>
                    </div>
                    <a class="dashboard-primary-action" href="finance.php">Review approvals <span
                            aria-hidden="true">→</span></a>
                </section>

                <!-- STAT CARDS -->
                <section class="stat-grid">
                    <div class="stat-card">
                        <span class="stat-label">Total Products</span>
                        <span class="stat-value"><?= $total_products ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Transactions Today</span>
                        <span class="stat-value"><?= $today_transactions ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Sales Today</span>
                        <span class="stat-value"><?= peso($today_sales) ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Low Stock</span>
                        <span class="stat-value"><?= $low_stock_count ?></span>
                    </div>
                </section>

                <!-- BUSINESS + APPROVALS OVERVIEW -->
                <section class="stat-grid">
                    <div class="stat-card" style="border-left:4px solid var(--mustard);">
                        <span class="stat-label">Pending Approvals</span>
                        <span class="stat-value"><?= $total_pending_approvals ?></span>
                        <a href="finance.php" style="font-size:12px; font-weight:600; color:var(--text-accent);">Review
                            →</a>
                    </div>
                    <div class="stat-card" style="border-left:4px solid var(--mustard);">
                        <span class="stat-label">Purchase Orders Awaiting Approval</span>
                        <span class="stat-value"><?= $po_status_counts['pending_approval'] ?? 0 ?></span>
                        <a href="purchase_orders.php"
                            style="font-size:12px; font-weight:600; color:var(--text-accent);">View →</a>
                    </div>
                    <div class="stat-card" style="border-left:4px solid var(--green-mid);">
                        <span class="stat-label">Business Cash Position</span>
                        <span class="stat-value"
                            style="<?= $cash_position['available_cash'] < 0 ? 'color:var(--red-alert)' : '' ?>"><?= peso($cash_position['available_cash']) ?></span>
                        <a href="finance.php" style="font-size:12px; font-weight:600; color:var(--text-accent);">Details
                            →</a>
                    </div>
                </section>

                <!-- PURCHASE ORDER SUMMARY -->
                <div class="panel">
                    <div class="panel-header">
                        <h2>Purchase Order Summary</h2>
                        <a href="purchase_orders.php"
                            style="font-size:12.5px; font-weight:600; color: var(--text-accent);">View all →</a>
                    </div>
                    <div style="display:flex; flex-wrap:wrap; gap:10px; padding:4px 22px 20px;">
                        <?php foreach ($po_status_labels as $key => $label): ?>
                            <div
                                style="display:flex; align-items:center; gap:8px; background:var(--cream); border:1.5px solid var(--line); border-radius:12px; padding:8px 14px; font-size:12.5px; font-weight:600; color:var(--ink-soft);">
                                <b
                                    style="color:var(--ink); font-size:15px; font-family:var(--font-display);"><?= $po_status_counts[$key] ?? 0 ?></b>
                                <?= clean($label) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php include 'includes/announcements_panel.php'; ?>

                <section class="content-grid">
                    <div class="panel">
                        <div class="panel-header">
                            <h2>Recent Transactions</h2>
                        </div>
                        <div class="panel-body panel-body--table">
                            <table class="data-table data-table--simple">
                                <thead>
                                    <tr>
                                        <th>Cashier</th>
                                        <th>Items</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_transactions)): ?>
                                        <tr>
                                            <td colspan="3" class="empty-row">There are no transactions yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_transactions as $txn): ?>
                                            <tr>
                                                <td><?= clean($txn['cashier_name']) ?></td>
                                                <td><?= clean(implode(', ', $recent_txn_items[$txn['id']] ?? [])) ?: '—' ?></td>
                                                <td><?= peso($txn['total_amount']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-header">
                            <h2>Low Stock Products</h2>
                        </div>
                        <div class="panel-body panel-body--table">
                            <table class="data-table data-table--simple">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Stock</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($low_stock_products)): ?>
                                        <tr>
                                            <td colspan="2" class="empty-row">No low stock products.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($low_stock_products as $product): ?>
                                            <tr>
                                                <td><?= clean($product['product_name']) ?></td>
                                                <td>
                                                    <?php if ($product['stock'] == 0): ?>
                                                        <span class="badge-out-of-stock">Out of Stock</span>
                                                    <?php else: ?>
                                                        <?= $product['stock'] ?> pcs
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <!-- USER & SYSTEM ACTIVITY SUMMARY -->
                <div class="panel">
                    <div class="panel-header">
                        <h2>System Activity Summary</h2>
                        <span style="font-size:12.5px; color:var(--ink-soft);">Latest inventory changes and Purchase
                            Order updates</span>
                    </div>
                    <div class="panel-body panel-body--table">
                        <table class="data-table data-table--simple">
                            <thead>
                                <tr>
                                    <th>Activity</th>
                                    <th>By</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($system_activity)): ?>
                                    <tr>
                                        <td colspan="3" class="empty-row">No recent activity yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($system_activity as $a): ?>
                                        <tr>
                                            <td style="font-weight:600"><?= clean($a['activity_desc']) ?></td>
                                            <td><?= clean($a['actor']) ?></td>
                                            <td style="font-size:13px; color:var(--ink-soft)">
                                                <?= date('M d, Y h:i A', strtotime($a['activity_date'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <section class="quick-actions">
                    <a href="products.php?action=add" class="action-btn">Add Product</a>
                    <a href="reports.php" class="action-btn">Sales Report</a>
                    <a href="purchase_orders.php" class="action-btn">Purchase Orders</a>
                    <a href="finance.php" class="action-btn">Finance</a>
                </section>

                <footer class="dashboard-footer">
                    <span>Last updated <?= date('M j, Y g:i A') ?></span>
                    <a href="financial_audit_log.php">View audit log →</a>
                    <button type="button" class="theme-toggle-btn dashboard-footer-theme-toggle"
                        data-theme-storage-key="siaa_theme_<?= clean((string) ($_SESSION['user_id'] ?? 'guest')) ?>"
                        aria-label="Switch light/dark mode">
                        <svg class="tt-icon-light" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <circle cx="10" cy="10" r="4" stroke="currentColor" stroke-width="1.6" />
                            <path
                                d="M10 2.5v2M10 15.5v2M17.5 10h-2M4.5 10h-2M15.3 4.7l-1.4 1.4M6.1 13.9l-1.4 1.4M15.3 15.3l-1.4-1.4M6.1 6.1 4.7 4.7"
                                stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                        <svg class="tt-icon-dark" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="M17 11.3A7 7 0 0 1 8.7 3 7 7 0 1 0 17 11.3Z" stroke="currentColor"
                                stroke-width="1.6" stroke-linejoin="round" />
                        </svg>
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
        function confirmAction(e, form, opts) {
            e.preventDefault();
            Swal.fire(Object.assign({
                showCancelButton: true,
                cancelButtonText: 'Cancel'
            }, opts)).then(function (result) {
                if (result.isConfirmed) form.submit();
            });
            return false;
        }
    </script>

</body>

</html>