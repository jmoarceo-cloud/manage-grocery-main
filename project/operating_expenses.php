<?php
// ============================================================
// operating_expenses.php — Operating Expenses (Indirect Procurement).
// See PROCUREMENT_MODULE_BUILD_PROMPT.md, Section 3B / 7.
//
// Status flow (see ensureOperatingExpenseTables() + the workflow
// functions in includes/config.php):
//
//   draft -> pending_verification -> verified -> approved
//                                  -> pending_approval -> approved   (>= threshold)
//                                  -> returned -> (draft-like edit, resubmit)
//   (cancelled, from draft or returned only)
//
// Admin + Finance can encode/verify (never the same person for both,
// on the same bill). Level 2 approval (>= threshold) is Admin only.
// Inventory Staff / Cashier have no access to this page at all.
// ============================================================

require_once 'includes/config.php';
require_once 'includes/export.php';
requireOperatingExpenseManager();

// Run schema checks before any approval transaction; MySQL DDL may commit
// an open transaction, so approval must never lazily create these tables.
ensureOperatingExpenseTables($pdo);

$__role       = $_SESSION['role'] ?? '';
$__userId     = currentPosUserId();
$__userName   = $_SESSION['full_name'] ?? '';
$__isAdmin    = ($__role === 'admin');
$__threshold  = getOperatingExpenseLevel2Threshold($pdo);

