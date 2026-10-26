<?php
require_once 'includes/config.php';
requireSupervisorRole();

$supervisorEmployeeId = isSupervisorRole() ? (int) ($_SESSION['employee_id'] ?? 0) : dec_id($_GET['supervisor_id'] ?? '');
// Admin browsing without picking a specific Supervisor sees every
// Supervisor's team combined, instead of an empty page.
$viewingAllTeams = isAdminRole() && $supervisorEmployeeId === 0;
$teamIds = $viewingAllTeams ? getAllSupervisedEmployeeIds($pdo) : getSupervisorTeamEmployeeIds($pdo, $supervisorEmployeeId);

// Inventory Staff (and any other team member with a POS account) on
// this Supervisor's team, so activity can be scoped/authorized against
// stock_logs.user_id — same mapping supervisor_pos_monitor.php uses
// for sales.
$posUserMap = getPosUserIdsForEmployees($posPdo, $teamIds); // [employee_id => pos_user_id]
$teamPosUserIds = array_values($posUserMap);

$success_message = "";
$error_message   = "";
$LOW_STOCK_THRESHOLD = 15;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_restock_request') {
        // Restock workflow v2 (confirmed Aug 2026 — see the flow
        // diagram): Supervisor submits -> Inventory Staff validates
        // (checks actual stock vs. the requested quantity, sets the
        // estimated cost) -> Finance approves/rejects -> Inventory
        // Staff receives the delivery and restocks. See
        // inventory_requests.php for the rest of this chain.
        if (!isSupervisorRole()) {
            $error_message = "Only Supervisors can submit a restock request from this page.";
        } else {
            $product_id = $_POST['product_id'] ?? '';
            $item_name  = trim($_POST['item_name'] ?? '');
            $quantity   = (int) ($_POST['quantity'] ?? 0);
            $notes      = trim($_POST['notes'] ?? '');

            if ($supervisorEmployeeId <= 0) {
                $error_message = "Your account isn't linked to an HRMS employee record, so a request can't be attributed to you. Please contact Admin.";
            } elseif ($quantity <= 0) {
                $error_message = "Please enter a valid quantity.";
            } elseif ($item_name === '') {
                $error_message = "Please provide an item name (pick from the list, or type one for a new item).";
            } elseif (!$posPdo) {
                $error_message = "Inventory system is currently unreachable. Please try again later.";
            } else {
                try {
                    $selectedProductId = $product_id !== '' ? (int) $product_id : null;
                    $selectedItemName = $item_name;
                    $supplierId = null;
                    if ($selectedProductId !== null) {
                        $productStmt = $posPdo->prepare("SELECT product_name, supplier_id FROM products WHERE id = ? AND is_active = 1 LIMIT 1");
                        $productStmt->execute([$selectedProductId]);
                        $selectedProduct = $productStmt->fetch();
                        if (!$selectedProduct) {
                            $error_message = "The selected product is no longer active.";
                        } elseif (empty($selectedProduct['supplier_id'])) {
                            $error_message = "This product has no registered supplier. Link it to a supplier in Products before requesting procurement.";
                        } else {
                            $selectedItemName = (string) $selectedProduct['product_name'];
                            $supplierId = (int) $selectedProduct['supplier_id'];
                        }
                    }
                    if ($error_message !== '') {
                        throw new RuntimeException($error_message);
                    }
                    $stmt = $posPdo->prepare("
                        INSERT INTO inventory_requests (requested_by_employee_id, product_id, supplier_id, item_name, quantity, notes, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'pending_validation')
                    ");
                    $stmt->execute([$supervisorEmployeeId, $selectedProductId, $supplierId, $selectedItemName, $quantity, $notes ?: null]);
                    $success_message = "Restock request submitted — it will go to Inventory Staff for validation first.";
                } catch (PDOException $e) {
                    error_log("Submit restock request error (supervisor_inventory.php): " . $e->getMessage());
                    $error_message = "Could not submit this request. Please try again.";
                } catch (RuntimeException $e) {
                    $error_message = $e->getMessage();
                }
            }
        }
    } elseif ($action === 'cancel_own_restock_request') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        if (!$posPdo) {
            $error_message = "Inventory system is currently unreachable. Please try again later.";
        } else {
            try {
                $posPdo->prepare("DELETE FROM inventory_requests WHERE request_id = ? AND requested_by_employee_id = ? AND status = 'pending_validation'")
                    ->execute([$request_id, $supervisorEmployeeId]);
                $success_message = "Request cancelled.";
            } catch (PDOException $e) {
                error_log("Cancel own restock request error: " . $e->getMessage());
                $error_message = "Could not cancel this request.";
            }
        }
    }
}

