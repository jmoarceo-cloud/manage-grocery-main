<?php
// ============================================================
// financial_analysis.php — Financial Analysis for the Finance module
// (Finance + Admin). Builds entirely on the same accrual figures as
// financial_statements.php (getIncomeStatement / getCashFlowStatement)
// so nothing here can ever disagree with the formal statements:
//   1. KPI cards — Revenue, Gross Margin %, Net Margin %, Total
//      Expenses for a chosen period, each vs. the immediately
//      comparable prior-month or equal-length period.
//   2. Trend — Revenue / COGS / Net Income and Gross/Net Margin %
//      over the trailing 3/6/12/24 months.
//   3. Expense Breakdown — inventory cost, payroll, and approved
//      operating bills on an accrual basis, plus actual cash outflows.
//
// Read-only — never writes to the database. All computation lives in
// includes/config.php (getMonthlyFinancialTrend / getExpenseBreakdown
// / computeMarginPct) so other pages can reuse the same figures.
// ============================================================

require_once 'includes/config.php';
require_once 'includes/export.php';
requireFinance();

$selected_month = isset($_GET['month']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $_GET['month'])
    ? (string) $_GET['month'] : '';
if ($selected_month !== '') {
    $date_from = $selected_month . '-01';
    $date_to = $selected_month === date('Y-m') ? date('Y-m-d') : date('Y-m-t', strtotime($date_from));
} else {
    $date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
    $date_to   = isset($_GET['date_to'])   && !empty($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-d');
}
$selected_month = substr($date_from, 0, 7);

$trend_months = isset($_GET['trend_months']) && in_array((int) $_GET['trend_months'], [3, 6, 12, 24], true)
    ? (int) $_GET['trend_months'] : 6;

// --- Current period figures ---
$income   = getIncomeStatement($pdo, $hrmsPdo, $date_from, $date_to);
$supplierPurchases = getSupplierPurchasesReceived($pdo, $date_from, $date_to);
$margins  = computeMarginPct($income);
$expenses = getExpenseBreakdown($pdo, $hrmsPdo, $date_from, $date_to);
$payroll  = getPayrollBreakdown($pdo, $hrmsPdo, $date_from, $date_to);
$expenseChartTotal = array_sum(array_column($expenses['categories'], 'amount'));
$cashOutflowChartTotal = array_sum(array_column($expenses['cash_outflows'], 'amount'));
$hasExpenseChartData = $expenseChartTotal > 0;
$hasCashOutflowChartData = $cashOutflowChartTotal > 0;
$accrualSourcesAvailable = !empty($income['payroll_available']) && !empty($income['operating_expense_available']);
$uncostedSalesQuantity = (int) ($income['unknown_cost_qty'] ?? 0);
$accrualTotalsComplete = $accrualSourcesAvailable && $uncostedSalesQuantity === 0;
$netIncomeAvailable = $accrualTotalsComplete;

$__comparisonPeriod = getComparableFinancialPeriod($date_from, $date_to);
$prev_date_from = $__comparisonPeriod['from'];
$prev_date_to = $__comparisonPeriod['to'];
$comparisonLabel = date('M j', strtotime($prev_date_from)) . ' – ' . date('M j, Y', strtotime($prev_date_to));
$income_prev    = getIncomeStatement($pdo, $hrmsPdo, $prev_date_from, $prev_date_to);
$expenses_prev  = getExpenseBreakdown($pdo, $hrmsPdo, $prev_date_from, $prev_date_to);
$margins_prev   = computeMarginPct($income_prev);
$cashOutflowPrevTotal = array_sum(array_column($expenses_prev['cash_outflows'], 'amount'));
$previousAccrualComplete = !empty($income_prev['payroll_available'])
    && !empty($income_prev['operating_expense_available'])
    && (int) ($income_prev['unknown_cost_qty'] ?? 0) === 0;

// Simple point-change / percent-change helper for the KPI deltas below.
function __pctDelta($current, $previous) {
    if ($previous <= 0) {
        return null; // A percentage change has no meaningful base when the prior value is zero.
    }
    return (($current - $previous) / abs($previous)) * 100;
}
$delta_revenue = __pctDelta($income['revenue'], $income_prev['revenue']);
$delta_expense = $accrualTotalsComplete && $previousAccrualComplete
    ? __pctDelta($expenses['total_expenses'], $expenses_prev['total_expenses'])
    : null;
$delta_cash_expense = __pctDelta($cashOutflowChartTotal, $cashOutflowPrevTotal);
$marginAvailable = $income['revenue'] > 0 && (int) ($income['unknown_cost_qty'] ?? 0) === 0;
$previousMarginAvailable = $income_prev['revenue'] > 0 && (int) ($income_prev['unknown_cost_qty'] ?? 0) === 0;
$delta_gm_pts = $marginAvailable && $previousMarginAvailable
    ? $margins['gross_margin_pct'] - $margins_prev['gross_margin_pct']
    : null;
$delta_nm_pts = $marginAvailable && $previousMarginAvailable
    ? $margins['net_margin_pct'] - $margins_prev['net_margin_pct']
    : null;

// --- Trend (trailing N months) ---
$trend = getMonthlyFinancialTrend($pdo, $hrmsPdo, $trend_months);

// --- Export ---------------------------------------------------
if (isset($_GET['export']) && in_array($_GET['export'], ['xlsx', 'pdf'], true)) {
    $__view = $_GET['view'] ?? 'summary';
    if ($__view === 'payroll_paid' && empty($payroll['cash_paid_available'])) {
        http_response_code(503);
        exit('Payroll payment data is unavailable. Please check the HRMS connection and try again.');
    }

    if ($__view === 'trend') {
        $__h = ['Month', 'Income', 'Supplier Purchases', 'COGS', 'Gross Profit*', 'Payroll', 'Bills', 'Net Income*', 'Gross Margin %', 'Net Margin %'];
        $__r = [];
        foreach ($trend as $m) {
            $__r[] = [
                $m['label'],
                number_format($m['revenue'], 2),
                !empty($m['supplier_purchases_available']) ? number_format($m['supplier_purchases_received'], 2) : 'Partial data',
                number_format($m['cogs'], 2),
                number_format($m['gross_profit'], 2) . ((int) ($m['unknown_cost_qty'] ?? 0) > 0 ? ' (incomplete)' : ''),
                number_format($m['payroll_expense'], 2),
                number_format($m['operating_expense'] ?? 0, 2),
                number_format($m['net_income'], 2) . ((int) ($m['unknown_cost_qty'] ?? 0) > 0 ? ' (incomplete)' : ''),
                $m['revenue'] > 0 && (int) ($m['unknown_cost_qty'] ?? 0) === 0 ? number_format($m['gross_margin_pct'], 2) . '%' : 'N/A',
                $m['revenue'] > 0 && (int) ($m['unknown_cost_qty'] ?? 0) === 0 ? number_format($m['net_margin_pct'], 2) . '%' : 'N/A',
            ];
        }
        $__r[] = ['', '', '', '', '', '', '', '', '', ''];
        $__r[] = ['* Supplier purchases are shown when received but remain inventory until sold. Net income uses recorded inventory costs; margins are N/A when cost data is incomplete or revenue is zero.', '', '', '', '', '', '', '', '', ''];
        $__name  = 'financial_trend_' . $trend_months . 'mo_' . date('Y-m-d');
        $__title = 'Financial Trend — Trailing ' . $trend_months . ' Months';
        $__meta  = [];
    } elseif ($__view === 'payroll_paid') {
        $__h = ['Employee', 'Department', 'Position', 'Pay Period', 'Paid At', 'Gross Pay', 'Deductions', 'Net Pay'];
        $__r = [];
        foreach ($payroll['cash_paid_rows'] as $p) {
            $__r[] = [
                $p['employee_name'],
                $p['department'],
                $p['position'],
                date('M d', strtotime($p['pay_period_start'])) . ' - ' . date('M d, Y', strtotime($p['pay_period_end'])),
                date('M d, Y g:i A', strtotime($p['paid_at'])),
                number_format($p['gross_pay'], 2),
                number_format($p['total_deductions'], 2),
                number_format($p['net_pay'], 2),
            ];
        }
        $__r[] = ['', '', '', '', '', '', '', ''];
        $__r[] = ['TOTAL CASH PAYROLL PAID', '', '', '', '', '', '', number_format($payroll['cash_paid_total'], 2)];
        $__name  = 'payroll_paid_' . $date_from . '_to_' . $date_to;
        $__title = 'Payroll Payments — Actual Cash Paid';
        $__meta  = [$__title, 'Payment date: ' . $date_from . ' to ' . $date_to];
    } elseif ($__view === 'payroll') {
        $__h = ['Employee', 'Department', 'Position', 'Pay Period', 'Gross Pay', 'Deductions', 'Net Pay', 'Status'];
        $__r = [];
        foreach ($payroll['rows'] as $p) {
            $__r[] = [
                $p['employee_name'] ?? trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')),
                $p['department'] ?: '—',
                $p['position'] ?: '—',
                date('M d', strtotime($p['pay_period_start'])) . ' – ' . date('M d, Y', strtotime($p['pay_period_end'])),
                number_format($p['gross_pay'], 2),
                number_format($p['total_deductions'], 2),
                number_format($p['net_pay'], 2),
                ucfirst($p['payment_status']),
            ];
        }
        $__r[] = ['', '', '', '', '', '', '', ''];
        $__r[] = ['TOTAL', '', '', '', number_format($payroll['total_gross'], 2), number_format($payroll['total_deductions'], 2), number_format($payroll['total_net'], 2), ''];
        $__name  = 'payroll_breakdown_' . $date_from . '_to_' . $date_to;
        $__title = 'Payroll Breakdown — Employee Salaries';
        $__meta  = [$__title, 'Period: ' . $date_from . ' to ' . $date_to];
    } else {
        $__h = ['Metric', 'Value'];
        $__r = [
            ['Monthly Income (Sales Revenue)', number_format($income['revenue'], 2)],
            ['Supplier Purchases Received (inventory; not yet an expense)', !empty($supplierPurchases['available']) ? number_format($supplierPurchases['amount'], 2) : 'Partial data'],
            ['Cost of Goods Sold (COGS)', number_format($income['cogs'], 2)],
            ['Known-cost Gross Profit', number_format($income['gross_profit'], 2) . ($uncostedSalesQuantity > 0 ? ' (incomplete)' : '')],
            ['Gross Margin %', $marginAvailable ? number_format($margins['gross_margin_pct'], 2) . '%' : 'N/A'],
            ['Payroll Expense', number_format($income['payroll_expense'], 2)],
            ['Approved Operating Bills', number_format($income['operating_expense'] ?? 0, 2)],
            ['Known-cost Net Income', number_format($income['net_income'], 2) . ($uncostedSalesQuantity > 0 ? ' (incomplete)' : '')],
            ['Net Margin %', $marginAvailable ? number_format($margins['net_margin_pct'], 2) . '%' : 'N/A'],
            ['', ''],
            ['Expense Breakdown (accrual)', ''],
        ];
        foreach ($expenses['categories'] as $c) {
            $__r[] = ['  ' . $c['label'], number_format($c['amount'], 2) . ' (' . number_format($c['pct_of_total'], 1) . '% of expenses)'];
        }
        $__r[] = [
            'Total Accrual Expenses',
            $accrualTotalsComplete
                ? number_format($expenses['total_expenses'], 2)
                : 'Partial data (known: ' . number_format($expenses['total_expenses'], 2) . ')',
        ];
        $__r[] = ['', ''];
        $__r[] = ['Cash Outflow Breakdown (cash-basis)', ''];
        foreach ($expenses['cash_outflows'] as $c) {
            $__r[] = ['  ' . $c['label'], number_format($c['amount'], 2)];
        }
        $__name  = 'financial_analysis_' . $date_from . '_to_' . $date_to;
        $__title = 'Financial Analysis Summary';
        $__meta  = [$__title, 'Period: ' . $date_from . ' to ' . $date_to];
    }

    if ($_GET['export'] === 'xlsx') {
        exportToExcel($__name, $__h, $__r, $__meta);
    } else {
        exportToPDF($__name, $__title, $__h, $__r, $__meta);
    }
}

$__icon_financial_analysis = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M3 14l4-5 3 3 6-7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M13 5h4v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M2.5 17h15" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
$__dateQs = 'date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monthly Expenses &amp; Financial Analysis — HCI GroceryOne</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<style>
.date-filter-form {
    display: grid;
    grid-template-columns: max-content minmax(240px, 560px);
    align-items: end;
    justify-content: start;
    gap: 14px 28px;
    background: var(--white);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 18px 22px;
    box-shadow: var(--shadow-card);
}
.date-filter-controls {
    display: flex;
    align-items: flex-end;
    gap: 10px;
}
.date-filter-form .field-group { margin: 0; }
.date-filter-form label {
    display: block;
    margin-bottom: 6px;
    color: var(--ink-soft);
    font-size: 12px;
    font-weight: 700;
}
.date-filter-form input[type=month] {
    min-width: 190px;
    min-height: 42px;
    padding: 10px 14px;
    border: 1.5px solid var(--line);
    border-radius: 10px;
    font-family: var(--font-body);
    font-size: 14px;
    color: var(--ink);
    background: var(--white);
}
.date-filter-form input[type=month]:focus { outline: none; border-color: var(--green-mid); }
.date-filter-form .action-btn {
    min-height: 42px;
    padding: 10px 18px !important;
    border-radius: 10px;
    white-space: nowrap;
}
.date-filter-note {
    max-width: 560px;
    margin: 0;
    padding-left: 18px;
    border-left: 1px solid var(--line);
    color: var(--ink-soft);
    font-size: 12.5px;
    line-height: 1.55;
}
@media (max-width: 900px) {
    .date-filter-form {
        grid-template-columns: minmax(0, 1fr);
        gap: 12px;
    }
    .date-filter-note {
        max-width: none;
        padding: 10px 0 0;
        border-top: 1px solid var(--line);
        border-left: 0;
    }
}
@media (max-width: 560px) {
    .date-filter-form { padding: 16px; }
    .date-filter-controls {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }
    .date-filter-controls .field-group { grid-column: 1 / -1; }
    .date-filter-form input[type=month] { width: 100%; box-sizing: border-box; }
    .date-filter-controls .action-btn { width: 100%; justify-content: center; }
}

.kpi-card { position: relative; }
.kpi-delta {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    font-weight: 700;
    margin-top: 4px;
}
.kpi-delta.up   { color: var(--green-mid); }
.kpi-delta.down { color: var(--red-alert); }
.kpi-delta.flat { color: var(--ink-soft); }
.kpi-delta-sub { font-size: 11.5px; color: var(--ink-soft); font-weight: 500; margin-left: 2px; }

.analysis-grid {
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}
@media (max-width: 980px) { .analysis-grid { grid-template-columns: 1fr; } }

.chart-toggle-row { display: flex; gap: 6px; }
.chart-toggle-btn {
    padding: 6px 14px;
    font-size: 12.5px;
    border-radius: 8px;
    border: 1.5px solid var(--line);
    background: var(--white);
    color: var(--ink-soft);
    font-weight: 700;
    cursor: pointer;
}
.chart-toggle-btn.active { background: var(--green-mid); border-color: var(--green-mid); color: var(--white); }

.expense-legend { list-style: none; margin: 14px 22px 20px; padding: 0; }
.expense-legend li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 9px 0;
    border-bottom: 1px solid var(--line);
    font-size: 13.5px;
}
.expense-legend li:last-child { border-bottom: none; }
.expense-legend .eg-swatch { width: 11px; height: 11px; border-radius: 3px; display: inline-block; margin-right: 8px; flex-shrink: 0; }
.expense-legend .eg-name { display: flex; align-items: center; font-weight: 600; }
.expense-legend .eg-amt { font-weight: 700; text-align: right; }
.expense-legend .eg-sub { font-size: 11.5px; color: var(--ink-soft); font-weight: 500; display: block; }
.analysis-grid .panel-header h2 {
    margin: 0 0 3px;
    color: var(--ink);
    font-family: var(--font-body);
    font-size: 16px;
    font-weight: 800;
}
.financial-stat-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
@media (max-width: 1100px) { .financial-stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 600px) { .financial-stat-grid { grid-template-columns: 1fr; } }
.expense-chart-wrap {
    position: relative;
    height: 220px;
    max-width: 360px;
    margin: 0 auto;
}
.chart-empty-state {
    min-height: 122px;
    margin: 12px 22px 0;
    padding: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
    border: 1px dashed var(--line);
    border-radius: 14px;
    background: var(--cream);
}
.chart-empty-icon {
    width: 42px;
    height: 42px;
    flex: 0 0 42px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: var(--white);
    color: var(--ink-soft);
    font-size: 20px;
    font-weight: 800;
}
.chart-empty-copy { color: var(--ink-soft); font-size: 13px; line-height: 1.5; }
.chart-empty-copy strong { display: block; color: var(--ink); font-size: 14px; }
.expense-total {
    margin: 2px 22px 0;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-radius: 12px;
    background: var(--cream);
}
.expense-total-label { color: var(--ink-soft); font-size: 12px; font-weight: 700; }
.expense-total-value { color: var(--ink); font-size: 18px; font-weight: 800; }
.expense-legend { margin-top: 8px; }
.payroll-subheader {
    margin-top: 18px;
    border-top: 1px solid var(--line);
}
.payroll-subheader h3 {
    margin: 0 0 4px;
    color: var(--ink);
    font-size: 15px;
    font-weight: 800;
}
.payroll-subheader span { color: var(--ink-soft); font-size: 12.5px; }
.payroll-paid-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
    padding-top: 14px;
    padding-bottom: 14px;
}
.payroll-paid-total {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 16px;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: var(--cream);
}
.payroll-paid-total span { color: var(--ink-soft); font-size: 12px; font-weight: 700; }
.payroll-paid-total strong { color: var(--ink); font-size: 18px; }
.statement-note { margin-top: 0; }
@media (max-width: 520px) {
    .chart-empty-state { margin-right: 14px; margin-left: 14px; padding: 16px; }
    .expense-total { margin-right: 14px; margin-left: 14px; }
    .expense-legend { margin-right: 14px; margin-left: 14px; }
    .statement-note { margin-right: 14px; margin-left: 14px; }
}