$success_message = $_SESSION['flash_success'] ?? '';
$error_message   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// =====================================================
// HANDLE FORM SUBMISSIONS
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    // -------------------------------------------------
    // ENCODE (new bill -> draft). Receipt/bill attachment is a hard
    // requirement — validated server-side inside createOperatingExpenseDraft().
    // -------------------------------------------------
    if ($action === 'encode') {
        $data = [
            'category_id'          => (int) ($_POST['category_id'] ?? 0),
            'payee_name'           => trim($_POST['payee_name'] ?? ''),
            'description'          => trim($_POST['description'] ?? ''),
            'billing_period_start' => trim($_POST['billing_period_start'] ?? ''),
            'billing_period_end'   => trim($_POST['billing_period_end'] ?? ''),
            'due_date'             => trim($_POST['due_date'] ?? ''),
            'amount'               => trim($_POST['amount'] ?? ''),
            'is_recurring'         => !empty($_POST['is_recurring']),
        ];
        $submitNow = ($_POST['submit_now'] ?? '') === '1';
        $category  = getExpenseCategoryById($pdo, $data['category_id']);

        if (!$category || $category['status'] !== 'active') {
            $error_message = "Please select a valid, active expense category.";
        } elseif ($data['payee_name'] === '') {
            $error_message = "Payee / vendor name is required.";
        } elseif ($data['due_date'] === '' || !strtotime($data['due_date'])) {
            $error_message = "Please provide a valid due date.";
        } elseif ($data['amount'] === '' || !is_numeric($data['amount']) || (float) $data['amount'] <= 0) {
            $error_message = "Please enter a valid amount greater than 0.";
        } elseif (empty($_FILES['receipt_file']) || ($_FILES['receipt_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $error_message = "Please attach a copy of the bill/receipt (required — image or PDF).";
        } else {
            $result = createOperatingExpenseDraft($pdo, $data, $_FILES['receipt_file'], $__userId, $__userName);
            if (!$result['ok']) {
                $error_message = $result['message'];
            } else {
                $newId = $result['id'];
                if ($submitNow) {
                    $sub = submitOperatingExpense($pdo, $newId, $__userId);
                    $_SESSION['flash_success'] = $sub['ok']
                        ? "Bill encoded and submitted for verification."
                        : "Bill encoded, but could not submit automatically: " . $sub['message'];
                } else {
                    $_SESSION['flash_success'] = "Bill saved as Draft.";
                }
                redirect('operating_expenses.php?oe=' . enc_id($newId));
            }
        }
    }

    // -------------------------------------------------
    // SUBMIT (draft -> pending_verification)
    // -------------------------------------------------
    elseif ($action === 'submit') {
        $id = dec_id($_POST['oe_id'] ?? '');
        $r  = submitOperatingExpense($pdo, $id, $__userId);
        $_SESSION[$r['ok'] ? 'flash_success' : 'flash_error'] = $r['ok'] ? "Submitted for verification." : $r['message'];
        redirect('operating_expenses.php?oe=' . enc_id($id));
    }

    // -------------------------------------------------
    // LEVEL 1 VERIFY (pending_verification -> verified/pending_approval,
    // or straight to approved if under threshold)
    // -------------------------------------------------
    elseif ($action === 'verify') {
        $id             = dec_id($_POST['oe_id'] ?? '');
        $overrideReason = trim($_POST['override_reason'] ?? '');
        $r = verifyOperatingExpense($pdo, $id, $__userId, $__userName, $overrideReason ?: null);
        if (!$r['ok'] && !empty($r['needs_override_reason'])) {
            $_SESSION['flash_error'] = $r['message'];
            redirect('operating_expenses.php?oe=' . enc_id($id) . '&needs_override=1');
        }
        $_SESSION[$r['ok'] ? 'flash_success' : 'flash_error'] = $r['ok']
            ? (!empty($r['needs_level2']) ? "Verified — this bill is at/above the ₱" . number_format($__threshold, 2) . " threshold, so it now needs Admin Level 2 approval." : "Verified and approved — posted to Accounts Payable.")
            : $r['message'];
        redirect('operating_expenses.php?oe=' . enc_id($id));
    }

    // -------------------------------------------------
    // RETURN (pending_verification/pending_approval -> returned)
    // -------------------------------------------------
    elseif ($action === 'return') {
        $id     = dec_id($_POST['oe_id'] ?? '');
        $reason = trim($_POST['return_reason'] ?? '');
        $r = returnOperatingExpense($pdo, $id, $__userId, $reason);
        $_SESSION[$r['ok'] ? 'flash_success' : 'flash_error'] = $r['ok'] ? "Bill returned to the encoder." : $r['message'];
        redirect('operating_expenses.php?oe=' . enc_id($id));
    }

    // -------------------------------------------------
    // RESUBMIT (edit a returned bill -> pending_verification)
    // -------------------------------------------------
    elseif ($action === 'resubmit') {
        $id   = dec_id($_POST['oe_id'] ?? '');
        $data = [
            'category_id'          => (int) ($_POST['category_id'] ?? 0),
            'payee_name'           => trim($_POST['payee_name'] ?? ''),
            'description'          => trim($_POST['description'] ?? ''),
            'billing_period_start' => trim($_POST['billing_period_start'] ?? ''),
            'billing_period_end'   => trim($_POST['billing_period_end'] ?? ''),
            'due_date'             => trim($_POST['due_date'] ?? ''),
            'amount'               => trim($_POST['amount'] ?? ''),
            'is_recurring'         => !empty($_POST['is_recurring']),
        ];
        $category = getExpenseCategoryById($pdo, $data['category_id']);

        if (!$category || $category['status'] !== 'active') {
            $error_message = "Please select a valid, active expense category.";
        } elseif ($data['payee_name'] === '') {
            $error_message = "Payee / vendor name is required.";
        } elseif ($data['due_date'] === '' || !strtotime($data['due_date'])) {
            $error_message = "Please provide a valid due date.";
        } elseif ($data['amount'] === '' || !is_numeric($data['amount']) || (float) $data['amount'] <= 0) {
            $error_message = "Please enter a valid amount greater than 0.";
        } else {
            $newFile = (!empty($_FILES['receipt_file']) && ($_FILES['receipt_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) ? $_FILES['receipt_file'] : null;
            $r = resubmitOperatingExpense($pdo, $id, $data, $newFile, $__userId);
            $_SESSION[$r['ok'] ? 'flash_success' : 'flash_error'] = $r['ok'] ? "Bill revised and resubmitted for verification." : $r['message'];
            if ($r['ok']) redirect('operating_expenses.php?oe=' . enc_id($id));
        }
        if (!empty($error_message)) {
            redirect('operating_expenses.php?oe=' . enc_id($id) . '&edit=1');
        }
    }

    // -------------------------------------------------
    // LEVEL 2 APPROVE (Admin only, pending_approval -> approved)
    // -------------------------------------------------
    elseif ($action === 'level2_approve') {
        requireOperatingExpenseLevel2Approver();
        $id             = dec_id($_POST['oe_id'] ?? '');
        $overrideReason = trim($_POST['override_reason'] ?? '');
        $r = level2ApproveOperatingExpense($pdo, $id, $__userId, $__userName, $overrideReason ?: null);
        if (!$r['ok'] && !empty($r['needs_override_reason'])) {
            $_SESSION['flash_error'] = $r['message'];
            redirect('operating_expenses.php?oe=' . enc_id($id) . '&needs_override=1');
        }
        $_SESSION[$r['ok'] ? 'flash_success' : 'flash_error'] = $r['ok']
            ? ("Approved" . (!empty($r['over_budget']) ? " (over budget, reason logged)" : "") . " — posted to Accounts Payable.")
            : $r['message'];
        redirect('operating_expenses.php?oe=' . enc_id($id));
    }

    // -------------------------------------------------
    // CANCEL (draft/returned -> cancelled)
    // -------------------------------------------------
    elseif ($action === 'cancel') {
        $id = dec_id($_POST['oe_id'] ?? '');
        $r  = cancelOperatingExpense($pdo, $id, $__userId);
        $_SESSION[$r['ok'] ? 'flash_success' : 'flash_error'] = $r['ok'] ? "Bill cancelled." : $r['message'];
        redirect('operating_expenses.php');
    }
}

// =====================================================
// SHOW A SINGLE BILL'S DETAIL?
// =====================================================
$oe_detail   = null;
$edit_mode   = isset($_GET['edit']) && $_GET['edit'] === '1';
$need_override = isset($_GET['needs_override']) && $_GET['needs_override'] === '1';
if (isset($_GET['oe'])) {
    $oe_detail = getOperatingExpenseById($pdo, dec_id($_GET['oe']));
}

// =====================================================
// LIST + STATUS FILTER
// =====================================================
$status_labels = [
    'draft'                => 'Draft',
    'pending_verification' => 'Pending Verification',
    'verified'              => 'Verified',
    'pending_approval'     => 'Pending Level 2 Approval',
    'approved'             => 'Approved',
    'returned'             => 'Returned',
    'cancelled'            => 'Cancelled',
];
$status_colors = [
    'draft'                => 'neutral',
    'pending_verification' => 'mustard',
    'verified'              => 'mustard',
    'pending_approval'     => 'mustard',
    'approved'             => 'green',
    'returned'             => 'red',
    'cancelled'            => 'red',
];

$filter_status = trim($_GET['status'] ?? '');
$search        = trim($_GET['search'] ?? '');
$operating_expenses = getOperatingExpenses($pdo, $filter_status !== '' ? $filter_status : null, $search);
$categories = getActiveExpenseCategories($pdo);

// --- Export the (filtered) list ---
if (isset($_GET['export']) && in_array($_GET['export'], ['xlsx', 'pdf'], true)) {
    $__h = ['Payee', 'Category', 'Amount', 'Due Date', 'Status', 'Encoded By', 'Created At'];
    $__rows = [];
    foreach ($operating_expenses as $oe) {
        $__rows[] = [
            $oe['payee_name'], $oe['category_name'], number_format((float) $oe['amount'], 2),
            $oe['due_date'], $status_labels[$oe['status']] ?? $oe['status'], $oe['encoded_by_name'], $oe['created_at'],
        ];
    }
    $__meta = ['Operating Expenses', ($filter_status !== '' ? 'Status: ' . ($status_labels[$filter_status] ?? $filter_status) : 'All statuses')];
    if ($_GET['export'] === 'xlsx') { exportToExcel('operating_expenses', $__h, $__rows, $__meta); }
    else { exportToPDF('operating_expenses', 'Operating Expenses', $__h, $__rows, $__meta); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Operating Expenses — HCI GroceryOne</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.oe-status-badge { display:inline-flex; align-items:center; font-size:12px; font-weight:700; border-radius:999px; padding:3px 10px; white-space:nowrap; }
.oe-status-badge--neutral { background: var(--cream); color: var(--ink-soft); }
.oe-status-badge--green   { background: var(--green-pale); color: var(--text-accent); }
.oe-status-badge--mustard { background: var(--mustard-pale); color: #7A5A1E; }
.oe-status-badge--red     { background: var(--red-pale); color: #9c3a23; }
html[data-theme="dark"] .oe-status-badge--mustard { color: var(--mustard); }
.filter-pills { display:flex; gap:6px; flex-wrap:wrap; }
.oe-meta-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:16px; }
@media (max-width: 900px) { .oe-meta-grid { grid-template-columns:1fr; } }
.oe-meta-item { font-size:13.5px; }
.oe-meta-item .label { display:block; font-size:11.5px; text-transform:uppercase; letter-spacing:.04em; color:var(--ink-soft); margin-bottom:2px; }
.oe-receipt-preview { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; background:var(--cream); border-radius:10px; font-weight:600; text-decoration:none; color:var(--ink); }
.oe-receipt-preview:hover { background:var(--mustard-pale); }

.oe-receipt-modal-overlay {
    position: fixed; inset: 0; background: rgba(11,33,49,.5);
    display: none; align-items: center; justify-content: center;
    padding: 24px; z-index: 200;
}
.oe-receipt-modal-overlay.is-open { display: flex; }
.oe-receipt-modal-card {
    width: min(100%, 920px); max-height: 92vh; background: var(--white);
    border-radius: 18px; overflow: hidden; border: 1px solid var(--line);
    box-shadow: 0 24px 64px rgba(0,0,0,.25);
}
.oe-receipt-modal-head {
    display:flex; align-items:center; justify-content:space-between; gap:12px;
    padding: 16px 18px; background: var(--white); border-bottom: 1px solid var(--line);
}
.oe-receipt-modal-head h3 {
    margin: 0; font-size: 17px; font-weight: 700; color: var(--text-accent);
}
.oe-receipt-modal-actions { display:flex; gap:8px; }
.oe-receipt-modal-btn {
    border: none; border-radius: 8px; cursor: pointer; padding: 8px 12px;
    font-weight: 700; font-size: 13px; font-family: var(--font-body);
}
.oe-receipt-modal-btn--primary {
    background: var(--green-pale); color: var(--text-accent);
}
.oe-receipt-modal-btn--secondary {
    background: var(--cream); color: var(--ink-soft);
}
.oe-receipt-modal-body {
    background: var(--cream); min-height: 72vh; max-height: 72vh; overflow: hidden;
}
.oe-receipt-modal-body iframe {
    width: 100%; height: 100%; min-height: 72vh; border: none; display: block; background: #fff;
}
@media (max-width: 640px) {
    .oe-receipt-modal-overlay { padding: 0; }
    .oe-receipt-modal-card { width: 100%; max-height: 100vh; height: 100vh; border-radius: 0; }
    .oe-receipt-modal-body, .oe-receipt-modal-body iframe { min-height: calc(100vh - 66px); max-height: calc(100vh - 66px); }
}

/* ---- info alert variant (not in the shared stylesheet — scoped here) ---- */
.alert--info { background: var(--green-pale); color: var(--text-accent); }
.alert--info i { margin-top: 1px; }

/* ---- Encode form polish ---- */
.oe-panel-subtitle { font-size: 13px; color: var(--ink-soft); margin: -2px 0 16px; }
.oe-encode-form .field-group label,
.oe-encode-form .field-group > span.field-label { font-size: 13px; font-weight: 600; color: var(--ink-soft); }
.oe-encode-form .field-checkbox { display:flex; align-items:center; gap:8px; font-size:14px; font-weight:500; color:var(--ink); }
.oe-encode-form .field-checkbox input[type="checkbox"] { width:16px; height:16px; accent-color: var(--green-mid); cursor:pointer; }
.oe-encode-form input[type="file"].field-input { padding: 9px 12px; border-style: dashed; cursor: pointer; }
.oe-encode-form .field-hint { display:block; margin-top:4px; }
.oe-encode-form .form-actions { margin-top: 4px; }

/* Operating Expenses uses the same blue POS surface as Finance, with a
   clearer upload control and consistent panel hierarchy. */
.main-area > .content {
    width: 100%;
    max-width: none;
    box-sizing: border-box;
    margin-inline: 0;
    align-self: stretch;
}
.main-area > .content > .alert,
.main-area > .content > .panel,
.main-area > .content > .toolbar {
    width: 100%;
    box-sizing: border-box;
}
.content > .panel,
.content > .toolbar,
.content > .alert {
    border-radius: 16px;
}
.content > .panel {
    border: 1px solid var(--line);
    box-shadow: 0 10px 28px rgba(15, 23, 42, .055);
}
.content > .panel .panel-header {
    gap: 16px;
    align-items: center;
}
.content > .panel .panel-header h2 {
    color: var(--text-accent);
    letter-spacing: -.02em;
}
.oe-encode-form {
    width: 100%;
    box-sizing: border-box;
    background: var(--cream);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 22px;
}
.oe-encode-form .form-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px 16px;
}
.oe-encode-form .field-group--full {
    grid-column: 1 / -1;
}
.oe-panel-subtitle {
    max-width: 900px;
    margin-bottom: 18px;
    line-height: 1.6;
}
.main-area > .content > .panel > .panel-body {
    padding: 22px;
}
.main-area > .content > .panel > .panel-body > .oe-encode-form {
    margin: 0;
}
.content > .toolbar {
    align-items: center;
    gap: 14px;
}
.content > .toolbar .filter-tabs {
    min-width: 0;
    flex: 1 1 auto;
}
.content > .toolbar .search-form {
    flex: 0 1 340px;
    width: min(100%, 340px);
    max-width: 340px !important;
}
.content > .toolbar .search-form .field-input {
    min-width: 0;
    width: 100%;
}
.oe-encode-form .field-input {
    background: var(--white);
}
.oe-encode-form input[type="file"].field-input {
    display: block;
    min-height: 48px;
    padding: 7px 9px;
    border: 1px dashed #93c5fd;
    background: #f8fbff;
}
.oe-encode-form input[type="file"].field-input::file-selector-button {
    margin-right: 10px;
    padding: 8px 12px;
    border: 0;
    border-radius: 8px;
    background: linear-gradient(135deg, #2563eb, #0284c7);
    color: #fff;
    font: inherit;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}
.oe-encode-form input[type="file"].field-input:hover {
    border-color: #2563eb;
    background: #eff6ff;
}
.filter-pills .filter-tab {
    display: inline-flex;
    align-items: center;
    min-height: 36px;
    padding: 8px 13px;
    border: 1px solid var(--line);
    border-radius: 999px;
    background: var(--white);
    color: var(--ink-soft);
    font-size: 12.5px;
    font-weight: 700;
}
.filter-pills .filter-tab:hover,
.filter-pills .filter-tab.is-active {
    border-color: #93c5fd;
    background: #eff6ff;
    color: #1d4ed8;
}
.oe-receipt-preview {
    border: 1px solid var(--line);
    color: #1d4ed8;
    background: #eff6ff;
}
.oe-receipt-preview:hover {
    border-color: #93c5fd;
    background: #dbeafe;
}
html[data-theme="dark"] .oe-encode-form {
    background: #111827;
    border-color: #26313a;
}
html[data-theme="dark"] .oe-encode-form .field-input,
html[data-theme="dark"] .oe-encode-form input[type="file"].field-input {
    background: #0d1013;
    border-color: #475569;
    color: #f1f5f9;
}
html[data-theme="dark"] .oe-encode-form input[type="file"].field-input:hover {
    background: #172554;
    border-color: #60a5fa;
}
html[data-theme="dark"] .filter-pills .filter-tab {
    background: #0d1013;
    border-color: #334155;
    color: #cbd5e1;
}
html[data-theme="dark"] .filter-pills .filter-tab:hover,
html[data-theme="dark"] .filter-pills .filter-tab.is-active {
    background: #172554;
    border-color: #60a5fa;
    color: #bfdbfe;
}
html[data-theme="dark"] .oe-receipt-preview {
    background: #172554;
    border-color: #334155;
    color: #bfdbfe;
}
@media (max-width: 720px) {
    .oe-encode-form { padding: 14px; }
    .content > .panel .panel-header { align-items: flex-start; flex-direction: column; }
    .content > .panel .panel-header > * { width: 100%; }
    .main-area > .content > .panel > .panel-body { padding: 16px; }
    .oe-encode-form .form-grid { grid-template-columns: 1fr; }
    .oe-encode-form .field-group--full { grid-column: auto; }
    .content > .toolbar { align-items: stretch; flex-direction: column; }
    .content > .toolbar .filter-tabs { width: 100%; }
    .content > .toolbar .search-form {
        width: 100%;
        max-width: none !important;
        flex-basis: auto;
        margin-left: 0 !important;
    }
}
</style>
</head>
<body>
<div class="app-shell">

    <?php $active = 'operating_expenses'; include 'includes/sidebar.php'; ?>

    <div class="main-area">
        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_operating_expenses ?></div>
            <h1>Operating Expenses</h1>
            <div class="topbar-user">
                <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'A') ?><?php endif; ?></span>
                <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'User') ?></span>
            </div>
        </header>

        <main class="content">

            <?php if (!empty($success_message)): ?>
                <script>Swal.fire({ icon: 'success', title: <?= json_encode(strip_tags($success_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 3200, timerProgressBar: true });</script>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <script>Swal.fire({ icon: 'error', title: <?= json_encode(clean($error_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 3800, timerProgressBar: true });</script>
            <?php endif; ?>

            <div class="alert alert--info" style="margin-bottom:16px;">
                <i class="fa-solid fa-circle-info"></i>
                <span>Level 2 threshold: bills of <strong><?= peso($__threshold) ?></strong> or more need an extra Admin approval after Level 1 verification. Admins can change this on <a href="expense_categories.php" style="color:var(--green-mid); font-weight:700;">Expense Categories</a>.</span>
            </div>

            <?php if ($oe_detail): ?>
                <!-- ================= DETAIL / EDIT VIEW ================= -->
                <div class="panel">
                    <div class="panel-header">
                        <h2><?= clean($oe_detail['payee_name']) ?> <span class="oe-status-badge oe-status-badge--<?= $status_colors[$oe_detail['status']] ?? 'neutral' ?>"><?= clean($status_labels[$oe_detail['status']] ?? $oe_detail['status']) ?></span></h2>
                        <a href="operating_expenses.php" class="action-btn action-btn--secondary action-btn--small">&larr; Back to list</a>
                    </div>
                    <div class="panel-body">

                        <?php if ($edit_mode && $oe_detail['status'] === 'returned'): ?>
                            <!-- ---- EDIT & RESUBMIT FORM ---- -->
                            <?php if (!empty($oe_detail['return_reason'])): ?>
                                <div class="alert alert--error" style="margin-bottom:14px;">Returned: <?= clean($oe_detail['return_reason']) ?></div>
                            <?php endif; ?>
                            <form method="POST" action="operating_expenses.php" enctype="multipart/form-data" class="oe-encode-form" style="display:grid; gap:16px; max-width:640px;">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="resubmit">
                                <input type="hidden" name="oe_id" value="<?= enc_id($oe_detail['id']) ?>">
                                <div class="form-grid" style="margin-bottom:0;">
                                    <div class="field-group field-group--full">
                                        <label>Category</label>
                                        <select name="category_id" class="field-input" required>
                                            <?php foreach ($categories as $c): ?>
                                                <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $oe_detail['category_id'] ? 'selected' : '' ?>><?= clean($c['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="field-group field-group--full"><label>Payee / Vendor</label><input type="text" name="payee_name" class="field-input" value="<?= clean($oe_detail['payee_name']) ?>" required></div>
                                    <div class="field-group field-group--full"><label>Description</label><input type="text" name="description" class="field-input" value="<?= clean($oe_detail['description'] ?? '') ?>"></div>
                                    <div class="field-group"><label>Billing Period Start</label><input type="date" name="billing_period_start" class="field-input" value="<?= clean($oe_detail['billing_period_start'] ?? '') ?>"></div>
                                    <div class="field-group"><label>Billing Period End</label><input type="date" name="billing_period_end" class="field-input" value="<?= clean($oe_detail['billing_period_end'] ?? '') ?>"></div>
                                    <div class="field-group"><label>Due Date</label><input type="date" name="due_date" class="field-input" value="<?= clean($oe_detail['due_date']) ?>" required></div>
                                    <div class="field-group"><label>Amount</label><input type="number" step="0.01" min="0.01" name="amount" class="field-input" value="<?= clean($oe_detail['amount']) ?>" required></div>
                                    <div class="field-group field-group--full">
                                        <label class="field-checkbox"><input type="checkbox" name="is_recurring" value="1" <?= $oe_detail['is_recurring'] ? 'checked' : '' ?>> Recurring bill</label>
                                    </div>
                                    <div class="field-group field-group--full">
                                        <label>Replace Receipt/Bill (optional — leave blank to keep current file)</label>
                                        <input type="file" name="receipt_file" class="field-input" accept=".jpg,.jpeg,.png,.pdf">
                                    </div>
                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="action-btn"><i class="fa-regular fa-paper-plane"></i> Resubmit for Verification</button>
                                    <a href="operating_expenses.php?oe=<?= clean($_GET['oe']) ?>" class="action-btn action-btn--secondary">Cancel</a>
                                </div>
                            </form>

                        <?php else: ?>
                            <!-- ---- READ-ONLY DETAIL ---- -->
                            <div class="oe-meta-grid">
                                <div class="oe-meta-item"><span class="label">Category</span><?= clean($oe_detail['category_name'] ?? '—') ?></div>
                                <div class="oe-meta-item"><span class="label">Amount</span><strong><?= peso($oe_detail['amount']) ?></strong><?= (float) $oe_detail['amount'] >= $__threshold ? ' <span style="color:var(--mustard); font-weight:700;">(≥ Level 2 threshold)</span>' : '' ?></div>
                                <div class="oe-meta-item"><span class="label">Due Date</span><?= clean(date('M d, Y', strtotime($oe_detail['due_date']))) ?></div>
                                <?php if (!empty($oe_detail['billing_period_start'])): ?>
                                    <div class="oe-meta-item"><span class="label">Billing Period</span><?= clean(date('M d, Y', strtotime($oe_detail['billing_period_start']))) ?> – <?= clean(date('M d, Y', strtotime($oe_detail['billing_period_end']))) ?></div>
                                <?php endif; ?>
                                <div class="oe-meta-item"><span class="label">Recurring</span><?= $oe_detail['is_recurring'] ? 'Yes' : 'No' ?></div>
                                <div class="oe-meta-item"><span class="label">Encoded By</span><?= clean($oe_detail['encoded_by_name'] ?? '—') ?> &middot; <?= clean(date('M d, Y', strtotime($oe_detail['created_at']))) ?></div>
                                <?php if (!empty($oe_detail['verified_at'])): ?>
                                    <div class="oe-meta-item"><span class="label">Verified By</span><?= clean($oe_detail['verified_by_name'] ?? '—') ?> &middot; <?= clean(date('M d, Y g:i A', strtotime($oe_detail['verified_at']))) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($oe_detail['approved_at'])): ?>
                                    <div class="oe-meta-item"><span class="label">Approved By</span><?= clean($oe_detail['approved_by_name'] ?? '—') ?> &middot; <?= clean(date('M d, Y g:i A', strtotime($oe_detail['approved_at']))) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($oe_detail['over_budget_flag'])): ?>
                                    <div class="oe-meta-item"><span class="label">Over Budget Reason</span><span style="color:#9c3a23; font-weight:600;"><?= clean($oe_detail['over_budget_reason'] ?? '—') ?></span></div>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($oe_detail['description'])): ?>
                                <div class="finance-req-notes" style="margin-bottom:14px;"><?= nl2br(clean($oe_detail['description'])) ?></div>
                            <?php endif; ?>

                            <?php if (!empty($oe_detail['receipt_file_path'])): ?>
                                <button type="button" class="oe-receipt-preview" data-receipt-url="<?= OE_RECEIPT_UPLOAD_DIR . rawurlencode($oe_detail['receipt_file_path']) ?>" onclick="openReceiptModal(this.dataset.receiptUrl)">
                                    <i class="fa-regular fa-file-lines"></i> View attached receipt/bill
                                </button>
                            <?php endif; ?>

                            <?php if ($oe_detail['status'] === 'returned' && !empty($oe_detail['return_reason'])): ?>
                                <div class="alert alert--error" style="margin-top:14px;">Returned: <?= clean($oe_detail['return_reason']) ?></div>
                            <?php endif; ?>

                            <?php if ($need_override): ?>
                                <!-- ---- OVER-BUDGET OVERRIDE FORM ---- -->
                                <div class="alert alert--warning" style="margin:16px 0;">
                                    This bill would push its category over its monthly budget. Provide a reason to proceed, or approve normally another time.
                                </div>
                                <form method="POST" action="operating_expenses.php" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:16px;">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="<?= $oe_detail['status'] === 'pending_verification' ? 'verify' : 'level2_approve' ?>">
                                    <input type="hidden" name="oe_id" value="<?= enc_id($oe_detail['id']) ?>">
                                    <div class="field-group" style="flex:1; min-width:260px;">
                                        <label class="field-label">Override Reason (required)</label>
                                        <input type="text" name="override_reason" class="field-input" placeholder="e.g. Urgent, no alternative, will adjust next month's budget" required>
                                    </div>
                                    <button type="submit" class="action-btn action-btn--danger">Approve Anyway</button>
                                </form>
                            <?php endif; ?>

                            <!-- ACTIONS -->
                            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;">
                                <?php if ($oe_detail['status'] === 'draft'): ?>
                                    <?php if ((int) $oe_detail['encoded_by'] === (int) $__userId || $__isAdmin): ?>
                                        <form method="POST" action="operating_expenses.php" class="inline-form"
                                              onsubmit="return confirmAction(event, this, {icon:'question', title:'Submit for Verification?', text:'Send this bill for Level 1 verification.', confirmButtonText:'Yes, submit', confirmButtonColor:'#123C5C'});">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="submit">
                                            <input type="hidden" name="oe_id" value="<?= enc_id($oe_detail['id']) ?>">
                                            <button type="submit" class="action-btn">Submit for Verification</button>
                                        </form>
                                        <form method="POST" action="operating_expenses.php" class="inline-form"
                                              onsubmit="return confirmAction(event, this, {icon:'warning', title:'Cancel this bill?', text:'This cannot be undone.', confirmButtonText:'Yes, cancel', confirmButtonColor:'#c0392b'});">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="oe_id" value="<?= enc_id($oe_detail['id']) ?>">
                                            <button type="submit" class="action-btn action-btn--secondary">Cancel Bill</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($oe_detail['status'] === 'pending_verification'): ?>
                                    <?php if ((int) $oe_detail['encoded_by'] !== (int) $__userId): ?>
                                        <form method="POST" action="operating_expenses.php" class="inline-form"
                                              onsubmit="return confirmAction(event, this, {icon:'question', title:'Verify this bill?', text:'Level 1 verification — Level 2 Admin approval will still be needed if this is at/above the threshold.', confirmButtonText:'Yes, verify', confirmButtonColor:'#123C5C'});">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="verify">
                                            <input type="hidden" name="oe_id" value="<?= enc_id($oe_detail['id']) ?>">
                                            <button type="submit" class="action-btn">Verify</button>
                                        </form>
                                        <button type="button" class="action-btn action-btn--secondary" onclick="document.getElementById('returnForm').style.display='flex'">Return to Encoder</button>
                                    <?php else: ?>
                                        <div class="alert alert--warning" style="margin:0;">You encoded this bill — another Finance/Admin user needs to verify it (segregation of duties).</div>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($oe_detail['status'] === 'pending_approval'): ?>
                                    <?php if ($__isAdmin): ?>
                                        <form method="POST" action="operating_expenses.php" class="inline-form"
                                              onsubmit="return confirmAction(event, this, {icon:'question', title:'Level 2 Approve?', text:'This bill is at/above the ₱<?= number_format($__threshold, 2) ?> threshold. This will post it to Accounts Payable.', confirmButtonText:'Yes, approve', confirmButtonColor:'#123C5C'});">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="level2_approve">
                                            <input type="hidden" name="oe_id" value="<?= enc_id($oe_detail['id']) ?>">
                                            <button type="submit" class="action-btn">Level 2 Approve</button>
                                        </form>
                                        <button type="button" class="action-btn action-btn--secondary" onclick="document.getElementById('returnForm').style.display='flex'">Return to Encoder</button>
                                    <?php else: ?>
                                        <div class="alert alert--warning" style="margin:0;">Waiting for Admin Level 2 approval — this bill is at/above the ₱<?= number_format($__threshold, 2) ?> threshold.</div>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($oe_detail['status'] === 'returned' && ((int) $oe_detail['encoded_by'] === (int) $__userId || $__isAdmin)): ?>
                                    <a href="operating_expenses.php?oe=<?= clean($_GET['oe']) ?>&edit=1" class="action-btn">Edit &amp; Resubmit</a>
                                    <form method="POST" action="operating_expenses.php" class="inline-form"
                                          onsubmit="return confirmAction(event, this, {icon:'warning', title:'Cancel this bill?', text:'This cannot be undone.', confirmButtonText:'Yes, cancel', confirmButtonColor:'#c0392b'});">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="oe_id" value="<?= enc_id($oe_detail['id']) ?>">
                                        <button type="submit" class="action-btn action-btn--secondary">Cancel Bill</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($oe_detail['status'] === 'approved'): ?>
                                    <a href="accounts_payable.php" class="action-btn action-btn--secondary">View in Accounts Payable →</a>
                                <?php endif; ?>
                            </div>

                            <form method="POST" action="operating_expenses.php" id="returnForm" style="display:none; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-top:14px;">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="return">
                                <input type="hidden" name="oe_id" value="<?= enc_id($oe_detail['id']) ?>">
                                <div class="field-group" style="flex:1; min-width:260px;">
                                    <label>Reason for returning</label>
                                    <input type="text" name="return_reason" placeholder="e.g. Wrong amount, blurry receipt, wrong category" required>
                                </div>
                                <button type="submit" class="action-btn action-btn--danger">Return</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>
                <!-- ================= ENCODE FORM ================= -->
                <div class="panel" style="margin-bottom:16px;">
                    <div class="panel-header"><h2><i class="fa-solid fa-file-circle-plus" style="margin-right:8px; color:var(--green-mid);"></i>Encode a New Bill</h2></div>
                    <div class="panel-body">
                        <p class="oe-panel-subtitle">Fill in the bill details below, attach a copy of the receipt/bill, then save it as a draft or send it straight for verification.</p>
                        <form method="POST" action="operating_expenses.php" enctype="multipart/form-data" class="oe-encode-form" style="display:grid; gap:16px;">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="encode">
                            <div class="form-grid" style="margin-bottom:0;">
                                <div class="field-group">
                                    <label>Category</label>
                                    <select name="category_id" class="field-input" required>
                                        <option value="">— Select category —</option>
                                        <?php foreach ($categories as $c): ?>
                                            <option value="<?= (int) $c['id'] ?>"><?= clean($c['name']) ?><?= $c['monthly_budget'] !== null ? ' (Budget: ' . peso($c['monthly_budget']) . '/mo)' : '' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($categories)): ?>
                                        <small class="field-hint">No active categories yet — <a href="expense_categories.php" style="color:var(--green-mid); font-weight:700;">create one first</a>.</small>
                                    <?php endif; ?>
                                </div>
                                <div class="field-group"><label>Payee / Vendor</label><input type="text" name="payee_name" class="field-input" placeholder="e.g. Meralco, PLDT, Landlord" required></div>
                                <div class="field-group field-group--full"><label>Description (optional)</label><input type="text" name="description" class="field-input" placeholder="e.g. July electricity bill"></div>
                                <div class="field-group"><label>Billing Period Start (optional)</label><input type="date" name="billing_period_start" class="field-input"></div>
                                <div class="field-group"><label>Billing Period End (optional)</label><input type="date" name="billing_period_end" class="field-input"></div>
                                <div class="field-group"><label>Due Date</label><input type="date" name="due_date" class="field-input" required></div>
                                <div class="field-group"><label>Amount</label><input type="number" step="0.01" min="0.01" name="amount" class="field-input" placeholder="0.00" required></div>
                                <div class="field-group field-group--full">
                                    <label class="field-checkbox"><input type="checkbox" name="is_recurring" value="1"> This is a recurring bill</label>
                                </div>
                                <div class="field-group field-group--full">
                                    <label>Receipt / Bill Attachment</label>
                                    <input type="file" name="receipt_file" class="field-input" accept=".jpg,.jpeg,.png,.pdf" required>
                                    <small class="field-hint">Required — JPG, PNG, or PDF, max 5MB.</small>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="button" class="action-btn action-btn--secondary" onclick="document.querySelector('[name=submit_now]').value='0'; this.closest('form').submit();"><i class="fa-regular fa-floppy-disk"></i> Save as Draft</button>
                                <input type="hidden" name="submit_now" value="0">
                                <button type="submit" class="action-btn" onclick="document.querySelector('[name=submit_now]').value='1';"><i class="fa-regular fa-paper-plane"></i> Save &amp; Submit for Verification</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- ================= LIST ================= -->
                <div class="toolbar" style="margin-bottom:12px;">
                    <div class="filter-tabs">
                        <a href="operating_expenses.php" class="filter-tab <?= $filter_status === '' ? 'is-active' : '' ?>">All</a>
                        <?php foreach ($status_labels as $key => $label): ?>
                            <a href="operating_expenses.php?status=<?= $key ?>" class="filter-tab <?= $filter_status === $key ? 'is-active' : '' ?>"><?= $label ?></a>
                        <?php endforeach; ?>
                    </div>
                    <form method="GET" action="operating_expenses.php" class="search-form" style="max-width:260px; margin-left:auto;">
                        <?php if ($filter_status !== ''): ?><input type="hidden" name="status" value="<?= clean($filter_status) ?>"><?php endif; ?>
                        <input type="text" name="search" placeholder="Search payee or description..." value="<?= clean($search) ?>" class="field-input">
                        <button type="submit" class="action-btn action-btn--small"><i class="fa-solid fa-magnifying-glass"></i></button>
                    </form>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2>Bills (<?= count($operating_expenses) ?>)</h2>
                        <div style="display:flex; gap:8px;">
                            <?= exportPostForm('operating_expenses.php?' . http_build_query(array_filter(['status' => $filter_status, 'search' => $search])) . '&export=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                            <?= exportPostForm('operating_expenses.php?' . http_build_query(array_filter(['status' => $filter_status, 'search' => $search])) . '&export=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
                        </div>
                    </div>
                    <div class="panel-body panel-body--table">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Payee</th>
                                    <th>Category</th>
                                    <th>Amount</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th>Encoded By</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($operating_expenses)): ?>
                                    <tr><td colspan="7" class="empty-row">No bills found for this filter.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($operating_expenses as $oe): ?>
                                        <tr>
                                            <td style="font-weight:600"><?= clean($oe['payee_name']) ?></td>
                                            <td><?= clean($oe['category_name'] ?? '—') ?></td>
                                            <td><?= peso($oe['amount']) ?></td>
                                            <td style="font-size:13px;"><?= clean(date('M d, Y', strtotime($oe['due_date']))) ?></td>
                                            <td><span class="oe-status-badge oe-status-badge--<?= $status_colors[$oe['status']] ?? 'neutral' ?>"><?= clean($status_labels[$oe['status']] ?? $oe['status']) ?></span></td>
                                            <td style="font-size:13px;"><?= clean($oe['encoded_by_name'] ?? '—') ?></td>
                                            <td><a href="operating_expenses.php?oe=<?= enc_id($oe['id']) ?>" class="link-btn" title="View" aria-label="View"><i class="fa-regular fa-eye"></i></a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<div class="oe-receipt-modal-overlay" id="oeReceiptModal" aria-hidden="true">
    <div class="oe-receipt-modal-card" role="dialog" aria-modal="true" aria-label="Attached receipt or bill preview">
        <div class="oe-receipt-modal-head">
            <h3>Attached Receipt / Bill</h3>
            <div class="oe-receipt-modal-actions">
                <button type="button" class="oe-receipt-modal-btn oe-receipt-modal-btn--primary" onclick="printReceiptPreview()">Print</button>
                <button type="button" class="oe-receipt-modal-btn oe-receipt-modal-btn--secondary" onclick="closeReceiptModal()">Close</button>
            </div>
        </div>
        <div class="oe-receipt-modal-body">
            <iframe id="oeReceiptFrame" title="Attached receipt or bill preview" src="about:blank"></iframe>
        </div>
    </div>
</div>

<script>
function confirmNav(e, url, opts) {
    e.preventDefault();
    Swal.fire(Object.assign({ showCancelButton: true, cancelButtonText: 'Cancel' }, opts)).then(function (result) {
        if (result.isConfirmed) window.location.href = url;
    });
    return false;
}
function confirmAction(e, form, opts) {
    e.preventDefault();
    Swal.fire(Object.assign({ showCancelButton: true, cancelButtonText: 'Cancel' }, opts)).then(function (result) {
        if (result.isConfirmed) form.submit();
    });
    return false;
}

function openReceiptModal(url) {
    const modal = document.getElementById('oeReceiptModal');
    const frame = document.getElementById('oeReceiptFrame');
    if (!modal || !frame) return;
    frame.src = url;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
}

function closeReceiptModal() {
    const modal = document.getElementById('oeReceiptModal');
    const frame = document.getElementById('oeReceiptFrame');
    if (!modal || !frame) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    frame.src = 'about:blank';
}

function printReceiptPreview() {
    const frame = document.getElementById('oeReceiptFrame');
    if (!frame || !frame.src || frame.src === 'about:blank') return;
    frame.contentWindow && frame.contentWindow.focus();
    frame.contentWindow && frame.contentWindow.print && frame.contentWindow.print();
}

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        const modal = document.getElementById('oeReceiptModal');
        if (modal && modal.classList.contains('is-open')) {
            closeReceiptModal();
        }
    }
});

document.addEventListener('click', function (event) {
    const modal = document.getElementById('oeReceiptModal');
    if (!modal || !modal.classList.contains('is-open')) return;
    if (event.target === modal) {
        closeReceiptModal();
    }
});
</script>
</body>
</html>