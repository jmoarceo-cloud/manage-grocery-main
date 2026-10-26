<?php

require_once 'includes/config.php';
requireVendorRatingAccess(); // Admin, Finance: full. Inventory Staff: can add, cannot edit (Section 2 matrix).

$__role     = $_SESSION['role'] ?? '';
$__userId   = currentPosUserId();
$__userName = trim($_SESSION['full_name'] ?? 'User');
$__isEditor = in_array($__role, ['admin', 'finance'], true); // can edit any rating
$__isAdmin  = ($__role === 'admin');                          // can delete a mistaken entry

$success_message = $_SESSION['flash_success'] ?? '';
$error_message   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// =====================================================
// HANDLE FORM SUBMISSIONS
// =====================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    if ($action == 'add') {
        $result = addVendorRating($pdo, $_POST, $__userId, $__userName);
        if ($result['ok']) {
            $_SESSION['flash_success'] = "Supplier rating saved.";
            redirect('vendor_ratings.php');
        } else {
            $error_message = $result['message'];
        }
    }

    elseif ($action == 'edit') {
        // Server-side re-check, not just a hidden Edit button — Inventory
        // Staff must never be able to edit a rating even via a direct POST.
        requireVendorRatingEditor();
        $rating_id = dec_id($_POST['rating_id'] ?? '');
        $result    = updateVendorRating($pdo, $rating_id, $_POST, $__userId, $__userName);
        if ($result['ok']) {
            $_SESSION['flash_success'] = "Rating updated.";
            redirect('vendor_ratings.php');
        } else {
            $error_message = $result['message'];
        }
    }

    elseif ($action == 'delete') {
        if (!$__isAdmin) { redirectToRoleHome(); }
        $rating_id = dec_id($_POST['rating_id'] ?? '');
        deleteVendorRating($pdo, $rating_id);
        $_SESSION['flash_success'] = "Rating entry removed.";
        redirect('vendor_ratings.php');
    }
}

// =====================================================
// PAGE STATE (add form / edit form / list / search)
// =====================================================
$show_add_form = isset($_GET['action']) && $_GET['action'] == 'add';

$edit_rating = null;
if ($__isEditor && isset($_GET['edit'])) {
    $edit_id = dec_id($_GET['edit']);
    if ($edit_id) {
        $stmt = $pdo->prepare("SELECT vr.*, s.company_name FROM vendor_ratings vr LEFT JOIN suppliers s ON s.id = vr.supplier_id WHERE vr.id = ?");
        $stmt->execute([$edit_id]);
        $edit_rating = $stmt->fetch();
    }
}