// Default range widened from 7 days to 90 days — a 7-day default made
// it look like older restock/adjust history had disappeared, when it
// was just outside the filter window. "All Time" is available via the
// quick-filter buttons below for anything older than that.
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days'));
$dateTo   = $_GET['date_to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $dateFrom = date('Y-m-d', strtotime('-90 days')); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   { $dateTo   = date('Y-m-d'); }
$__allTimeFrom = '2000-01-01';

$products = [];
$lowStockProducts = [];
$activityLogs = [];
$rangeRestocked = 0;
$rangeAdjusted  = 0;

if ($posPdo) {
    try {
        $products = $posPdo->query("SELECT id, product_name, category, stock, supplier_id FROM products WHERE is_active = 1 ORDER BY product_name")->fetchAll();
        $lowStockProducts = array_values(array_filter($products, fn($p) => (int) $p['stock'] <= $LOW_STOCK_THRESHOLD));

        if (!empty($teamPosUserIds)) {
            $ph = implode(',', array_fill(0, count($teamPosUserIds), '?'));
            $stmt = $posPdo->prepare("
                SELECT sl.*, p.product_name, p.category, u.full_name AS done_by
                FROM stock_logs sl
                JOIN products p ON p.id = sl.product_id
                JOIN users u    ON u.id = sl.user_id
                WHERE sl.change_type IN ('restock', 'adjust', 'issuance')
                  AND sl.user_id IN ($ph)
                  AND DATE(sl.date_created) BETWEEN ? AND ?
                ORDER BY sl.date_created DESC
                LIMIT 100
            ");
            $stmt->execute(array_merge($teamPosUserIds, [$dateFrom, $dateTo]));
            $activityLogs = $stmt->fetchAll();

            foreach ($activityLogs as $log) {
                if ($log['change_type'] === 'restock') $rangeRestocked += (int) $log['quantity_change'];
                if ($log['change_type'] === 'adjust')  $rangeAdjusted++;
            }
        }
    } catch (PDOException $e) {
        error_log("Supervisor inventory page query skipped: " . $e->getMessage());
    }
}

// This Supervisor's own restock requests (see the submit_restock_request
// action above), so they can track them through validation -> Finance
// -> fulfillment without needing access to inventory_requests.php.
$myRestockRequests = [];
if ($posPdo && isSupervisorRole() && $supervisorEmployeeId > 0) {
    try {
        $stmt = $posPdo->prepare("SELECT * FROM inventory_requests WHERE requested_by_employee_id = ? ORDER BY requested_at DESC LIMIT 20");
        $stmt->execute([$supervisorEmployeeId]);
        $myRestockRequests = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Supervisor's own restock requests query skipped: " . $e->getMessage());
    }
}
$myOpenRequestCount = count(array_filter($myRestockRequests, fn($rr) => in_array($rr['status'], ['pending_validation', 'pending_finance', 'approved'], true)));

$activeNav = 'inventory';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventory Monitoring — Supervisor</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php include 'includes/supervisor_style.php'; ?>
<style>
.change-badge {
    display: inline-block; font-size: 11.5px; font-weight: 700; letter-spacing: .02em;
    padding: 3px 9px; border-radius: 999px; text-transform: uppercase;
}
.change-badge.restock  { background: var(--green-pale); color: var(--text-accent); }
.change-badge.adjust   { background: var(--mustard-pale); color: var(--mustard-text2, #7A5A1E); }
.change-badge.issuance { background: var(--red-pale); color: var(--red-alert); }
.qty-change { font-weight: 700; }
.qty-change.pos { color: var(--text-accent); }
.qty-change.neg { color: var(--red-alert); }
</style>
</head>
<body>
<div class="app-shell">
    <?php include 'includes/supervisor_sidebar.php'; ?>
    <div class="main-area">
        <div class="topbar">
            <h1>Inventory Monitoring<?= $viewingAllTeams ? ' — All Teams' : '' ?></h1>
            <div class="user-info"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span><span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span></div>
        </div>
        <div class="container">

            <?php if (!empty($success_message)): ?><div class="alert alert--success"><?= clean($success_message) ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="alert alert--error"><?= clean($error_message) ?></div><?php endif; ?>
            <?php if (!$posPdo): ?><div class="alert alert--error">Could not connect to the POS/Inventory system right now.</div><?php endif; ?>
            <?php if ($posPdo && empty($teamPosUserIds)): ?>
            <div class="note-banner">None of your team members have an Inventory Staff (or other POS) account yet, so there's no inventory activity to monitor.</div>
            <?php endif; ?>

            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">Low Stock Items</div>
                    <div class="kpi-value"><?= count($lowStockProducts) ?></div>
                    <div class="kpi-sub">&le; <?= $LOW_STOCK_THRESHOLD ?> units</div>
                </div>
                <div class="kpi-card<?= $myOpenRequestCount > 0 ? ' accent-mustard' : '' ?>">
                    <div class="kpi-label">My Open Requests</div>
                    <div class="kpi-value"><?= $myOpenRequestCount ?></div>
                    <div class="kpi-sub">awaiting Inventory Staff / Finance</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Units Restocked</div>
                    <div class="kpi-value"><?= $rangeRestocked ?></div>
                    <div class="kpi-sub">in selected range</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Adjustments Made</div>
                    <div class="kpi-value"><?= $rangeAdjusted ?></div>
                    <div class="kpi-sub">in selected range</div>
                </div>
            </div>

            <div class="section">
                <div class="section-header"><h2>Recent Inventory Activity</h2></div>
                <div class="panel">
                    <form method="GET" class="form-row">
                        <div class="field-group"><label>From</label><input type="date" name="date_from" value="<?= clean($dateFrom) ?>" max="<?= date('Y-m-d') ?>"></div>
                        <div class="field-group"><label>To</label><input type="date" name="date_to" value="<?= clean($dateTo) ?>" max="<?= date('Y-m-d') ?>"></div>
                        <button type="submit" class="btn btn-primary">Filter</button>
                    </form>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; text-decoration:none;">
                        <a class="btn btn-sm btn-success" style="text-decoration:none;" href="?date_from=<?= date('Y-m-d', strtotime('-7 days')) ?>&date_to=<?= date('Y-m-d') ?>">Last 7 days</a>
                        <a class="btn btn-sm btn-success" style="text-decoration:none;" href="?date_from=<?= date('Y-m-d', strtotime('-30 days')) ?>&date_to=<?= date('Y-m-d') ?>">Last 30 days</a>
                        <a class="btn btn-sm btn-success" style="text-decoration:none;" href="?date_from=<?= date('Y-m-d', strtotime('-90 days')) ?>&date_to=<?= date('Y-m-d') ?>">Last 90 days</a>
                        <a class="btn btn-sm btn-success" style="text-decoration:none;" href="?date_from=<?= $__allTimeFrom ?>&date_to=<?= date('Y-m-d') ?>">All time</a>
                    </div>
                </div>
                <div class="table-card"><div class="table-scroll">
                    <table>
                        <thead><tr><th>Date/Time</th><th>Employee</th><th>Product</th><th>Type</th><th>Qty Change</th><th>New Stock</th><th>Reason</th></tr></thead>
                        <tbody>
                        <?php if (empty($activityLogs)): ?>
                            <tr><td colspan="7"><div class="empty-state">No inventory activity from your team in this date range.</div></td></tr>
                        <?php else: foreach ($activityLogs as $log):
                            $qty = (int) $log['quantity_change'];
                        ?>
                            <tr>
                                <td><?= clean(date('M d, Y g:i A', strtotime($log['date_created']))) ?></td>
                                <td><?= clean($log['done_by']) ?></td>
                                <td><?= clean($log['product_name']) ?></td>
                                <td><span class="change-badge <?= clean($log['change_type']) ?>"><?= clean(ucfirst($log['change_type'])) ?></span></td>
                                <td><span class="qty-change <?= $qty >= 0 ? 'pos' : 'neg' ?>"><?= $qty >= 0 ? '+' . $qty : $qty ?></span></td>
                                <td><?= (int) $log['new_stock'] ?></td>
                                <td style="max-width:200px;"><?= clean($log['reason'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div></div>
            </div>

            <div class="section">
                <div class="section-header"><h2>Low Stock Alert <span class="badge" style="background:var(--red-pale); color:var(--red-alert);">&le; <?= $LOW_STOCK_THRESHOLD ?> units</span></h2></div>
                <div class="table-card"><div class="table-scroll">
                    <table>
                        <thead><tr><th>Product</th><th>Category</th><th>Stock</th></tr></thead>
                        <tbody>
                        <?php if (empty($lowStockProducts)): ?>
                            <tr><td colspan="3"><div class="empty-state">No low-stock items right now.</div></td></tr>
                        <?php else: foreach ($lowStockProducts as $p): ?>
                            <tr>
                                <td><?= clean($p['product_name']) ?></td>
                                <td><?= clean($p['category']) ?></td>
                                <td><span class="badge" style="background:var(--red-pale); color:var(--red-alert);"><?= (int) $p['stock'] ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div></div>
            </div>

            <?php if (isSupervisorRole()): ?>
            <div class="section">
                <div class="section-header"><h2>Submit a Restock Request</h2></div>
                <div class="panel">
                    <p style="color:var(--ink-soft); font-size:13px; margin:0 0 16px;">
                        Spotted a low-stock item above? Request a restock here — it goes to Inventory Staff to validate the actual stock and quantity first, then to Finance for approval, before the delivery gets received.
                    </p>
                    <form method="POST" style="display:grid; grid-template-columns:1.4fr 1fr 1fr; gap:14px; align-items:end;">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="submit_restock_request">
                        <div class="field-group">
                            <label>Product (or pick "New item" and type below)</label>
                            <select name="product_id" id="reqProductSelect" onchange="onReqProductChange()">
                                <option value="">— New item (type name below) —</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= (int) $p['id'] ?>" data-name="<?= clean($p['product_name']) ?>">
                                        <?= clean($p['product_name']) ?> (<?= (int) $p['stock'] ?> in stock<?= (int) $p['stock'] <= $LOW_STOCK_THRESHOLD ? ' — low' : '' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="item_name" id="reqItemName" style="margin-top:8px;" placeholder="Item name (required for a new item)">
                        </div>
                        <div class="field-group">
                            <label>Quantity</label>
                            <input type="number" name="quantity" min="1" required>
                        </div>
                        <div class="field-group">
                            <label>Notes <span style="font-weight:400;">(optional)</span></label>
                            <input type="text" name="notes" placeholder="Reason / urgency">
                        </div>
                        <div style="grid-column:1 / -1;">
                            <button type="submit" class="btn btn-primary">Submit Request</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="section">
                <div class="section-header"><h2>My Restock Requests</h2></div>
                <div class="table-card"><div class="table-scroll">
                    <table>
                        <thead><tr><th>Item</th><th>Qty</th><th>Status</th><th>Requested</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php if (empty($myRestockRequests)): ?>
                            <tr><td colspan="5"><div class="empty-state">You haven't submitted any restock requests yet.</div></td></tr>
                        <?php else: foreach ($myRestockRequests as $rr):
                            $statusLabels = [
                                'pending_validation' => ['Needs Validation', 'var(--mustard-pale)', '#8a5c10'],
                                'pending_finance'    => ['Awaiting Finance', '#E6F0FB', '#1F4E8C'],
                                'approved'           => ['Approved — Awaiting Delivery', '#e8eeff', '#2d4ecf'],
                                'rejected'           => ['Rejected', 'var(--red-pale)', 'var(--red-alert)'],
                                'fulfilled'          => ['Fulfilled', '#e6f4ea', '#1a7a3b'],
                            ];
                            $sl = $statusLabels[$rr['status']] ?? [strtoupper($rr['status']), 'var(--cream)', 'var(--ink)'];
                        ?>
                            <tr>
                                <td><?= clean($rr['item_name']) ?></td>
                                <td><?= (int) $rr['quantity'] ?> pcs</td>
                                <td><span class="badge" style="background:<?= $sl[1] ?>; color:<?= $sl[2] ?>;"><?= clean($sl[0]) ?></span></td>
                                <td><?= clean(date('M d, Y', strtotime($rr['requested_at']))) ?></td>
                                <td>
                                    <?php if ($rr['status'] === 'pending_validation'): ?>
                                        <form method="POST" data-confirm="Cancel this request?" data-confirm-title="Cancel Restock Request" data-confirm-danger="1" data-confirm-ok="Yes, cancel">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="cancel_own_restock_request">
                                            <input type="hidden" name="request_id" value="<?= (int) $rr['request_id'] ?>">
                                            <button type="submit" class="btn btn-sm">Cancel</button>
                                        </form>
                                    <?php else: ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div></div>
            </div>
            <?php endif; ?>

            <div class="section">
                <div class="section-header"><h2>Full Inventory Status</h2></div>
                <div class="table-card"><div class="table-scroll">
                    <table>
                        <thead><tr><th>Product</th><th>Category</th><th>Stock</th></tr></thead>
                        <tbody>
                        <?php if (empty($products)): ?>
                            <tr><td colspan="3"><div class="empty-state">No product data available.</div></td></tr>
                        <?php else: foreach ($products as $p): ?>
                            <tr>
                                <td><?= clean($p['product_name']) ?></td>
                                <td><?= clean($p['category']) ?></td>
                                <td><?= (int) $p['stock'] ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div></div>
            </div>

        </div>
    </div>
</div>
<?php include 'includes/sweetalert.php'; ?>
<script>
function onReqProductChange() {
    const sel = document.getElementById('reqProductSelect');
    const nameInput = document.getElementById('reqItemName');
    if (!sel || !nameInput) return;
    const opt = sel.options[sel.selectedIndex];
    if (sel.value !== '') {
        nameInput.value = opt.getAttribute('data-name') || '';
    } else {
        nameInput.value = '';
        nameInput.focus();
    }
}
</script>
</body>
</html>