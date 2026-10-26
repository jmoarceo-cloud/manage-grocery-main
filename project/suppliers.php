<?php

require_once 'includes/config.php';
require_once 'includes/export.php';
require_once 'includes/phone_countries.php';
requireSupplierManager();

$success_message = $_SESSION['flash_success'] ?? '';
$error_message = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// =====================================================
// HANDLE FORM SUBMISSIONS
// =====================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    // -------------------------------------------------
    // ADD SUPPLIER
    // -------------------------------------------------
    if ($action == 'add') {

        $company_name = trim($_POST['company_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $contact_country = trim($_POST['contact_number_country'] ?? 'PH');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $phone_error = validatePhoneNumber($contact_country, $contact_number);
        $contact_number = formatPhoneForStorage($contact_country, $contact_number);
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($company_name === '') {
            $error_message = "Supplier / company name is required.";
        } elseif (mb_strlen($company_name) > 150) {
            $error_message = "Supplier / company name is too long (max 150 characters).";
        } elseif ($phone_error !== null) {
            $error_message = $phone_error;
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Please enter a valid email address.";
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO suppliers (company_name, contact_person, contact_number, email, address, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                            $company_name,
                            $contact_person ?: null,
                            $contact_number ?: null,
                            $email ?: null,
                            $address ?: null,
                            $notes ?: null,
                            currentPosUserId(),
                        ]);
                $_SESSION['flash_success'] = "Supplier \"" . $company_name . "\" was added.";
            } catch (PDOException $e) {
                error_log("Add supplier error: " . $e->getMessage());
                $error_message = "Could not add the supplier. Please try again.";
            }
            if (empty($error_message)) {
                redirect('suppliers.php');
            }
        }
    }

    // -------------------------------------------------
    // EDIT SUPPLIER
    // -------------------------------------------------
    elseif ($action == 'edit') {

        $supplier_id = dec_id($_POST['supplier_id'] ?? '');
        $company_name = trim($_POST['company_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $contact_country = trim($_POST['contact_number_country'] ?? 'PH');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $phone_error = validatePhoneNumber($contact_country, $contact_number);
        $contact_number = formatPhoneForStorage($contact_country, $contact_number);
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if (!ctype_digit((string) $supplier_id)) {
            $error_message = "Invalid supplier.";
        } elseif ($company_name === '') {
            $error_message = "Supplier / company name is required.";
        } elseif (mb_strlen($company_name) > 150) {
            $error_message = "Supplier / company name is too long (max 150 characters).";
        } elseif ($phone_error !== null) {
            $error_message = $phone_error;
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Please enter a valid email address.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE suppliers
                    SET company_name = ?, contact_person = ?, contact_number = ?, email = ?, address = ?, notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $company_name,
                    $contact_person ?: null,
                    $contact_number ?: null,
                    $email ?: null,
                    $address ?: null,
                    $notes ?: null,
                    $supplier_id,
                ]);
                $_SESSION['flash_success'] = "Supplier \"" . $company_name . "\" was updated.";
            } catch (PDOException $e) {
                error_log("Edit supplier error: " . $e->getMessage());
                $error_message = "Could not update the supplier. Please try again.";
            }
            if (empty($error_message)) {
                redirect('suppliers.php');
            }
        }
    }

    // -------------------------------------------------
    // DEACTIVATE (archive instead of delete)
    // -------------------------------------------------
    elseif ($action == 'deactivate') {
        $supplier_id = dec_id($_POST['supplier_id'] ?? '');
        if (ctype_digit((string) $supplier_id)) {
            $pdo->prepare("UPDATE suppliers SET status = 'inactive' WHERE id = ?")->execute([$supplier_id]);
            $_SESSION['flash_success'] = "Supplier was deactivated. It's hidden from active supplier pickers (e.g. Purchase Orders) but its records are kept.";
        }
        redirect('suppliers.php' . (isset($_GET['view']) ? '?view=inactive' : ''));
    }

    // -------------------------------------------------
    // REACTIVATE
    // -------------------------------------------------
    elseif ($action == 'activate') {
        $supplier_id = dec_id($_POST['supplier_id'] ?? '');
        if (ctype_digit((string) $supplier_id)) {
            $pdo->prepare("UPDATE suppliers SET status = 'active' WHERE id = ?")->execute([$supplier_id]);
            $_SESSION['flash_success'] = "Supplier was reactivated.";
        }
        redirect('suppliers.php?view=inactive');
    }
}

