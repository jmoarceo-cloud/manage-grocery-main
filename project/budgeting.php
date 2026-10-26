<?php
// ============================================================
// budgeting.php — Budgeting for the Finance module (Finance + Admin).
//
// Finance sets a target amount per period (calendar month by
// default, but any date range works) for three categories:
//   - revenue  : Sales Revenue target
//   - payroll  : Payroll spending budget
//   - restock  : Restocking/cash-spend budget
//
// Budget vs Actual reuses the exact same figures already shown on
// financial_statements.php (getIncomeStatement / getCashFlowStatement)
// via getBudgetVsActual() in includes/config.php, so the two pages
// never disagree with each other.
// ============================================================

require_once 'includes/config.php';
requireFinance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';
    $back_qs = !empty($_POST['back_qs']) ? '?' . $_POST['back_qs'] : '';

    if ($action === 'save_budget') {
        $period_start = $_POST['period_start'] ?? '';
        $period_end   = $_POST['period_end'] ?? '';
        $category     = $_POST['category'] ?? '';
        $amount       = (float) ($_POST['amount'] ?? 0);
        $notes        = trim($_POST['notes'] ?? '');

        if (!$period_start || !$period_end || strtotime($period_end) < strtotime($period_start)) {
            $_SESSION['flash_error'] = "Please provide a valid period (End date can't be before Start date).";
        } elseif (!in_array($category, ['revenue', 'payroll', 'restock'], true)) {
            $_SESSION['flash_error'] = "Please choose a valid budget category.";
        } elseif ($amount < 0) {
            $_SESSION['flash_error'] = "Please enter a valid amount.";
        } else {
            $ok = upsertBudget($pdo, $period_start, $period_end, $category, $amount, $notes, currentPosUserId(), $_SESSION['full_name'] ?? null);
            $_SESSION[$ok ? 'flash_success' : 'flash_error'] = $ok
                ? "Budget saved."
                : "The budget could not be saved. Please try again.";
        }
        redirect('budgeting.php' . $back_qs);

    } elseif ($action === 'delete_budget') {
        $id = (int) ($_POST['budget_id'] ?? 0);
        $ok = deleteBudget($pdo, $id);
        $_SESSION[$ok ? 'flash_success' : 'flash_error'] = $ok ? "Budget removed." : "Could not remove that budget.";
        redirect('budgeting.php' . $back_qs);
    }
}

$success_message = $_SESSION['flash_success'] ?? '';
$error_message   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$period_start = isset($_GET['period_start']) && !empty($_GET['period_start']) ? $_GET['period_start'] : date('Y-m-01');
$period_end   = isset($_GET['period_end'])   && !empty($_GET['period_end'])   ? $_GET['period_end']   : date('Y-m-t');

$bva = getBudgetVsActual($pdo, $hrmsPdo, $period_start, $period_end);

