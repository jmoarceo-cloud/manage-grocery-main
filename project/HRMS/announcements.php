<?php
// ============================================================
// announcements.php — Central Announcements module.
//
// Access (see canManageAnnouncements() / decideAnnouncement() in
// includes/config.php — every action is re-checked there too, not
// just gated here):
//   admin / hr_manager -> create (any scope, published immediately),
//                          approve/reject pending, take down published
//   hr (staff)          -> create (any scope, goes to pending_approval)
//   supervisor           -> create (forced to their own department,
//                            published immediately, no approval needed)
//   employee              -> view only
//
// Everyone logged into HRMS can see this page; what they see on it
// depends on role (handled entirely by the query functions below,
// server-side — never just hidden in the template).
// ============================================================

require_once 'includes/config.php';
requireLogin();

$role       = $_SESSION['role'] ?? '';
$userId     = $_SESSION['user_id'] ?? null;
$fullName   = $_SESSION['full_name'] ?? '';
$employeeId = $_SESSION['employee_id'] ?? null;
$myDepartment = getSessionEmployeeDepartment($pdo, $employeeId);

$canManage = canManageAnnouncements($role);
$canApprove = in_array($role, ['admin', 'hr_manager'], true);

$success_message = "";
$error_message   = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';
    $actor = ['user_id' => $userId, 'name' => $fullName, 'role' => $role, 'employee_id' => $employeeId];

    if ($action === 'create') {
        $result = createAnnouncement($pdo, $actor, [
            'title'      => $_POST['title'] ?? '',
            'content'    => $_POST['content'] ?? '',
            'scope'      => $_POST['scope'] ?? 'company_wide',
            'department' => $_POST['department'] ?? '',
            'expires_at' => $_POST['expires_at'] ?? '',
        ]);
        $result['success'] ? $success_message = $result['message'] : $error_message = $result['message'];
    } elseif ($action === 'approve' && $canApprove) {
        $result = decideAnnouncement($pdo, (int) ($_POST['id'] ?? 0), $actor, 'approve');
        $result['success'] ? $success_message = $result['message'] : $error_message = $result['message'];
    } elseif ($action === 'reject' && $canApprove) {
        $result = decideAnnouncement($pdo, (int) ($_POST['id'] ?? 0), $actor, 'reject', $_POST['reason'] ?? '');
        $result['success'] ? $success_message = $result['message'] : $error_message = $result['message'];
    } elseif ($action === 'take_down' && $canManage) {
        $result = takeDownAnnouncement($pdo, (int) ($_POST['id'] ?? 0), $actor);
        $result['success'] ? $success_message = $result['message'] : $error_message = $result['message'];
    } else {
        $error_message = "You are not allowed to do that.";
    }
}

$tab = $_GET['tab'] ?? 'all';
if ($tab === 'pending' && !$canApprove) { $tab = 'all'; }
if ($tab === 'mine' && !$canManage) { $tab = 'all'; }

$visibleAnnouncements = getVisibleAnnouncementsForHrmsUser($pdo, $role, $myDepartment);
$pendingAnnouncements = $canApprove ? getPendingAnnouncementsForApproval($pdo) : [];
$myAnnouncements = $canManage ? getMyAnnouncements($pdo, $userId) : [];
$allDepartments = $canManage ? getAllDepartments($pdo) : [];

$statusBadge = [
    'draft'            => ['label' => 'Draft', 'color' => 'var(--ink-soft)'],
    'pending_approval' => ['label' => 'Pending Approval', 'color' => 'var(--mustard)'],
    'published'        => ['label' => 'Published', 'color' => 'var(--green-mid)'],
    'rejected'         => ['label' => 'Rejected', 'color' => 'var(--red-alert)'],
    'expired'          => ['label' => 'Expired', 'color' => 'var(--ink-soft)'],
];

