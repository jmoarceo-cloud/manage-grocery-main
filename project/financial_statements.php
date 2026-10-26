<?php
// ============================================================
// financial_statements.php — Financial Reporting for the Finance
// module (Finance + Admin). Three formal statements built on top
// of the existing Profit / Business Cash Position / Payroll data:
//   1. Income Statement  — Revenue, COGS, Gross Profit, Payroll
//      Expense, Net Income for a chosen date range.
//   2. Balance Sheet      — Assets (Cash + Inventory), Liabilities,
//      Equity (Owner's Capital + Retained Earnings). Always "as of
//      today" — see getBalanceSheet() in includes/config.php for why.
//   3. Cash Flow Statement — Operating / Investing / Financing cash
//      movement (direct method) for a chosen date range.
//
// All computation lives in includes/config.php
// (getIncomeStatement / getBalanceSheet / getCashFlowStatement) so
// other pages (or future reports) can reuse the same figures.
// ============================================================

require_once 'includes/config.php';
require_once 'includes/export.php';
requireFinance();

$date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to   = isset($_GET['date_to'])   && !empty($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-d');

$runway_lookback = isset($_GET['runway_lookback']) && in_array((int) $_GET['runway_lookback'], [30, 60, 90], true) ? (int) $_GET['runway_lookback'] : 30;

$income   = getIncomeStatement($pdo, $hrmsPdo, $date_from, $date_to);
$balance  = getBalanceSheet($pdo, $hrmsPdo);
$cashflow = getCashFlowStatement($pdo, $hrmsPdo, $date_from, $date_to);
$runway   = getCashRunway($pdo, $hrmsPdo, $runway_lookback);

// --- Export ---------------------------------------------------
if (isset($_GET['export']) && in_array($_GET['export'], ['xlsx', 'pdf'], true)) {
    $__statement  = $_GET['statement'] ?? 'income';
    $__rangeLabel = 'Period: ' . $date_from . ' to ' . $date_to;

    if ($__statement === 'balance') {
        $__h = ['Line Item', 'Amount'];
        $__r = [
            ['Cash', (float) $balance['cash']],
            ['Inventory (at cost)', (float) $balance['inventory_value']],
            ['Total Assets', (float) $balance['total_assets']],
            ['Total Liabilities', (float) $balance['total_liabilities']],
            ["Owner's Capital (net)", (float) $balance['capital_net']],
            ['Retained Earnings', (float) $balance['retained_earnings']],
            ['Total Equity', (float) $balance['total_equity']],
            ['Reconciling Difference', (float) $balance['reconciling']],
        ];
        $__name = 'balance_sheet_' . $balance['as_of'];
        $__title = 'Balance Sheet — As of ' . date('M d, Y', strtotime($balance['as_of']));
        $__rangeLabel = 'As of: ' . date('M d, Y', strtotime($balance['as_of']));
    } elseif ($__statement === 'runway') {
        $__h = ['Line Item', 'Amount'];
        $__r = [
            ['Current Business Cash Position', (float) $runway['current_cash']],
            ["Trailing {$runway['lookback_days']}-Day Net Operating Cash Flow", (float) $runway['net_operating_window']],
            ['Average Daily Operating Cash Flow', (float) $runway['daily_burn']],
            ['Runway (Days)', $runway['is_burning'] ? (string) $runway['runway_days'] : 'N/A — cash is stable/growing'],
            ['Projected Zero-Cash Date', $runway['is_burning'] ? date('M d, Y', strtotime($runway['projected_zero_date'])) : 'N/A'],
        ];
        foreach ($runway['projection'] as $__wk) {
            $__r[] = ['Projected Cash — Week ' . $__wk['week'] . ' (' . date('M d, Y', strtotime($__wk['date'])) . ')', (float) $__wk['cash']];
        }
        $__name = 'cash_runway_' . date('Y-m-d');
        $__title = 'Cash Runway Projection';
        $__rangeLabel = "Based on trailing {$runway['lookback_days']}-day average, as of " . date('M d, Y');
    } elseif ($__statement === 'cashflow') {
        $__h = ['Line Item', 'Amount'];
        $__r = [
            ['Cash from Sales', (float) $cashflow['cash_from_sales']],
            ['Cash Paid for Restocking', (float) -$cashflow['cash_paid_restock']],
            ['Cash Paid for Payroll', (float) -$cashflow['cash_paid_payroll']],
            ['Net Cash from Operating Activities', (float) $cashflow['net_operating']],
            ['Net Cash from Investing Activities', (float) $cashflow['net_investing']],
            ['Capital In', (float) $cashflow['capital_in']],
            ['Capital Out', (float) -$cashflow['capital_out']],
            ['Net Cash from Financing Activities', (float) $cashflow['net_financing']],
            ['Net Change in Cash', (float) $cashflow['net_change_in_cash']],
        ];
        $__name = 'cash_flow_statement_' . $date_from . '_to_' . $date_to;
        $__title = 'Cash Flow Statement';
    } else { // income
        $__h = ['Line Item', 'Amount'];
        $__r = [
            ['Revenue', (float) $income['revenue']],
            ['Cost of Goods Sold (COGS)', (float) -$income['cogs']],
            ['Gross Profit', (float) $income['gross_profit']],
            ['Payroll Expense', (float) -$income['payroll_expense']],
            ['Operating Income', (float) $income['operating_income']],
            ['Net Income', (float) $income['net_income']],
        ];
        $__name = 'income_statement_' . $date_from . '_to_' . $date_to;
        $__title = 'Income Statement';
    }

    $__meta = array_values(array_filter([$__title, $__rangeLabel]));

    if ($_GET['export'] === 'xlsx') {
        exportToExcel($__name, $__h, $__r, $__meta);
    } else {
        exportToPDF($__name, $__title, $__h, $__r, $__meta);
    }
}

$__icon_financial_statements = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="4" y="2.5" width="12" height="15" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M7 7h6M7 10h6M7 13h3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Financial Statements — HCI GroceryOne</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
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

.statement-tabs {
    display: flex;
    gap: 8px;
    margin: 18px 0 0;
    flex-wrap: wrap;
}
.statement-tab-btn {
    padding: 10px 20px;
    border-radius: 10px;
    border: 1.5px solid var(--line);
    background: var(--white);
    color: var(--ink-soft);
    font-family: var(--font-body);
    font-weight: 700;
    font-size: 13.5px;
    cursor: pointer;
    transition: background .15s, color .15s, border-color .15s;
}
.statement-tab-btn:hover { border-color: var(--green-mid); color: var(--ink); }
.statement-tab-btn.active {
    background: var(--green-mid);
    border-color: var(--green-mid);
    color: var(--white);
}
.statement-panel { display: none; }
.statement-panel.active { display: block; }

.statement-table { width: 100%; border-collapse: collapse; }
.statement-table thead th {
    background: #0d3d5d;
    color: var(--white);
    text-align: left;
    padding: 16px 22px;
    font-size: 14px;
    font-weight: 800;
    letter-spacing: 0.02em;
    border-bottom: 1px solid rgba(255,255,255,0.18);
}
.statement-table thead th:last-child { text-align: right; }
.statement-table td { padding: 11px 22px; font-size: 14px; border-bottom: 1px solid var(--line); }
.statement-table td:last-child { text-align: right; font-variant-numeric: tabular-nums; }
.statement-table tr.subtotal td { font-weight: 700; background: var(--cream); }
.statement-table tr.grand-total td { font-weight: 800; font-size: 15.5px; background: var(--mustard-pale); color: #7A5A1E; }
html[data-theme="dark"] .statement-table tr.grand-total td { color: var(--mustard); }
.statement-table tr.indent td:first-child { padding-left: 38px; color: var(--ink-soft); }
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
    <?php $active = 'financial_statements'; include 'includes/sidebar.php'; ?>

    <!-- MAIN -->
    <div class="main-area">
        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_financial_statements ?></div>
            <h1>Financial Statements</h1>
            <div class="topbar-user"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'F') ?><?php endif; ?></span>
                <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Finance') ?></span></div>
        </header>

        <main class="content">

            <form method="GET" action="financial_statements.php" class="date-filter-form">
                <input type="hidden" name="runway_lookback" value="<?= $runway['lookback_days'] ?>">
                <div class="field-group">
                    <label>Date From</label>
                    <input type="date" name="date_from" value="<?= clean($date_from) ?>">
                </div>
                <div class="field-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" value="<?= clean($date_to) ?>">
                </div>
                <button type="submit" class="action-btn" style="padding:10px 22px; font-size:14px">Filter</button>
                <a href="financial_statements.php" class="action-btn" style="padding:10px 22px; font-size:14px; background:var(--white); color:var(--ink-soft); border:1.5px solid var(--line); box-shadow:none;">Reset</a>
                <span style="font-size:13px; color:var(--ink-soft); margin-left:auto;">
                    Period applies to Income Statement &amp; Cash Flow. Balance Sheet is always as of today.
                </span>
            </form>

            <div class="statement-tabs">
                <button type="button" class="statement-tab-btn active" data-tab="income" onclick="showStatement('income')">Income Statement</button>
                <button type="button" class="statement-tab-btn" data-tab="balance" onclick="showStatement('balance')">Balance Sheet</button>
                <button type="button" class="statement-tab-btn" data-tab="cashflow" onclick="showStatement('cashflow')">Cash Flow Statement</button>
                <button type="button" class="statement-tab-btn" data-tab="runway" onclick="showStatement('runway')">Cash Runway</button>
            </div>

            <?php $__dateQs = 'date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to); ?>

            <!-- ===================== INCOME STATEMENT ===================== -->
            <div class="statement-panel active" id="panel-income">
                <div class="panel" style="margin-top:16px;">
                    <div class="panel-header" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                        <div>
                            <h2>Income Statement</h2>
                            <span style="font-size:12.5px; color:var(--ink-soft);"><?= date('M d, Y', strtotime($date_from)) ?> — <?= date('M d, Y', strtotime($date_to)) ?></span>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <?= exportPostForm('financial_statements.php?' . $__dateQs . '&statement=income&export=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                            <?= exportPostForm('financial_statements.php?' . $__dateQs . '&statement=income&export=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
                        </div>
                    </div>
                    <div class="panel-body panel-body--table" style="padding:0;">
                        <table class="statement-table">
                            <tbody>
                                <tr><td>Revenue</td><td><?= peso($income['revenue']) ?></td></tr>
                                <tr class="indent"><td>Less: Cost of Goods Sold (COGS)</td><td>(<?= peso($income['cogs']) ?>)</td></tr>
                                <tr class="subtotal"><td>Gross Profit</td><td><?= peso($income['gross_profit']) ?></td></tr>
                                <tr class="indent"><td>Less: Payroll Expense</td><td>(<?= peso($income['payroll_expense']) ?>)</td></tr>
                                <tr class="subtotal"><td>Operating Income</td><td><?= peso($income['operating_income']) ?></td></tr>
                                <tr class="grand-total"><td>Net Income</td><td><?= peso($income['net_income']) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="statement-note">
                        ℹ️ Payroll is currently the only operating expense category tracked in the system (rent, utilities, at ibang gastos ay wala pang tracking) — Net Income above reflects that. Kung may ibang recurring na gastos ang negosyo, dapat idagdag muna ito bilang bagong expense category bago tumpak ang Net Income na ito.
                        <?php if (!empty($income['unknown_cost_qty'])): ?>
                            <br>⚠️ <?= (int) $income['unknown_cost_qty'] ?> pcs sa napiling range ay walang naka-record na batch cost (legacy stock bago pa ma-enable ang batch tracking) — hindi kasama sa COGS computation, kaya maaaring mas mataas nang bahagya ang Gross Profit kaysa aktwal.
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ===================== BALANCE SHEET ===================== -->
            <div class="statement-panel" id="panel-balance">
                <div class="panel" style="margin-top:16px;">
                    <div class="panel-header" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                        <div>
                            <h2>Balance Sheet</h2>
                            <span style="font-size:12.5px; color:var(--ink-soft);">As of <?= date('F d, Y', strtotime($balance['as_of'])) ?></span>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <?= exportPostForm('financial_statements.php?statement=balance&export=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                            <?= exportPostForm('financial_statements.php?statement=balance&export=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
                        </div>
                    </div>
                    <div class="panel-body panel-body--table" style="padding:0;">
                        <table class="statement-table" aria-label="Balance sheet table">
                            <thead>
                                <tr>
                                    <th scope="col">Line Item</th>
                                    <th scope="col">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td style="font-weight:700;">Assets</td><td></td></tr>
                                <tr class="indent"><td>Cash</td><td><?= peso($balance['cash']) ?></td></tr>
                                <tr class="indent"><td>Inventory (at cost)</td><td><?= peso($balance['inventory_value']) ?></td></tr>
                                <tr class="subtotal"><td>Total Assets</td><td><?= peso($balance['total_assets']) ?></td></tr>

                                <tr><td style="font-weight:700; padding-top:18px;">Liabilities</td><td style="padding-top:18px;"></td></tr>
                                <tr class="subtotal"><td>Total Liabilities</td><td><?= peso($balance['total_liabilities']) ?></td></tr>

                                <tr><td style="font-weight:700; padding-top:18px;">Equity</td><td style="padding-top:18px;"></td></tr>
                                <tr class="indent"><td>Owner's Capital (net contributions)</td><td><?= peso($balance['capital_net']) ?></td></tr>
                                <tr class="indent"><td>Retained Earnings</td><td><?= peso($balance['retained_earnings']) ?></td></tr>
                                <tr class="subtotal"><td>Total Equity</td><td><?= peso($balance['total_equity']) ?></td></tr>

                                <tr class="indent"><td>Reconciling Difference</td><td><?= peso($balance['reconciling']) ?></td></tr>
                                <tr class="grand-total"><td>Total Liabilities &amp; Equity</td><td><?= peso($balance['total_liabilities'] + $balance['total_equity'] + $balance['reconciling']) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="statement-note">
                        ℹ️ Walang Accounts Receivable dito dahil cash-only ang benta ng grocery (hindi nagpapautang sa customer, confirmed). Ang Total Liabilities sa itaas ay mula sa Accounts Payable — utang sa supplier para sa mga natanggap/na-fulfill nang delivery pero hindi pa nababayaran (hindi pa cash outflow hangga't hindi pa aktwal na binabayaran, tingnan ang Accounts Payable page).
                        <br>⚠️ Ang "Reconciling Difference" ay ang pagkakaiba sa pagitan ng Total Assets at (Liabilities + Equity) — nagmumula ito sa timing assumptions sa itaas (halimbawa, Inventory na naka-valuate base sa kasalukuyang stock lang, hindi historical reconstruction as of a past date).
                    </div>
                </div>
            </div>

            <!-- ===================== CASH FLOW STATEMENT ===================== -->
            <div class="statement-panel" id="panel-cashflow">
                <div class="panel" style="margin-top:16px;">
                    <div class="panel-header" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                        <div>
                            <h2>Cash Flow Statement</h2>
                            <span style="font-size:12.5px; color:var(--ink-soft);"><?= date('M d, Y', strtotime($date_from)) ?> — <?= date('M d, Y', strtotime($date_to)) ?></span>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <?= exportPostForm('financial_statements.php?' . $__dateQs . '&statement=cashflow&export=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                            <?= exportPostForm('financial_statements.php?' . $__dateQs . '&statement=cashflow&export=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
                        </div>
                    </div>
                    <div class="panel-body panel-body--table" style="padding:0;">
                        <table class="statement-table">
                            <tbody>
                                <tr><td style="font-weight:700;">Operating Activities</td><td></td></tr>
                                <tr class="indent"><td>Cash from Sales</td><td><?= peso($cashflow['cash_from_sales']) ?></td></tr>
                                <tr class="indent"><td>Cash Paid for Restocking</td><td>(<?= peso($cashflow['cash_paid_restock']) ?>)</td></tr>
                                <tr class="indent"><td>Cash Paid for Payroll</td><td>(<?= peso($cashflow['cash_paid_payroll']) ?>)</td></tr>
                                <tr class="subtotal"><td>Net Cash from Operating Activities</td><td><?= peso($cashflow['net_operating']) ?></td></tr>

                                <tr><td style="font-weight:700; padding-top:18px;">Investing Activities</td><td style="padding-top:18px;"></td></tr>
                                <tr class="subtotal"><td>Net Cash from Investing Activities</td><td><?= peso($cashflow['net_investing']) ?></td></tr>

                                <tr><td style="font-weight:700; padding-top:18px;">Financing Activities</td><td style="padding-top:18px;"></td></tr>
                                <tr class="indent"><td>Capital In (Owner)</td><td><?= peso($cashflow['capital_in']) ?></td></tr>
                                <tr class="indent"><td>Capital Out (Owner Withdrawal)</td><td>(<?= peso($cashflow['capital_out']) ?>)</td></tr>
                                <tr class="subtotal"><td>Net Cash from Financing Activities</td><td><?= peso($cashflow['net_financing']) ?></td></tr>

                                <tr class="grand-total"><td>Net Change in Cash</td><td><?= peso($cashflow['net_change_in_cash']) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="statement-note">
                        ℹ️ "Cash Paid for Restocking" dito ay base sa aktwal na natanggap na delivery sa loob ng period (stock_logs), at "Cash Paid for Payroll" ay base sa aktwal na petsa ng release (payment_status = paid), kaya maaaring hindi tugma sa "Restock Cost" / "Payroll Released" na makikita sa Finance dashboard — doon kasi, binibilang na ang mga naaprubahan pero hindi pa natatanggap/nare-release bilang committed cash-out agad.
                        <br>Kasalukuyang Business Cash Position (all-time, para lang sa konteksto — hindi ito strict na Beginning + Net Change = Ending para sa napiling range): <strong><?= peso($cashflow['current_cash_position']) ?></strong>
                    </div>
                </div>
            </div>

            <!-- ===================== CASH RUNWAY ===================== -->
            <div class="statement-panel" id="panel-runway">
                <div class="panel" style="margin-top:16px;">
                    <div class="panel-header" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                        <div>
                            <h2>Cash Runway</h2>
                            <span style="font-size:12.5px; color:var(--ink-soft);">Base sa trailing <?= $runway['lookback_days'] ?>-day average, as of <?= date('M d, Y') ?></span>
                        </div>
                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <div style="display:flex; gap:6px;">
                                <?php foreach ([30, 60, 90] as $__lb): ?>
                                    <a href="financial_statements.php?<?= $__dateQs ?>&runway_lookback=<?= $__lb ?>&statement=runway#panel-runway"
                                       class="action-btn action-btn--small <?= $__lb === $runway['lookback_days'] ? '' : 'action-btn--outline' ?>"
                                       style="padding:6px 14px; font-size:12.5px;"><?= $__lb ?>d</a>
                                <?php endforeach; ?>
                            </div>
                            <?= exportPostForm('financial_statements.php?' . $__dateQs . '&runway_lookback=' . (int) $runway['lookback_days'] . '&statement=runway&export=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                            <?= exportPostForm('financial_statements.php?' . $__dateQs . '&runway_lookback=' . (int) $runway['lookback_days'] . '&statement=runway&export=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
                        </div>
                    </div>

                    <div class="panel-body" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; padding:20px 22px 6px;">
                        <div style="background:var(--cream); border-radius:12px; padding:14px 16px;">
                            <div style="font-size:12px; font-weight:700; color:var(--ink-soft); text-transform:uppercase; letter-spacing:.3px;">Current Cash Position</div>
                            <div style="font-size:22px; font-weight:800; margin-top:4px;"><?= peso($runway['current_cash']) ?></div>
                        </div>
                        <div style="background:var(--cream); border-radius:12px; padding:14px 16px;">
                            <div style="font-size:12px; font-weight:700; color:var(--ink-soft); text-transform:uppercase; letter-spacing:.3px;">Avg. Daily Operating Cash Flow</div>
                            <div style="font-size:22px; font-weight:800; margin-top:4px; <?= $runway['is_burning'] ? 'color:var(--red-alert);' : 'color:var(--green-mid);' ?>">
                                <?= $runway['is_burning'] ? '−' : '+' ?><?= peso(abs($runway['daily_burn'])) ?> <span style="font-size:12px; font-weight:600; color:var(--ink-soft);">/day</span>
                            </div>
                        </div>
                        <div style="background:<?= $runway['is_burning'] ? 'var(--red-pale)' : 'var(--cream)' ?>; border-radius:12px; padding:14px 16px;">
                            <div style="font-size:12px; font-weight:700; color:var(--ink-soft); text-transform:uppercase; letter-spacing:.3px;">Estimated Runway</div>
                            <?php if ($runway['is_burning']): ?>
                                <div style="font-size:22px; font-weight:800; margin-top:4px; color:var(--red-alert);"><?= number_format($runway['runway_days']) ?> days</div>
                                <div style="font-size:12px; color:var(--ink-soft); margin-top:2px;">~<?= date('M d, Y', strtotime($runway['projected_zero_date'])) ?></div>
                            <?php else: ?>
                                <div style="font-size:22px; font-weight:800; margin-top:4px; color:var(--green-mid);">Stable / Growing</div>
                                <div style="font-size:12px; color:var(--ink-soft); margin-top:2px;">Walang projected zero-cash date sa kasalukuyang trend</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="panel-body" style="padding:6px 22px 4px;">
                        <div style="height:280px;"><canvas id="runwayChart"></canvas></div>
                    </div>

                    <div class="panel-body panel-body--table" style="padding:0; margin-top:8px;">
                        <table class="statement-table">
                            <tbody>
                                <?php foreach ($runway['projection'] as $__wk): if ($__wk['week'] === 0 || $__wk['week'] % 2 === 0): ?>
                                <tr>
                                    <td>Week <?= $__wk['week'] ?> — <?= date('M d, Y', strtotime($__wk['date'])) ?></td>
                                    <td><?= peso($__wk['cash']) ?></td>
                                </tr>
                                <?php endif; endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="statement-note">
                        ℹ️ Ang Runway ay straight-line projection lang base sa average Net Cash from Operating Activities (Sales minus Restocking minus Payroll) sa nakaraang <?= $runway['lookback_days'] ?> araw — hindi kasama rito ang Capital In/Owner Withdrawal (Financing) para hindi ma-mask ng paulit-ulit na "Add Capital" ang totoong takbo ng negosyo mula sa normal operations. Sa loob ng nakaraang <?= $runway['lookback_days'] ?> araw, may Capital In na <?= peso($runway['capital_in_window']) ?> at Owner Withdrawal na <?= peso($runway['capital_out_window']) ?> — hindi kasama sa computation sa itaas pero nakaapekto pa rin sa Current Cash Position.
                        <br>⚠️ Hindi ito guaranteed forecast — hindi nito makikita ang malalaking one-off na gastos (hal. bulk restock) o seasonal na pagbabago sa benta na hindi pa nangyayari sa nakaraang <?= $runway['lookback_days'] ?> araw. Gamitin bilang early-warning signal lang, hindi bilang eksaktong prediksyon.
                    </div>
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
const __runwayLabels = <?= json_encode(array_map(function($w) { return 'Wk ' . $w['week']; }, $runway['projection'])) ?>;
const __runwayData   = <?= json_encode(array_map(function($w) { return round($w['cash'], 2); }, $runway['projection'])) ?>;
const __runwayIsDark = document.documentElement.getAttribute('data-theme') === 'dark';
new Chart(document.getElementById('runwayChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: __runwayLabels,
        datasets: [{
            label: 'Projected Cash Position (₱)',
            data: __runwayData,
            borderColor: <?= $runway['is_burning'] ? "'#D64545'" : "'#2F8F5B'" ?>,
            backgroundColor: <?= $runway['is_burning'] ? "'rgba(214,69,69,0.10)'" : "'rgba(47,143,91,0.10)'" ?>,
            borderWidth: 2.5,
            fill: true,
            tension: 0.25,
            pointRadius: 3,
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
                grid: { color: __runwayIsDark ? '#2A3846' : '#DCE5EC' },
                ticks: { callback: val => '₱' + Number(val).toLocaleString() }
            },
            x: { grid: { display: false } }
        }
    }
});

function showStatement(tab) {
    document.querySelectorAll('.statement-panel').forEach(function (p) { p.classList.remove('active'); });
    document.querySelectorAll('.statement-tab-btn').forEach(function (b) { b.classList.remove('active'); });
    document.getElementById('panel-' + tab).classList.add('active');
    document.querySelector('.statement-tab-btn[data-tab="' + tab + '"]').classList.add('active');
}

// Re-open whichever tab the URL points to (e.g. after clicking a 30d/60d/90d
// Cash Runway lookback link, which reloads the page with ?statement=runway)
// so the user isn't dropped back on Income Statement every time.
(function () {
    var __params = new URLSearchParams(window.location.search);
    var __tab = __params.get('statement');
    if (__tab && document.getElementById('panel-' + __tab)) {
        showStatement(__tab);
    }
})();
</script>
</body>
</html>