// =====================================================
// SHOW ADD / EDIT FORM?
// =====================================================
$show_add_form = isset($_GET['action']) && $_GET['action'] == 'add';

$edit_supplier = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([dec_id($_GET['edit'])]);
    $edit_supplier = $stmt->fetch();
}

// =====================================================
// LIST + SEARCH + ACTIVE/INACTIVE TABS
// =====================================================
$show_inactive = isset($_GET['view']) && $_GET['view'] == 'inactive';
$search = trim($_GET['search'] ?? '');

$where = ['status = ?'];
$params = [$show_inactive ? 'inactive' : 'active'];

if ($search !== '') {
    $where[] = "(company_name LIKE ? OR contact_person LIKE ? OR email LIKE ? OR contact_number LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql = "SELECT s.*,
               (SELECT COUNT(*) FROM products p WHERE p.supplier_id = s.id AND p.is_active = 1) AS product_count
        FROM suppliers s WHERE " . implode(' AND ', $where) . " ORDER BY company_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

$inactive_count = (int) $pdo->query("SELECT COUNT(*) FROM suppliers WHERE status = 'inactive'")->fetchColumn();

// Products whose typed-in supplier name doesn't match any record here —
// surfaced with a banner below so they don't stay invisible free text.
$unlinked_supplier_count = (int) $pdo->query("
    SELECT COUNT(*) FROM products
    WHERE is_active = 1 AND supplier_id IS NULL AND supplier IS NOT NULL AND supplier <> ''
")->fetchColumn();

// --- Export the (filtered) supplier list — same $where/$params as the
// list above, gated by the same requireSupplierManager() check at the
// top of this file (Admin / Inventory Staff only).
if (isset($_GET['export']) && in_array($_GET['export'], ['xlsx', 'pdf'], true)) {
    $__supHeaders = ['Company', 'Contact Person', 'Contact Number', 'Email', 'Address', 'Notes', 'Status'];
    $__supRows = [];
    foreach ($suppliers as $s) {
        $__supRows[] = [
            $s['company_name'],
            $s['contact_person'] ?: '—',
            $s['contact_number'] ?: '—',
            $s['email'] ?: '—',
            $s['address'] ?: '—',
            $s['notes'] ?: '—',
            ucfirst($s['status']),
        ];
    }
    $__supMeta = [($show_inactive ? 'Inactive Suppliers' : 'Active Suppliers'), ($search !== '' ? 'Search: ' . $search : 'All records')];

    if ($_GET['export'] === 'xlsx') {
        exportToExcel('supplier_list', $__supHeaders, $__supRows, $__supMeta);
    } else {
        exportToPDF('supplier_list', ($show_inactive ? 'Inactive Suppliers' : 'Supplier List'), $__supHeaders, $__supRows, $__supMeta);
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suppliers — HCI GroceryOne</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .view-tabs {
            display: flex;
            gap: 4px;
        }

        .view-tab {
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            color: var(--ink-soft);
            text-decoration: none;
            border: 1.5px solid transparent;
        }

        .view-tab.is-active {
            background: var(--green-pale);
            color: var(--text-accent);
            border-color: var(--green-mid);
        }

        .archived-badge {
            display: inline-flex;
            align-items: center;
            font-size: 11px;
            font-weight: 700;
            background: var(--cream);
            color: var(--ink-soft);
            border-radius: 999px;
            padding: 2px 8px;
            margin-left: 6px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            font-size: 12px;
            font-weight: 700;
            border-radius: 999px;
            padding: 3px 10px;
        }

        .status-badge--active {
            background: var(--green-pale);
            color: var(--text-accent);
        }

        .status-badge--inactive {
            background: var(--cream);
            color: var(--ink-soft);
        }

        .supplier-notes {
            max-width: 220px;
            white-space: normal;
            color: var(--ink-soft);
            font-size: 12.5px;
        }
    </style>
</head>

<body>
    <div class="app-shell">

        <!-- SIDEBAR -->
        <?php $active = 'suppliers';
        include 'includes/sidebar.php'; ?>

        <!-- MAIN -->
        <div class="main-area">

            <header class="topbar">
                <div class="topbar-icon"><?= $__icon_suppliers ?></div>
                <h1>Suppliers</h1>
                <div class="topbar-user">
                    <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img
                                src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>"
                                alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'A') ?><?php endif; ?></span>
                    <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'User') ?></span>
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

                <!-- VIEW TABS (Active / Inactive) -->
                <div class="view-tabs">
                    <a href="suppliers.php" class="view-tab <?= !$show_inactive ? 'is-active' : '' ?>">Active
                        Suppliers</a>
                    <a href="suppliers.php?view=inactive" class="view-tab <?= $show_inactive ? 'is-active' : '' ?>">
                        Inactive
                        <?php if ($inactive_count > 0): ?>
                            <span class="archived-badge"><?= $inactive_count ?></span>
                        <?php endif; ?>
                    </a>
                </div>

                <!-- SEARCH + ADD BUTTON -->
                <div class="toolbar">
                    <form method="GET" action="suppliers.php" class="search-form" id="supplierFilterForm">
                        <?php if ($show_inactive): ?>
                            <input type="hidden" name="view" value="inactive">
                        <?php endif; ?>
                        <input type="text" name="search" id="supplierSearchInput" placeholder="Search suppliers..."
                            value="<?= clean($search) ?>" class="field-input" style="width:240px;" autocomplete="off">
                        <button type="submit" class="action-btn action-btn--small">Search</button>
                        <?php if ($search !== ''): ?>
                            <a href="suppliers.php<?= $show_inactive ? '?view=inactive' : '' ?>"
                                class="action-btn action-btn--small action-btn--secondary">Clear</a>
                        <?php endif; ?>
                    </form>
                    <?php if (!$show_inactive): ?>
                        <a href="suppliers.php?action=add" class="action-btn">+ Add Supplier</a>
                    <?php endif; ?>
                </div>

                <?php if (!$show_inactive && $unlinked_supplier_count > 0): ?>
                    <div class="alert alert--warning" style="margin-bottom:16px;">
                        ⚠ <?= $unlinked_supplier_count ?> product<?= $unlinked_supplier_count === 1 ? '' : 's' ?>
                        <?= $unlinked_supplier_count === 1 ? 'has' : 'have' ?> a supplier name that doesn't match any record
                        here.
                        <a href="products.php?supplier_id=unlinked">Review and link them in Products</a>.
                    </div>
                <?php endif; ?>

                <!-- ADD FORM -->
                <?php if ($show_add_form): ?>
                    <div class="panel">
                        <div class="panel-header">
                            <h2>Add New Supplier</h2>
                        </div>
                        <div class="panel-body">
                            <form method="POST" action="suppliers.php?action=add">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="add">
                                <div class="form-grid">

                                    <div class="field-group">
                                        <label class="field-label">Supplier / Company Name <span
                                                style="color:var(--red-alert)">*</span></label>
                                        <input type="text" name="company_name" class="field-input"
                                            placeholder="e.g. San Miguel Corp." maxlength="150" required>
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Contact Person</label>
                                        <input type="text" name="contact_person" class="field-input"
                                            placeholder="e.g. Juan Dela Cruz" maxlength="150">
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Contact Number</label>
                                        <?php renderPhoneFieldInputs('contact_number', null, false, 'field-input'); ?>
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Email Address</label>
                                        <input type="email" name="email" class="field-input"
                                            placeholder="e.g. sales@supplier.com" maxlength="150">
                                    </div>

                                    <div class="field-group field-group--full">
                                        <label class="field-label">Business Address</label>
                                        <input type="text" name="address" class="field-input"
                                            placeholder="Complete business address" maxlength="255">
                                    </div>

                                    <div class="field-group field-group--full">
                                        <label class="field-label">Notes <span
                                                style="color:var(--ink-soft); font-weight:400">(optional)</span></label>
                                        <textarea name="notes" class="field-input" rows="2"
                                            placeholder="Any other relevant notes about this supplier"></textarea>
                                    </div>

                                </div>

                                <div style="display:flex; gap:10px; margin-top:18px;">
                                    <button type="submit" class="action-btn">Save Supplier</button>
                                    <a href="suppliers.php" class="action-btn action-btn--secondary">Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- EDIT FORM -->
                <?php if ($edit_supplier): ?>
                    <div class="panel">
                        <div class="panel-header">
                            <h2>Edit Supplier</h2>
                        </div>
                        <div class="panel-body">
                            <form method="POST" action="suppliers.php?edit=<?= enc_id($edit_supplier['id']) ?>">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="supplier_id" value="<?= enc_id($edit_supplier['id']) ?>">
                                <div class="form-grid">

                                    <div class="field-group">
                                        <label class="field-label">Supplier / Company Name <span
                                                style="color:var(--red-alert)">*</span></label>
                                        <input type="text" name="company_name" class="field-input"
                                            value="<?= clean($edit_supplier['company_name']) ?>" maxlength="150" required>
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Contact Person</label>
                                        <input type="text" name="contact_person" class="field-input"
                                            value="<?= clean($edit_supplier['contact_person'] ?? '') ?>" maxlength="150">
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Contact Number</label>
                                        <?php renderPhoneFieldInputs('contact_number', $edit_supplier['contact_number'] ?? null, false, 'field-input'); ?>
                                    </div>

                                    <div class="field-group">
                                        <label class="field-label">Email Address</label>
                                        <input type="email" name="email" class="field-input"
                                            value="<?= clean($edit_supplier['email'] ?? '') ?>" maxlength="150">
                                    </div>

                                    <div class="field-group field-group--full">
                                        <label class="field-label">Business Address</label>
                                        <input type="text" name="address" class="field-input"
                                            value="<?= clean($edit_supplier['address'] ?? '') ?>" maxlength="255">
                                    </div>

                                    <div class="field-group field-group--full">
                                        <label class="field-label">Notes <span
                                                style="color:var(--ink-soft); font-weight:400">(optional)</span></label>
                                        <textarea name="notes" class="field-input"
                                            rows="2"><?= clean($edit_supplier['notes'] ?? '') ?></textarea>
                                    </div>

                                </div>

                                <div style="display:flex; gap:10px; margin-top:18px;">
                                    <button type="submit" class="action-btn">Save Changes</button>
                                    <a href="suppliers.php" class="action-btn action-btn--secondary">Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- SUPPLIER TABLE -->
                <div class="panel">
                    <div class="panel-header"
                        style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                        <h2><?= $show_inactive ? 'Inactive Suppliers' : 'All Suppliers' ?> (<?= count($suppliers) ?>)
                        </h2>
                        <?php $__supExportQs = ($show_inactive ? 'view=inactive&' : '') . 'search=' . urlencode($search); ?>
                        <div style="display:flex; gap:8px;">
                            <?= exportPostForm('suppliers.php?' . $__supExportQs . '&export=xlsx', 'Export Excel', 'action-btn action-btn--small action-btn--outline') ?>
                            <?= exportPostForm('suppliers.php?' . $__supExportQs . '&export=pdf', 'Export PDF', 'action-btn action-btn--small action-btn--outline') ?>
                        </div>
                    </div>
                    <div class="panel-body panel-body--table">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Supplier / Company</th>
                                    <th>Contact Person</th>
                                    <th>Contact Number</th>
                                    <th>Email</th>
                                    <th>Address</th>
                                    <th>Notes</th>
                                    <th>Products</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($suppliers)): ?>
                                    <tr>
                                        <td colspan="9" class="empty-row">
                                            <?= $show_inactive
                                                ? 'No inactive suppliers.'
                                                : 'No suppliers yet. Click "+ Add Supplier" to get started.' ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <tr>
                                            <td style="font-weight:600"><?= clean($supplier['company_name']) ?></td>
                                            <td><?= !empty($supplier['contact_person']) ? clean($supplier['contact_person']) : '<span style="color:var(--ink-soft);font-size:12px">—</span>' ?>
                                            </td>
                                            <td><?= !empty($supplier['contact_number']) ? clean($supplier['contact_number']) : '<span style="color:var(--ink-soft);font-size:12px">—</span>' ?>
                                            </td>
                                            <td><?= !empty($supplier['email']) ? clean($supplier['email']) : '<span style="color:var(--ink-soft);font-size:12px">—</span>' ?>
                                            </td>
                                            <td><?= !empty($supplier['address']) ? clean($supplier['address']) : '<span style="color:var(--ink-soft);font-size:12px">—</span>' ?>
                                            </td>
                                            <td class="supplier-notes">
                                                <?= !empty($supplier['notes']) ? nl2br(clean($supplier['notes'])) : '<span style="color:var(--ink-soft);font-size:12px">—</span>' ?>
                                            </td>
                                            <td>
                                                <?php if ((int) $supplier['product_count'] > 0): ?>
                                                    <a href="products.php?supplier_id=<?= enc_id($supplier['id']) ?>"><?= (int) $supplier['product_count'] ?>
                                                        product<?= (int) $supplier['product_count'] === 1 ? '' : 's' ?></a>
                                                <?php else: ?>
                                                    <span style="color:var(--ink-soft);font-size:12px">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span
                                                    class="status-badge status-badge--<?= $supplier['status'] ?>"><?= ucfirst($supplier['status']) ?></span>
                                            </td>
                                            <td class="actions-cell">
                                                <div class="actions-wrap">
                                                    <?php if ($supplier['status'] === 'active'): ?>
                                                        <a href="suppliers.php?edit=<?= enc_id($supplier['id']) ?>" class="link-btn"
                                                            title="Edit" aria-label="Edit"><i
                                                                class="fa-regular fa-pen-to-square"></i></a>
                                                        <form method="POST" action="suppliers.php"
                                                            class="inline-form js-confirm-form" data-confirm="<?= htmlspecialchars(json_encode([
                                                                'icon' => 'warning',
                                                                'title' => 'Deactivate Supplier?',
                                                                'text' => 'Deactivate ' . $supplier['company_name'] . '? It will be hidden from active supplier pickers (e.g. new Purchase Orders), but its records are kept and can be reactivated anytime.',
                                                                'confirmButtonText' => 'Yes, deactivate',
                                                            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                                                            <?php csrfField(); ?>
                                                            <input type="hidden" name="action" value="deactivate">
                                                            <input type="hidden" name="supplier_id"
                                                                value="<?= enc_id($supplier['id']) ?>">
                                                            <button type="submit" class="link-btn link-btn--danger"
                                                                title="Deactivate" aria-label="Deactivate"><i
                                                                    class="fa-regular fa-circle-xmark"></i></button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="POST" action="suppliers.php?view=inactive"
                                                            class="inline-form js-confirm-form" data-confirm="<?= htmlspecialchars(json_encode([
                                                                'icon' => 'question',
                                                                'title' => 'Reactivate Supplier?',
                                                                'text' => 'Reactivate ' . $supplier['company_name'] . '? It will appear in Active Suppliers again.',
                                                                'confirmButtonText' => 'Yes, reactivate',
                                                            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                                                            <?php csrfField(); ?>
                                                            <input type="hidden" name="action" value="activate">
                                                            <input type="hidden" name="supplier_id"
                                                                value="<?= enc_id($supplier['id']) ?>">
                                                            <button type="submit" class="link-btn" title="Reactivate"
                                                                aria-label="Reactivate"><i
                                                                    class="fa-solid fa-rotate-left"></i></button>
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
        // Deactivate/Reactivate confirmations — config comes from each form's
        // data-confirm attribute (JSON) instead of being built into an inline
        // onsubmit string, so a supplier name with a quote/apostrophe in it can
        // never break the popup or corrupt the button.
        document.querySelectorAll('.js-confirm-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var opts;
                try {
                    opts = JSON.parse(form.dataset.confirm || '{}');
                } catch (err) {
                    form.submit();
                    return;
                }
                Swal.fire(Object.assign({
                    showCancelButton: true,
                    cancelButtonText: 'Cancel',
                    reverseButtons: true,
                    focusCancel: true,
                    buttonsStyling: false,
                    customClass: {
                        confirmButton: 'action-btn' + (opts.icon === 'warning' ? ' action-btn--danger' : ''),
                        cancelButton: 'action-btn action-btn--secondary'
                    }
                }, opts)).then(function (result) {
                    if (result.isConfirmed) form.submit();
                });
            });
        });
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

    <script>
        // Live search: auto-submits the search form a moment after the user
        // stops typing, so matching suppliers show up right away.
        (function () {
            var input = document.getElementById('supplierSearchInput');
            if (!input) return;
            var timer = null;
            input.addEventListener('input', function () {
                if (timer) clearTimeout(timer);
                timer = setTimeout(function () { input.form.submit(); }, 450);
            });
        })();
    </script>
    <?php renderPhoneFieldScript(); ?>
</body>

</html>