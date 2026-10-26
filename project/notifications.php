<?php

require_once 'includes/config.php';
requireNotificationsAccess(); // Admin, Inventory Staff, Finance — Merit/Demerit alerts from HRMS, low-stock alerts, at cross-role Operating Expense handoffs (encode -> verify -> approve -> pay)
ensureNotificationRoutingColumns($pdo);

$__role   = $_SESSION['role'] ?? '';
$__userId = (int) currentPosUserId();

// Admin at Inventory Staff pa rin ang nakakakita ng lumang "global"
// alerts (low-stock, HRMS performance) na walang partikular na
// recipient (user_id IS NULL) — Finance hindi na kailangang makakita
// nito, kundi lang ng sarili nilang targeted na Operating Expense
// notifications.
$__seesLegacyGlobal = in_array($__role, ['admin', 'inventory_staff'], true);
$__scopeSql = "(n.user_id = :uid" . ($__seesLegacyGlobal ? " OR n.user_id IS NULL" : "") . ")";

$view = ($_GET['view'] ?? '') === 'archived' ? 'archived' : 'active';
$__announcementAlerts = [];

if ($__seesLegacyGlobal) {
    checkLowStock($pdo);
}

// Mark as read kapag nag-click — lahat ng action ay naka-scope sa mga
// notification na nakikita mismo ng kasalukuyang user (sarili niyang
// targeted rows, plus ang legacy global rows kung Admin/Inventory
// Staff siya), para hindi maapektuhan ang notifications ng ibang tao.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    requireCSRF();
    if ($_POST['action'] == 'mark_read') {
        $pdo->prepare("UPDATE notifications n SET is_read = 1 WHERE is_read = 0 AND $__scopeSql")
            ->execute([':uid' => $__userId]);
        if ($view === 'active' && $hrmsPdo && function_exists('getCompanyWideAnnouncementsForPos')) {
            $announcementRead = $pdo->prepare("INSERT IGNORE INTO announcement_reads (user_id, announcement_id) VALUES (:uid, :aid)");
            foreach (getCompanyWideAnnouncementsForPos($hrmsPdo, 100) as $announcement) {
                $announcementRead->execute([':uid' => $__userId, ':aid' => (int) $announcement['id']]);
            }
        }
    } elseif ($_POST['action'] == 'mark_one_read' && isset($_POST['notif_id'])) {
        $pdo->prepare("UPDATE notifications n SET is_read = 1 WHERE id = :id AND $__scopeSql")
            ->execute([':id' => $_POST['notif_id'], ':uid' => $__userId]);
    } elseif ($_POST['action'] == 'delete_read') {
        $pdo->prepare("UPDATE notifications n SET is_archived = 1 WHERE is_read = 1 AND $__scopeSql")
            ->execute([':uid' => $__userId]);
    } elseif ($_POST['action'] == 'unarchive' && isset($_POST['notif_id'])) {
        $pdo->prepare("UPDATE notifications n SET is_archived = 0 WHERE id = :id AND $__scopeSql")
            ->execute([':id' => $_POST['notif_id'], ':uid' => $__userId]);
    }
    header('Location: notifications.php' . ($view === 'archived' ? '?view=archived' : ''));
    exit();
}

