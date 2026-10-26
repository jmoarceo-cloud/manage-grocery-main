<?php
// ============================================================
// expense_categories.php — Expense Categories + Level 2 threshold.
// Admin + Finance manage categories and monthly budgets (Section 5).
// Only Admin can change the Level 2 approval threshold (Section 6),
// since it controls who gets to approve big-ticket bills.
// ============================================================

require_once 'includes/config.php';
requireExpenseCategoryManager();
ensureOperatingExpenseTables($pdo);

$__isAdmin = (($_SESSION['role'] ?? '') === 'admin');

$success_message = $_SESSION['flash_success'] ?? '';
$error_message   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_category') {
        $encodedId     = $_POST['category_id'] ?? '';
        $id            = $encodedId !== '' ? dec_id($encodedId) : null;
        $name          = trim($_POST['name'] ?? '');
        $isRecurring   = !empty($_POST['is_recurring']);
        $monthlyBudget = trim($_POST['monthly_budget'] ?? '');
        $status        = $_POST['status'] ?? 'active';

        if ($encodedId !== '' && (!ctype_digit((string) $id) || (int) $id <= 0)) {
            $error_message = "Invalid expense category.";
        } elseif ($name === '') {
            $error_message = "Category name is required.";
        } elseif (strlen($name) > 100) {
            $error_message = "Category name cannot exceed 100 characters.";
        } elseif ($monthlyBudget !== '' && (!is_numeric($monthlyBudget) || !is_finite((float) $monthlyBudget) || (float) $monthlyBudget < 0)) {
            $error_message = "Monthly budget must be a valid non-negative number, or left blank for no limit.";
        } elseif (!in_array($status, ['active', 'inactive'], true)) {
            $error_message = "Please select a valid category status.";
        } else {
            $ok = upsertExpenseCategory($pdo, $id, $name, $isRecurring, $monthlyBudget, $status);
            if ($ok) {
                $_SESSION['flash_success'] = "Category saved.";
                redirect('expense_categories.php');
            }
            $error_message = "Could not save the category. Please try again.";
        }
    }

    elseif ($action === 'delete_category' || $action === 'deactivate_category') {
        $id = dec_id($_POST['category_id'] ?? '');
        $ok = deactivateExpenseCategory($pdo, $id);
        $_SESSION[$ok ? 'flash_success' : 'flash_error'] = $ok
            ? "Category deleted from active use. Existing bills and reports remain unchanged."
            : "Could not delete the category. It may already be inactive or no longer exist.";
        redirect('expense_categories.php');
    }

    elseif ($action === 'save_threshold') {
        if (!$__isAdmin) {
            $_SESSION['flash_error'] = "Only an Admin can change the Level 2 approval threshold.";
            redirect('expense_categories.php');
        }
        $amount = trim($_POST['threshold'] ?? '');
        if ($amount === '' || !is_numeric($amount) || (float) $amount < 0) {
            $_SESSION['flash_error'] = "Please enter a valid threshold amount.";
        } else {
            setOperatingExpenseLevel2Threshold($pdo, $amount);
            $_SESSION['flash_success'] = "Level 2 approval threshold updated to " . peso($amount) . ".";
        }
        redirect('expense_categories.php');
    }
}

$edit_category = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $editId = dec_id($_GET['id']);
    if (!ctype_digit((string) $editId) || (int) $editId <= 0) {
        $error_message = "Invalid expense category.";
    } else {
        $edit_category = getExpenseCategoryById($pdo, $editId);
        if (!$edit_category) $error_message = "Expense category not found.";
    }
}
$show_add = isset($_GET['action']) && $_GET['action'] === 'add';

$categories = getAllExpenseCategories($pdo);
$threshold  = getOperatingExpenseLevel2Threshold($pdo);
$today      = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Expense Categories — HCI GroceryOne</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.status-pill { display:inline-flex; align-items:center; font-size:12px; font-weight:700; border-radius:999px; padding:3px 10px; white-space:nowrap; }
.status-pill--active   { background: var(--green-pale); color: var(--text-accent); }
.status-pill--inactive { background: var(--cream); color: var(--ink-soft); }

