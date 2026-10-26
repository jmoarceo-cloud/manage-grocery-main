<?php
require_once 'includes/config.php';
requireSupervisorRole();

$supervisorEmployeeId = isSupervisorRole() ? (int) ($_SESSION['employee_id'] ?? 0) : dec_id($_GET['supervisor_id'] ?? '');
// Admin browsing without picking a specific Supervisor sees every
// Supervisor's team combined, instead of an empty page.
$viewingAllTeams = isAdminRole() && $supervisorEmployeeId === 0;
$teamIds = $viewingAllTeams ? getAllSupervisedEmployeeIds($pdo) : getSupervisorTeamEmployeeIds($pdo, $supervisorEmployeeId);

$dateFrom = $_GET['date_from'] ?? date('Y-m-d');
$dateTo   = $_GET['date_to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $dateFrom = date('Y-m-d'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   { $dateTo   = date('Y-m-d'); }

$posUserMap = getPosUserIdsForEmployees($posPdo, $teamIds); // [employee_id => pos_user_id]
$cashierEmployeeIds = array_keys($posUserMap);

// Employee names for the cashiers on this team, keyed by pos_user_id
$cashierNames = [];
if (!empty($cashierEmployeeIds)) {
    $ph = implode(',', array_fill(0, count($cashierEmployeeIds), '?'));
    $stmt = $pdo->prepare("SELECT employee_id, first_name, last_name, employee_code FROM employees WHERE employee_id IN ($ph)");
    $stmt->execute($cashierEmployeeIds);
    foreach ($stmt->fetchAll() as $row) {
        $posUserId = $posUserMap[$row['employee_id']] ?? null;
        if ($posUserId) {
            $cashierNames[$posUserId] = $row['last_name'] . ', ' . $row['first_name'] . ' (' . $row['employee_code'] . ')';
        }
    }
}

$sales = [];
$refundedItemsBySale = []; // sale_id => [ "Product Name x2 — reason", ... ]
$summary = ['total_sales' => 0, 'tx_count' => 0, 'voided' => 0, 'refunded' => 0];
if ($posPdo && !empty($posUserMap)) {
    try {
        $ph = implode(',', array_fill(0, count($posUserMap), '?'));
        $stmt = $posPdo->prepare("
            SELECT * FROM sales
            WHERE cashier_id IN ($ph) AND DATE(transaction_date) BETWEEN ? AND ?
            ORDER BY transaction_date DESC LIMIT 200
        ");
        $stmt->execute(array_merge(array_values($posUserMap), [$dateFrom, $dateTo]));
        $sales = $stmt->fetchAll();

        // Per-item breakdown for partially refunded sales — sales.refund_reason
        // only holds the latest reason typed in, not which item(s) it was for,
        // so pull the actual lines from sale_refunds for those transactions.
        // Also totals the refunded amount per sale so Total Sales reflects
        // what the customer actually kept, not the original full amount.
        $refundedAmountBySale = []; // sale_id => total refunded amount
        $partialSaleIds = array_column(array_filter($sales, fn($s) => $s['status'] === 'partial_refunded'), 'id');
        if (!empty($partialSaleIds)) {
            $ph2 = implode(',', array_fill(0, count($partialSaleIds), '?'));
            $stmt = $posPdo->prepare("
                SELECT sr.sale_id, sr.quantity, sr.reason, sr.amount, p.product_name
                FROM sale_refunds sr
                JOIN products p ON p.id = sr.product_id
                WHERE sr.sale_id IN ($ph2)
                ORDER BY sr.refunded_at ASC
            ");
            $stmt->execute($partialSaleIds);
            foreach ($stmt->fetchAll() as $r) {
                $refundedItemsBySale[$r['sale_id']][] = $r['product_name'] . ' (x' . (int) $r['quantity'] . '): ' . $r['reason'];
                $refundedAmountBySale[$r['sale_id']] = ($refundedAmountBySale[$r['sale_id']] ?? 0) + (float) $r['amount'];
            }
        }

        foreach ($sales as $s) {
            if ($s['status'] === 'completed') {
                $summary['total_sales'] += (float) $s['total_amount'];
                $summary['tx_count']++;
            } elseif ($s['status'] === 'voided') {
                $summary['voided']++;
            } elseif ($s['status'] === 'refunded') {
                $summary['refunded']++;
            } elseif ($s['status'] === 'partial_refunded') {
                // Still a real, counted transaction — just netted down by
                // whatever portion of it was refunded (from sale_refunds),
                // and also flagged as refunded since part of it came back.
                $refundedAmount = $refundedAmountBySale[$s['id']] ?? 0;
                $summary['total_sales'] += (float) $s['total_amount'] - $refundedAmount;
                $summary['tx_count']++;
                $summary['refunded']++;
            }
        }
    } catch (PDOException $e) {
        error_log("Supervisor POS monitor query skipped: " . $e->getMessage());
    }
}

$activeNav = 'pos_monitor';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS Monitor — Supervisor</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php include 'includes/supervisor_style.php'; ?>
</head>
<body>
<div class="app-shell">
    <?php include 'includes/supervisor_sidebar.php'; ?>
    <div class="main-area">
        <div class="topbar">
            <h1>POS Monitor — <?= $viewingAllTeams ? 'All Teams' : "My Team's Cashiers" ?></h1>
            <div class="user-info"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span><span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span></div>
        </div>
        <div class="container">

            <?php if (!$posPdo): ?><div class="alert alert--error">Could not connect to the POS system right now.</div><?php endif; ?>
            <?php if (empty($posUserMap)): ?>
            <div class="note-banner">None of your team members have a POS (cashier) account yet, so there's no POS activity to show.</div>
            <?php endif; ?>

            <div class="panel">
                <form method="GET" class="form-row">
                    <div class="field-group"><label>From</label><input type="date" name="date_from" value="<?= clean($dateFrom) ?>" max="<?= date('Y-m-d') ?>"></div>
                    <div class="field-group"><label>To</label><input type="date" name="date_to" value="<?= clean($dateTo) ?>" max="<?= date('Y-m-d') ?>"></div>
                    <button type="submit" class="btn btn-primary">Filter</button>
                </form>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">Total Sales</div>
                    <div class="kpi-value" style="font-size:24px;"><?= peso($summary['total_sales']) ?></div>
                    <div class="kpi-sub">completed transactions</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Transactions</div>
                    <div class="kpi-value"><?= $summary['tx_count'] ?></div>
                </div>
                <div class="kpi-card accent-mustard">
                    <div class="kpi-label">Voided</div>
                    <div class="kpi-value"><?= $summary['voided'] ?></div>
                </div>
                <div class="kpi-card accent-red">
                    <div class="kpi-label">Refunded</div>
                    <div class="kpi-value"><?= $summary['refunded'] ?></div>
                </div>
            </div>

            <div class="section">
                <div class="section-header"><h2>Transactions</h2></div>
                <div class="table-card"><div class="table-scroll">
                    <table>
                        <thead><tr><th>Date/Time</th><th>Cashier</th><th>Amount</th><th>Payment</th><th>Change</th><th>Status</th><th>Refund Reason</th></tr></thead>
                        <tbody>
                        <?php if (empty($sales)): ?>
                            <tr><td colspan="7"><div class="empty-state">No transactions in this date range.</div></td></tr>
                        <?php else: foreach ($sales as $s):
                            $statusColors = [
                                'completed'         => ['var(--green-pale)', 'var(--text-accent)'],
                                'voided'            => ['var(--mustard-pale)', 'var(--mustard-text2, #7A5A1E)'],
                                'refunded'          => ['var(--red-pale)', 'var(--red-alert)'],
                                'partial_refunded'  => ['var(--red-pale)', 'var(--red-alert)'],
                            ];
                            [$bg, $fg] = $statusColors[$s['status']] ?? ['var(--gray-bg, #eee)', 'var(--gray-text, #333)'];
                            $statusLabels = [
                                'completed'        => 'Completed',
                                'voided'           => 'Voided',
                                'refunded'         => 'Refunded',
                                'partial_refunded' => 'Partially Refunded',
                            ];
                            $statusLabel = $statusLabels[$s['status']] ?? ucfirst($s['status']);
                        ?>
                            <tr>
                                <td><?= clean(date('M d, Y g:i A', strtotime($s['transaction_date']))) ?></td>
                                <td><?= clean($cashierNames[$s['cashier_id']] ?? ('User #' . $s['cashier_id'])) ?></td>
                                <td><?= peso($s['total_amount']) ?></td>
                                <td><?= peso($s['payment']) ?></td>
                                <td><?= peso($s['change_amount']) ?></td>
                                <td><span class="badge" style="background:<?= $bg ?>; color:<?= $fg ?>;"><?= clean($statusLabel) ?></span></td>
                                <td style="max-width:260px;">
                                    <?php if ($s['status'] === 'partial_refunded' && !empty($refundedItemsBySale[$s['id']])): ?>
                                        <?php foreach ($refundedItemsBySale[$s['id']] as $__line): ?>
                                            <div><?= clean($__line) ?></div>
                                        <?php endforeach; ?>
                                    <?php elseif (in_array($s['status'], ['refunded', 'partial_refunded'], true)): ?>
                                        <?= clean($s['refund_reason'] ?: '—') ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
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
</body>
</html>