// Get notifications for the current tab (Active vs Archived), scoped
// to what this user is actually allowed to see.
$notifications = $pdo->prepare("
    SELECT n.*, p.product_name, p.stock
    FROM notifications n
    LEFT JOIN products p ON p.id = n.product_id
    WHERE n.is_archived = :archived AND $__scopeSql
    ORDER BY n.date_created DESC
");
$notifications->execute([':archived' => $view === 'archived' ? 1 : 0, ':uid' => $__userId]);
$notifications = $notifications->fetchAll();

foreach ($notifications as &$__notification) {
    if ($__notification['type'] === 'oe_paid') {
        $__notification['message'] = str_replace(
            ['Nabayaran na nang buo ang bill na ', 'Nabayaran nang bahagya (partial) ang bill na ', ' na binayaran ngayon.'],
            ['Fully paid: bill for ', 'Partially paid: bill for ', ' paid today.'],
            $__notification['message']
        );
    } elseif ($__notification['type'] === 'oe_verification_needed') {
        $__notification['message'] = str_replace(
            ['Bagong bill mula kay ', 'isang user', ' — kailangan ng Level 1 verification.'],
            ['New bill submitted by ', 'a user', ' — Level 1 verification required.'],
            $__notification['message']
        );
    }
}
unset($__notification);

$__announcementUnreadCount = 0;
if ($view === 'active' && $hrmsPdo && function_exists('getCompanyWideAnnouncementsForPos')) {
    $__companyAnnouncements = getCompanyWideAnnouncementsForPos($hrmsPdo, 100);
    if ($__companyAnnouncements) {
        try {
            $stmt = $pdo->prepare("SELECT announcement_id FROM announcement_reads WHERE user_id = ?");
            $stmt->execute([$__userId]);
            $__readAnnouncementIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            foreach ($__companyAnnouncements as $__announcement) {
                if (!in_array((int) $__announcement['id'], $__readAnnouncementIds, true)) {
                    $__announcementAlerts[] = $__announcement;
                }
            }
            $__announcementUnreadCount = count($__announcementAlerts);
        } catch (PDOException $e) {
            error_log("notifications announcement load error: " . $e->getMessage());
        }
    }
}

// Unread count badge is always based on active (non-archived) notifications
// visible to THIS user, regardless of which tab is currently open.
$unread_count = ($view === 'active')
    ? array_reduce($notifications, fn($c, $n) => $c + ($n['is_read'] == 0 ? 1 : 0), 0)
    : (function () use ($pdo, $__scopeSql, $__userId) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM notifications n WHERE is_archived = 0 AND is_read = 0 AND $__scopeSql");
        $s->execute([':uid' => $__userId]);
        return (int) $s->fetchColumn();
    })();
$__totalUnreadCount = $unread_count + $__announcementUnreadCount;
$__totalNotificationCount = count($notifications) + count($__announcementAlerts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications — HCI GroceryOne</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.notif-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}
.notif-list { display: flex; flex-direction: column; gap: 10px; }
.notif-item {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    background: var(--white);
    border: 1.5px solid var(--line);
    border-radius: 14px;
    padding: 16px 18px;
    transition: border-color .15s, box-shadow .15s;
    position: relative;
}
.notif-item.unread {
    border-color: var(--mustard);
    background: var(--mustard-pale);
}
.notif-item:hover { box-shadow: 0 4px 14px rgba(18,60,92,0.08); }