/* ---- Add/Edit Category form polish ---- */
.ec-panel-subtitle { font-size: 13px; color: var(--ink-soft); margin: -2px 0 16px; }
.ec-form .field-checkbox { display:flex; align-items:center; gap:8px; font-size:14px; font-weight:500; color:var(--ink); cursor:pointer; }
.ec-form .field-checkbox input[type="checkbox"] { width:16px; height:16px; accent-color: var(--green-mid); cursor:pointer; }
.ec-actions-cell { white-space: nowrap; }
.ec-row-actions { display:flex; align-items:center; justify-content:center; gap:8px; min-height:36px; }
.ec-row-actions form { display:flex; align-items:center; margin:0; }
.ec-row-actions .link-btn {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:36px;
    height:36px;
    min-height:36px;
    padding:0;
    border:1px solid var(--line);
    border-radius:9px;
    line-height:1;
    text-decoration:none;
}
.ec-row-actions .link-btn:hover { background:var(--green-pale); text-decoration:none; }
.ec-row-actions .link-btn--danger { color:var(--red-alert); }
.ec-row-actions .link-btn--danger:hover { background:var(--red-pale); color:#9c3a23; }
</style>
</head>
<body>
<div class="app-shell">

    <?php $active = 'expense_categories'; include 'includes/sidebar.php'; ?>

    <div class="main-area">
        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_expense_categories ?></div>
            <h1>Expense Categories</h1>
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

            <!-- LEVEL 2 THRESHOLD -->
            <div class="panel" style="margin-bottom:16px;">
                <div class="panel-header"><h2>Level 2 Approval Threshold</h2></div>
                <div class="panel-body">
                    <p style="font-size:13.5px; color:var(--ink-soft); margin:0 0 12px;">
                        Operating expense bills of this amount or more need a second, Admin-only approval after Level 1 verification, on top of the usual segregation-of-duties check. Currently <strong><?= peso($threshold) ?></strong>.
                    </p>
                    <?php if ($__isAdmin): ?>
                        <form method="POST" action="expense_categories.php" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="save_threshold">
                            <div class="field-group" style="margin:0;">
                                <label class="field-label">Threshold Amount</label>
                                <input type="number" step="0.01" min="0" name="threshold" value="<?= clean($threshold) ?>" class="field-input" style="width:200px;">
                            </div>
                            <button type="submit" class="action-btn">Save Threshold</button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert--warning" style="margin:0;"><i class="fa-solid fa-lock" style="margin-top:2px;"></i> Only an Admin can change this setting.</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($show_add || $edit_category): ?>
                <!-- ADD / EDIT CATEGORY -->
                <div class="panel" style="margin-bottom:16px;">
                    <div class="panel-header"><h2><i class="<?= $edit_category ? 'fa-regular fa-pen-to-square' : 'fa-solid fa-folder-plus' ?>" style="color:var(--text-accent); margin-right:6px;"></i><?= $edit_category ? 'Edit Category' : 'Add New Category' ?></h2></div>
                    <div class="panel-body">
                        <p class="ec-panel-subtitle">
                            <?= $edit_category ? 'Update this category\'s name, budget, or status.' : 'Categories group operating expense bills so budgets and reports stay organized (e.g. Electricity, Water, Internet, Rent).' ?>
                        </p>
                        <form method="POST" action="expense_categories.php" class="ec-form" style="max-width:560px;">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="save_category">
                            <?php if ($edit_category): ?><input type="hidden" name="category_id" value="<?= enc_id($edit_category['id']) ?>"><?php endif; ?>
                            <div class="form-grid">
                                <div class="field-group field-group--full">
                                    <label class="field-label">Category Name <span style="color:var(--red-alert)">*</span></label>
                                    <input type="text" name="name" class="field-input" value="<?= clean($edit_category['name'] ?? '') ?>" placeholder="e.g. Electricity, Water, Internet, Rent" maxlength="100" required>
                                </div>
                                <div class="field-group">
                                    <label class="field-label">Monthly Budget <span style="color:var(--ink-soft); font-weight:400;">(optional)</span></label>
                                    <input type="number" step="0.01" min="0" name="monthly_budget" class="field-input" placeholder="Leave blank for no limit" value="<?= $edit_category && $edit_category['monthly_budget'] !== null ? clean($edit_category['monthly_budget']) : '' ?>">
                                </div>
                                <?php if ($edit_category): ?>
                                    <div class="field-group">
                                        <label class="field-label">Status</label>
                                        <select name="status" class="field-input">
                                            <option value="active" <?= $edit_category['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                            <option value="inactive" <?= $edit_category['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                        </select>
                                    </div>
                                <?php else: ?>
                                    <input type="hidden" name="status" value="active">
                                <?php endif; ?>
                                <div class="field-group field-group--full">
                                    <label class="field-checkbox">
                                        <input type="checkbox" name="is_recurring" value="1" <?= !empty($edit_category['is_recurring']) ? 'checked' : '' ?>>
                                        This category is typically recurring (utilities, rent, etc.)
                                    </label>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="action-btn"><i class="fa-regular fa-<?= $edit_category ? 'floppy-disk' : 'square-plus' ?>"></i> <?= $edit_category ? 'Save Changes' : 'Add Category' ?></button>
                                <a href="expense_categories.php" class="action-btn action-btn--secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div style="margin-bottom:12px;">
                    <a href="expense_categories.php?action=add" class="action-btn"><i class="fa-solid fa-plus"></i> Add Category</a>
                </div>
            <?php endif; ?>

            <!-- LIST -->
            <div class="panel">
                <div class="panel-header"><h2>Categories (<?= count($categories) ?>)</h2></div>
                <div class="panel-body panel-body--table">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Recurring</th>
                                <th>Monthly Budget</th>
                                <th>This Month's Approved Actual</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($categories)): ?>
                                <tr><td colspan="6" class="empty-row">No expense categories yet — add one above (e.g. Electricity, Water, Internet, Rent).</td></tr>
                            <?php else: ?>
                                <?php foreach ($categories as $c): ?>
                                    <?php $actual = getExpenseCategoryMonthActual($pdo, $c['id'], $today); ?>
                                    <tr>
                                        <td style="font-weight:600"><?= clean($c['name']) ?></td>
                                        <td><?= $c['is_recurring'] ? 'Yes' : 'No' ?></td>
                                        <td><?= $c['monthly_budget'] !== null ? peso($c['monthly_budget']) : '—' ?></td>
                                        <td>
                                            <?= peso($actual) ?>
                                            <?php if ($c['monthly_budget'] !== null && $actual > (float) $c['monthly_budget'] + 0.01): ?>
                                                <span style="color:#9c3a23; font-weight:700; font-size:11.5px;">OVER BUDGET</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="status-pill status-pill--<?= $c['status'] ?>"><?= ucfirst($c['status']) ?></span></td>
                                        <td class="ec-actions-cell">
                                            <div class="ec-row-actions">
                                            <a href="expense_categories.php?action=edit&id=<?= enc_id($c['id']) ?>" class="link-btn" title="Edit" aria-label="Edit <?= clean($c['name']) ?>"><i class="fa-regular fa-pen-to-square" aria-hidden="true"></i></a>
                                            <?php if ($c['status'] === 'active'): ?>
                                                <form method="POST" action="expense_categories.php" class="inline-form"
                                                      onsubmit="return confirmAction(event, this, {icon:'warning', title:'Delete this category?', text:'It will be removed from active use so it cannot be selected for new bills. Existing bills and reports keep their history.', confirmButtonText:'Yes, delete', confirmButtonColor:'#c0392b'});">
                                                    <?php csrfField(); ?>
                                                    <input type="hidden" name="action" value="delete_category">
                                                    <input type="hidden" name="category_id" value="<?= enc_id($c['id']) ?>">
                                                    <button type="submit" class="link-btn link-btn--danger" title="Delete category" aria-label="Delete <?= clean($c['name']) ?>">
                                                        <i class="fa-regular fa-trash-can" aria-hidden="true"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            </div>
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
</script>
</body>
</html>