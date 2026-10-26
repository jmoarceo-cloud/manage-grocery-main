<?php

require_once 'includes/config.php';
require_once 'includes/export.php';
// Financial reports are for Finance and Admin — Finance needs to be
// able to pull these the same way Admin already could (per Export
// Options requirement: "Financial reports for authorized Finance/Admin
// users"). requireFinance() already allows ['finance', 'admin'].
requireFinance();

// Note: "Add Capital / Owner Transaction" and the full Profit &
// Business Capital ledger moved to the dedicated Finance module
// (finance.php) — this page keeps a compact summary card with a
// link there instead of duplicating the form/ledger.

$success_message = $_SESSION['flash_success'] ?? '';
$error_message   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to   = isset($_GET['date_to'])   && !empty($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-d');

// Profit ng napiling date range (Revenue - COGS, base sa aktwal na
// batch cost ng bawat naibentang item — see getProfitForPeriod()).
$profit_period = getProfitForPeriod($pdo, $date_from, $date_to);

// All-time Business Cash Position — ito ang binabantayan ng HRMS
// Payroll kapag nagre-run ng sahod, para malaman kung sapat pa ang
// kinita ng grocery o kailangan na ng "Add Capital" mula kay Owner.
$cash_position = getBusinessCashPosition($pdo, $hrmsPdo);

// Capital / Owner Transaction ledger (pinaka-bago muna sa taas)
$capital_ledger = [];
try {
    $capital_ledger = $pdo->query("SELECT * FROM capital_transactions ORDER BY created_at DESC LIMIT 25")->fetchAll();
} catch (Exception $e) {}

// Summary — kasama na ang 'partial_refunded' sales, pero ang na-refund
// lang na parte (mula sa sale_refunds) ang ikinakaltas sa total, hindi
// ang buong transaction. Kaya kung 1 lang sa maraming binili ang
// na-refund, ang ibang items pa rin sa parehong resibo ay ina-account.
// Ang 'voided' at fully 'refunded' pa rin ang talagang hindi kasama.
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS total_transactions, SUM(s.total_amount - COALESCE(sr.refunded_amount, 0)) AS total_sales
    FROM sales s
    LEFT JOIN (SELECT sale_id, SUM(amount) AS refunded_amount FROM sale_refunds GROUP BY sale_id) sr ON sr.sale_id = s.id
    WHERE DATE(s.transaction_date) BETWEEN :f AND :t AND s.status IN ('completed', 'partial_refunded')
");
$stmt->execute([':f' => $date_from, ':t' => $date_to]);
$summary            = $stmt->fetch();
$total_transactions = $summary['total_transactions'] ?? 0;
$total_sales        = $summary['total_sales'] ?? 0;
$average_sale       = $total_transactions > 0 ? $total_sales / $total_transactions : 0;

// Sales per day
$stmt = $pdo->prepare("
    SELECT DATE(s.transaction_date) AS sale_date, SUM(s.total_amount - COALESCE(sr.refunded_amount, 0)) AS daily_total, COUNT(*) AS daily_transactions
    FROM sales s
    LEFT JOIN (SELECT sale_id, SUM(amount) AS refunded_amount FROM sale_refunds GROUP BY sale_id) sr ON sr.sale_id = s.id
    WHERE DATE(s.transaction_date) BETWEEN :f AND :t AND s.status IN ('completed', 'partial_refunded')
    GROUP BY DATE(s.transaction_date) ORDER BY sale_date ASC
");
$stmt->execute([':f' => $date_from, ':t' => $date_to]);
$sales_per_day = $stmt->fetchAll();

// Best-selling products — per-line net (binawasan lang ng dami/amount
// na talagang na-refund sa linyang iyon, hindi ang ibang linya).
$stmt = $pdo->prepare("
    SELECT p.product_name, SUM(sd.quantity - COALESCE(lr.refunded_qty, 0)) AS total_qty, SUM(sd.subtotal - COALESCE(lr.refunded_amount, 0)) AS total_rev
    FROM sales_details sd
    JOIN sales s ON s.id = sd.sale_id
    JOIN products p ON p.id = sd.product_id
    LEFT JOIN (SELECT sales_detail_id, SUM(quantity) AS refunded_qty, SUM(amount) AS refunded_amount FROM sale_refunds GROUP BY sales_detail_id) lr ON lr.sales_detail_id = sd.id
    WHERE DATE(s.transaction_date) BETWEEN :f AND :t AND s.status IN ('completed', 'partial_refunded')
    GROUP BY sd.product_id, p.product_name ORDER BY total_qty DESC LIMIT 10
");
$stmt->execute([':f' => $date_from, ':t' => $date_to]);
$best_selling = $stmt->fetchAll();

// Sales per cashier
$stmt = $pdo->prepare("
    SELECT u.full_name AS cashier_name, COUNT(s.id) AS total_txn, SUM(s.total_amount - COALESCE(sr.refunded_amount, 0)) AS total_sales
    FROM sales s
    JOIN users u ON u.id = s.cashier_id
    LEFT JOIN (SELECT sale_id, SUM(amount) AS refunded_amount FROM sale_refunds GROUP BY sale_id) sr ON sr.sale_id = s.id
    WHERE DATE(s.transaction_date) BETWEEN :f AND :t AND s.status IN ('completed', 'partial_refunded')
    GROUP BY s.cashier_id, u.full_name ORDER BY total_sales DESC
");
$stmt->execute([':f' => $date_from, ':t' => $date_to]);
$sales_per_cashier = $stmt->fetchAll();

// Sales per category
$stmt = $pdo->prepare("
    SELECT p.category, SUM(sd.quantity - COALESCE(lr.refunded_qty, 0)) AS total_qty, SUM(sd.subtotal - COALESCE(lr.refunded_amount, 0)) AS total_rev
    FROM sales_details sd
    JOIN sales s ON s.id = sd.sale_id
    JOIN products p ON p.id = sd.product_id
    LEFT JOIN (SELECT sales_detail_id, SUM(quantity) AS refunded_qty, SUM(amount) AS refunded_amount FROM sale_refunds GROUP BY sales_detail_id) lr ON lr.sales_detail_id = sd.id
    WHERE DATE(s.transaction_date) BETWEEN :f AND :t AND s.status IN ('completed', 'partial_refunded')
    GROUP BY p.category ORDER BY total_rev DESC
");
$stmt->execute([':f' => $date_from, ':t' => $date_to]);
$sales_per_category = $stmt->fetchAll();

// Transaction list — HIWALAY na ang date filter nito sa main "Date From/To"
// sa taas (na para sa summary cards, chart, at breakdowns). Bawat araw
// lang ang default dito (txn_date) para hindi ito humaba nang humaba
// araw-araw, pero may Previous Day / Next Day / date picker para
// kayang mabalikan ang mga nakaraang petsa. Ipinapakita LAHAT ng status
// (completed, voided, refunded) dito para may buong audit trail, pero
// may badge tayo para malinaw na "excluded na sa Total Sales / Profit"
// ang mga na-void/na-refund (see status badge sa table below).
$txn_date      = isset($_GET['txn_date']) && !empty($_GET['txn_date']) ? $_GET['txn_date'] : date('Y-m-d');
$txn_prev_date = date('Y-m-d', strtotime($txn_date . ' -1 day'));
$txn_next_date = date('Y-m-d', strtotime($txn_date . ' +1 day'));
$txn_is_today  = $txn_date === date('Y-m-d');

// Helper — bumubuo ng link papuntang reports.php na may bagong txn_date,
// pero pinapanatili ang kasalukuyang main date_from/date_to (at iba pang
// query string) na naka-set na sa page.
function txnDateLink($date) {
    $qs = array_merge($_GET, ['txn_date' => $date]);
    return 'reports.php?' . http_build_query($qs);
}

$stmt = $pdo->prepare("SELECT s.id, s.total_amount, s.payment, s.change_amount, s.transaction_date, s.status, u.full_name AS cashier_name FROM sales s JOIN users u ON u.id = s.cashier_id WHERE DATE(s.transaction_date) = :d ORDER BY s.transaction_date DESC");
$stmt->execute([':d' => $txn_date]);
$transactions = $stmt->fetchAll();

// Chart data
$chart_labels = json_encode(array_column($sales_per_day, 'sale_date'));
$chart_data   = json_encode(array_map(fn($d) => (float)$d['daily_total'], $sales_per_day));

// --- Export — several distinct financial reports live on this one
// page, so ?report= picks which table gets exported (?export=
// picks the format). Gated by the same requireFinance() check at
// the top of this file, same as everything else on this page.
if (isset($_GET['export']) && in_array($_GET['export'], ['xlsx', 'pdf'], true)) {
    $__report = $_GET['report'] ?? 'summary';
    $__rangeLabel = 'Date range: ' . $date_from . ' to ' . $date_to;

    if ($__report === 'bestselling') {
        $__h = ['Product', 'Qty Sold', 'Revenue'];
        $__r = [];
        foreach ($best_selling as $row) {
            $__r[] = [$row['product_name'], $row['total_qty'], number_format((float) $row['total_rev'], 2)];
        }
        $__name = 'best_selling_products'; $__title = 'Best-Selling Products';
    } elseif ($__report === 'percashier') {
        $__h = ['Cashier', 'Transactions', 'Total Sales'];
        $__r = [];
        foreach ($sales_per_cashier as $row) {
            $__r[] = [$row['cashier_name'], $row['total_txn'], number_format((float) $row['total_sales'], 2)];
        }
        $__name = 'sales_per_cashier'; $__title = 'Sales per Cashier';
    } elseif ($__report === 'percategory') {
        $__h = ['Category', 'Qty Sold', 'Revenue'];
        $__r = [];
        foreach ($sales_per_category as $row) {
            $__r[] = [$row['category'], $row['total_qty'], number_format((float) $row['total_rev'], 2)];
        }
        $__name = 'sales_per_category'; $__title = 'Sales per Category';
    } elseif ($__report === 'transactions') {
        $__h = ['Sale ID', 'Cashier', 'Amount', 'Payment', 'Status', 'Date/Time'];
        $__r = [];
        foreach ($transactions as $t) {
            $__r[] = [$t['id'], $t['cashier_name'], number_format((float) $t['total_amount'], 2), ucfirst($t['payment']), ucfirst(str_replace('_', ' ', $t['status'])), $t['transaction_date']];
        }
        $__name = 'daily_transactions_' . $txn_date; $__title = 'Transactions — ' . $txn_date;
        $__rangeLabel = 'Date: ' . $txn_date;
    } else { // summary / daily breakdown
        $__h = ['Date', 'Transactions', 'Total Sales'];
        $__r = [];
        foreach ($sales_per_day as $row) {
            $__r[] = [$row['sale_date'], $row['daily_transactions'], number_format((float) $row['daily_total'], 2)];
        }
        $__name = 'sales_summary'; $__title = 'Sales Summary — Daily Breakdown';
    }

    $__meta = [
        $__title,
        $__rangeLabel,
        ($__report === 'summary') ? ('Total Sales: ₱' . number_format((float) $total_sales, 2) . ' | Transactions: ' . $total_transactions . ' | Average Sale: ₱' . number_format((float) $average_sale, 2)) : '',
    ];
    $__meta = array_values(array_filter($__meta, fn($l) => $l !== ''));

    if ($_GET['export'] === 'xlsx') {
        exportToExcel($__name, $__h, $__r, $__meta);
    } else {
        exportToPDF($__name, $__title, $__h, $__r, $__meta);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sales Report — HCI GroceryOne</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.date-filter-form {
    display: flex;
    align-items: flex-end;
    gap: 14px;
    flex-wrap: wrap;
    background: var(--white);
    border-radius: 14px;
    padding: 18px 22px;
    box-shadow: var(--shadow-card);
}
.date-filter-form .field-group { margin: 0; }
.date-filter-form label { font-size: 12px; font-weight: 700; color: var(--ink-soft); display: block; margin-bottom: 6px; }
.date-filter-form input[type=date] {
    padding: 10px 14px;
    border: 1.5px solid var(--line);
    border-radius: 10px;
    font-family: var(--font-body);
    font-size: 14px;
    color: var(--ink);
    background: var(--cream);
}
.date-filter-form input[type=date]:focus { outline: none; border-color: var(--green-mid); }

.chart-wrap {
    position: relative;
    height: 240px;
    padding: 0 22px 22px;
}

.rank-list { list-style: none; padding: 0 22px 16px; margin: 0; }
.rank-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--line);
}
.rank-item:last-child { border-bottom: none; }
.rank-num {
    width: 26px; 
    height: 26px;
    border-radius: 50%;
    background: var(--green-pale);
    color: var(--text-accent);
    font-size: 12px;
    font-weight: 700;
    display: flex; align-items: center; 
    justify-content: center;
    flex-shrink: 0;
}
.rank-name { 
    flex: 1; 
    font-size: 14px; 
    font-weight: 600; 
}
.rank-qty  { 
    font-size: 13px; 
    color: var(--ink-soft); 
}
.rank-rev  { 
    font-size: 14px; 
    font-weight: 700; 
    color: var(--green-mid); 
}

.two-col { 
    display: grid; 
    grid-template-columns: 1fr 1fr; 
    gap: 18px; 
}
@media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }

.summary-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
@media (max-width: 700px) { .summary-row { grid-template-columns: 1fr; } }

.stat-card.accent-green  { border-left: 4px solid var(--green-mid); }
.stat-card.accent-mustard { border-left: 4px solid var(--mustard); }
.stat-card.accent-leaf    { border-left: 4px solid var(--green-leaf); }

.txn-date-nav {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    padding: 0 22px 16px;
}
.txn-date-form { margin: 0; }
.txn-date-form input[type=date] {
    padding: 9px 12px;
    border: 1.5px solid var(--line);
    border-radius: 10px;
    font-family: var(--font-body);
    font-size: 13.5px;
    color: var(--ink);
    background: var(--cream);
}
.txn-date-form input[type=date]:focus { outline: none; border-color: var(--green-mid); }
.txn-nav-btn {
    display: inline-flex;
    align-items: center;
    padding: 9px 16px;
    border-radius: 10px;
    border: 1.5px solid var(--line);
    background: var(--white);
    color: var(--ink);
    font-size: 13.5px;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.txn-nav-btn:hover { border-color: var(--green-mid); background: var(--green-pale); }
.txn-nav-btn--today { background: var(--green-pale); border-color: var(--green-mid); color: var(--text-accent); }
.txn-nav-btn--disabled {
    display: inline-flex;
    align-items: center;
    padding: 9px 16px;
    border-radius: 10px;
    border: 1.5px solid var(--line);
    background: var(--cream);
    color: var(--ink-soft);
    font-size: 13.5px;
    font-weight: 600;
    white-space: nowrap;
    opacity: 0.6;
}
.txn-nav-today-label { font-size: 12.5px; color: var(--ink-soft); font-style: italic; }
</style>
</head>
<body>
<div class="app-shell">

    <!-- SIDEBAR -->
    <?php $active = 'reports'; include 'includes/sidebar.php'; ?>

    <!-- MAIN -->
    <div class="main-area">
        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_reports ?></div>
            <h1>Sales Report</h1>
            <div class="topbar-user"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'A') ?><?php endif; ?></span>
                <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Administrator') ?></span></div>
        </header>

        <main class="content">

            <form method="GET" action="reports.php" class="date-filter-form">
                <div class="field-group">
                    <label>Date From</label>
                    <input type="date" name="date_from" value="<?= clean($date_from) ?>">
                </div>
                <div class="field-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" value="<?= clean($date_to) ?>">
                </div>
                <button type="submit" class="action-btn" style="padding:10px 22px; font-size:14px">Filter</button>
                <a href="reports.php" class="action-btn" style="padding:10px 22px; font-size:14px; background:var(--white); color:var(--ink-soft); border:1.5px solid var(--line); box-shadow:none;">Reset</a>
                <span style="font-size:13px; color:var(--ink-soft); margin-left:auto;">
                    Showing: <?= date('M d, Y', strtotime($date_from)) ?> — <?= date('M d, Y', strtotime($date_to)) ?>
                </span>
            </form>

            <!-- SUMMARY CARDS -->
            <div class="summary-row">
                <div class="stat-card accent-green">
                    <span class="stat-label">Total Sales</span>
                    <span class="stat-value"><?= peso($total_sales) ?></span>
                </div>
                <div class="stat-card accent-mustard">
                    <span class="stat-label">Total Transactions</span>
                    <span class="stat-value"><?= $total_transactions ?></span>
                </div>
                <div class="stat-card accent-leaf">
                    <span class="stat-label">Average per Transaction</span>
                    <span class="stat-value"><?= peso($average_sale) ?></span>
                </div>
            </div>

            <!-- PROFIT & BUSINESS CAPITAL (summary — full ledger + Add Capital now in Finance) -->
            <div class="panel">
                <div class="panel-header">
                    <h2>Profit &amp; Business Capital</h2>
                    <span style="font-size:12.5px; color:var(--ink-soft);">Full ledger, Add Capital, and Restock Finance approvals now live in the <a href="finance.php" style="color:var(--green-mid); font-weight:700;">Finance</a> module.</span>
                </div>
                <div class="summary-row" style="padding:0 22px 22px;">
                    <div class="stat-card accent-green">
                        <span class="stat-label">Gross Profit (selected range)</span>
                        <span class="stat-value"><?= peso($profit_period['gross_profit']) ?></span>
                        <span style="font-size:12px; color:var(--ink-soft);">Revenue <?= peso($profit_period['revenue'] ?? ($total_sales)) ?> − COGS <?= peso($profit_period['cogs']) ?></span>
                    </div>
                    <div class="stat-card accent-mustard">
                        <span class="stat-label">Business Cash Position (all-time)</span>
                        <span class="stat-value" style="<?= $cash_position['available_cash'] < 0 ? 'color:var(--red-alert, #C8472D);' : '' ?>"><?= peso($cash_position['available_cash']) ?></span>
                        <span style="font-size:12px; color:var(--ink-soft);">Sales Revenue − Restock Cost + Capital In − Capital Out − Payroll Released</span>
                    </div>
                    <div class="stat-card accent-leaf">
                        <span class="stat-label">Payroll Released (all-time, HRMS)</span>
                        <span class="stat-value"><?= peso($cash_position['payroll_released']) ?></span>
                    </div>
                </div>

                <?php if ($cash_position['available_cash'] < 0): ?>
                <div style="margin:0 22px 22px; background:var(--red-pale); color:var(--red-alert); border:1px solid var(--red-pale); border-radius:12px; padding:12px 16px; font-size:13.5px; line-height:1.5;">
                    ⚠️ Negative ang Business Cash Position — pumunta sa <a href="finance.php" style="color:var(--red-alert); font-weight:700;">Finance</a> para mag-"Add Capital".
                </div>
                <?php endif; ?>
            </div>

            <?php $__dateQs = 'date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to); ?>

            <!-- CHART -->
            <div class="panel">
                <div class="panel-header" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                    <h2>Sales Per Day</h2>
                    <div style="display:flex; gap:8px;">
                        <?= exportPostForm('reports.php?' . $__dateQs . '&report=summary&export=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                        <?= exportPostForm('reports.php?' . $__dateQs . '&report=summary&export=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
                    </div>
                </div>
                <div class="chart-wrap">
                    <?php if (empty($sales_per_day)): ?>
                        <p style="text-align:center; color:var(--ink-soft); padding-top:80px;">No data for the selected date range.</p>
                    <?php else: ?>
                        <canvas id="salesChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <!-- BEST SELLING + CASHIER -->
            <div class="two-col">
                <div class="panel">
                    <div class="panel-header" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                        <h2>Best-Selling Products</h2>
                        <div style="display:flex; gap:6px;">
                            <?= exportPostForm('reports.php?' . $__dateQs . '&report=bestselling&export=xlsx', 'Excel', 'action-btn action-btn--small action-btn--outline') ?>
                            <?= exportPostForm('reports.php?' . $__dateQs . '&report=bestselling&export=pdf', 'PDF', 'action-btn action-btn--small action-btn--outline') ?>
                        </div>
                    </div>
                    <?php if (empty($best_selling)): ?>
                        <p style="padding:16px 22px; color:var(--ink-soft); font-size:14px;">No data.</p>
                    <?php else: ?>
                        <ul class="rank-list">
                            <?php foreach ($best_selling as $i => $p): ?>
                                <li class="rank-item">
                                    <span class="rank-num"><?= $i + 1 ?></span>
                                    <span class="rank-name"><?= clean($p['product_name']) ?></span>
                                    <span class="rank-qty"><?= $p['total_qty'] ?> pcs</span>
                                    <span class="rank-rev"><?= peso($p['total_rev']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                        <h2>Sales per Cashier</h2>
                        <div style="display:flex; gap:6px;">
                            <?= exportPostForm('reports.php?' . $__dateQs . '&report=percashier&export=xlsx', 'Excel', 'action-btn action-btn--small action-btn--outline') ?>
                            <?= exportPostForm('reports.php?' . $__dateQs . '&report=percashier&export=pdf', 'PDF', 'action-btn action-btn--small action-btn--outline') ?>
                        </div>
                    </div>
                    <div class="panel-body panel-body--table">
                        <table class="data-table data-table--simple">
                            <thead>
                                <tr>
                                    <th>Cashier</th>
                                    <th>Transactions</th>
                                    <th>Total Sales</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($sales_per_cashier)): ?>
                                    <tr><td colspan="3" class="empty-row">No data.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($sales_per_cashier as $c): ?>
                                        <tr>
                                            <td style="font-weight:600"><?= clean($c['cashier_name']) ?></td>
                                            <td><?= $c['total_txn'] ?></td>
                                            <td style="font-weight:700; color:var(--green-mid)"><?= peso($c['total_sales']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- CATEGORY BREAKDOWN -->
            <div class="panel">
                <div class="panel-header" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                    <h2>Sales per Category</h2>
                    <div style="display:flex; gap:6px;">
                        <?= exportPostForm('reports.php?' . $__dateQs . '&report=percategory&export=xlsx', 'Excel', 'action-btn action-btn--small action-btn--outline') ?>
                        <?= exportPostForm('reports.php?' . $__dateQs . '&report=percategory&export=pdf', 'PDF', 'action-btn action-btn--small action-btn--outline') ?>
                    </div>
                </div>
                <div class="panel-body panel-body--table">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Qty Sold</th>
                                <th>Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sales_per_category)): ?>
                                <tr><td colspan="3" class="empty-row">No data.</td></tr>
                            <?php else: ?>
                                <?php foreach ($sales_per_category as $cat): ?>
                                    <tr>
                                        <td style="font-weight:600"><?= clean($cat['category']) ?></td>
                                        <td><?= $cat['total_qty'] ?> pcs</td>
                                        <td style="font-weight:700; color:var(--green-mid)"><?= peso($cat['total_rev']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TRANSACTION LIST -->
            <div class="panel">
                <div class="panel-header" style="flex-wrap:wrap; row-gap:10px;">
                    <h2>Transaction List (<?= count($transactions) ?>)</h2>
                    <div style="display:flex; gap:6px;">
                        <?= exportPostForm('reports.php?' . $__dateQs . '&txn_date=' . urlencode($txn_date) . '&report=transactions&export=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                        <?= exportPostForm('reports.php?' . $__dateQs . '&txn_date=' . urlencode($txn_date) . '&report=transactions&export=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
                    </div>
                    <span style="font-size:12.5px; color:var(--ink-soft);">"Voided"/"Refunded" transactions are not included in the Total Sales, Profit, and other totals above. For "Partially Refunded", only the refunded item(s) are excluded — the rest of the items bought in the same receipt are still accounted for.</span>
                </div>
                <div class="txn-date-nav">
                    <a href="<?= clean(txnDateLink($txn_prev_date)) ?>" class="txn-nav-btn" title="Previous Day">&#8592; Previous Day</a>

                    <form method="GET" action="reports.php" class="txn-date-form">
                        <?php foreach ($_GET as $__k => $__v): if ($__k === 'txn_date') continue; ?>
                            <input type="hidden" name="<?= clean($__k) ?>" value="<?= clean($__v) ?>">
                        <?php endforeach; ?>
                        <input type="date" name="txn_date" value="<?= clean($txn_date) ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()">
                    </form>

                    <?php if (!$txn_is_today): ?>
                        <a href="<?= clean(txnDateLink($txn_next_date)) ?>" class="txn-nav-btn" title="Next Day">Next Day &#8594;</a>
                        <a href="<?= clean(txnDateLink(date('Y-m-d'))) ?>" class="txn-nav-btn txn-nav-btn--today" title="Jump to Today">Today</a>
                    <?php else: ?>
                        <span class="txn-nav-btn txn-nav-btn--disabled">Next Day &#8594;</span>
                        <span class="txn-nav-today-label">Showing today</span>
                    <?php endif; ?>
                </div>
                <div class="panel-body panel-body--table">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Cashier</th>
                                <th>Status</th>
                                <th>Total</th>
                                <th>Payment</th>
                                <th>Change</th>
                                <th>Date & Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($transactions)): ?>
                                <tr><td colspan="7" class="empty-row">No transactions in the selected range.</td></tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $txn): ?>
                                    <?php $__isVoidOrRefund = in_array($txn['status'], ['voided', 'refunded'], true); ?>
                                    <tr>
                                        <td style="color:var(--ink-soft); font-size:13px">#<?= $txn['id'] ?></td>
                                        <td style="font-weight:600"><?= clean($txn['cashier_name']) ?></td>
                                        <td>
                                            <?php if ($txn['status'] === 'completed'): ?>
                                                <span style="font-size:12px; font-weight:700; color:var(--green-mid);">Completed</span>
                                            <?php elseif ($txn['status'] === 'voided'): ?>
                                                <span style="font-size:12px; font-weight:700; color:var(--mustard); background:var(--mustard-pale); padding:3px 9px; border-radius:999px;">Voided</span>
                                            <?php elseif ($txn['status'] === 'refunded'): ?>
                                                <span style="font-size:12px; font-weight:700; color:var(--mustard); background:var(--mustard-pale); padding:3px 9px; border-radius:999px;">Refunded</span>
                                            <?php elseif ($txn['status'] === 'partial_refunded'): ?>
                                                <span style="font-size:12px; font-weight:700; color:var(--mustard); background:var(--mustard-pale); padding:3px 9px; border-radius:999px;">Partially Refunded</span>
                                            <?php else: ?>
                                                <span style="font-size:12px; color:var(--ink-soft);"><?= clean(ucfirst($txn['status'])) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-weight:700; <?= $__isVoidOrRefund ? 'color:var(--ink-soft); text-decoration:line-through;' : 'color:var(--green-mid)' ?>"><?= peso($txn['total_amount']) ?></td>
                                        <td<?= $__isVoidOrRefund ? ' style="color:var(--ink-soft);"' : '' ?>><?= peso($txn['payment']) ?></td>
                                        <td<?= $__isVoidOrRefund ? ' style="color:var(--ink-soft);"' : '' ?>><?= peso($txn['change_amount']) ?></td>
                                        <td style="font-size:13px; color:var(--ink-soft)"><?= date('M d, Y h:i A', strtotime($txn['transaction_date'])) ?></td>
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
<?php if (!empty($sales_per_day)): ?>
const ctx = document.getElementById('salesChart').getContext('2d');
const __isDark = document.documentElement.getAttribute('data-theme') === 'dark';
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= $chart_labels ?>,
        datasets: [{
            label: 'Daily Sales (₱)',
            data: <?= $chart_data ?>,
            backgroundColor: 'rgba(25, 120, 168, 0.18)',
            borderColor: '#1978A8',
            borderWidth: 2,
            borderRadius: 6,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx => '₱ ' + Number(ctx.raw).toLocaleString('en-PH', {minimumFractionDigits: 2})
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: __isDark ? '#2A3846' : '#DCE5EC' },
                ticks: {
                    callback: val => '₱' + Number(val).toLocaleString()
                }
            },
            x: { grid: { display: false } }
        }
    }
});
<?php endif; ?>

<?php if (!empty($success_message)): ?>
Swal.fire({ icon: 'success', title: <?= json_encode(strip_tags($success_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 2200, timerProgressBar: true });
<?php endif; ?>
<?php if (!empty($error_message)): ?>
Swal.fire({ icon: 'error', title: <?= json_encode(clean($error_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 3200, timerProgressBar: true });
<?php endif; ?>

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