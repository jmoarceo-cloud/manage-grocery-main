<?php
// ============================================================
// accounts_payable.php — Finance (+ Admin) side of the Accounts
// Payable module. Every unpaid/partial payable was created the
// moment goods were actually received/fulfilled — never at PO
// or Inventory Request "approved" — see the ACCOUNTS PAYABLE
// MODULE comment block in includes/config.php for the full
// accrual-accounting rationale.
//
// This page:
//   - Lists every outstanding (unpaid/partial) payable, grouped
//     by supplier, with a standard aging breakdown (current /
//     1-30 / 31-60 / 61+ days past due) via getAccountsPayableAging().
//   - Lets Finance (or Admin) record a payment against a payable
//     (recordSupplierPayment()) — the only place actual cash
//     leaves against a payable, which is what
//     getTotalRestockCost() / getCashFlowStatement() key off of
//     for their cash-basis figures.
// ============================================================

require_once 'includes/config.php';
requireAccountsPayableManager();
ensureAccountsPayableTables($pdo);
$cash_position = getBusinessCashPosition($pdo, $hrmsPdo ?? null);
$cash_check_available = !empty($cash_position['payroll_data_available']);
$cash_available_for_payment = $cash_check_available ? max(0, (float) $cash_position['available_cash']) : 0.0;
$poCommitmentSummary = getUnreceivedPurchaseOrderCommitments($pdo);
$po_commitments = $poCommitmentSummary['rows'];
$po_commitments_total = $poCommitmentSummary['total'];

$success_message = $_SESSION['flash_success'] ?? '';
$error_message   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// =====================================================
// HANDLE PAYMENT SUBMISSION
// =====================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    if ($action == 'record_payment') {
        $payable_id     = dec_id($_POST['payable_id'] ?? '');
        $amount         = trim($_POST['amount'] ?? '');
        $payment_method = trim($_POST['payment_method'] ?? '');
        $reference_no   = trim($_POST['reference_no'] ?? '');
        $notes          = trim($_POST['notes'] ?? '');

        if (!ctype_digit((string) $payable_id) || (int) $payable_id <= 0) {
            $_SESSION['flash_error'] = "Invalid payable.";
        } elseif (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $amount) || (float) $amount <= 0) {
            $_SESSION['flash_error'] = "Please enter a valid payment amount.";
        } else {
            $result = recordSupplierPayment(
                $pdo,
                $payable_id,
                (float) $amount,
                $payment_method ?: null,
                $reference_no ?: null,
                $notes ?: null,
                currentPosUserId(),
                $_SESSION['full_name'] ?? null,
                $hrmsPdo ?? null
            );
            if ($result['ok']) {
                $_SESSION['flash_success'] = "Payment of " . peso((float) $amount) . " recorded"
                    . ($result['status'] === 'paid' ? " — payable is now fully paid." : ".");
            } else {
                $_SESSION['flash_error'] = $result['message'];
            }
        }
        redirect('accounts_payable.php' . (!empty($_POST['back_qs']) ? '?' . $_POST['back_qs'] : ''));
    } elseif ($action === 'record_supplier_invoice') {
        $payableId = dec_id($_POST['payable_id'] ?? '');
        $invoiceNumber = trim((string) ($_POST['supplier_invoice_number'] ?? ''));
        $invoiceDate = trim((string) ($_POST['supplier_invoice_date'] ?? ''));
        $invoiceAmount = trim((string) ($_POST['supplier_invoice_amount'] ?? ''));

        if (!ctype_digit((string) $payableId) || (int) $payableId <= 0) {
            $_SESSION['flash_error'] = 'Invalid purchase-order payable.';
        } elseif (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $invoiceAmount)) {
            $_SESSION['flash_error'] = 'Enter a valid supplier invoice amount.';
        } else {
            $result = recordSupplierInvoice($pdo, (int) $payableId, $invoiceNumber, $invoiceDate, (float) $invoiceAmount);
            if ($result['ok']) {
                $_SESSION['flash_success'] = 'Supplier invoice ' . $invoiceNumber . ' recorded. Payment is now available; due date: ' . date('M d, Y', strtotime($result['due_date'])) . '.';
            } else {
                $_SESSION['flash_error'] = $result['message'];
            }
        }
        redirect('accounts_payable.php?section=invoices');
    }
}

