<?php
// ============================================================
// announcements.php — Dedicated, read-only "Announcements" page
// for the POS side (Admin, Cashier, Finance, Inventory Staff).
//
// Before this page existed, the only place POS-side accounts could
// see announcements was a small embedded panel on their dashboard
// (includes/announcements_panel.php) — there was no actual module/
// page for it, unlike the HRMS side (HRMS/announcements.php) which
// has a dedicated nav item. This page gives POS accounts the same:
// a real "Announcements" entry in the sidebar with the full list.
//
// Same data source and same scoping as the dashboard panel — pulls
// COMPANY-WIDE, PUBLISHED, not-yet-expired announcements from the
// `hrms` database via the $hrmsPdo bridge (includes/config.php).
// Department-scoped HR announcements never reach this page — that
// filter happens in the SQL itself (getCompanyWideAnnouncementsForPos()
// in HRMS/includes/config.php), not just by omission here.
//
// Any logged-in POS account (any role) can view this — it's
// read-only company news, not a role-gated module. "Read" state is
// tracked per POS account in this database's own announcement_reads
// table (see ensureAnnouncementReadsTable() in includes/config.php) —
// the announcements themselves live in `hrms` and stay untouched.
// ============================================================

require_once 'includes/config.php';
requireLogin();

$__role   = $_SESSION['role'] ?? '';
$__userId = (int) currentPosUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCSRF();
    if ($_POST['action'] === 'mark_read' && isset($_POST['announcement_id'])) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO announcement_reads (user_id, announcement_id) VALUES (:uid, :aid)");
        $stmt->execute([':uid' => $__userId, ':aid' => (int) $_POST['announcement_id']]);
    } elseif ($_POST['action'] === 'mark_all_read' && !empty($_POST['announcement_ids'])) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO announcement_reads (user_id, announcement_id) VALUES (:uid, :aid)");
        foreach (explode(',', $_POST['announcement_ids']) as $__aid) {
            $__aid = (int) $__aid;
            if ($__aid > 0) $stmt->execute([':uid' => $__userId, ':aid' => $__aid]);
        }
    }
    redirect('announcements.php');
}

// Fail-open, same pattern as the dashboard panel: if $hrmsPdo is
// null (HRMS database unreachable) or the query fails, this page
// just shows an empty state instead of erroring out.
$__announcements = function_exists('getCompanyWideAnnouncementsForPos')
    ? getCompanyWideAnnouncementsForPos($hrmsPdo, 100)
    : [];

// Which of these has this POS account already marked as read.
$__readIds = [];
if (!empty($__announcements)) {
    $stmt = $pdo->prepare("SELECT announcement_id FROM announcement_reads WHERE user_id = ?");
    $stmt->execute([$__userId]);
    $__readIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
$__unreadCount = 0;
foreach ($__announcements as $__ann) {
    if (!in_array((int) $__ann['id'], $__readIds, true)) $__unreadCount++;
}

$__icon_announcements = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M3 8.5v3a1 1 0 001 1h1.5l5.5 3.2V4.3L5.5 7.5H4a1 1 0 00-1 1z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M14.5 8a2.3 2.3 0 010 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M11 13v2.3a1.2 1.2 0 01-2.3.4l-.9-2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Announcements — HCI GroceryOne</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.empty-notif {
    text-align: center;
    padding: 60px 20px;
    color: var(--ink-soft);
}
.empty-notif svg { width: 48px; height: 48px; margin-bottom: 16px; opacity: .4; }
.empty-notif p { font-size: 15px; font-weight: 600; }
.empty-notif span { font-size: 13.5px; }

.notif-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}
.action-btn--sm {
    padding: 9px 18px;
    font-size: 13px;
}
.action-btn--outline {
    background: var(--white);
    color: var(--text-accent);
    border: 1.5px solid var(--green-deep);
    box-shadow: none;
}
.action-btn--outline:hover { background: var(--green-pale); }

.ann-card {
    width: 100%;
    padding: 18px 22px;
    position: relative;
    border-left: 4px solid var(--line);
}
.ann-card.is-unread { border-left-color: var(--green-mid); background: var(--green-pale); }
.ann-card .ann-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}
.ann-mark-btn {
    flex-shrink: 0;
    padding: 7px 14px;
    border-radius: 8px;
    border: 1.5px solid var(--line);
    background: var(--cream);
    font-size: 12px;
    font-weight: 600;
    color: var(--ink-soft);
    cursor: pointer;
    transition: all .15s;
    white-space: nowrap;
}
.ann-mark-btn:hover { border-color: var(--green-mid); color: var(--green-mid); }