.notif-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.notif-icon.warn  { background: var(--mustard-pale); color: #a06b10; }
html[data-theme="dark"] .notif-icon.warn { color: var(--mustard); }
.notif-icon.info  { background: var(--green-pale); color: var(--text-accent); }
.notif-icon svg { width: 20px; height: 20px; }

.notif-body { flex: 1; }
.notif-msg  { font-size: 14.5px; font-weight: 500; color: var(--ink); margin-bottom: 4px; }
.notif-meta { font-size: 12px; color: var(--ink-soft); }
.notif-stock-badge {
    font-size: 12px;
    font-weight: 700;
    padding: 2px 9px;
    border-radius: 12px;
    margin-left: 8px;
}
.badge-warn { background: var(--mustard-pale); color: #8a5c10; }
html[data-theme="dark"] .badge-warn { color: var(--mustard); }

.notif-mark-btn {
    background: none;
    border: 1.5px solid var(--line);
    border-radius: 8px;
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 600;
    color: var(--ink-soft);
    cursor: pointer;
    transition: all .15s;
    white-space: nowrap;
}
.notif-mark-btn:hover { border-color: var(--green-mid); color: var(--green-mid); }

.unread-dot {
    width: 8px; height: 8px;
    background: var(--mustard);
    border-radius: 50%;
    position: absolute;
    top: 14px; right: 16px;
}

.empty-notif {
    text-align: center;
    padding: 60px 20px;
    color: var(--ink-soft);
}
.empty-notif svg { width: 48px; height: 48px; margin-bottom: 16px; opacity: .4; }
.empty-notif p { font-size: 15px; font-weight: 600; }
.empty-notif span { font-size: 13.5px; }

.action-btn--sm {
    padding: 9px 18px;
    font-size: 13px;
}
.action-btn--red {
    background: var(--red-alert);
    box-shadow: none;
}
.action-btn--red:hover { background: #a3391f; }
.action-btn--outline {
    background: var(--white);
    color: var(--text-accent);
    border: 1.5px solid var(--green-deep);
    box-shadow: none;
}
.action-btn--outline:hover { background: var(--green-pale); }
</style>
</head>
<body>
<div class="app-shell">

    <?php $active = 'notifications'; include 'includes/sidebar.php'; ?>

    <div class="main-area">
        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_notifications ?></div>
            <h1>Notifications <?php if ($view === 'active' && $__totalUnreadCount > 0): ?><span style="font-size:16px; background:var(--mustard); color:white; padding:3px 10px; border-radius:20px; margin-left:8px; font-family:var(--font-body);"><?= $__totalUnreadCount ?> new</span><?php endif; ?></h1>
            <div class="topbar-user"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'A') ?><?php endif; ?></span>
                <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Administrator') ?></span></div>
        </header>

        <main class="content">

            <div class="filter-tabs" style="margin-bottom:14px;">
                <a href="notifications.php" class="filter-tab <?= $view === 'active' ? 'is-active' : '' ?>">Active</a>
                <a href="notifications.php?view=archived" class="filter-tab <?= $view === 'archived' ? 'is-active' : '' ?>">Archived</a>
            </div>

            <div class="notif-toolbar">
                <p style="font-size:14px; color:var(--ink-soft);">
                    <?= $__totalNotificationCount ?> <?= $view === 'archived' ? 'archived ' : '' ?>notification<?= $__totalNotificationCount != 1 ? 's' : '' ?> total
                    <?php if ($view === 'active' && $__totalUnreadCount > 0): ?> — <strong><?= $__totalUnreadCount ?> unread</strong><?php endif; ?>
                </p>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <?php if ($view === 'active' && $__totalUnreadCount > 0): ?>
                        <form method="POST">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="mark_read">
                            <button type="submit" class="action-btn action-btn--sm action-btn--outline">Mark All as Read</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($view === 'active' && count($notifications) > 0): ?>
                        <form method="POST" onsubmit="return confirmAction(event, this, {icon:'warning', title:'Archive Read Notifications?', text:'Archive all read notifications? They will be removed from this list but kept in the database.', confirmButtonText:'Yes, archive', confirmButtonColor:'#C8472D'})">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="delete_read">
                            <button type="submit" class="action-btn action-btn--sm action-btn--red">Archive Read</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($__announcementAlerts)): ?>
                <section class="notif-list" aria-label="Unread company announcements" style="margin-bottom:16px;">
                    <?php foreach (array_slice($__announcementAlerts, 0, 5) as $__announcement): ?>
                        <article class="notif-item unread">
                            <div class="notif-icon info" aria-hidden="true">
                                <svg viewBox="0 0 20 20" fill="none"><path d="M3 8.5v3a1 1 0 001 1h1.5l5.5 3.2V4.3L5.5 7.5H4a1 1 0 00-1 1z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M14.5 8a2.3 2.3 0 010 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                            </div>
                            <div class="notif-body">
                                <div class="notif-msg"><?= clean(str_ireplace('Paskong Handog', 'Christmas Gift Giving', $__announcement['title'])) ?></div>
                                <div class="notif-meta"><?= clean(date('F d, Y', strtotime($__announcement['published_at']))) ?> · Company announcement</div>
                            </div>
                            <a class="action-btn action-btn--sm action-btn--outline" href="announcements.php">Read announcement</a>
                        </article>
                    <?php endforeach; ?>
                    <?php if (count($__announcementAlerts) > 5): ?>
                        <a href="announcements.php" class="action-btn action-btn--sm action-btn--outline">View all <?= (int) $__announcementUnreadCount ?> unread announcements</a>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if (empty($notifications) && empty($__announcementAlerts)): ?>
                <div class="empty-notif">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                    <p>No <?= $view === 'archived' ? 'archived ' : '' ?>notifications</p>
                    <span><?php
                        if ($view === 'archived') {
                            echo 'Notifications you archive will show up here.';
                        } elseif ($__role === 'cashier') {
                            echo 'Only notifications assigned directly to your account appear here. Shared inventory alerts stay with Admin and Inventory Staff.';
                        } elseif ($__role === 'finance') {
                            echo 'Expense handoffs and approvals assigned to you will appear here.';
                        } else {
                            echo 'Low-stock, HR performance, and workflow alerts will appear here.';
                        }
                    ?></span>
                </div>
            <?php elseif (!empty($notifications)): ?>
                <div class="notif-list">
                    <?php foreach ($notifications as $n): ?>
                        <div class="notif-item <?= ($view === 'active' && $n['is_read'] == 0) ? 'unread' : '' ?>">
                            <?php if ($view === 'active' && $n['is_read'] == 0): ?>
                                <div class="unread-dot"></div>
                            <?php endif; ?>
                            <div class="notif-icon <?= $n['category'] === 'performance' ? 'info' : ($n['category'] === 'workflow' ? 'info' : 'warn') ?>">
                                <?php if ($n['category'] === 'performance'): ?>
                                <svg viewBox="0 0 20 20" fill="none">
                                    <circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.6"/>
                                    <path d="M10 6v4l3 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <?php elseif ($n['category'] === 'workflow'): ?>
                                <svg viewBox="0 0 20 20" fill="none">
                                    <path d="M4 6.5h9.5a2.5 2.5 0 012.5 2.5v4a2.5 2.5 0 01-2.5 2.5H6a2 2 0 01-2-2v-7z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                                    <path d="M4 6.5V5a1.5 1.5 0 011.5-1.5H12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                                </svg>
                                <?php else: ?>
                                <svg viewBox="0 0 20 20" fill="none">
                                    <path d="M10 3l8 14H2L10 3z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                                    <path d="M10 8v4M10 14.5v.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                                </svg>
                                <?php endif; ?>
                            </div>
                            <div class="notif-body">
                                <div class="notif-msg">
                                    <?= clean($n['message']) ?>
                                    <?php if ($n['stock'] !== null && $n['stock'] <= LOW_STOCK && $n['stock'] > 0): ?>
                                        <span class="notif-stock-badge badge-warn"><?= $n['stock'] ?> pcs left</span>
                                    <?php endif; ?>
                                    <?php if (!empty($n['link_url'])): ?>
                                        <a href="<?= htmlspecialchars($n['link_url']) ?>" style="font-size:12.5px; font-weight:700; color:var(--green-mid); margin-left:6px;">View →</a>
                                    <?php endif; ?>
                                </div>
                                <div class="notif-meta"><?= date('F d, Y — h:i A', strtotime($n['date_created'])) ?></div>
                            </div>
                            <?php if ($view === 'archived'): ?>
                                <form method="POST">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="unarchive">
                                    <input type="hidden" name="notif_id" value="<?= $n['id'] ?>">
                                    <button type="submit" class="notif-mark-btn">Restore</button>
                                </form>
                            <?php elseif ($n['is_read'] == 0): ?>
                                <form method="POST">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="mark_one_read">
                                    <input type="hidden" name="notif_id" value="<?= $n['id'] ?>">
                                    <button type="submit" class="notif-mark-btn">Mark as read</button>
                                </form>
                            <?php else: ?>
                                <span style="font-size:12px; color:var(--green-leaf); font-weight:600;">✓ Read</span>
                            <?php endif; ?>
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
function confirmAction(e, form, opts) {
    e.preventDefault();
    Swal.fire(Object.assign({
        showCancelButton: true,
        cancelButtonText: 'Cancel'
    }, opts)).then(function (result) {
        if (result.isConfirmed) form.submit();
    });
    return false;
}
</script>
</body>
</html>