// =====================================================
// AGING DATA + BUCKET FILTER
// =====================================================
$aging   = getAccountsPayableAging($pdo);
$buckets = $aging['buckets'];
$rows    = $aging['rows'];
$pendingSupplierInvoices = array_values(array_filter($rows, static function ($row) {
    return ($row['source_type'] ?? '') === 'purchase_order' && empty($row['supplier_invoice_number']);
}));

$bucket_filter = $_GET['bucket'] ?? 'all';
$valid_buckets = ['all', 'current', '1_30', '31_60', '61_plus'];
if (!in_array($bucket_filter, $valid_buckets, true)) $bucket_filter = 'all';

$search = trim($_GET['search'] ?? '');

$filtered_rows = array_filter($rows, function ($r) use ($bucket_filter, $search) {
    if ($bucket_filter !== 'all' && $r['bucket'] !== $bucket_filter) return false;
    if ($search !== '' && stripos($r['supplier_name'], $search) === false && stripos((string) $r['reference_label'], $search) === false) return false;
    return true;
});

$bucket_labels = [
    'current'  => 'Current (not yet due)',
    '1_30'     => '1–30 Days Past Due',
    '31_60'    => '31–60 Days Past Due',
    '61_plus'  => '61+ Days Past Due',
];

$back_qs = $_SERVER['QUERY_STRING'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Accounts Payable — HCI GroceryOne</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.ap-bucket-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
@media (max-width: 900px) { .ap-bucket-grid { grid-template-columns: repeat(2, 1fr); } }
.ap-bucket-card {
    display: block;
    background: var(--white);
    border-radius: var(--radius-lg);
    padding: 16px 18px;
    box-shadow: var(--shadow-card);
    text-decoration: none;
    border: 1.5px solid transparent;
    transition: border-color .15s;
}
.ap-bucket-card:hover { border-color: var(--green-mid); }
.ap-bucket-card.is-active { border-color: var(--green-deep); }
.ap-bucket-card .ap-bucket-label { font-size: 12.5px; font-weight: 600; color: var(--ink-soft); display:block; margin-bottom: 6px; }
.ap-bucket-card .ap-bucket-value { font-family: var(--font-display); font-size: 22px; font-weight: 700; color: var(--text-accent); }
.ap-bucket-card.accent-red .ap-bucket-value   { color: var(--red-alert); }
.ap-bucket-card.accent-mustard .ap-bucket-value { color: #8a5c10; }
html[data-theme="dark"] .ap-bucket-card.accent-mustard .ap-bucket-value { color: var(--mustard); }
.status-badge {
    display: inline-flex; align-items: center; font-size: 12px; font-weight: 700;
    border-radius: 999px; padding: 3px 10px;
}
.status-badge--current  { background: var(--green-pale); color: var(--text-accent); }
.status-badge--1_30     { background: var(--mustard-pale); color: #8a5c10; }
.status-badge--31_60    { background: #FCE6DA; color: #B0501E; }
.status-badge--61_plus  { background: var(--red-pale); color: var(--red-alert); }
html[data-theme="dark"] .status-badge--1_30 { color: var(--mustard); }
.ap-progress { width: 100px; height: 6px; border-radius: 4px; background: var(--cream); overflow: hidden; }
.ap-progress-fill { height: 100%; background: var(--green-mid); }
.modal-overlay {
    display: none; position: fixed; inset: 0; padding: 20px; box-sizing: border-box;
    background: rgba(20,30,25,.55); align-items: center; justify-content: center;
    z-index: 10000; overflow-y: auto;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: var(--white); border-radius: 18px; padding: 26px 28px;
    width: 420px; max-width: min(92vw, 480px); max-height: calc(100vh - 40px);
    overflow-y: auto; box-shadow: 0 18px 55px rgba(15,23,42,.28);
}
.modal-box h3 { font-family: var(--font-display); margin: 0 0 4px; }
.modal-box .modal-sub { color: var(--ink-soft); font-size: 13px; margin-bottom: 18px; }
.modal-actions { display: flex; gap: 10px; margin-top: 22px; }
.btn-cancel { flex: 1; padding: 10px; border-radius: 10px; border: 1.5px solid var(--line); background: var(--white); font-weight: 600; cursor: pointer; }
.btn-confirm { flex: 1; padding: 10px; border-radius: 10px; border: none; background: var(--green-deep); color: #FFFFFF; font-weight: 600; cursor: pointer; }
</style>
</head>
<body>
<div class="app-shell">

    <!-- SIDEBAR -->
    <?php $active = 'accounts_payable'; include 'includes/sidebar.php'; ?>

    <!-- MAIN -->
    <div class="main-area">

        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_accounts_payable ?></div>
            <h1>Accounts Payable</h1>
            <div class="topbar-user">
                <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'F') ?><?php endif; ?></span>
                <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Finance') ?></span>
            </div>
        </header>

        <main class="content">

            <?php if (!empty($success_message)): ?>
                <script>
                Swal.fire({ icon: 'success', title: <?= json_encode(strip_tags($success_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 2600, timerProgressBar: true });
                </script>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <script>
                Swal.fire({ icon: 'error', title: <?= json_encode(clean($error_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 3200, timerProgressBar: true });
                </script>
            <?php endif; ?>

            <?php if (!$cash_check_available): ?>
                <div style="margin-bottom:18px; background:var(--red-pale); color:var(--red-alert); border:1px solid var(--line); border-radius:12px; padding:12px 16px; font-size:13.5px; line-height:1.5;">
                    ⚠️ Available cash cannot be verified because HRMS payroll data is unavailable. Payments are disabled until the cash position can be checked.
                </div>
            <?php elseif ((float) $cash_position['available_cash'] < 0): ?>
                <?php $payableFundingGap = (float) $aging['total_outstanding'] - (float) $cash_position['available_cash']; ?>
                <div style="margin-bottom:18px; background:var(--red-pale); color:var(--red-alert); border:1px solid var(--line); border-radius:12px; padding:12px 16px; font-size:13.5px; line-height:1.5;">
                    ⚠️ Business Cash Position is negative by <?= peso($cash_position['cash_shortfall']) ?>. Outstanding bills remain liabilities; add at least <?= peso($payableFundingGap) ?> in capital to cover the current deficit and settle all payables. Open <a href="finance.php#business-capital" style="font-weight:700; color:var(--text-accent);">Finance</a> to record the capital.
                </div>
            <?php elseif ((float) $cash_position['available_cash'] < (float) $aging['total_outstanding']): ?>
                <?php $payableFundingGap = (float) $aging['total_outstanding'] - (float) $cash_position['available_cash']; ?>
                <div style="margin-bottom:18px; background:var(--mustard-pale); color:var(--ink); border:1px solid var(--line); border-radius:12px; padding:12px 16px; font-size:13.5px; line-height:1.5;">
                    ⚠️ Outstanding payables are <?= peso($payableFundingGap) ?> more than available cash. Pay only what current cash can cover; the remaining payable stays recorded as a liability. To settle all outstanding bills now, add at least <?= peso($payableFundingGap) ?> in capital on the <a href="finance.php#business-capital" style="font-weight:700; color:var(--text-accent);">Finance dashboard</a>.
                </div>
            <?php endif; ?>

            <!-- AGING BUCKET SUMMARY -->
            <div class="ap-bucket-grid">
                <div class="ap-bucket-card">
                    <span class="ap-bucket-label">Available Business Cash</span>
                    <span class="ap-bucket-value" style="<?= $cash_check_available && (float) $cash_position['available_cash'] < 0 ? 'color:var(--red-alert);' : '' ?>"><?= $cash_check_available ? peso($cash_position['available_cash']) : 'Unavailable' ?></span>
                </div>
                <a href="accounts_payable.php" class="ap-bucket-card <?= $bucket_filter === 'all' ? 'is-active' : '' ?>">
                    <span class="ap-bucket-label">Total Outstanding</span>
                    <span class="ap-bucket-value"><?= peso($aging['total_outstanding']) ?></span>
                </a>
                <a href="accounts_payable.php?bucket=current" class="ap-bucket-card <?= $bucket_filter === 'current' ? 'is-active' : '' ?>">
                    <span class="ap-bucket-label">Current (not yet due)</span>
                    <span class="ap-bucket-value"><?= peso($buckets['current']) ?></span>
                </a>
                <a href="accounts_payable.php?bucket=1_30" class="ap-bucket-card accent-mustard <?= $bucket_filter === '1_30' ? 'is-active' : '' ?>">
                    <span class="ap-bucket-label">1–30 Days Past Due</span>
                    <span class="ap-bucket-value"><?= peso($buckets['1_30']) ?></span>
                </a>
                <a href="accounts_payable.php?bucket=61_plus" class="ap-bucket-card accent-red <?= $bucket_filter === '61_plus' ? 'is-active' : '' ?>">
                    <span class="ap-bucket-label">61+ Days Past Due</span>
                    <span class="ap-bucket-value"><?= peso($buckets['61_plus']) ?></span>
                </a>
            </div>

            <!-- SEARCH -->
            <div class="toolbar">
                <form method="GET" action="accounts_payable.php" class="search-form" id="apFilterForm">
                    <?php if ($bucket_filter !== 'all'): ?>
                        <input type="hidden" name="bucket" value="<?= clean($bucket_filter) ?>">
                    <?php endif; ?>
                    <input type="text" name="search" id="apSearchInput" placeholder="Search supplier or reference..." value="<?= clean($search) ?>" class="field-input" style="width:260px;" autocomplete="off">
                    <button type="submit" class="action-btn action-btn--small">Search</button>
                    <?php if ($search !== '' || $bucket_filter !== 'all'): ?>
                        <a href="accounts_payable.php" class="action-btn action-btn--small action-btn--secondary">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="panel" id="po-commitments">
                <div class="panel-header">
                    <h2>Approved Purchase Orders Awaiting Receipt (<?= count($po_commitments) ?>)</h2>
                    <strong><?= peso($po_commitments_total) ?></strong>
                </div>
                <div style="padding:12px 22px 4px; color:var(--ink-soft); font-size:13px; line-height:1.5;">
                    These approved PO amounts are visible here for tracking, but are not yet Accounts Payable liabilities because the goods have not all been received. Each received portion is posted to the payable list below, where Finance can record payment.
                </div>
                <div class="panel-body panel-body--table">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Supplier</th>
                                <th>Purchase Order</th>
                                <th>Status</th>
                                <th>Not Yet Received</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($po_commitments)): ?>
                                <tr><td colspan="5" class="empty-row">No approved Purchase Orders are awaiting receipt.</td></tr>
                            <?php else: ?>
                                <?php foreach ($po_commitments as $commitment): ?>
                                    <tr>
                                        <td style="font-weight:600"><?= clean($commitment['supplier_name']) ?></td>
                                        <td><?= clean($commitment['po_number'] ?: ('PO #' . $commitment['id'])) ?></td>
                                        <td><span class="status-badge status-badge--mustard"><?= clean(ucwords(str_replace('_', ' ', $commitment['status']))) ?></span></td>
                                        <td style="font-weight:700"><?= peso($commitment['unreceived_amount']) ?></td>
                                        <td><a class="action-btn action-btn--small action-btn--secondary" href="purchase_orders.php?po=<?= enc_id($commitment['id']) ?>">View PO</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel" id="supplier-invoices" style="margin-top:18px;">
                <div class="panel-header">
                    <div>
                        <h2>Received Items Awaiting Supplier Invoice (<?= count($pendingSupplierInvoices) ?>)</h2>
                        <span style="font-size:12.5px; color:var(--ink-soft);">Finance records the supplier invoice before any payment can be made.</span>
                    </div>
                </div>
                <div class="panel-body panel-body--table">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Supplier / PO</th>
                                <th>Received Amount</th>
                                <th>Supplier Invoice No.</th>
                                <th>Invoice Date</th>
                                <th>Invoice Amount</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pendingSupplierInvoices)): ?>
                                <tr><td colspan="6" class="empty-row">No received purchase orders are waiting for invoice details.</td></tr>
                            <?php else: ?>
                                <?php foreach ($pendingSupplierInvoices as $invoicePayable): ?>
                                    <tr>
                                        <td style="font-weight:600">
                                            <?= clean($invoicePayable['supplier_name']) ?>
                                            <span style="display:block; font-size:12px; color:var(--ink-soft);"><?= clean($invoicePayable['reference_label'] ?? 'Purchase Order') ?></span>
                                        </td>
                                        <td><?= peso($invoicePayable['amount_owed']) ?></td>
                                        <td colspan="4">
                                            <form method="POST" action="accounts_payable.php?section=invoices" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="record_supplier_invoice">
                                                <input type="hidden" name="payable_id" value="<?= htmlspecialchars(enc_id($invoicePayable['id']), ENT_QUOTES, 'UTF-8') ?>">
                                                <input class="field-input" type="text" name="supplier_invoice_number" maxlength="100" placeholder="Invoice number" required style="width:160px;">
                                                <input class="field-input" type="date" name="supplier_invoice_date" required style="width:155px;">
                                                <input class="field-input" type="number" name="supplier_invoice_amount" min="0.01" max="9999999999.99" step="0.01" value="<?= number_format((float) $invoicePayable['amount_owed'], 2, '.', '') ?>" required style="width:145px;">
                                                <button class="action-btn action-btn--small" type="submit">Record Invoice</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- OUTSTANDING PAYABLES TABLE -->
            <div class="panel">
                <div class="panel-header">
                    <h2><?= isset($bucket_labels[$bucket_filter]) ? clean($bucket_labels[$bucket_filter]) : 'All Outstanding Payables' ?> (<?= count($filtered_rows) ?>)</h2>
                </div>
                <div class="panel-body panel-body--table">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Supplier</th>
                                <th>Source</th>
                                <th>Reference</th>
                                <th>Supplier Invoice</th>
                                <th>Amount Owed</th>
                                <th>Amount Paid</th>
                                <th>Balance</th>
                                <th>Due Date</th>
                                <th>Aging</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($filtered_rows)): ?>
                                <tr><td colspan="10" class="empty-row">No outstanding payables here — every received order in this view has been paid in full.</td></tr>
                            <?php else: ?>
                                <?php
                                    $__ap_source_labels = ['purchase_order' => 'Purchase Order', 'inventory_request' => 'Inventory Request', 'operating_expense' => 'Operating Expense'];
                                    $__ap_source_colors = ['purchase_order' => 'green', 'inventory_request' => 'mustard', 'operating_expense' => 'neutral'];
                                ?>
                                <?php foreach ($filtered_rows as $r): ?>
                                    <?php $paidPct = ((float) $r['amount_owed'] > 0) ? min(100, round(((float) $r['amount_paid'] / (float) $r['amount_owed']) * 100)) : 0; ?>
                                    <tr>
                                        <td style="font-weight:600"><?= clean($r['supplier_name']) ?></td>
                                        <td>
                                            <span class="status-badge status-badge--<?= $__ap_source_colors[$r['source_type']] ?? 'neutral' ?>">
                                                <?= clean($__ap_source_labels[$r['source_type']] ?? $r['source_type']) ?>
                                            </span>
                                            <?php if ($r['source_type'] === 'operating_expense'): ?>
                                                <a href="operating_expenses.php?oe=<?= enc_id($r['source_id']) ?>" style="font-size:11.5px; color:var(--green-mid); font-weight:700; display:block; margin-top:2px;">View bill →</a>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:13px; color:var(--ink-soft);"><?= clean($r['reference_label'] ?? '—') ?></td>
                                        <td style="font-size:13px;">
                                            <?php if ($r['source_type'] === 'purchase_order'): ?>
                                                <?php if (!empty($r['supplier_invoice_number'])): ?>
                                                    <strong><?= clean($r['supplier_invoice_number']) ?></strong>
                                                    <span style="display:block; color:var(--ink-soft);"><?= !empty($r['supplier_invoice_date']) ? date('M d, Y', strtotime($r['supplier_invoice_date'])) : '—' ?></span>
                                                <?php else: ?>
                                                    <span class="status-badge status-badge--mustard">Invoice required</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td><?= peso($r['amount_owed']) ?></td>
                                        <td>
                                            <?= peso($r['amount_paid']) ?>
                                            <?php if ((float) $r['amount_paid'] > 0): ?>
                                                <div class="ap-progress" style="margin-top:4px;"><div class="ap-progress-fill" style="width:<?= $paidPct ?>%"></div></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-weight:700"><?= peso($r['balance']) ?></td>
                                        <td style="font-size:13px;"><?= date('M d, Y', strtotime($r['due_date'])) ?></td>
                                        <td>
                                            <span class="status-badge status-badge--<?= clean($r['bucket']) ?>">
                                                <?= $r['bucket'] === 'current' ? 'Current' : (str_replace('_', '–', $r['bucket']) . ' days') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($r['source_type'] === 'purchase_order' && empty($r['supplier_invoice_number'])): ?>
                                                <span style="font-size:12px; color:var(--ink-soft);">Record invoice first</span>
                                            <?php else: ?>
                                                <button type="button" class="action-btn action-btn--small ap-record-payment"
                                                    data-payable-id="<?= htmlspecialchars(enc_id($r['id']), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-supplier-name="<?= htmlspecialchars((string) $r['supplier_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-balance="<?= number_format((float) $r['balance'], 2, '.', '') ?>">Record Payment</button>
                                            <?php endif; ?>
                                        </td>
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

<!-- RECORD PAYMENT MODAL -->
<div class="modal-overlay" id="paymentModal" aria-hidden="true">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="paymentModalTitle">
        <h3 id="paymentModalTitle">Record Payment</h3>
        <p class="modal-sub" id="paymentSupplierName"></p>
        <form method="POST" action="accounts_payable.php">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" name="back_qs" value="<?= clean($back_qs) ?>">
            <input type="hidden" name="payable_id" id="paymentPayableId">
            <div class="field-group">
                <label class="field-label">Remaining Balance</label>
                <input type="text" id="paymentBalanceDisplay" class="field-input" disabled>
            </div>
            <div class="field-group">
                <label class="field-label">Available Business Cash</label>
                <input type="text" id="paymentCashDisplay" class="field-input" disabled>
                <small id="paymentCashNote" style="display:block; margin-top:5px; color:var(--ink-soft); line-height:1.45;"></small>
            </div>
            <div class="field-group">
                <label class="field-label">Amount to Pay (₱)</label>
                <input type="number" name="amount" id="paymentAmount" class="field-input" min="0.01" step="0.01" required>
            </div>
            <div class="field-group">
                <label class="field-label">Payment Method <span style="font-weight:400; color:var(--ink-soft);">(optional)</span></label>
                <select name="payment_method" class="field-input">
                    <option value="">— Not specified —</option>
                    <option value="cash">Cash</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="check">Check</option>
                    <option value="gcash">GCash</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="field-group">
                <label class="field-label">Reference No. <span style="font-weight:400; color:var(--ink-soft);">(optional)</span></label>
                <input type="text" name="reference_no" class="field-input" placeholder="e.g. Check # or transfer ref.">
            </div>
            <div class="field-group">
                <label class="field-label">Notes <span style="font-weight:400; color:var(--ink-soft);">(optional)</span></label>
                <input type="text" name="notes" class="field-input" placeholder="Any other detail worth noting">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" data-close-payment>Cancel</button>
                <button type="submit" class="btn-confirm" id="recordPaymentSubmit">Record Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('paymentModal');
    var form = modal && modal.querySelector('form');
    if (!modal || !form) return;
    var cashCheckAvailable = <?= $cash_check_available ? 'true' : 'false' ?>;
    var availableCash = <?= json_encode(number_format($cash_available_for_payment, 2, '.', '')) ?>;

    function closePaymentModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.ap-record-payment');
        if (button) {
            var balance = Number(button.dataset.balance);
            if (!Number.isFinite(balance) || balance <= 0) {
                Swal.fire({ icon: 'error', title: 'Invalid balance', text: 'This payable has no remaining balance to pay.' });
                return;
            }
            document.getElementById('paymentPayableId').value = button.dataset.payableId || '';
            document.getElementById('paymentSupplierName').textContent = button.dataset.supplierName || '';
            document.getElementById('paymentBalanceDisplay').value = '₱ ' + balance.toFixed(2);
            document.getElementById('paymentCashDisplay').value = cashCheckAvailable ? '₱ ' + Number(availableCash).toFixed(2) : 'Unavailable';
            var amountInput = document.getElementById('paymentAmount');
            var maxPayment = cashCheckAvailable ? Math.min(balance, Number(availableCash)) : 0;
            amountInput.max = maxPayment.toFixed(2);
            amountInput.value = maxPayment.toFixed(2);
            amountInput.disabled = maxPayment <= 0;
            var cashNote = document.getElementById('paymentCashNote');
            cashNote.textContent = !cashCheckAvailable
                ? 'Payment is disabled until payroll data is available for the cash check.'
                : maxPayment < balance
                    ? 'You can pay up to ₱ ' + maxPayment.toFixed(2) + ' now. The remaining balance stays payable; add capital to pay more.'
                    : 'Payment is limited to available cash and the remaining balance.';
            var submitButton = document.getElementById('recordPaymentSubmit');
            submitButton.disabled = maxPayment <= 0;
            submitButton.textContent = 'Record Payment';
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
            amountInput.focus();
            return;
        }

        if (event.target.closest('[data-close-payment]')) {
            closePaymentModal();
            return;
        }
        if (event.target === modal) closePaymentModal();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('open')) closePaymentModal();
    });

    form.addEventListener('submit', function () {
        var button = document.getElementById('recordPaymentSubmit');
        if (button) {
            button.disabled = true;
            button.textContent = 'Saving payment…';
        }
    });
})();
(function () {
    var input = document.getElementById('apSearchInput');
    if (!input) return;
    var timer = null;
    input.addEventListener('input', function () {
        if (timer) clearTimeout(timer);
        timer = setTimeout(function () { input.form.submit(); }, 450);
    });
})();
</script>
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