// Past budgets set for other periods, for quick reference/history.
ensureBudgetsTable($pdo);
$budget_history = [];
try {
    $budget_history = $pdo->query("
        SELECT * FROM budgets ORDER BY period_start DESC, category ASC LIMIT 50
    ")->fetchAll();
} catch (Exception $e) {}

$__icon_budgeting = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="2.5" y="4" width="15" height="12" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M2.5 8h15" stroke="currentColor" stroke-width="1.6"/><circle cx="14" cy="12.2" r="1.4" fill="currentColor"/></svg>';

$categoryLabels = ['revenue' => 'Sales Revenue', 'payroll' => 'Payroll', 'restock' => 'Restocking'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Budgeting — HCI GroceryOne</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
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
.date-filter-form .financial-statements-link-wrap {
    margin-left: auto;
    display: flex;
    align-items: center;
}
.date-filter-form .financial-statements-link {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    min-height: 42px;
    padding: 10px 14px;
    border: 1.5px solid #BFDBFE;
    border-radius: 10px;
    background: #EFF6FF;
    color: #1D4ED8;
    font-size: 12.5px;
    font-weight: 700;
    line-height: 1.25;
    white-space: nowrap;
    transition: background .15s ease, border-color .15s ease, color .15s ease, transform .15s ease;
}
.date-filter-form .financial-statements-link:hover,
.date-filter-form .financial-statements-link:focus-visible {
    background: #DBEAFE;
    border-color: #60A5FA;
    color: #1E40AF;
    transform: translateY(-1px);
    outline: none;
}
@media (max-width: 900px) {
    .date-filter-form .financial-statements-link-wrap { margin-left: 0; width: 100%; }
    .date-filter-form .financial-statements-link { width: 100%; justify-content: center; }
}

.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
@media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }

.budget-card {
    background: var(--white);
    border: 1.5px solid var(--line);
    border-radius: 14px;
    padding: 18px 20px;
    display: grid;
    gap: 10px;
}
.budget-card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
.budget-card-title { font-size: 15px; font-weight: 700; color: var(--ink); }
.budget-bar-track { background: var(--cream); border-radius: 999px; height: 10px; overflow: hidden; }
.budget-bar-fill { height: 100%; border-radius: 999px; background: var(--green-mid); transition: width .2s; }
.budget-bar-fill.over { background: var(--red-alert, #C8472D); }
.budget-figures { display: flex; justify-content: space-between; font-size: 13px; color: var(--ink-soft); }
.budget-variance { font-size: 12.5px; font-weight: 700; }
.budget-variance.favorable { color: var(--green-mid); }
.budget-variance.unfavorable { color: var(--red-alert, #C8472D); }
.no-budget-note { font-size: 13px; color: var(--ink-soft); font-style: italic; }
</style>
</head>
<body>
<div class="app-shell">

    <!-- SIDEBAR -->
    <?php $active = 'budgeting'; include 'includes/sidebar.php'; ?>

    <!-- MAIN -->
    <div class="main-area">
        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_budgeting ?></div>
            <h1>Budgeting</h1>
            <div class="topbar-user"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'F') ?><?php endif; ?></span>
                <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Finance') ?></span></div>
        </header>

        <main class="content">

            <?php if ($success_message): ?>
                <div class="alert alert--success"><?= clean($success_message) ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="alert alert--error"><?= clean($error_message) ?></div>
            <?php endif; ?>

            <form method="GET" action="budgeting.php" class="date-filter-form">
                <div class="field-group">
                    <label>Period Start</label>
                    <input type="date" name="period_start" value="<?= clean($period_start) ?>">
                </div>
                <div class="field-group">
                    <label>Period End</label>
                    <input type="date" name="period_end" value="<?= clean($period_end) ?>">
                </div>
                <button type="submit" class="action-btn" style="padding:10px 22px; font-size:14px">View Period</button>
                <a href="budgeting.php" class="action-btn" style="padding:10px 22px; font-size:14px; background:var(--white); color:var(--ink-soft); border:1.5px solid var(--line); box-shadow:none;">This Month</a>
                <span class="financial-statements-link-wrap">
                    <a class="financial-statements-link" href="financial_statements.php?date_from=<?= urlencode($period_start) ?>&date_to=<?= urlencode($period_end) ?>">View Financial Statements <span aria-hidden="true">→</span></a>
                </span>
            </form>

            <!-- BUDGET VS ACTUAL -->
            <div class="panel" style="margin-top:16px;">
                <div class="panel-header">
                    <h2>Budget vs Actual</h2>
                    <span style="font-size:12.5px; color:var(--ink-soft);"><?= date('M d, Y', strtotime($period_start)) ?> — <?= date('M d, Y', strtotime($period_end)) ?></span>
                </div>
                <div style="padding:0 22px 22px; display:grid; gap:14px; grid-template-columns:repeat(3,1fr);" class="summary-row">
                    <?php foreach (['revenue', 'payroll', 'restock'] as $cat): ?>
                        <?php $row = $bva[$cat]; ?>
                        <div class="budget-card">
                            <div class="budget-card-top">
                                <span class="budget-card-title"><?= $categoryLabels[$cat] ?></span>
                                <?php if ($row['budgeted'] !== null && $row['is_favorable'] !== null): ?>
                                    <span class="budget-variance <?= $row['is_favorable'] ? 'favorable' : 'unfavorable' ?>">
                                        <?= $row['is_favorable'] ? 'On Track' : 'Over Budget' ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if ($row['budgeted'] === null): ?>
                                <div class="budget-figures"><span>Actual so far</span><span style="font-weight:700; color:var(--ink);"><?= peso($row['actual']) ?></span></div>
                                <div class="no-budget-note">No budget set yet for this period — set one below.</div>
                            <?php else: ?>
                                <?php
                                    $pct = $row['budgeted'] > 0 ? min(100, max(0, ($row['actual'] / $row['budgeted']) * 100)) : 0;
                                    $overBudget = $row['actual'] > $row['budgeted'];
                                ?>
                                <div class="budget-bar-track">
                                    <div class="budget-bar-fill <?= $overBudget ? 'over' : '' ?>" style="width:<?= round($pct, 1) ?>%;"></div>
                                </div>
                                <div class="budget-figures">
                                    <span>Actual: <strong style="color:var(--ink);"><?= peso($row['actual']) ?></strong></span>
                                    <span>Budget: <strong style="color:var(--ink);"><?= peso($row['budgeted']) ?></strong></span>
                                </div>
                                <div class="budget-figures">
                                    <span>Variance: <?= $row['variance'] >= 0 ? '+' : '' ?><?= peso($row['variance']) ?><?= $row['variance_pct'] !== null ? ' (' . ($row['variance'] >= 0 ? '+' : '') . number_format($row['variance_pct'], 1) . '%)' : '' ?></span>
                                </div>
                                <?php if (!empty($row['notes'])): ?>
                                    <div style="font-size:12px; color:var(--ink-soft); background:var(--cream); border-radius:8px; padding:6px 10px;"><?= clean($row['notes']) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="statement-note" style="margin:0 22px 22px; background:var(--cream); border-radius:12px; padding:12px 16px; font-size:13px; color:var(--ink-soft); line-height:1.55;">
                    ℹ️ "Over Budget" is favorable for Sales Revenue because it exceeds the target, but for Payroll and Restocking it means spending above plan. The "Actual" figures here match the values shown in <a href="financial_statements.php" style="color:var(--green-mid); font-weight:700;">Financial Statements</a> for the same period.
                </div>
            </div>

            <!-- OPERATING EXPENSES: ACTUAL VS BUDGET (per category, calendar month) -->
            <?php $__oeActualVsBudget = getOperatingExpensesActualVsBudget($pdo, $period_start); ?>
            <div class="panel" style="margin-top:16px;">
                <div class="panel-header">
                    <h2>Operating Expenses — Actual vs Budget</h2>
                    <span style="font-size:12.5px; color:var(--ink-soft);"><?= date('F Y', strtotime($period_start)) ?> &middot; <a href="expense_categories.php" style="color:var(--green-mid); font-weight:700;">Manage categories →</a></span>
                </div>
                <?php if (empty($__oeActualVsBudget)): ?>
                    <div class="statement-note" style="margin:0 22px 22px; background:var(--cream); border-radius:12px; padding:12px 16px; font-size:13px; color:var(--ink-soft);">
                        No active expense categories yet. Set these up on <a href="expense_categories.php" style="color:var(--green-mid); font-weight:700;">Expense Categories</a> to track utilities, rent, and other indirect procurement against a monthly budget.
                    </div>
                <?php else: ?>
                    <div class="panel-body panel-body--table">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Approved Actual (this month)</th>
                                    <th>Monthly Budget</th>
                                    <th>Variance</th>
                                    <th>% Used</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($__oeActualVsBudget as $row): ?>
                                    <tr>
                                        <td style="font-weight:600"><?= clean($row['category']['name']) ?></td>
                                        <td><?= peso($row['actual']) ?></td>
                                        <td><?= $row['budget'] !== null ? peso($row['budget']) : '— (no limit)' ?></td>
                                        <td>
                                            <?php if ($row['budget'] !== null): ?>
                                                <?php $variance = (float) $row['budget'] - $row['actual']; ?>
                                                <span style="font-weight:700; color:<?= $row['over_budget'] ? '#9c3a23' : 'var(--green-mid)' ?>;">
                                                    <?= $variance >= 0 ? '+' : '' ?><?= peso($variance) ?>
                                                </span>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['percent_used'] !== null): ?>
                                                <span class="status-badge status-badge--<?= $row['over_budget'] ? 'red' : 'green' ?>"><?= $row['percent_used'] ?>%</span>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <div class="statement-note" style="margin:0 22px 22px; background:var(--cream); border-radius:12px; padding:12px 16px; font-size:13px; color:var(--ink-soft); line-height:1.55;">
                    ℹ️ "Approved Actual" here is based on the calendar month of each approved Operating Expense's <em>due date</em>, not the custom period above. It is always tracked by calendar month, matching the budget check used before Level 2 approval. Review individual bills in <a href="operating_expenses.php" style="color:var(--green-mid); font-weight:700;">Operating Expenses</a>.
                </div>
            </div>

            <div class="two-col">
                <!-- SET/UPDATE BUDGET -->
                <div class="panel">
                    <div class="panel-header"><h2>Set / Update Budget</h2></div>
                    <form method="POST" action="budgeting.php" style="padding:0 22px 22px; display:grid; gap:12px;">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="save_budget">
                        <input type="hidden" name="back_qs" value="<?= clean(http_build_query(['period_start' => $period_start, 'period_end' => $period_end])) ?>">

                        <div class="field-group" style="margin:0;">
                            <label style="font-size:12px; font-weight:700; color:var(--ink-soft); display:block; margin-bottom:6px;">Period Start</label>
                            <input type="date" name="period_start" value="<?= clean($period_start) ?>" required style="width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:10px; background:var(--white); font-family:var(--font-body);">
                        </div>
                        <div class="field-group" style="margin:0;">
                            <label style="font-size:12px; font-weight:700; color:var(--ink-soft); display:block; margin-bottom:6px;">Period End</label>
                            <input type="date" name="period_end" value="<?= clean($period_end) ?>" required style="width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:10px; background:var(--white); font-family:var(--font-body);">
                        </div>
                        <div class="field-group" style="margin:0;">
                            <label style="font-size:12px; font-weight:700; color:var(--ink-soft); display:block; margin-bottom:6px;">Category</label>
                            <select name="category" required style="width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:10px; background:var(--white); font-family:var(--font-body);">
                                <option value="revenue">Sales Revenue Target</option>
                                <option value="payroll">Payroll Budget</option>
                                <option value="restock">Restocking Budget</option>
                            </select>
                        </div>
                        <div class="field-group" style="margin:0;">
                            <label style="font-size:12px; font-weight:700; color:var(--ink-soft); display:block; margin-bottom:6px;">Amount</label>
                            <input type="number" name="amount" min="0" step="0.01" required placeholder="0.00" style="width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:10px; background:var(--white); font-family:var(--font-body);">
                        </div>
                        <div class="field-group" style="margin:0;">
                            <label style="font-size:12px; font-weight:700; color:var(--ink-soft); display:block; margin-bottom:6px;">Notes (optional)</label>
                            <input type="text" name="notes" maxlength="255" placeholder="hal. Based on last quarter's average" style="width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:10px; background:var(--white); font-family:var(--font-body);">
                        </div>
                        <div style="font-size:12px; color:var(--ink-soft);">Saving a budget for a period + category that already has one will update it instead of creating a duplicate.</div>
                        <button type="submit" class="action-btn" style="padding:10px 22px; font-size:14px;">Save Budget</button>
                    </form>
                </div>

                <!-- BUDGET HISTORY -->
                <div class="panel">
                    <div class="panel-header"><h2>Budget History</h2></div>
                    <div class="panel-body panel-body--table" style="padding:0;">
                        <table class="data-table data-table--simple">
                            <thead>
                                <tr>
                                    <th>Period</th>
                                    <th>Category</th>
                                    <th>Amount</th>
                                    <th>Set By</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($budget_history)): ?>
                                    <tr><td colspan="5" class="empty-row">No budgets set yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($budget_history as $b): ?>
                                        <tr>
                                            <td style="font-size:13px;"><?= date('M d', strtotime($b['period_start'])) ?> – <?= date('M d, Y', strtotime($b['period_end'])) ?></td>
                                            <td style="font-size:13px;"><?= clean($categoryLabels[$b['category']] ?? $b['category']) ?></td>
                                            <td style="font-weight:700;"><?= peso($b['budgeted_amount']) ?></td>
                                            <td style="font-size:12.5px; color:var(--ink-soft);"><?= clean($b['created_by_name'] ?? '—') ?></td>
                                            <td>
                                                <form method="POST" action="budgeting.php" onsubmit="return confirmAction(event, this)">
                                                    <?php csrfField(); ?>
                                                    <input type="hidden" name="action" value="delete_budget">
                                                    <input type="hidden" name="budget_id" value="<?= (int) $b['id'] ?>">
                                                    <input type="hidden" name="back_qs" value="<?= clean(http_build_query(['period_start' => $period_start, 'period_end' => $period_end])) ?>">
                                                    <button type="submit" style="background:none; border:none; color:var(--red-alert, #C8472D); font-weight:700; font-size:12.5px; cursor:pointer;">Remove</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<script>
function confirmAction(e, form, opts) {
    e.preventDefault();
    Swal.fire(Object.assign({
        title: 'Remove this budget?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, remove',
        confirmButtonColor: '#C8472D',
    }, opts)).then((result) => {
        if (result.isConfirmed) form.submit();
    });
    return false;
}
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
