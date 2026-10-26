<?php
// ============================================================
// inventory_dashboard.php — Landing page for Inventory Staff
// (and Admin, if they want a quick glance). Read-only snapshot:
// financial summary, stock-level counts, low/out-of-stock and
// expiring-soon quick lists, and the latest stock movements.
// Actual actions (Restock / Adjust / Issue / add-product) stay
// on inventory.php and products.php — this page is purely for
// "what's going on right now" at a glance.
// ============================================================

require_once 'includes/config.php';
requireInventoryStaff();

// --- Stat counts (same logic as inventory.php) ---
$total_count = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$low_count   = $pdo->prepare("SELECT COUNT(*) FROM products WHERE stock <= :l AND stock > 0");
$low_count->execute([':l' => LOW_STOCK]);
$low_count   = $low_count->fetchColumn();
$out_count   = $pdo->query("SELECT COUNT(*) FROM products WHERE stock = 0")->fetchColumn();
$in_count    = $total_count - $low_count - $out_count;

// --- Financial snapshot (same logic as inventory.php) ---
$total_sales = (float) $pdo->query("
    SELECT COALESCE(SUM(s.total_amount - COALESCE(sr.refunded_amount, 0)), 0)
    FROM sales s
    LEFT JOIN (SELECT sale_id, SUM(amount) AS refunded_amount FROM sale_refunds GROUP BY sale_id) sr ON sr.sale_id = s.id
    WHERE s.status IN ('completed', 'partial_refunded')
")->fetchColumn();

// See getTotalRestockCost() in includes/config.php — counts requests
// Finance has approved immediately, not just ones already received.
$total_restock_cost = getTotalRestockCost($pdo);

$net_profit = $total_sales - $total_restock_cost;

// --- Needs Attention: lowest-stock products first (low + out of stock) ---
$needs_attention = $pdo->prepare("
    SELECT id, product_name, category, stock
    FROM products
    WHERE stock <= :l
    ORDER BY stock ASC, product_name ASC
    LIMIT 8
");
$needs_attention->execute([':l' => LOW_STOCK]);
$needs_attention = $needs_attention->fetchAll();

// --- Expiring Soon: nearest-expiry batch per product, within 30 days ---
$expiring_soon = $pdo->query("
    SELECT p.id, p.product_name, MIN(pb.expiry_date) AS nearest_expiry,
           SUM(pb.quantity_remaining) AS qty_in_expiring_batches
    FROM product_batches pb
    JOIN products p ON p.id = pb.product_id
    WHERE pb.quantity_remaining > 0
      AND pb.expiry_date IS NOT NULL
      AND pb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    GROUP BY p.id, p.product_name
    ORDER BY nearest_expiry ASC
    LIMIT 8
")->fetchAll();

// --- Recent Activity: latest 10 stock movements ---
$recent_activity = $pdo->query("
    SELECT sl.*, p.product_name, u.full_name AS done_by
    FROM stock_logs sl
    JOIN products p ON p.id = sl.product_id
    JOIN users u    ON u.id = sl.user_id
    ORDER BY sl.date_created DESC
    LIMIT 10
")->fetchAll();

// --- Pending Receiving Tasks: POs that are approved/ordered/partially
// received and still have quantity left to receive — this is the
// Inventory Staff's queue of "go receive this delivery" work. ---
$pending_receiving = $pdo->query("
    SELECT po.id, po.po_number, po.status, po.ordered_at, po.approved_at, s.company_name,
        (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.id) AS item_count,
        (SELECT COALESCE(SUM(quantity_ordered - quantity_received), 0) FROM purchase_order_items WHERE po_id = po.id) AS qty_remaining
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    WHERE po.status IN ('approved', 'ordered', 'partially_received')
    ORDER BY COALESCE(po.ordered_at, po.approved_at) ASC
    LIMIT 8
")->fetchAll();

// --- Recently Received Items: latest PO line items that have had
// stock actually received against them, newest PO update first. ---
$recently_received = $pdo->query("
    SELECT poi.item_name, poi.quantity_received, po.po_number, po.status, po.updated_at, s.company_name
    FROM purchase_order_items poi
    JOIN purchase_orders po ON po.id = poi.po_id
    JOIN suppliers s ON s.id = po.supplier_id
    WHERE poi.quantity_received > 0 AND po.status IN ('partially_received', 'received')
    ORDER BY po.updated_at DESC
    LIMIT 8
")->fetchAll();
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
.finance-card.sales .f-value   { color: var(--green-mid); }
.finance-card.cost .f-value    { color: var(--red-alert); }
.finance-card.profit .f-value.positive { color: var(--text-accent); }
.finance-card.profit .f-value.negative { color: var(--red-alert); }
.finance-card .f-note {
    font-size: 11.5px;
    color: var(--ink-soft);
    margin-top: 4px;
}
.stock-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}
.stock-badge.in    { background: #e6f4ea; color: #1a7a3b; }
.stock-badge.low   { background: var(--mustard-pale); color: #8a5c10; }
html[data-theme="dark"] .stock-badge.in  { background: var(--green-pale); color: var(--green-leaf); }
html[data-theme="dark"] .stock-badge.low { color: var(--mustard); }
.stock-badge.out   { background: var(--red-pale); color: var(--red-alert); }
.change-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 700;
}
.change-badge.restock  { background: #e6f4ea; color: #1a7a3b; }
.change-badge.sale     { background: #e8eeff; color: #2d4ecf; }
.change-badge.adjust   { background: var(--mustard-pale); color: #8a5c10; }
.change-badge.void     { background: #f3e8ff; color: #7b2fbe; }
.change-badge.issuance { background: #fde8e8; color: #b8391f; }
html[data-theme="dark"] .change-badge.restock  { background: var(--green-pale); color: var(--green-leaf); }
html[data-theme="dark"] .change-badge.sale     { background: #182449; color: #8fa4ff; }
html[data-theme="dark"] .change-badge.adjust   { color: var(--mustard); }
html[data-theme="dark"] .change-badge.void     { background: #2a1a40; color: #c79bf0; }
html[data-theme="dark"] .change-badge.issuance { background: var(--red-pale); color: var(--red-alert); }

.dash-two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}
@media (max-width: 900px) { .dash-two-col { grid-template-columns: 1fr; } }
.quick-list { list-style: none; margin: 0; padding: 0; }
.quick-list li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid var(--line);
    font-size: 13.5px;
}
.quick-list li:last-child { border-bottom: none; }
.quick-list .ql-name { font-weight: 600; }
.quick-list .ql-sub { color: var(--ink-soft); font-size: 12px; }
.quick-list .ql-empty { color: var(--ink-soft); text-align: center; padding: 1.5rem 0; font-size: 13.5px; }

.dashboard-page.dashboard-page--inventory .content {
    box-sizing: border-box;
    width: 100%;
    max-width: none;
    margin-inline: 0;
    padding: clamp(20px, 2.2vw, 34px) clamp(18px, 2.4vw, 40px) 44px;
}
.dashboard-page.dashboard-page--inventory .finance-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: clamp(14px, 1.5vw, 22px);
}
.dashboard-page.dashboard-page--inventory .finance-card { min-width: 0; }
.dashboard-page.dashboard-page--inventory .dash-two-col {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: clamp(16px, 1.8vw, 24px);
}
@media (max-width: 1024px) {
    .dashboard-page.dashboard-page--inventory .finance-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 900px) {
    .dashboard-page.dashboard-page--inventory .dash-two-col { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 639px) {
    .dashboard-page.dashboard-page--inventory .content { padding: 18px 14px 32px; }
    .dashboard-page.dashboard-page--inventory .finance-grid { grid-template-columns: minmax(0, 1fr); }
}
</style>
<link rel="stylesheet" href="assets/css/dashboard.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
</head>
<body class="dashboard-page dashboard-page--inventory">

<div class="app-shell">

    <!-- SIDEBAR -->
    <?php $active = 'inventory_dashboard'; include 'includes/sidebar.php'; ?>

    <!-- MAIN -->
    <div class="main-area">
        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_dashboard ?></div>
            <h1>Dashboard</h1>
            <div class="topbar-user">
                <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'I') ?><?php endif; ?></span>
                <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Inventory Staff') ?></span>
            </div>
        </header>

        <main class="content">
            <section class="dashboard-hero" aria-labelledby="dashboard-greeting">
                <div class="dashboard-hero__copy">
                    <span class="dashboard-hero__label">Inventory Clerk</span>
                    <h2 id="dashboard-greeting"><?= date('G') < 12 ? 'Good morning' : (date('G') < 18 ? 'Good afternoon' : 'Good evening') ?>, <?= clean($_SESSION['full_name'] ?? 'Inventory team') ?>.</h2>
                    <p>Counts, adjustments, and reorder alerts.</p>
                </div>
                <a class="dashboard-primary-action" href="inventory.php">Review inventory <span aria-hidden="true">→</span></a>
            </section>

            <!-- FINANCIAL SUMMARY -->
            <section class="finance-grid">
                <div class="finance-card sales">
                    <span class="f-label">Total Sales</span>
                    <div class="f-value"><?= peso($total_sales) ?></div>
                    <div class="f-note">All-time income from completed sales</div>
                </div>
                <div class="finance-card cost">
                    <span class="f-label">Total Restock Cost</span>
                    <div class="f-value"><?= peso($total_restock_cost) ?></div>
                    <div class="f-note">All-time spent on restocking products</div>
                </div>
                <div class="finance-card profit">
                    <span class="f-label">Cash Flow</span>
                    <div class="f-value <?= $net_profit >= 0 ? 'positive' : 'negative' ?>"><?= peso($net_profit) ?></div>
                    <div class="f-note">Total Sales − Total Restock Cost</div>
                </div>
            </section>

            <!-- STAT CARDS -->
            <section class="stat-grid">
                <div class="stat-card">
                    <span class="stat-label">Total Products</span>
                    <span class="stat-value"><?= $total_count ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">In Stock</span>
                    <span class="stat-value" style="color:var(--green-mid)"><?= $in_count ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Low Stock</span>
                    <span class="stat-value" style="color:var(--mustard)"><?= $low_count ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Out of Stock</span>
                    <span class="stat-value" style="color:var(--red-alert)"><?= $out_count ?></span>
                </div>
            </section>

            <?php include 'includes/announcements_panel.php'; ?>

            <div class="dash-two-col">
                <!-- NEEDS ATTENTION -->
                <div class="panel">
                    <div class="panel-header">
                        <h2>Needs Attention</h2>
                        <a href="inventory.php?status=low" style="font-size:12.5px; font-weight:600; color: var(--text-accent);">View all →</a>
                    </div>
                    <div class="panel-body" style="padding: 8px 20px;">
                        <?php if (empty($needs_attention)): ?>
                            <p class="ql-empty">Walang low-stock o out-of-stock na item ngayon. 🎉</p>
                        <?php else: ?>
                            <ul class="quick-list">
                                <?php foreach ($needs_attention as $p): ?>
                                    <li>
                                        <div>
                                            <div class="ql-name"><?= clean($p['product_name']) ?></div>
                                            <div class="ql-sub"><?= clean($p['category']) ?></div>
                                        </div>
                                        <span class="stock-badge <?= $p['stock'] == 0 ? 'out' : 'low' ?>">
                                            <?= $p['stock'] == 0 ? 'Out of Stock' : $p['stock'] . ' pcs left' ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- EXPIRING SOON -->
                <div class="panel">
                    <div class="panel-header">
                        <h2>Expiring Soon (30 days)</h2>
                        <a href="inventory_reports.php" style="font-size:12.5px; font-weight:600; color: var(--text-accent);">View all →</a>
                    </div>
                    <div class="panel-body" style="padding: 8px 20px;">
                        <?php if (empty($expiring_soon)): ?>
                            <p class="ql-empty">Walang batch na malapit nang mag-expire.</p>
                        <?php else: ?>
                            <ul class="quick-list">
                                <?php foreach ($expiring_soon as $e):
                                    $status = getExpiryStatus($e['nearest_expiry']);
                                ?>
                                    <li>
                                        <div>
                                            <div class="ql-name"><?= clean($e['product_name']) ?></div>
                                            <div class="ql-sub"><?= (int) $e['qty_in_expiring_batches'] ?> pcs in this batch</div>
                                        </div>
                                        <span style="font-size:12.5px; font-weight:700; color:<?= $status['color'] ?>">
                                            <?= $status['icon'] ?> <?= clean($status['label']) ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="dash-two-col">
                <!-- PENDING RECEIVING TASKS -->
                <div class="panel">
                    <div class="panel-header">
                        <h2>Pending Receiving Tasks</h2>
                        <a href="purchase_orders.php" style="font-size:12.5px; font-weight:600; color: var(--text-accent);">View all →</a>
                    </div>
                    <div class="panel-body" style="padding: 8px 20px;">
                        <?php if (empty($pending_receiving)): ?>
                            <p class="ql-empty">No Purchase Orders waiting to be received right now.</p>
                        <?php else: ?>
                            <ul class="quick-list">
                                <?php foreach ($pending_receiving as $po):
                                    $statusLabel = ['approved' => 'Approved', 'ordered' => 'Ordered', 'partially_received' => 'Partially Received'][$po['status']] ?? $po['status'];
                                ?>
                                    <li>
                                        <div>
                                            <div class="ql-name"><?= clean($po['po_number'] ?: ('#' . $po['id'])) ?> — <?= clean($po['company_name']) ?></div>
                                            <div class="ql-sub"><?= (int) $po['item_count'] ?> item(s), <?= (int) $po['qty_remaining'] ?> pcs remaining</div>
                                        </div>
                                        <span class="stock-badge low"><?= clean($statusLabel) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- RECENTLY RECEIVED ITEMS -->
                <div class="panel">
                    <div class="panel-header">
                        <h2>Recently Received Items</h2>
                        <a href="purchase_orders.php" style="font-size:12.5px; font-weight:600; color: var(--text-accent);">View all →</a>
                    </div>
                    <div class="panel-body" style="padding: 8px 20px;">
                        <?php if (empty($recently_received)): ?>
                            <p class="ql-empty">No items received yet.</p>
                        <?php else: ?>
                            <ul class="quick-list">
                                <?php foreach ($recently_received as $r): ?>
                                    <li>
                                        <div>
                                            <div class="ql-name"><?= clean($r['item_name']) ?></div>
                                            <div class="ql-sub"><?= clean($r['po_number']) ?> — <?= clean($r['company_name']) ?></div>
                                        </div>
                                        <span class="stock-badge in">+<?= (int) $r['quantity_received'] ?> pcs</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- RECENT ACTIVITY -->
            <div class="panel">
                <div class="panel-header">
                    <h2>Recent Activity (Latest 10)</h2>
                    <a href="inventory_reports.php" style="font-size:12.5px; font-weight:600; color: var(--text-accent);">View full history →</a>
                </div>
                <div class="panel-body panel-body--table">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Product</th>
                                <th>Change</th>
                                <th>Before → After</th>
                                <th>Reason</th>
                                <th>By</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_activity)): ?>
                                <tr><td colspan="7" class="empty-row">No stock change history yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_activity as $log):
                                    $type_map = [
                                        'restock'  => 'restock',
                                        'sale'     => 'sale',
                                        'void'     => 'void',
                                        'adjust'   => 'adjust',
                                        'issuance' => 'issuance',
                                    ];
                                    $type_class = $type_map[$log['change_type']] ?? 'adjust';
                                    $sign = $log['quantity_change'] >= 0 ? '+' : '';
                                ?>
                                    <tr>
                                        <td><span class="change-badge <?= $type_class ?>"><?= strtoupper($log['change_type']) ?></span></td>
                                        <td style="font-weight:600"><?= clean($log['product_name']) ?></td>
                                        <td style="font-weight:700; color:<?= $log['quantity_change'] >= 0 ? 'var(--green-mid)' : 'var(--red-alert)' ?>">
                                            <?= $sign . $log['quantity_change'] ?> pcs
                                        </td>
                                        <td style="color:var(--ink-soft); font-size:13px"><?= $log['previous_stock'] ?> → <?= $log['new_stock'] ?></td>
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

            <footer class="dashboard-footer">
                <span>Last updated <?= date('M j, Y g:i A') ?></span>
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