.content > .notif-toolbar {
    padding: 16px 18px;
    border: 1px solid var(--line);
    border-radius: 14px;
    background: var(--white);
    box-shadow: 0 6px 18px rgba(15, 23, 42, .04);
}
.notif-toolbar p { margin: 0; }
.ann-card {
    border: 1px solid var(--line);
    border-left: 4px solid var(--line);
    border-radius: 14px;
    background: var(--white);
    box-shadow: 0 6px 18px rgba(15, 23, 42, .04);
    transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
}
.ann-card:hover {
    border-color: #93c5fd;
    box-shadow: 0 10px 24px rgba(37, 99, 235, .10);
    transform: translateY(-1px);
}
.ann-card.is-unread {
    border-left-color: var(--green-mid);
    background: linear-gradient(90deg, var(--green-pale), var(--white) 42%);
}
.ann-card .ann-top > div:first-child > div:first-child {
    color: var(--text-accent) !important;
}
.ann-mark-btn {
    background: var(--white);
    border-color: #bfdbfe;
    color: #1d4ed8;
}
.ann-mark-btn:hover { background: #eff6ff; border-color: #60a5fa; color: #1d4ed8; }
.empty-notif {
    border: 1px dashed var(--line);
    border-radius: 16px;
    background: var(--white);
}
html[data-theme="dark"] .content > .notif-toolbar,
html[data-theme="dark"] .ann-card,
html[data-theme="dark"] .empty-notif {
    background: #111518;
    border-color: #26313a;
}
html[data-theme="dark"] .ann-card.is-unread {
    background: linear-gradient(90deg, #172554, #111518 48%);
    border-left-color: #60a5fa;
}
html[data-theme="dark"] .ann-card:hover {
    border-color: #3b82f6;
    box-shadow: 0 10px 24px rgba(37, 99, 235, .18);
}
html[data-theme="dark"] .ann-mark-btn {
    background: #172554;
    border-color: #3b82f6;
    color: #bfdbfe;
}
html[data-theme="dark"] .ann-mark-btn:hover { background: #1e3a8a; }
@media (max-width: 640px) {
    .ann-card { padding: 16px; }
    .ann-card .ann-top { flex-direction: column; }
    .ann-card .ann-top form,
    .ann-card .ann-top .ann-mark-btn { width: 100%; }
}
</style>
</head>
<body>
<div class="app-shell">

    <?php $active = 'announcements'; include 'includes/sidebar.php'; ?>

    <div class="main-area">
        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_announcements ?></div>
            <h1>Announcements</h1>
            <div class="topbar-user">
                <span class="user-avatar"><?php
                    $__announcementAvatar = $_SESSION['profile_picture'] ?? null;
                    $__announcementAvatarIsValid = is_string($__announcementAvatar)
                        && preg_match('/^[A-Za-z0-9._-]+$/', $__announcementAvatar)
                        && is_file(__DIR__ . '/uploads/avatars/' . $__announcementAvatar);
                    if ($__announcementAvatarIsValid):
                ?><img src="<?= htmlspecialchars(systemBaseUrl() . '/uploads/avatars/' . rawurlencode(basename($__announcementAvatar)), ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'A') ?><?php endif; ?></span>
                <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Administrator') ?></span>
            </div>
        </header>

        <main class="content">

            <div class="notif-toolbar" style="margin-bottom:16px;">
                <p style="font-size:14px; color:var(--ink-soft);">
                    <?= count($__announcements) ?> active announcement<?= count($__announcements) != 1 ? 's' : '' ?>
                    <?php if ($__unreadCount > 0): ?> — <strong><?= $__unreadCount ?> unread</strong><?php endif; ?>
                </p>
                <?php if ($__unreadCount > 0): ?>
                    <form method="POST">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="mark_all_read">
                        <input type="hidden" name="announcement_ids" value="<?= clean(implode(',', array_column($__announcements, 'id'))) ?>">
                        <button type="submit" class="action-btn action-btn--sm action-btn--outline">Mark All as Read</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (empty($__announcements)): ?>
                <div class="empty-notif">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M3 10.5v3a1 1 0 001 1h2l6.5 3.8V5.7L6 9.5H4a1 1 0 00-1 1z"/>
                        <path d="M17.5 9.5a3 3 0 010 5"/>
                    </svg>
                    <p>No announcements right now</p>
                    <span>Company-wide announcements from HR/Management will show up here.</span>
                </div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <?php foreach ($__announcements as $__ann):
                        $__isRead = in_array((int) $__ann['id'], $__readIds, true);
                    ?>
                        <div class="panel ann-card<?= $__isRead ? '' : ' is-unread' ?>">
                            <div class="ann-top">
                                <div style="min-width:0;">
                                    <div style="font-size:16px; font-weight:700; color:var(--ink); font-family:var(--font-display);"><?= clean($__ann['title']) ?></div>
                                    <div style="font-size:14px; color:var(--ink); margin-top:8px; white-space:pre-wrap; line-height:1.5;"><?= clean($__ann['content']) ?></div>
                                    <div style="font-size:12px; color:var(--ink-soft); margin-top:12px;">
                                        <?= clean(date('F d, Y', strtotime($__ann['published_at']))) ?>
                                        <?php if (!empty($__ann['expires_at'])): ?>
                                            &middot; Expires <?= clean(date('F d, Y', strtotime($__ann['expires_at']))) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($__isRead): ?>
                                    <span style="font-size:12px; color:var(--green-leaf); font-weight:600; flex-shrink:0;">✓ Read</span>
                                <?php else: ?>
                                    <form method="POST">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="mark_read">
                                        <input type="hidden" name="announcement_id" value="<?= (int) $__ann['id'] ?>">
                                        <button type="submit" class="ann-mark-btn">Mark as read</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>
<script>
function confirmNav(e, url, opts) {
    e.preventDefault();
    Swal.fire(Object.assign({
        showCancelButton: true,
        cancelButtonText: 'Cancel'
    }, opts)).then(function (result) {
        if (result.isConfirmed) window.location.href = url;
    });
    return false;
}
</script>
</body>
</html>
