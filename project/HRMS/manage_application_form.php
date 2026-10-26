<?php
require_once 'includes/config.php';
require_once 'includes/application_form.php';
requireHRManagerRole(); // HR Manager + Admin only (HR Staff is not included on this page)

$success_message = "";
$error_message   = "";

// "View public form" preview — opened as a popup (iframe modal) instead of
// navigating to apply.php in a new tab/page, since this is just a preview
// for HR/employees who manage the form; actual applicants still go through
// apply.php's normal full-page flow from index.php.
$show_preview = isset($_GET['preview']);

$ALLOWED_TYPES = ['text', 'textarea', 'tel', 'number', 'email', 'date', 'select', 'checkbox'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    requireCSRF();
    $action = $_POST['action'] ?? '';

    // -------------------------------------------------
    // ADD NEW FIELD
    // -------------------------------------------------
    if ($action == 'add_field') {

        $label       = trim($_POST['label'] ?? '');
        $field_type  = $_POST['field_type'] ?? 'text';
        $options     = trim($_POST['options'] ?? '');
        $placeholder = trim($_POST['placeholder'] ?? '');
        $is_required = isset($_POST['is_required']) ? 1 : 0;

        if ($label === '') {
            $error_message = "label is required for the new field.";
        } elseif (!in_array($field_type, $ALLOWED_TYPES)) {
            $error_message = "Invalid field type.";
        } elseif (in_array($field_type, ['select', 'checkbox']) && $options === '') {
            $error_message = "Add choices (comma-separated) for the dropdown/checkbox field.";
        } else {
            try {
                $field_key = generateFieldKey($pdo, $label);
                $maxOrder  = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM application_form_fields")->fetchColumn();

                $stmt = $pdo->prepare("
                    INSERT INTO application_form_fields
                    (field_key, label, field_type, options, placeholder, is_required, sort_order, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([
                    $field_key,
                    $label,
                    $field_type,
                    in_array($field_type, ['select', 'checkbox']) ? $options : null,
                    $placeholder ?: null,
                    $is_required,
                    $maxOrder + 1,
                ]);

                $success_message = "The field was added \"$label\".";
            } catch (PDOException $e) {
                error_log("Add form field error: " . $e->getMessage());
                $error_message = "There was a problem adding the field. Please try again.";
            }
        }
    }

    // -------------------------------------------------
    // EDIT FIELD (label, type, options, placeholder, required)
    // field_key is NOT changed anymore so as not to break the
    // mapping for already-saved applications.
    // -------------------------------------------------
    elseif ($action == 'edit_field') {

        $field_id    = $_POST['field_id'] ?? 0;
        $label       = trim($_POST['label'] ?? '');
        $field_type  = $_POST['field_type'] ?? 'text';
        $options     = trim($_POST['options'] ?? '');
        $placeholder = trim($_POST['placeholder'] ?? '');
        $is_required = isset($_POST['is_required']) ? 1 : 0;

        if ($label === '') {
            $error_message = "The field requires a label.";
        } elseif (!in_array($field_type, $ALLOWED_TYPES)) {
            $error_message = "Invalid field type.";
        } elseif (in_array($field_type, ['select', 'checkbox']) && $options === '') {
            $error_message = "Add choices (comma-separated) for the dropdown/checkbox field.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE application_form_fields
                    SET label = ?, field_type = ?, options = ?, placeholder = ?, is_required = ?
                    WHERE field_id = ?
                ");
                $stmt->execute([
                    $label,
                    $field_type,
                    in_array($field_type, ['select', 'checkbox']) ? $options : null,
                    $placeholder ?: null,
                    $is_required,
                    $field_id,
                ]);
                $success_message = "The field has been updated. \"$label\".";
            } catch (PDOException $e) {
                error_log("Edit form field error: " . $e->getMessage());
                $error_message = "There was a problem updating the field. Please try again.";
            }
        }
    }

    // -------------------------------------------------
    // TOGGLE ACTIVE / INACTIVE (hide from public form but
    // don't delete the field definition)
    // -------------------------------------------------
    elseif ($action == 'toggle_active') {
        $field_id = $_POST['field_id'] ?? 0;
        try {
            $pdo->prepare("UPDATE application_form_fields SET is_active = NOT is_active WHERE field_id = ?")
                ->execute([$field_id]);
            $success_message = "The field status has been updated.";
        } catch (PDOException $e) {
            error_log("Toggle field error: " . $e->getMessage());
            $error_message = "Something went wrong. Please try again.";
        }
    }

    // -------------------------------------------------
    // DELETE FIELD
    // -------------------------------------------------
    elseif ($action == 'delete_field') {
        $field_id = $_POST['field_id'] ?? 0;
        try {
            $pdo->prepare("DELETE FROM application_form_fields WHERE field_id = ?")->execute([$field_id]);
            $success_message = "The field has been deleted. (Existing responses from applicants will not be affected.)";
        } catch (PDOException $e) {
            error_log("Delete field error: " . $e->getMessage());
            $error_message = "There was a problem deleting the field. Please try again.";
        }
    }

    // -------------------------------------------------
    // REORDER (move up / down) — swaps the sort_order
    // of the field with its neighbor
    // -------------------------------------------------
    elseif ($action == 'move_field') {
        $field_id  = $_POST['field_id'] ?? 0;
        $direction = $_POST['direction'] ?? '';

        try {
            $all = $pdo->query("SELECT field_id, sort_order FROM application_form_fields ORDER BY sort_order ASC, field_id ASC")->fetchAll();
            $ids = array_column($all, 'field_id');
            $pos = array_search((int) $field_id, $ids);

            if ($pos !== false) {
                $swapPos = $direction === 'up' ? $pos - 1 : $pos + 1;
                if ($swapPos >= 0 && $swapPos < count($ids)) {
                    $a = $all[$pos];
                    $b = $all[$swapPos];
                    $pdo->prepare("UPDATE application_form_fields SET sort_order = ? WHERE field_id = ?")->execute([$b['sort_order'], $a['field_id']]);
                    $pdo->prepare("UPDATE application_form_fields SET sort_order = ? WHERE field_id = ?")->execute([$a['sort_order'], $b['field_id']]);
                }
            }
        } catch (PDOException $e) {
            error_log("Reorder field error: " . $e->getMessage());
            $error_message = "There was a problem reordering the fields. Please try again";
        }
    }
}

$fields    = getAllFormFields($pdo);
$edit_id   = dec_id($_GET['edit'] ?? '');
$edit_row  = null;
if ($edit_id) {
    foreach ($fields as $f) {
        if ((int) $f['field_id'] === $edit_id) { $edit_row = $f; break; }
    }
}

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Application Form — HRMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --green-deep: #123C5C; --text-accent: var(--green-deep); --green-mid: #1978A8; --green-leaf: #4FC3D1; --green-pale: #E3F2F6;
    --mustard: #E0A23B; --mustard-pale: #FBEFD8;
    --red-alert: #C8472D; --red-pale: #FBE7E2;
    --cream: #F3F6F9; --white: #FFFFFF; --ink: #17222E; --ink-soft: #3D4C5C; --line: #DCE5EC;
    --font-display: 'Sora', sans-serif; --font-body: 'Inter', sans-serif;
}
html, body { min-height: 100vh; font-family: var(--font-body); color: var(--ink); background: var(--cream); }
a { text-decoration: none; color: inherit; }