$search    = trim($_GET['search'] ?? '');
$ratings   = getVendorRatingList($pdo, $search);
$summary   = getVendorRatingSummary($pdo);
$suppliers = getActiveSuppliers($pdo);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Supplier Ratings — HCI GroceryOne</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.vr-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.vr-summary-card {
    background: var(--white);
    border: 1.5px solid var(--line);
    border-radius: 14px;
    padding: 16px 18px;
}
.vr-summary-card h3 { font-size: 14.5px; margin: 0 0 10px; font-family: var(--font-heading); }
.vr-summary-row { display:flex; justify-content:space-between; font-size:12.5px; color:var(--ink-soft); margin-top:6px; }
.vr-summary-row strong { color: var(--ink); }
.vr-pct-good { color: var(--green-mid); font-weight:700; }
.vr-pct-bad  { color: var(--red-alert); font-weight:700; }
.vr-badge-yes { display:inline-flex; align-items:center; font-size:11.5px; font-weight:700; border-radius:999px; padding:2px 9px; background: var(--green-pale); color: var(--text-accent); }
.vr-badge-no  { display:inline-flex; align-items:center; font-size:11.5px; font-weight:700; border-radius:999px; padding:2px 9px; background: #fbe6e0; color: var(--red-alert); }
.vr-badge-na  { display:inline-flex; align-items:center; font-size:11.5px; font-weight:700; border-radius:999px; padding:2px 9px; background: var(--cream); color: var(--ink-soft); }
.vr-vendor-toggle { display:flex; gap:8px; margin-bottom:10px; }
.vr-vendor-toggle label { font-size:12.5px; font-weight:600; display:flex; align-items:center; gap:5px; }
.vr-notes { max-width: 220px; white-space: normal; color: var(--ink-soft); font-size: 12.5px; }
</style>
</head>
<body>
<div class="app-shell">

    <!-- SIDEBAR -->
    <?php $active = 'vendor_ratings'; include 'includes/sidebar.php'; ?>

    <!-- MAIN -->
    <div class="main-area">

        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_vendor_ratings ?></div>
            <h1>Supplier Ratings</h1>
            <div class="topbar-user">
                <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'A') ?><?php endif; ?></span>
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

            <?php if (!$__isEditor): ?>
                <div class="alert alert--success" style="margin-bottom:16px;">
                    ℹ️ Puwede kang mag-add ng bagong rating, pero Admin o Finance lang ang makaka-edit ng isang na-save nang rating (segregation of duties).
                </div>
            <?php endif; ?>

            <!-- PER-SUPPLIER SUMMARY -->
            <div class="vr-summary-grid">
                <?php if (empty($summary)): ?>
                    <div class="vr-summary-card"><h3>No ratings recorded yet</h3><p style="font-size:12.5px; color:var(--ink-soft); margin:0;">Add the first rating below after the next delivery or settlement.</p></div>
                <?php else: ?>
                    <?php foreach ($summary as $v): ?>
                        <div class="vr-summary-card">
                            <h3><?= clean($v['vendor_label']) ?></h3>
                            <div class="vr-summary-row"><span>On-time deliveries</span>
                                <strong class="<?= $v['on_time_pct'] === null ? '' : ($v['on_time_pct'] >= 80 ? 'vr-pct-good' : 'vr-pct-bad') ?>">
                                    <?= $v['on_time_pct'] === null ? 'N/A' : $v['on_time_pct'] . '%' ?>
                                </strong>
                            </div>
                            <div class="vr-summary-row"><span>Quality issues logged</span> <strong class="<?= $v['quality_issue_count'] > 0 ? 'vr-pct-bad' : '' ?>"><?= $v['quality_issue_count'] ?></strong></div>
                            <div class="vr-summary-row"><span>Total ratings</span> <strong><?= $v['total_ratings'] ?></strong></div>
                            <div class="vr-summary-row"><span>Last rated</span> <strong><?= $v['last_rating_date'] ? date('M d, Y', strtotime($v['last_rating_date'])) : '—' ?></strong></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- SEARCH + ADD BUTTON -->
            <div class="toolbar">
                <form method="GET" action="vendor_ratings.php" class="search-form" id="vrSearchForm">
                    <input type="text" name="search" id="vrSearchInput" placeholder="Search supplier..." value="<?= clean($search) ?>" class="field-input" style="width:240px;" autocomplete="off">
                    <button type="submit" class="action-btn action-btn--small">Search</button>
                    <?php if ($search !== ''): ?>
                        <a href="vendor_ratings.php" class="action-btn action-btn--small action-btn--secondary">Clear</a>
                    <?php endif; ?>
                </form>
                <a href="vendor_ratings.php?action=add" class="action-btn">+ Add Rating</a>
            </div>

            <!-- ADD FORM -->
            <?php if ($show_add_form): ?>
            <div class="panel">
                <div class="panel-header"><h2>Add Supplier Rating</h2></div>
                <div class="panel-body">
                    <form method="POST" action="vendor_ratings.php?action=add">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="add">

                        <div class="vr-vendor-toggle">
                            <label><input type="radio" name="vendor_mode" value="supplier" checked onchange="document.getElementById('vrSupplierPick').style.display='block'; document.getElementById('vrVendorTypePick').style.display='none';"> Existing Supplier</label>
                            <label><input type="radio" name="vendor_mode" value="other" onchange="document.getElementById('vrSupplierPick').style.display='none'; document.getElementById('vrVendorTypePick').style.display='block';"> Other Supplier (e.g. Meralco, PLDT)</label>
                        </div>

                        <div class="form-grid">
                            <div class="field-group field-group--full" id="vrSupplierPick">
                                <label class="field-label">Supplier <span style="color:var(--red-alert)">*</span></label>
                                <select name="supplier_id" class="field-input">
                                    <option value="">— Select supplier —</option>
                                    <?php foreach ($suppliers as $s): ?>
                                        <option value="<?= (int) $s['id'] ?>"><?= clean($s['company_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field-group field-group--full" id="vrVendorTypePick" style="display:none;">
                                <label class="field-label">Supplier Name <span style="color:var(--red-alert)">*</span></label>
                                <input type="text" name="vendor_name" class="field-input" placeholder="e.g. Meralco" maxlength="150">
                            </div>

                            <div class="field-group">
                                <label class="field-label">Rating Date <span style="color:var(--red-alert)">*</span></label>
                                <input type="date" name="rating_date" class="field-input" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                            </div>

                            <div class="field-group">
                                <label class="field-label">On-time Delivery/Settlement?</label>
                                <select name="on_time_delivery" class="field-input">
                                    <option value="">— N/A —</option>
                                    <option value="1">Yes, on time</option>
                                    <option value="0">No, late</option>
                                </select>
                            </div>

                            <div class="field-group">
                                <label class="field-label">Any Quality Issue?</label>
                                <select name="quality_issue" class="field-input">
                                    <option value="">— N/A —</option>
                                    <option value="1">Yes, may issue</option>
                                    <option value="0">No issue</option>
                                </select>
                            </div>

                            <div class="field-group field-group--full">
                                <label class="field-label">Notes <span style="color:var(--ink-soft); font-weight:400">(optional)</span></label>
                                <textarea name="notes" class="field-input" rows="2" placeholder="e.g. Late by 3 days, 2 damaged sacks of rice"></textarea>
                            </div>
                        </div>

                        <div style="display:flex; gap:10px; margin-top:18px;">
                            <button type="submit" class="action-btn">Save Rating</button>
                            <a href="vendor_ratings.php" class="action-btn action-btn--secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- EDIT FORM (Admin/Finance only) -->
            <?php if ($__isEditor && $edit_rating): ?>
            <div class="panel">
                <div class="panel-header"><h2>Edit Rating — <?= clean($edit_rating['company_name'] ?: $edit_rating['vendor_name']) ?></h2></div>
                <div class="panel-body">
                    <form method="POST" action="vendor_ratings.php?edit=<?= enc_id($edit_rating['id']) ?>">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="rating_id" value="<?= enc_id($edit_rating['id']) ?>">
                        <div class="form-grid">
                            <div class="field-group">
                                <label class="field-label">Rating Date <span style="color:var(--red-alert)">*</span></label>
                                <input type="date" name="rating_date" class="field-input" value="<?= clean($edit_rating['rating_date']) ?>" max="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="field-group">
                                <label class="field-label">On-time Delivery/Settlement?</label>
                                <select name="on_time_delivery" class="field-input">
                                    <option value="" <?= $edit_rating['on_time_delivery'] === null ? 'selected' : '' ?>>— N/A —</option>
                                    <option value="1" <?= $edit_rating['on_time_delivery'] == 1 ? 'selected' : '' ?>>Yes, on time</option>
                                    <option value="0" <?= $edit_rating['on_time_delivery'] === '0' || $edit_rating['on_time_delivery'] === 0 ? 'selected' : '' ?>>No, late</option>
                                </select>
                            </div>
                            <div class="field-group">
                                <label class="field-label">Any Quality Issue?</label>
                                <select name="quality_issue" class="field-input">
                                    <option value="" <?= $edit_rating['quality_issue'] === null ? 'selected' : '' ?>>— N/A —</option>
                                    <option value="1" <?= $edit_rating['quality_issue'] == 1 ? 'selected' : '' ?>>Yes, may issue</option>
                                    <option value="0" <?= $edit_rating['quality_issue'] === '0' || $edit_rating['quality_issue'] === 0 ? 'selected' : '' ?>>No issue</option>
                                </select>
                            </div>
                            <div class="field-group field-group--full">
                                <label class="field-label">Notes</label>
                                <textarea name="notes" class="field-input" rows="2"><?= clean($edit_rating['notes'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div style="display:flex; gap:10px; margin-top:18px;">
                            <button type="submit" class="action-btn">Save Changes</button>
                            <a href="vendor_ratings.php" class="action-btn action-btn--secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- RATINGS TABLE -->
            <div class="panel">
                <div class="panel-header"><h2>Rating History (<?= count($ratings) ?>)</h2></div>
                <div class="panel-body panel-body--table">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Supplier</th>
                                <th>On-time?</th>
                                <th>Quality Issue?</th>
                                <th>Notes</th>
                                <th>Rated By</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ratings)): ?>
                                <tr><td colspan="7" style="text-align:center; color:var(--ink-soft); padding:24px;">No ratings recorded yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($ratings as $r): ?>
                                <tr>
                                    <td><?= date('M d, Y', strtotime($r['rating_date'])) ?></td>
                                    <td><?= clean($r['company_name'] ?: $r['vendor_name']) ?></td>
                                    <td>
                                        <?php if ($r['on_time_delivery'] === null): ?><span class="vr-badge-na">N/A</span>
                                        <?php elseif ((int) $r['on_time_delivery'] === 1): ?><span class="vr-badge-yes">On time</span>
                                        <?php else: ?><span class="vr-badge-no">Late</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($r['quality_issue'] === null): ?><span class="vr-badge-na">N/A</span>
                                        <?php elseif ((int) $r['quality_issue'] === 1): ?><span class="vr-badge-no">Issue</span>
                                        <?php else: ?><span class="vr-badge-yes">OK</span><?php endif; ?>
                                    </td>
                                    <td class="vr-notes"><?= clean($r['notes'] ?? '—') ?></td>
                                    <td style="font-size:12.5px; color:var(--ink-soft);">
                                        <?= clean($r['rated_by_name'] ?? '—') ?>
                                        <?php if (!empty($r['updated_at'])): ?><br><span style="font-size:11px;">(edited by <?= clean($r['updated_by_name'] ?? '—') ?>)</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display:flex; gap:6px;">
                                            <?php if ($__isEditor): ?>
                                                <a href="vendor_ratings.php?edit=<?= enc_id($r['id']) ?>" class="link-btn" title="Edit" aria-label="Edit"><i class="fa-regular fa-pen-to-square"></i></a>
                                            <?php endif; ?>
                                            <?php if ($__isAdmin): ?>
                                                <form method="POST" action="vendor_ratings.php" class="inline-form js-confirm-form"
                                                      data-confirm="<?= htmlspecialchars(json_encode([
                                                            'icon' => 'warning',
                                                            'title' => 'Delete this rating?',
                                                            'text' => 'This cannot be undone.',
                                                            'confirmButtonText' => 'Yes, delete',
                                                      ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                                                    <?php csrfField(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="rating_id" value="<?= enc_id($r['id']) ?>">
                                                    <button type="submit" class="link-btn link-btn--danger" title="Delete" aria-label="Delete"><i class="fa-regular fa-trash-can"></i></button>
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
(function () {
    var input = document.getElementById('vrSearchInput');
    if (!input) return;
    var timer = null;
    input.addEventListener('input', function () {
        if (timer) clearTimeout(timer);
        timer = setTimeout(function () { input.form.submit(); }, 450);
    });
})();
</script>
</body>
</html>