.statement-note {
    margin: 0 22px 20px;
    background: var(--cream);
    border-radius: 12px;
    padding: 12px 16px;
    font-size: 13px;
    color: var(--ink-soft);
    line-height: 1.55;
}
</style>
</head>
<body>
<div class="app-shell">

    <!-- SIDEBAR -->
    <?php $active = 'financial_analysis'; include 'includes/sidebar.php'; ?>

    <!-- MAIN -->
    <div class="main-area">
        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_financial_analysis ?></div>
            <h1>Monthly Expenses &amp; Financial Analysis</h1>
            <div class="topbar-user"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'F') ?><?php endif; ?></span>
                <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Finance') ?></span></div>
        </header>

        <main class="content">

            <form method="GET" action="financial_analysis.php" class="date-filter-form">
                <input type="hidden" name="trend_months" value="<?= $trend_months ?>">
                <div class="date-filter-controls">
                    <div class="field-group">
                        <label for="expense-month">Expense month</label>
                        <input id="expense-month" type="month" name="month" value="<?= clean($selected_month) ?>" required>
                    </div>
                    <button type="submit" class="action-btn">View Month</button>
                    <a href="financial_analysis.php" class="action-btn" style="background:var(--white); color:var(--ink-soft); border:1.5px solid var(--line); box-shadow:none;">Reset</a>
                </div>
                <span class="date-filter-note">
                    Includes recorded system data only. Comparisons use <?= clean($comparisonLabel) ?>; cash paid is shown separately.
                </span>
            </form>

            <?php if (empty($income['payroll_available']) || empty($income['operating_expense_available'])): ?>
                <div class="statement-note" role="alert" style="margin-top:14px;border:1px solid var(--red-pale);">
                    Some financial data sources could not be loaded. Affected categories may show zero and totals are incomplete.
                    <?= empty($income['payroll_available']) ? ' HRMS payroll data is unavailable.' : '' ?>
                    <?= empty($income['operating_expense_available']) ? ' Approved operating-bill data is unavailable.' : '' ?>
                </div>
            <?php endif; ?>
            <?php if (empty($expenses['cash_outflows_available'])): ?>
                <div class="statement-note" role="alert" style="margin-top:14px;border:1px solid var(--red-pale);">
                    Cash payment data is incomplete. Inventory supplier payments or HRMS payroll payments could not be fully loaded.
                </div>
            <?php endif; ?>
            <?php if ($uncostedSalesQuantity > 0): ?>
                <div class="statement-note" role="alert" style="margin-top:14px;border:1px solid var(--red-pale);">
                    Inventory cost is incomplete: <?= number_format($uncostedSalesQuantity) ?> sold unit(s) have no recorded batch cost. Accrual expense and profit totals exclude their unknown cost.
                </div>
            <?php endif; ?>

            <!-- KPI CARDS -->
            <section class="stat-grid financial-stat-grid" style="margin-top:20px;">
                <div class="stat-card accent-green kpi-card">
                    <span class="stat-label">Monthly Income (Sales Revenue)</span>
                    <span class="stat-value"><?= peso($income['revenue']) ?></span>
                    <?php if ($delta_revenue === null): ?>
                        <span class="kpi-delta flat">No prior revenue baseline · <?= clean($comparisonLabel) ?></span>
                    <?php else: ?>
                        <span class="kpi-delta <?= $delta_revenue > 0 ? 'up' : ($delta_revenue < 0 ? 'down' : 'flat') ?>">
                            <?= $delta_revenue > 0 ? '▲' : ($delta_revenue < 0 ? '▼' : '—') ?> <?= number_format(abs($delta_revenue), 1) ?>%
                            <span class="kpi-delta-sub">vs <?= clean($comparisonLabel) ?></span>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="stat-card accent-mustard kpi-card">
                    <span class="stat-label">Cash Paid Out (Inventory + Payroll + Bills)</span>
                    <span class="stat-value"><?= !empty($expenses['cash_outflows_available']) ? peso($cashOutflowChartTotal) : 'Partial data' ?></span>
                    <?php if (empty($expenses['cash_outflows_available'])): ?>
                        <span class="kpi-delta flat">Payment-source data unavailable</span>
                    <?php elseif ($delta_cash_expense === null): ?>
                        <span class="kpi-delta flat">No prior cash-spend baseline · <?= clean($comparisonLabel) ?></span>
                    <?php else: ?>
                        <span class="kpi-delta <?= $delta_cash_expense > 0 ? 'down' : ($delta_cash_expense < 0 ? 'up' : 'flat') ?>">
                            <?= $delta_cash_expense > 0 ? '▲' : ($delta_cash_expense < 0 ? '▼' : '—') ?> <?= number_format(abs($delta_cash_expense), 1) ?>%
                            <span class="kpi-delta-sub">vs <?= clean($comparisonLabel) ?></span>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="stat-card accent-leaf kpi-card">
                    <span class="stat-label">Gross Margin %</span>
                    <span class="stat-value"><?= $marginAvailable ? number_format($margins['gross_margin_pct'], 1) . '%' : 'N/A' ?></span>
                    <?php if (!$marginAvailable): ?>
                        <span class="kpi-delta flat"><?= $income['revenue'] > 0 ? 'Incomplete · sold inventory cost is unknown' : 'N/A · no revenue' ?></span>
                    <?php elseif (!$previousMarginAvailable): ?>
                        <span class="kpi-delta flat">No comparison margin · no revenue on <?= clean($comparisonLabel) ?></span>
                    <?php else: ?>
                        <span class="kpi-delta <?= $delta_gm_pts > 0 ? 'up' : ($delta_gm_pts < 0 ? 'down' : 'flat') ?>">
                            <?= $delta_gm_pts > 0 ? '▲' : ($delta_gm_pts < 0 ? '▼' : '—') ?> <?= number_format(abs($delta_gm_pts), 1) ?> pts
                            <span class="kpi-delta-sub">vs <?= clean($comparisonLabel) ?></span>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="stat-card accent-mustard kpi-card">
                    <span class="stat-label">Net Margin %</span>
                    <span class="stat-value" style="<?= $marginAvailable && $margins['net_margin_pct'] < 0 ? 'color:var(--red-alert)' : '' ?>"><?= $marginAvailable ? number_format($margins['net_margin_pct'], 1) . '%' : 'N/A' ?></span>
                    <?php if (!$marginAvailable): ?>
                        <span class="kpi-delta flat"><?= $income['revenue'] > 0 ? 'Incomplete · sold inventory cost is unknown' : 'N/A · no revenue' ?></span>
                    <?php elseif (!$previousMarginAvailable): ?>
                        <span class="kpi-delta flat">No comparison margin · no revenue on <?= clean($comparisonLabel) ?></span>
                    <?php else: ?>
                        <span class="kpi-delta <?= $delta_nm_pts > 0 ? 'up' : ($delta_nm_pts < 0 ? 'down' : 'flat') ?>">
                            <?= $delta_nm_pts > 0 ? '▲' : ($delta_nm_pts < 0 ? '▼' : '—') ?> <?= number_format(abs($delta_nm_pts), 1) ?> pts
                            <span class="kpi-delta-sub">vs <?= clean($comparisonLabel) ?></span>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="stat-card accent-red kpi-card">
                    <span class="stat-label">Accrual Expenses (Inventory Cost + Payroll + Bills)</span>
                    <span class="stat-value"><?= $accrualTotalsComplete ? peso($expenses['total_expenses']) : 'Partial data' ?></span>
                    <?php if ($delta_expense === null): ?>
                        <span class="kpi-delta flat"><?= $accrualTotalsComplete && $previousAccrualComplete ? 'No prior expense baseline' : 'Expense data incomplete' ?> · <?= clean($comparisonLabel) ?></span>
                    <?php else: ?>
                        <span class="kpi-delta <?= $delta_expense > 0 ? 'down' : ($delta_expense < 0 ? 'up' : 'flat') ?>">
                            <?= $delta_expense > 0 ? '▲' : ($delta_expense < 0 ? '▼' : '—') ?> <?= number_format(abs($delta_expense), 1) ?>%
                            <span class="kpi-delta-sub">vs <?= clean($comparisonLabel) ?></span>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="stat-card <?= $netIncomeAvailable && $income['net_income'] >= 0 ? 'accent-green' : 'accent-red' ?> kpi-card">
                    <span class="stat-label">Profit Remaining (Net Income)</span>
                    <span class="stat-value" style="<?= $netIncomeAvailable && $income['net_income'] < 0 ? 'color:var(--red-alert)' : '' ?>">
                        <?= $netIncomeAvailable ? peso($income['net_income']) : 'Partial data' ?>
                    </span>
                    <span class="kpi-delta <?= !$netIncomeAvailable || $income['net_income'] < 0 ? 'down' : 'flat' ?>">
                        <?php if ($netIncomeAvailable): ?>
                            <?= $income['net_income'] < 0 ? 'Loss for this period' : 'After inventory cost, payroll &amp; approved bills' ?>
                        <?php else: ?>
                            Known net income: <?= peso($income['net_income']) ?> · costs or financial sources are incomplete
                        <?php endif; ?>
                    </span>
                </div>
            </section>

            <!-- TREND -->
            <div class="panel" style="margin-top:4px;">
                <div class="panel-header" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                    <div>
                        <h2>Monthly Income, Purchases &amp; Profit Trend</h2>
                        <span style="font-size:12.5px; color:var(--ink-soft);">Trailing <?= $trend_months ?> months (calendar months, current month up to today)</span>
                    </div>
                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                        <div class="chart-toggle-row">
                            <?php foreach ([3, 6, 12, 24] as $__tm): ?>
                                <a href="financial_analysis.php?<?= $__dateQs ?>&trend_months=<?= $__tm ?>"
                                   class="chart-toggle-btn <?= $__tm === $trend_months ? 'active' : '' ?>"><?= $__tm ?>mo</a>
                            <?php endforeach; ?>
                        </div>
                        <?= exportPostForm('financial_analysis.php?' . $__dateQs . '&trend_months=' . (int) $trend_months . '&view=trend&export=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                        <?= exportPostForm('financial_analysis.php?' . $__dateQs . '&trend_months=' . (int) $trend_months . '&view=trend&export=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
                    </div>
                </div>
                <div class="panel-body" style="padding:16px 22px 6px;">
                    <div style="height:280px;"><canvas id="trendAmountChart"></canvas></div>
                </div>
                <div class="panel-header" style="border-top:1px solid var(--line); margin-top:8px;">
                    <h2 style="font-size:15px;">Margin % Trend</h2>
                </div>
                <div class="panel-body" style="padding:16px 22px 20px;">
                    <div style="height:220px;"><canvas id="trendMarginChart"></canvas></div>
                </div>
                <div class="panel-body panel-body--table" style="padding:0; margin-top:4px;">
                    <table class="data-table data-table--simple">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Monthly Income</th>
                                <th>Supplier Purchases Received</th>
                                <th>COGS</th>
                                <th>Payroll</th>
                                <th>Operating Bills</th>
                                <th>Known-cost Net Income</th>
                                <th>Gross Margin %</th>
                                <th>Net Margin %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trend as $m): ?>
                                <tr>
                                    <td style="font-weight:600"><?= clean($m['label']) ?></td>
                                    <td><?= peso($m['revenue']) ?></td>
                                    <td><?= !empty($m['supplier_purchases_available']) ? peso($m['supplier_purchases_received']) : 'Partial data' ?></td>
                                    <td><?= peso($m['cogs']) ?></td>
                                    <td><?= peso($m['payroll_expense']) ?></td>
                                    <td><?= peso($m['operating_expense'] ?? 0) ?></td>
                                    <td style="<?= $m['net_income'] < 0 ? 'color:var(--red-alert); font-weight:700;' : '' ?>"><?= peso($m['net_income']) ?><?= (int) ($m['unknown_cost_qty'] ?? 0) > 0 ? ' *' : '' ?></td>
                                    <td><?= $m['revenue'] > 0 && (int) ($m['unknown_cost_qty'] ?? 0) === 0 ? number_format($m['gross_margin_pct'], 1) . '%' : 'N/A' ?></td>
                                    <td style="<?= $m['net_income'] < 0 ? 'color:var(--red-alert); font-weight:700;' : '' ?>"><?= $m['revenue'] > 0 && (int) ($m['unknown_cost_qty'] ?? 0) === 0 ? number_format($m['net_margin_pct'], 1) . '%' : 'N/A' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="statement-note">
                    Supplier purchases are shown in the month received but remain inventory until sold, so they are not deducted from net income a second time. Net income uses recorded inventory costs. Margins are N/A when there is no revenue or sold inventory without a recorded batch cost; a * marks months with incomplete inventory costing.
                </div>
            </div>

            <!-- EXPENSE BREAKDOWN -->
            <div class="analysis-grid">
                <div class="panel">
                    <div class="panel-header" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                        <div>
                            <h2>Expense Breakdown</h2>
                            <span style="font-size:12.5px; color:var(--ink-soft);"><?= date('M d, Y', strtotime($date_from)) ?> — <?= date('M d, Y', strtotime($date_to)) ?> &middot; recognized when sold, earned, or approved</span>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <?= exportPostForm('financial_analysis.php?' . $__dateQs . '&view=summary&export=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                            <?= exportPostForm('financial_analysis.php?' . $__dateQs . '&view=summary&export=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
                        </div>
                    </div>
                    <?php if ($hasExpenseChartData): ?>
                        <div class="panel-body" style="padding:16px 22px 0;">
                            <div class="expense-chart-wrap"><canvas id="expensePieChart"></canvas></div>
                        </div>
                    <?php elseif ($accrualTotalsComplete): ?>
                        <div class="chart-empty-state">
                            <span class="chart-empty-icon" aria-hidden="true">0</span>
                            <span class="chart-empty-copy"><strong>No accrual expenses recorded for this period</strong>Cash paid during the month is shown separately, even when the sale, pay period, or bill approval belongs to another date.</span>
                        </div>
                    <?php else: ?>
                        <div class="chart-empty-state" role="alert">
                            <span class="chart-empty-icon" aria-hidden="true">!</span>
                            <span class="chart-empty-copy"><strong>Expense data is incomplete</strong>Connect to all required finance sources before relying on this breakdown.</span>
                        </div>
                    <?php endif; ?>
                    <div class="expense-total">
                        <span class="expense-total-label">Known accrual expenses</span>
                        <span class="expense-total-value"><?= $accrualTotalsComplete ? peso($expenses['total_expenses']) : 'Partial data' ?></span>
                    </div>
                    <div class="expense-total">
                        <span class="expense-total-label">Supplier purchases received (inventory)</span>
                        <span class="expense-total-value"><?= !empty($supplierPurchases['available']) ? peso($supplierPurchases['amount']) : 'Partial data' ?></span>
                    </div>
                    <ul class="expense-legend">
                        <?php $__colors = ['#C8472D', '#E0A23B', '#6F54A6']; ?>
                        <?php foreach ($expenses['categories'] as $__i => $c): ?>
                            <li>
                                <span class="eg-name"><span class="eg-swatch" style="background:<?= $__colors[$__i % count($__colors)] ?>"></span><?= clean($c['label']) ?></span>
                                <span class="eg-amt"><?= peso($c['amount']) ?><span class="eg-sub"><?= number_format($c['pct_of_total'], 1) ?>% of expenses &middot; <?= number_format($c['pct_of_revenue'], 1) ?>% of revenue</span></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="statement-note">
                        Supplier purchases are recorded when received but remain inventory until sold, so they are shown separately and are not added to expenses or deducted from profit a second time. Payroll is approved gross pay by pay-period end; operating bills use the approval date (or due date when no approval date is recorded).
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2>Cash Outflow Breakdown</h2>
                        <span style="font-size:12.5px; color:var(--ink-soft);">Cash paid during this period</span>
                    </div>
                    <?php if ($hasCashOutflowChartData): ?>
                        <div class="panel-body" style="padding:16px 22px 0;">
                            <div class="expense-chart-wrap"><canvas id="cashOutflowChart"></canvas></div>
                        </div>
                    <?php elseif (!empty($expenses['cash_outflows_available'])): ?>
                        <div class="chart-empty-state">
                            <span class="chart-empty-icon" aria-hidden="true">0</span>
                            <span class="chart-empty-copy"><strong>No cash payments recorded in this period</strong>Inventory purchases, payroll, and operating-bill payments appear here on their payment dates.</span>
                        </div>
                    <?php else: ?>
                        <div class="chart-empty-state" role="alert">
                            <span class="chart-empty-icon" aria-hidden="true">!</span>
                            <span class="chart-empty-copy"><strong>Payment data is incomplete</strong>Cash sources could not be fully loaded for this period.</span>
                        </div>
                    <?php endif; ?>
                    <div class="expense-total">
                        <span class="expense-total-label">Total cash paid</span>
                        <span class="expense-total-value"><?= !empty($expenses['cash_outflows_available']) ? peso($cashOutflowChartTotal) : 'Partial data' ?></span>
                    </div>
                    <ul class="expense-legend">
                        <?php $__cashColors = ['#C8472D', '#E0A23B', '#1978A8']; ?>
                        <?php foreach ($expenses['cash_outflows'] as $__i => $c): ?>
                            <li>
                                <span class="eg-name"><span class="eg-swatch" style="background:<?= $__cashColors[$__i % count($__cashColors)] ?>"></span><?= clean($c['label']) ?></span>
                                <span class="eg-amt"><?= peso($c['amount']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="statement-note">
                        Inventory payments are shown when cash is paid; inventory cost becomes an expense when those goods are sold. Payroll cash is net pay by payment date, while payroll expense is gross pay by pay-period end date. Owner withdrawals are excluded because they are not expenses.
                    </div>
                </div>
            </div>

            <!-- PAYROLL (EMPLOYEE SALARIES) -->
            <div class="panel" style="margin-top:20px;">
                <div class="panel-header" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                    <div>
                        <h2>Payroll — Employee Salaries</h2>
                        <span style="font-size:12.5px; color:var(--ink-soft);"><?= date('M d, Y', strtotime($date_from)) ?> — <?= date('M d, Y', strtotime($date_to)) ?> &middot; earned payroll is filtered by pay-period end</span>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <?= exportPostForm('financial_analysis.php?' . $__dateQs . '&view=payroll&export=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                        <?= exportPostForm('financial_analysis.php?' . $__dateQs . '&view=payroll&export=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
                    </div>
                </div>

                <div class="panel-body" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; padding:18px 22px 6px;">
                    <div style="background:var(--cream); border-radius:12px; padding:14px 16px;">
                        <div style="font-size:12px; font-weight:700; color:var(--ink-soft); text-transform:uppercase; letter-spacing:.3px;">Gross Payroll Earned</div>
                        <div style="font-size:20px; font-weight:800; margin-top:4px;"><?= peso($payroll['total_gross']) ?></div>
                    </div>
                    <div style="background:var(--cream); border-radius:12px; padding:14px 16px;">
                        <div style="font-size:12px; font-weight:700; color:var(--ink-soft); text-transform:uppercase; letter-spacing:.3px;">Payroll Deductions</div>
                        <div style="font-size:20px; font-weight:800; margin-top:4px; color:var(--red-alert);"><?= peso($payroll['total_deductions']) ?></div>
                    </div>
                    <div style="background:var(--cream); border-radius:12px; padding:14px 16px;">
                        <div style="font-size:12px; font-weight:700; color:var(--ink-soft); text-transform:uppercase; letter-spacing:.3px;">Net Payroll Earned</div>
                        <div style="font-size:20px; font-weight:800; margin-top:4px; color:var(--green-mid);"><?= peso($payroll['total_net']) ?></div>
                    </div>
                    <div style="background:var(--cream); border-radius:12px; padding:14px 16px;">
                        <div style="font-size:12px; font-weight:700; color:var(--ink-soft); text-transform:uppercase; letter-spacing:.3px;">Payroll Records Earned</div>
                        <div style="font-size:20px; font-weight:800; margin-top:4px;"><?= count($payroll['rows']) ?></div>
                    </div>
                </div>

                <?php if (empty($payroll['available'])): ?>
                    <div class="statement-note" role="alert">Payroll records could not be loaded from HRMS. Payroll totals are unavailable, not zero.</div>
                <?php endif; ?>

                <div class="panel-header payroll-subheader">
                    <div>
                        <h3>Payroll Payments Made</h3>
                        <span>Cash paid during this month, regardless of when the pay period ended.</span>
                    </div>
                    <?php if (!empty($payroll['cash_paid_available'])): ?>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <?= exportPostForm('financial_analysis.php?' . $__dateQs . '&view=payroll_paid&export=xlsx', 'Export Paid Payroll Excel', 'action-btn action-btn--small action-btn--outline') ?>
                            <?= exportPostForm('financial_analysis.php?' . $__dateQs . '&view=payroll_paid&export=pdf', 'Export Paid Payroll PDF', 'action-btn action-btn--small action-btn--outline') ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($payroll['cash_paid_available'])): ?>
                    <div class="statement-note" role="alert">Paid payroll details could not be loaded from HRMS. Cash payroll is unavailable.</div>
                <?php else: ?>
                    <div class="panel-body payroll-paid-summary">
                        <div class="payroll-paid-total">
                            <span>Net pay actually disbursed</span>
                            <strong><?= peso($payroll['cash_paid_total']) ?></strong>
                        </div>
                        <div class="payroll-paid-total">
                            <span>Payroll payments with cash paid</span>
                            <strong><?= count($payroll['cash_paid_rows']) ?></strong>
                        </div>
                    </div>

                    <?php if (!empty($payroll['cash_paid_by_department'])): ?>
                    <div class="panel-body panel-body--table" style="padding:0;">
                        <table class="data-table data-table--simple">
                            <thead><tr><th>Department</th><th>Net Pay Paid</th></tr></thead>
                            <tbody>
                                <?php foreach ($payroll['cash_paid_by_department'] as $__department => $__netPaid): ?>
                                    <tr><td><?= clean($__department) ?></td><td><?= peso($__netPaid) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <div class="panel-body panel-body--table" style="padding:0; margin-top:8px;">
                        <table class="data-table data-table--simple">
                            <thead><tr><th>Employee</th><th>Department / Position</th><th>Pay Period</th><th>Paid At</th><th>Gross Pay</th><th>Deductions</th><th>Net Pay Paid</th></tr></thead>
                            <tbody>
                                <?php if (empty($payroll['cash_paid_rows'])): ?>
                                    <tr><td colspan="7" class="empty-row">No payroll payments were made during the selected month.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($payroll['cash_paid_rows'] as $p): ?>
                                        <tr>
                                            <td style="font-weight:600"><?= clean($p['employee_name']) ?></td>
                                            <td><?= clean($p['department']) ?> / <?= clean($p['position']) ?></td>
                                            <td><?= date('M d', strtotime($p['pay_period_start'])) ?> – <?= date('M d, Y', strtotime($p['pay_period_end'])) ?></td>
                                            <td><?= date('M d, Y g:i A', strtotime($p['paid_at'])) ?></td>
                                            <td><?= peso($p['gross_pay']) ?></td>
                                            <td><?= peso($p['total_deductions']) ?></td>
                                            <td style="font-weight:700"><?= peso($p['net_pay']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="total-row">
                                        <td colspan="6" style="text-align:right;font-weight:700;">Total net pay paid</td>
                                        <td style="font-weight:700;"><?= peso($payroll['cash_paid_total']) ?></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="panel-header payroll-subheader">
                    <div>
                        <h3>Payroll Earned in Selected Month</h3>
                        <span>Gross pay recognized by pay-period end date; it may have been paid in a different month.</span>
                    </div>
                </div>

                <?php if (!empty($payroll['by_department'])): ?>
                <div class="panel-body" style="padding:6px 22px 4px;">
                    <div style="height:220px;"><canvas id="payrollDeptChart"></canvas></div>
                </div>
                <?php endif; ?>

                <div class="panel-body panel-body--table" style="padding:0; margin-top:8px;">
                    <table class="data-table data-table--simple">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Department / Position</th>
                                <th>Pay Period</th>
                                <th>Gross Pay</th>
                                <th>Deductions</th>
                                <th>Net Pay</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payroll['rows'])): ?>
                                <tr><td colspan="7" class="empty-row">No payroll periods ended during the selected month. Check “Payroll Payments Made” above for payments from earlier pay periods.</td></tr>
                            <?php else: ?>
                                <?php foreach ($payroll['rows'] as $p): ?>
                                    <tr>
                                        <td style="font-weight:600"><?= clean($p['employee_name'] ?? trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''))) ?></td>
                                        <td style="font-size:13px; color:var(--ink-soft)"><?= clean($p['department'] ?: '—') ?> / <?= clean($p['position'] ?: '—') ?></td>
                                        <td style="font-size:13px; color:var(--ink-soft)"><?= date('M d', strtotime($p['pay_period_start'])) ?> – <?= date('M d, Y', strtotime($p['pay_period_end'])) ?></td>
                                        <td><?= peso($p['gross_pay']) ?></td>
                                        <td style="color:var(--red-alert)">(<?= peso($p['total_deductions']) ?>)</td>
                                        <td style="font-weight:700"><?= peso($p['net_pay']) ?></td>
                                        <td>
                                            <span class="po-status-badge po-status-badge--<?= $p['payment_status'] === 'paid' ? 'green' : ($p['payment_status'] === 'processing' ? 'mustard' : 'neutral') ?>">
                                                <?= clean(ucfirst($p['payment_status'])) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="statement-note">
                    Payroll Earned is grouped by pay-period end date. Payroll Payments Made is grouped by actual disbursement date and includes only paid records with net pay greater than zero. This keeps payroll earned and cash disbursed separate and avoids treating a prior month’s payroll payment as current-month earnings.
                </div>
            </div>

        </main>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
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
<script>
const __isDark = document.documentElement.getAttribute('data-theme') === 'dark';
const __gridColor = __isDark ? '#2A3846' : '#DCE5EC';