/* ── Sidebar (same look/size as the POS "My Profile" sidebar) ── */
.app-shell { display: flex; min-height: 100vh; }
.sidebar {
    width: 288px; flex-shrink: 0;
    background: radial-gradient(420px 260px at 100% 0%, rgba(79,195,209,.16), transparent 60%), var(--green-deep);
    color: #FFFFFF;
    display: flex; flex-direction: column; padding: 30px 24px;
    position: fixed; top: 0; left: 0; height: 100vh; overflow-y: auto;
    z-index: 100;
    box-shadow: 2px 0 12px rgba(0,0,0,0.18);
}
.sidebar-brand { display: flex; align-items: center; gap: 14px; padding: 0 4px 26px; margin-bottom: 22px; border-bottom: 1px solid rgba(255,255,255,0.14); }
.brand-mark { width: 46px; height: 46px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: var(--white); border-radius: 13px; box-shadow: 0 4px 10px rgba(0,0,0,.14); }
.brand-mark .brand-icon, .brand-icon { width: 28px; height: 28px; }
.brand-text { display: flex; flex-direction: column; line-height: 1.35; }
.brand-name { font-family: var(--font-display); font-weight: 700; font-size: 17px; }
.brand-tagline { font-size: 11px; color: var(--green-leaf); font-weight: 600; letter-spacing: .02em; }
.nav-section-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: rgba(255,255,255,.42); padding: 0 14px; margin: 4px 0 10px; }
.sidebar-nav { display: flex; flex-direction: column; gap: 5px; }
.nav-link { display: flex; align-items: center; gap: 13px; padding: 13px 15px; border-radius: 12px; color: rgba(255,255,255,0.82); font-size: 14.5px; font-weight: 600; transition: background 0.15s ease, color 0.15s ease; }
.nav-icon-box { width: 30px; height: 30px; border-radius: 9px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,.08); }
.nav-link.is-active .nav-icon-box { background: rgba(255,255,255,.35); }
.nav-icon { width: 17px; height: 17px; flex-shrink: 0; }
.nav-link:hover { background: rgba(255,255,255,0.1); color: #FFFFFF; }
.nav-link.is-active { background: var(--green-leaf); color: var(--green-deep); box-shadow: 0 4px 12px rgba(0,0,0,.16); }
.nav-link--logout { margin-top: 16px; border-top: 1px solid rgba(255,255,255,0.14); padding-top: 18px; color: rgba(255,255,255,0.65); }
.nav-link--logout:hover { background: rgba(200,71,45,0.18); color: var(--salmon-bg, #ffb3a3); }
.sidebar-foot { margin-top: auto; padding: 14px; border-radius: 14px; background: rgba(255,255,255,.06); display: flex; align-items: center; gap: 12px; }
.sidebar-foot .foot-avatar { width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0; background: linear-gradient(150deg, var(--green-leaf), #2E9BAE); color: var(--green-deep); display: flex; align-items: center; justify-content: center; font-family: var(--font-display); font-weight: 700; font-size: 15px; overflow: hidden; }
.sidebar-foot .foot-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.sidebar-foot .foot-text { display: flex; flex-direction: column; line-height: 1.3; min-width: 0; }
.sidebar-foot .foot-name { font-size: 13.5px; font-weight: 700; color: #FFFFFF; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sidebar-foot .foot-role { font-size: 11.5px; color: rgba(255,255,255,0.6); font-weight: 600; }
.main-area { margin-left: 288px; flex: 1; min-width: 0; display: flex; flex-direction: column; position: relative; z-index: 1; }
@media (max-width: 900px) {
    .sidebar { width: 76px; padding: 24px 12px; }
    .brand-text, .nav-link span, .sidebar-foot .foot-text { display: none; }
    .sidebar-brand { justify-content: center; padding-bottom: 18px; }
    .nav-link { justify-content: center; padding: 12px; gap: 0; }
    .sidebar-foot { justify-content: center; padding: 10px; }
    .main-area { margin-left: 76px; }
}

.topbar {
    display: flex; align-items: center; justify-content: space-between;
    background: var(--white); border-bottom: 1px solid var(--line);
    padding: 16px 28px;
}
.topbar h1 { font-family: var(--font-display); font-size: 19px; color: var(--text-accent); }
.topbar .user-info { display: flex; align-items: center; gap: 16px; font-size: 13.5px; color: var(--ink-soft); }
.user-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--mustard); color: #FFFFFF; display: flex; align-items: center; justify-content: center; font-family: var(--font-display); font-weight: 700; font-size: 13px; flex-shrink: 0; overflow: hidden; }
.user-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.topbar .user-info a { font-weight: 600; color: var(--green-mid); }
.topbar .user-info a.logout { color: var(--red-alert); }

.container { max-width: 920px; margin: 0 auto; padding: 32px 20px 60px; }

.alert { padding: 12px 16px; border-radius: 12px; font-size: 14px; font-weight: 500; margin-bottom: 20px; }
.alert--success { background: var(--green-succ-bg, #e6f4ea); color: var(--green-succ-text, #1a7a3b); }
.alert--error { background: var(--red-pale); color: var(--red-alert); }

.note-banner {
    background: var(--mustard-pale); color: var(--mustard-text2, #97661F);
    border-radius: 12px; padding: 14px 18px; font-size: 13.5px; margin-bottom: 24px; line-height: 1.5;
}

.card { background: var(--white); border: 1px solid var(--line); border-radius: 18px; padding: 28px; margin-bottom: 24px; }
.card h2 { font-family: var(--font-display); font-size: 18px; color: var(--text-accent); margin-bottom: 4px; }
.card .sub { font-size: 13px; color: var(--ink-soft); margin-bottom: 20px; }

.field-row {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 0; border-bottom: 1px solid var(--line);
}
.field-row:last-child { border-bottom: none; }
.field-order { display: flex; flex-direction: column; gap: 2px; }
.field-order button {
    width: 24px; height: 20px; border: 1px solid var(--line); background: var(--cream);
    border-radius: 5px; cursor: pointer; font-size: 11px; color: var(--ink-soft);
}
.field-order button:hover { background: var(--green-pale); }
.field-info { flex: 1; min-width: 0; }
.field-info .flabel { font-weight: 700; font-size: 14.5px; }
.field-info .fmeta { font-size: 12px; color: var(--ink-soft); margin-top: 2px; }
.tag { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; margin-right: 6px; }
.tag--required { background: var(--mustard-pale); color: var(--mustard-text2, #97661F); }
.tag--inactive { background: var(--gray-bg, #EFEFEF); color: #8A8A8A; }
.field-actions { display: flex; gap: 6px; flex-shrink: 0; }
.btn {
    padding: 8px 14px; border: none; border-radius: 8px; font-family: var(--font-body);
    font-size: 12.5px; font-weight: 600; cursor: pointer;
}
.btn-primary { background: linear-gradient(135deg, var(--green-mid), var(--green-deep)); color: #FFFFFF; box-shadow: 0 2px 8px rgba(18,60,92,0.22); }
.btn-primary:hover { filter: brightness(1.08); box-shadow: 0 5px 16px rgba(18,60,92,0.32); transform: translateY(-2px); }
.btn-primary:active { transform: translateY(0); filter: brightness(0.97); }
.btn-secondary { background: var(--cream); color: var(--ink); border: 1px solid var(--line); }
.btn-secondary:hover { background: var(--gray-bg, #EFEDE4); }
.btn-danger { background: var(--red-pale); color: var(--red-alert); }
.btn-danger:hover { background: var(--salmon-bg, #f5cfc6); }
.btn-block { width: 100%; padding: 12px; font-size: 14px; margin-top: 6px; }

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-grid .full { grid-column: 1 / -1; }
.field-group label { display: block; font-size: 12.5px; font-weight: 600; margin-bottom: 6px; }
.field-group input[type=text], .field-group textarea, .field-group select {
    width: 100%; padding: 10px 12px; border: 1.5px solid var(--line); border-radius: 10px;
    font-family: var(--font-body); font-size: 13.5px; background: var(--cream);
}
.field-group textarea { resize: vertical; min-height: 60px; }
.field-group .hint { font-size: 11.5px; color: var(--ink-soft); margin-top: 4px; }
.checkbox-inline { display: flex; align-items: center; gap: 8px; font-size: 13.5px; font-weight: 600; margin-top: 4px; }

.empty-state { text-align: center; padding: 30px; color: var(--ink-soft); font-size: 13.5px; }

.modal-overlay {
    position: fixed; inset: 0; background: rgba(18,60,92,.25);
    display: flex; align-items: center; justify-content: center;
    padding: 24px; z-index: 100;
}
.modal-card {
    background: var(--white); border-radius: 20px; padding: 0;
    max-width: 960px; width: 100%; max-height: 88vh; overflow: hidden;
    display: flex; flex-direction: column;
}
.modal-card-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 24px; border-bottom: 1.5px solid var(--line); flex-shrink: 0;
}
.modal-card-head h2 { font-family: var(--font-display); color: var(--text-accent); font-size: 18px; }

@media (max-width: 640px) {
    .form-grid { grid-template-columns: 1fr; }
    .field-row { flex-wrap: wrap; }
}
</style>
</head>
<body>

<?php $__showHrSidebar = in_array($_SESSION['role'] ?? '', ['hr', 'hr_manager', 'admin'], true); ?>
<?php if ($__showHrSidebar): ?>
<div class="app-shell">
<?php $activeNav = 'application_form'; include 'includes/hr_sidebar.php'; ?>
<div class="main-area">
<?php endif; ?>

<div class="topbar">
    <h1>Manage Application Form</h1>
    <div class="user-info">
        <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span>
        <span><?= clean($_SESSION['full_name']) ?></span>
        <a href="?preview=1">View public form</a>
    </div>
</div>

<div class="container">

    <?php if (!empty($success_message)): ?>
        <div class="alert alert--success"><?= clean($success_message) ?></div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="alert alert--error"><?= clean($error_message) ?></div>
    <?php endif; ?>

    <div class="note-banner">
        ℹ️ The <strong>First Name, Last Name, Email Address,</strong> and <strong>Resume</strong> fields are always included in the application form because the system requires them to contact applicants and process their applications. Only the fields below can be edited, reordered, disabled, or deleted.
    </div>

    <!-- ============================================= -->
    <!-- CURRENT FIELDS LIST                            -->
    <!-- ============================================= -->
    <div class="card">
        <h2>Custom Fields</h2>
        <p class="sub">These are the fields that applicants will see below the Resume upload section, in the order shown below.</p>

        <?php if (empty($fields)): ?>
            <div class="empty-state">There are no custom fields yet. Add one using the form below.</div>
        <?php else: ?>
            <?php foreach ($fields as $i => $f): ?>
            <div class="field-row">
                <div class="field-order">
                    <form method="POST"><?php csrfField(); ?>
                        <input type="hidden" name="action" value="move_field">
                        <input type="hidden" name="field_id" value="<?= $f['field_id'] ?>">
                        <input type="hidden" name="direction" value="up">
                        <button type="submit" <?= $i === 0 ? 'disabled' : '' ?>>▲</button>
                    </form>
                    <form method="POST"><?php csrfField(); ?>
                        <input type="hidden" name="action" value="move_field">
                        <input type="hidden" name="field_id" value="<?= $f['field_id'] ?>">
                        <input type="hidden" name="direction" value="down">
                        <button type="submit" <?= $i === count($fields) - 1 ? 'disabled' : '' ?>>▼</button>
                    </form>
                </div>
                <div class="field-info">
                    <div class="flabel">
                        <?= clean($f['label']) ?>
                        <?php if ($f['is_required']): ?><span class="tag tag--required">Required</span><?php endif; ?>
                        <?php if (!$f['is_active']): ?><span class="tag tag--inactive">Inactive</span><?php endif; ?>
                    </div>
                    <div class="fmeta">
                        Type: <?= clean(ucfirst($f['field_type'])) ?>
                        <?php if (!empty($f['options'])): ?> · Choices: <?= clean($f['options']) ?><?php endif; ?>
                    </div>
                </div>
                <div class="field-actions">
                    <a href="?edit=<?= enc_id($f['field_id']) ?>" class="btn btn-secondary">Edit</a>
                    <form method="POST" style="display:inline;">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="field_id" value="<?= $f['field_id'] ?>">
                        <button type="submit" class="btn btn-secondary"><?= $f['is_active'] ? 'Turn off' : 'Turn on' ?></button>
                    </form>
                    <form method="POST" style="display:inline;" data-confirm="The field will be deleted. &quot;<?= clean($f['label']) ?>&quot;. Existing responses will not be affected." data-confirm-title="Delete Field" data-confirm-danger="1" data-confirm-ok="Yes, delete">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="delete_field">
                        <input type="hidden" name="field_id" value="<?= $f['field_id'] ?>">
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ============================================= -->
    <!-- EDIT FIELD FORM (if Edit was clicked)          -->
    <!-- ============================================= -->
    <?php if ($edit_row): ?>
    <div class="card">
        <h2>Edit: <?= clean($edit_row['label']) ?></h2>
        <p class="sub">Field key: <code><?= clean($edit_row['field_key']) ?></code> (this cannot be changed anymore)</p>
        <form method="POST">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="edit_field">
            <input type="hidden" name="field_id" value="<?= $edit_row['field_id'] ?>">
            <div class="form-grid">
                <div class="field-group full">
                    <label>Label *</label>
                    <input type="text" name="label" value="<?= clean($edit_row['label']) ?>" required>
                </div>
                <div class="field-group">
                    <label>Field Type *</label>
                    <select name="field_type" id="edit_field_type">
                        <?php foreach ($ALLOWED_TYPES as $t): ?>
                        <option value="<?= $t ?>" <?= $edit_row['field_type'] === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label><input type="checkbox" name="is_required" <?= $edit_row['is_required'] ? 'checked' : '' ?>> Required field</label>
                </div>
                <div class="field-group full">
                    <label>Placeholder (optional)</label>
                    <input type="text" name="placeholder" value="<?= clean($edit_row['placeholder'] ?? '') ?>">
                </div>
                <div class="field-group full" id="edit_options_group">
                    <label>Choices</label>
                    <input type="text" name="options" value="<?= clean($edit_row['options'] ?? '') ?>" placeholder="e.g. Full-time, Part-time, Internship">
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Save</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- ============================================= -->
    <!-- ADD NEW FIELD                                  -->
    <!-- ============================================= -->
    <div class="card">
        <h2>Add New Field</h2>
        <p class="sub">Example: Expected Salary, Available Start Date, LinkedIn Profile, Work Setup Preference.</p>
        <form method="POST">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="add_field">
            <div class="form-grid">
                <div class="field-group full">
                    <label>Label *</label>
                    <input type="text" name="label" placeholder="e.g. Expected Salary" required>
                </div>
                <div class="field-group">
                    <label>Field Type *</label>
                    <select name="field_type" id="add_field_type">
                        <?php foreach ($ALLOWED_TYPES as $t): ?>
                        <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label class="checkbox-inline"><input type="checkbox" name="is_required"> Required field</label>
                </div>
                <div class="field-group full">
                    <label>Placeholder (optional)</label>
                    <input type="text" name="placeholder" placeholder="e.g. 15000">
                </div>
                <div class="field-group full" id="add_options_group">
                    <label>Choices</label>
                    <input type="text" name="options" placeholder="e.g. Full-time, Part-time, Internship">
                    <div class="hint">Required only if Dropdown (select) or Checkbox is the selected type.</div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Add Field</button>
        </form>
    </div>

</div>

<?php if ($show_preview): ?>
<div class="modal-overlay">
    <div class="modal-card">
        <div class="modal-card-head">
            <h2>Public Form Preview</h2>
            <a href="manage_application_form.php" class="btn btn-secondary">Close</a>
        </div>
        <iframe
            src="apply.php?embed=1"
            style="width:100%; height:74vh; border:0; display:block;"
        ></iframe>
    </div>
</div>
<?php endif; ?>

<?php if ($__showHrSidebar): ?>
</div>
</div>
<?php endif; ?>

<script>
function toggleOptionsVisibility(selectEl, groupEl) {
    if (!selectEl || !groupEl) return;
    function update() {
        const needsOptions = selectEl.value === 'select' || selectEl.value === 'checkbox';
        groupEl.style.display = needsOptions ? '' : 'none';
    }
    selectEl.addEventListener('change', update);
    update();
}
toggleOptionsVisibility(document.getElementById('add_field_type'), document.getElementById('add_options_group'));
toggleOptionsVisibility(document.getElementById('edit_field_type'), document.getElementById('edit_options_group'));
</script>
<?php include 'includes/sweetalert.php'; ?>

</body>
</html>