$__useHrSidebar = in_array($role, ['admin', 'hr', 'hr_manager'], true);
$__useSupervisorSidebar = ($role === 'supervisor');
$activeNav = 'announcements';
$backUrl = ($role === 'employee') ? 'employee_dashboard.php' : dashboardUrlForRole($role);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Announcements — HRMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php include 'includes/supervisor_style.php'; ?>
<style>
/* ── "Create Announcement" — collapsible CTA card ── */
.ann-create {
    border: 1.5px solid var(--line);
    border-radius: 16px;
    background: var(--white);
    margin-bottom: 22px;
    overflow: hidden;
    box-shadow: 0 1px 2px rgba(23,34,46,.04);
}
.ann-create > summary {
    cursor: pointer;
    list-style: none;
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 18px 22px;
    font-weight: 700;
    font-family: var(--font-display);
    color: var(--text-accent);
    font-size: 15px;
}
.ann-create > summary::-webkit-details-marker { display: none; }
.ann-create-icon {
    width: 34px; height: 34px; border-radius: 10px;
    background: var(--green-pale); color: var(--text-accent);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.ann-create-chevron { margin-left: auto; color: var(--ink-soft); transition: transform .2s ease; flex-shrink: 0; }
.ann-create[open] > summary .ann-create-chevron { transform: rotate(180deg); }
.ann-create[open] > summary { border-bottom: 1px solid var(--line); }
.ann-create-body { padding: 22px; }

/* ── Tabs ── */
.ann-tabs { display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap; }

/* ── Announcement cards ── */
.ann-list { display: flex; flex-direction: column; gap: 14px; }
.ann-card {
    background: var(--white);
    border: 1.5px solid var(--line);
    border-left: 4px solid var(--green-mid);
    border-radius: 16px;
    padding: 20px 24px;
    box-shadow: 0 1px 2px rgba(23,34,46,.03);
    transition: box-shadow .18s ease, transform .18s ease;
}
.ann-card:hover { box-shadow: 0 6px 20px rgba(23,34,46,.08); transform: translateY(-1px); }
.ann-card.scope-department { border-left-color: var(--mustard); }
.ann-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; }
.ann-title { font-size: 17px; font-weight: 700; color: var(--ink); font-family: var(--font-display); line-height: 1.35; }
.ann-meta { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--ink-soft); margin-top: 6px; flex-wrap: wrap; }
.ann-meta svg { flex-shrink: 0; opacity: .65; }
.ann-content { font-size: 14.5px; color: var(--ink); margin-top: 14px; white-space: pre-wrap; line-height: 1.6; }
.ann-badges { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
.pill { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 999px; font-size: 11px; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; color: #fff; }
.ann-actions { display: flex; gap: 10px; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--line); flex-wrap: wrap; }
.ann-reject-reason { font-size: 13px; margin-top: 10px; padding: 10px 14px; border-radius: 10px; background: var(--red-pale); color: var(--red-alert); }

/* ── Empty state ── */
.ann-empty { text-align: center; padding: 56px 24px; color: var(--ink-soft); }
.ann-empty svg { width: 42px; height: 42px; opacity: .35; margin-bottom: 14px; }
.ann-empty p { font-size: 14.5px; font-weight: 600; }

/* ── Create Announcement form ── */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-grid .full { grid-column: 1/-1; }
.field-group label { display: block; font-size: 12px; font-weight: 700; color: var(--ink-soft); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .03em; }
.field-group input[type=text], .field-group input[type=date], .field-group textarea, .field-group select {
    width: 100%; padding: 11px 13px; border: 1.5px solid var(--line); border-radius: 10px;
    font-family: var(--font-body); font-size: 14px; background: var(--cream); transition: border-color .15s, background .15s;
}
.field-group input:focus, .field-group textarea:focus, .field-group select:focus { outline: none; border-color: var(--green-mid); background: var(--white); }
.field-group textarea { resize: vertical; min-height: 100px; }
.field-group .hint { font-size: 11.5px; color: var(--ink-soft); margin-top: 5px; }
</style>
</head>
<body>
<div class="app-shell">
<?php if ($__useHrSidebar): include 'includes/hr_sidebar.php';
      elseif ($__useSupervisorSidebar): include 'includes/supervisor_sidebar.php';
      endif; ?>
<div class="main-area">
    <div class="topbar">
        <h1>Announcements<?php if ($canApprove && count($pendingAnnouncements) > 0): ?> <span class="badge" style="background:var(--mustard); color:#FFFFFF;"><?= count($pendingAnnouncements) ?> pending</span><?php endif; ?></h1>
        <div class="user-info">
            <?php if (!$__useHrSidebar && !$__useSupervisorSidebar): ?>
                <a href="<?= clean($backUrl) ?>" style="font-weight:600; color:var(--text-accent);">&larr; Back to Dashboard</a>
            <?php endif; ?>
            <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($fullName), 0, 1)) ?: '?') ?><?php endif; ?></span>
            <span><?= clean($fullName) ?> (<?= clean(roleLabel($role)) ?>)</span>
        </div>
    </div>

    <div class="container">

        <?php if (!empty($success_message)): ?><div class="alert alert--success"><?= clean($success_message) ?></div><?php endif; ?>
        <?php if (!empty($error_message)): ?><div class="alert alert--error"><?= clean($error_message) ?></div><?php endif; ?>

        <?php if ($canManage): ?>
        <details class="ann-create">
            <summary>
                <span class="ann-create-icon"><svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>
                Create Announcement
                <span class="ann-create-chevron"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M5 8l5 5 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            </summary>
            <div class="ann-create-body">
                <form method="POST">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="create">
                    <div class="form-grid">
                        <div class="field-group full">
                            <label>Title</label>
                            <input type="text" name="title" maxlength="150" required placeholder="e.g. Holiday Schedule Update">
                        </div>
                        <div class="field-group full">
                            <label>Content</label>
                            <textarea name="content" required placeholder="Write the announcement..."></textarea>
                        </div>
                        <?php if ($role === 'supervisor'): ?>
                            <div class="field-group full">
                                <label>Scope</label>
                                <div class="hint" style="margin-top:0;">Supervisors can only post to their own department (<?= clean($myDepartment ?: 'unassigned') ?>). This will not appear on POS dashboards.</div>
                            </div>
                        <?php else: ?>
                            <div class="field-group">
                                <label>Scope</label>
                                <select name="scope" id="annScope" onchange="document.getElementById('annDeptWrap').style.display = this.value === 'department' ? 'block' : 'none';">
                                    <option value="company_wide">Company-wide (also shows on POS dashboards)</option>
                                    <option value="department">Specific Department</option>
                                </select>
                            </div>
                            <div class="field-group" id="annDeptWrap" style="display:none;">
                                <label>Department</label>
                                <select name="department">
                                    <option value="">-- Select department --</option>
                                    <?php foreach ($allDepartments as $dept): ?>
                                        <option value="<?= clean($dept) ?>"><?= clean($dept) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="field-group">
                            <label>Expires On (optional)</label>
                            <input type="date" name="expires_at">
                            <div class="hint">Leave blank for no expiry. It will automatically stop showing once this date passes.</div>
                        </div>
                    </div>
                    <?php if ($role === 'hr'): ?>
                        <div class="hint" style="margin:10px 0;">As HR Staff, this will be submitted for approval by an HR Manager or Admin before it becomes visible.</div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary" style="margin-top:6px;">
                        <?= $role === 'hr' ? 'Submit for Approval' : 'Publish Announcement' ?>
                    </button>
                </form>
            </div>
        </details>
        <?php endif; ?>

        <div class="ann-tabs">
            <a href="announcements.php" class="tab-btn <?= $tab === 'all' ? 'active' : '' ?>">Announcements</a>
            <?php if ($canApprove): ?>
                <a href="announcements.php?tab=pending" class="tab-btn <?= $tab === 'pending' ? 'active' : '' ?>">Pending Approval<?= count($pendingAnnouncements) > 0 ? ' (' . count($pendingAnnouncements) . ')' : '' ?></a>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <a href="announcements.php?tab=mine" class="tab-btn <?= $tab === 'mine' ? 'active' : '' ?>">My Announcements</a>
            <?php endif; ?>
        </div>

        <div class="section">

        <?php if ($tab === 'all'): ?>
            <?php if (empty($visibleAnnouncements)): ?>
                <div class="ann-empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 10.5v3a1 1 0 001 1h2l6.5 3.8V5.7L6 9.5H4a1 1 0 00-1 1z"/><path d="M17.5 9.5a3 3 0 010 5"/></svg>
                    <p>No announcements right now.</p>
                </div>
            <?php else: ?>
                <div class="ann-list">
                <?php foreach ($visibleAnnouncements as $a): ?>
                    <?php
                        $isCompanyWide = $a['scope'] === 'company_wide';
                        $scopeLabel = $isCompanyWide ? 'Company-wide' : ('Department: ' . $a['department']);
                        $canTakeDown = in_array($role, ['admin', 'hr_manager'], true) || (int) $a['created_by'] === (int) $userId;
                    ?>
                    <div class="ann-card<?= $isCompanyWide ? '' : ' scope-department' ?>">
                        <div class="ann-card-head">
                            <div>
                                <div class="ann-title"><?= clean($a['title']) ?></div>
                                <div class="ann-meta">
                                    <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="7" r="3.2" stroke="currentColor" stroke-width="1.6"/><path d="M3.5 17c1-3.5 4-5.2 6.5-5.2s5.5 1.7 6.5 5.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                    By <?= clean($a['created_by_name'] ?: 'Unknown') ?>
                                    <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M4 4h12v12H4z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M4 8h12" stroke="currentColor" stroke-width="1.5"/></svg>
                                    <?= clean(date('F d, Y', strtotime($a['published_at'] ?: $a['created_at']))) ?><?= $a['expires_at'] ? ' &middot; Expires ' . clean(date('F d, Y', strtotime($a['expires_at']))) : '' ?>
                                </div>
                            </div>
                        </div>
                        <div class="ann-content"><?= clean($a['content']) ?></div>
                        <div class="ann-badges">
                            <span class="pill" style="background:<?= $isCompanyWide ? 'var(--green-mid)' : 'var(--mustard)' ?>;"><?= clean($scopeLabel) ?></span>
                        </div>
                        <?php if ($canTakeDown): ?>
                        <div class="ann-actions">
                            <form method="POST" data-confirm="Take down this announcement? It will stop showing to everyone." data-confirm-title="Take Down Announcement" data-confirm-danger="1" data-confirm-ok="Yes, take down">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="take_down">
                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Take Down</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($tab === 'pending'): ?>
            <?php if (empty($pendingAnnouncements)): ?>
                <div class="ann-empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg>
                    <p>No announcements waiting for approval.</p>
                </div>
            <?php else: ?>
                <div class="ann-list">
                <?php foreach ($pendingAnnouncements as $a): ?>
                    <?php
                        $isCompanyWide = $a['scope'] === 'company_wide';
                        $scopeLabel = $isCompanyWide ? 'Company-wide' : ('Department: ' . $a['department']);
                    ?>
                    <div class="ann-card<?= $isCompanyWide ? '' : ' scope-department' ?>">
                        <div class="ann-card-head">
                            <div>
                                <div class="ann-title"><?= clean($a['title']) ?></div>
                                <div class="ann-meta">
                                    <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="7" r="3.2" stroke="currentColor" stroke-width="1.6"/><path d="M3.5 17c1-3.5 4-5.2 6.5-5.2s5.5 1.7 6.5 5.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                    Submitted by <?= clean($a['created_by_name'] ?: 'Unknown') ?> &middot; <?= clean(date('F d, Y — h:i A', strtotime($a['created_at']))) ?>
                                </div>
                            </div>
                            <span class="pill" style="background:<?= $statusBadge['pending_approval']['color'] ?>;">Pending Approval</span>
                        </div>
                        <div class="ann-content"><?= clean($a['content']) ?></div>
                        <div class="ann-badges"><span class="pill" style="background:<?= $isCompanyWide ? 'var(--green-mid)' : 'var(--mustard)' ?>;"><?= clean($scopeLabel) ?></span></div>
                        <div class="ann-actions">
                            <form method="POST" data-confirm="Approve and publish this announcement?" data-confirm-title="Approve Announcement" data-confirm-icon="question" data-confirm-ok="Yes, approve">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm">Approve</button>
                            </form>
                            <form method="POST" data-confirm="Why is this announcement being rejected?" data-confirm-title="Reject Announcement" data-confirm-danger="1" data-confirm-ok="Yes, reject" data-reason="required">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($tab === 'mine'): ?>
            <?php if (empty($myAnnouncements)): ?>
                <div class="ann-empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>
                    <p>You haven't created any announcements yet.</p>
                </div>
            <?php else: ?>
                <div class="ann-list">
                <?php foreach ($myAnnouncements as $a): ?>
                    <?php
                        $badge = $statusBadge[$a['status']] ?? ['label' => ucfirst($a['status']), 'color' => 'var(--ink-soft)'];
                        $isCompanyWide = $a['scope'] === 'company_wide';
                        $scopeLabel = $isCompanyWide ? 'Company-wide' : ('Department: ' . $a['department']);
                    ?>
                    <div class="ann-card<?= $isCompanyWide ? '' : ' scope-department' ?>">
                        <div class="ann-card-head">
                            <div>
                                <div class="ann-title"><?= clean($a['title']) ?></div>
                                <div class="ann-meta">
                                    <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M4 4h12v12H4z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M4 8h12" stroke="currentColor" stroke-width="1.5"/></svg>
                                    <?= clean(date('F d, Y', strtotime($a['created_at']))) ?><?= $a['expires_at'] ? ' &middot; Expires ' . clean(date('F d, Y', strtotime($a['expires_at']))) : '' ?>
                                </div>
                            </div>
                            <span class="pill" style="background:<?= $badge['color'] ?>;"><?= clean($badge['label']) ?></span>
                        </div>
                        <div class="ann-content"><?= clean($a['content']) ?></div>
                        <div class="ann-badges"><span class="pill" style="background:<?= $isCompanyWide ? 'var(--green-mid)' : 'var(--mustard)' ?>;"><?= clean($scopeLabel) ?></span></div>
                        <?php if ($a['status'] === 'rejected' && $a['reject_reason']): ?>
                            <div class="ann-reject-reason">Reason: <?= clean($a['reject_reason']) ?></div>
                        <?php endif; ?>
                        <?php if (in_array($a['status'], ['published', 'pending_approval'], true)): ?>
                        <div class="ann-actions">
                            <form method="POST" data-confirm="Take down this announcement?" data-confirm-title="Take Down Announcement" data-confirm-danger="1" data-confirm-ok="Yes, take down">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="take_down">
                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Take Down</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        </div>
    </div>
</div>
</div>
<?php include 'includes/sweetalert.php'; ?>
</body>
</html>