// --- Trend: revenue and expense categories with net income ---
new Chart(document.getElementById('trendAmountChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($m) => $m['label'], $trend)) ?>,
        datasets: [
            {
                type: 'bar',
                label: 'Monthly Income',
                data: <?= json_encode(array_map(fn($m) => round($m['revenue'], 2), $trend)) ?>,
                backgroundColor: 'rgba(25,120,168,0.55)',
                borderRadius: 4,
            },
            {
                type: 'bar',
                label: 'Supplier Purchases Received (Inventory)',
                data: <?= json_encode(array_map(fn($m) => !empty($m['supplier_purchases_available']) ? round($m['supplier_purchases_received'], 2) : null, $trend)) ?>,
                backgroundColor: 'rgba(47,143,91,0.50)',
                borderRadius: 4,
            },
            {
                type: 'bar',
                label: 'COGS',
                data: <?= json_encode(array_map(fn($m) => round($m['cogs'], 2), $trend)) ?>,
                backgroundColor: 'rgba(200,71,45,0.55)',
                borderRadius: 4,
            },
            {
                type: 'bar',
                label: 'Payroll',
                data: <?= json_encode(array_map(fn($m) => round($m['payroll_expense'], 2), $trend)) ?>,
                backgroundColor: 'rgba(224,162,59,0.55)',
                borderRadius: 4,
            },
            {
                type: 'bar',
                label: 'Operating Bills',
                data: <?= json_encode(array_map(fn($m) => round($m['operating_expense'] ?? 0, 2), $trend)) ?>,
                backgroundColor: 'rgba(111,84,166,0.55)',
                borderRadius: 4,
            },
            {
                type: 'line',
                label: 'Known-cost Net Income',
                data: <?= json_encode(array_map(fn($m) => round($m['net_income'], 2), $trend)) ?>,
                borderColor: '#2F8F5B',
                backgroundColor: 'rgba(47,143,91,0.10)',
                borderWidth: 2.5,
                tension: 0.25,
                pointRadius: 3,
                fill: false,
            },
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11.5 } } },
            tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ₱ ' + Number(ctx.raw).toLocaleString('en-PH', {minimumFractionDigits: 2}) } }
        },
        scales: {
            y: { grid: { color: __gridColor }, ticks: { callback: v => '₱' + Number(v).toLocaleString() } },
            x: { grid: { display: false } }
        }
    }
});

// --- Trend: Gross Margin % / Net Margin % (line) ---
new Chart(document.getElementById('trendMarginChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_map(fn($m) => $m['label'], $trend)) ?>,
        datasets: [
            {
                label: 'Gross Margin %',
                data: <?= json_encode(array_map(fn($m) => $m['revenue'] > 0 && (int) ($m['unknown_cost_qty'] ?? 0) === 0 ? round($m['gross_margin_pct'], 2) : null, $trend)) ?>,
                borderColor: '#1978A8',
                backgroundColor: 'rgba(25,120,168,0.10)',
                borderWidth: 2.5,
                tension: 0.25,
                pointRadius: 3,
                fill: true,
            },
            {
                label: 'Net Margin %',
                data: <?= json_encode(array_map(fn($m) => $m['revenue'] > 0 && (int) ($m['unknown_cost_qty'] ?? 0) === 0 ? round($m['net_margin_pct'], 2) : null, $trend)) ?>,
                borderColor: '#E0A23B',
                backgroundColor: 'rgba(224,162,59,0.10)',
                borderWidth: 2.5,
                tension: 0.25,
                pointRadius: 3,
                fill: true,
            },
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11.5 } } },
            tooltip: { callbacks: { label: ctx => ctx.raw === null ? ctx.dataset.label + ': N/A' : ctx.dataset.label + ': ' + Number(ctx.raw).toLocaleString('en-PH', {minimumFractionDigits: 1}) + '%' } }
        },
        scales: {
            y: { grid: { color: __gridColor }, ticks: { callback: v => v + '%' } },
            x: { grid: { display: false } }
        }
    }
});

<?php if ($hasExpenseChartData): ?>
// --- Expense Breakdown (accrual) donut ---
new Chart(document.getElementById('expensePieChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_map(fn($c) => $c['label'], $expenses['categories'])) ?>,
        datasets: [{
            data: <?= json_encode(array_map(fn($c) => round($c['amount'], 2), $expenses['categories'])) ?>,
            backgroundColor: ['#C8472D', '#E0A23B', '#6F54A6'],
            borderColor: __isDark ? '#1A2530' : '#FFFFFF',
            borderWidth: 2,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ctx.label + ': ₱ ' + Number(ctx.raw).toLocaleString('en-PH', {minimumFractionDigits: 2}) } }
        }
    }
});
<?php endif; ?>

<?php if ($hasCashOutflowChartData): ?>
// --- Cash Outflow Breakdown donut ---
new Chart(document.getElementById('cashOutflowChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_map(fn($c) => $c['label'], $expenses['cash_outflows'])) ?>,
        datasets: [{
            data: <?= json_encode(array_map(fn($c) => round($c['amount'], 2), $expenses['cash_outflows'])) ?>,
            backgroundColor: ['#C8472D', '#E0A23B', '#1978A8', '#6F54A6'],
            borderColor: __isDark ? '#1A2530' : '#FFFFFF',
            borderWidth: 2,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ctx.label + ': ₱ ' + Number(ctx.raw).toLocaleString('en-PH', {minimumFractionDigits: 2}) } }
        }
    }
});
<?php endif; ?>

<?php if (!empty($payroll['by_department'])): ?>
// --- Payroll by Department (bar) ---
new Chart(document.getElementById('payrollDeptChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_keys($payroll['by_department'])) ?>,
        datasets: [{
            label: 'Gross Pay',
            data: <?= json_encode(array_map(fn($v) => round($v, 2), array_values($payroll['by_department']))) ?>,
            backgroundColor: 'rgba(224,162,59,0.65)',
            borderRadius: 4,
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => '₱ ' + Number(ctx.raw).toLocaleString('en-PH', {minimumFractionDigits: 2}) } }
        },
        scales: {
            x: { grid: { color: __gridColor }, ticks: { callback: v => '₱' + Number(v).toLocaleString() } },
            y: { grid: { display: false } }
        }
    }
});
<?php endif; ?>
</script>

</body>
</html>
