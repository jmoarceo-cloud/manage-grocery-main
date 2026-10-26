<?php
require_once 'includes/config.php';
requireLogin();

$role = (string) ($_SESSION['role'] ?? '');
$hrEventRoles = ['admin', 'hr', 'hr_manager'];
$liveInboxRoles = ['supervisor', 'employee', 'accountant'];
if (!in_array($role, array_merge($hrEventRoles, $liveInboxRoles), true)) {
    redirect(appBaseUrl() . '/login.php');
}

$success_message = "";
$error_message   = "";
$view = in_array($role, $hrEventRoles, true) && ($_GET['view'] ?? '') === 'archived' ? 'archived' : 'active';
$notifications = [];
$unread_count = 0;
$liveInbox = in_array($role, $liveInboxRoles, true);
$liveItemLabel = $role === 'supervisor' ? 'team update' : ($role === 'employee' ? 'personal update' : 'payroll item');

if (in_array($role, $hrEventRoles, true)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCSRF();
        $action = $_POST['action'] ?? '';
        if ($posPdo) {
            try {
                if ($action === 'mark_read') {
                    $posPdo->query("UPDATE notifications SET is_read = 1 WHERE is_read = 0 AND category IN ('performance','applicant','hr_letter')");
                } elseif ($action === 'mark_one_read' && isset($_POST['notif_id'])) {
                    $posPdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND category IN ('performance','applicant','hr_letter')")
                        ->execute([$_POST['notif_id']]);
                } elseif ($action === 'delete_read') {
                    $posPdo->query("UPDATE notifications SET is_archived = 1 WHERE is_read = 1 AND category IN ('performance','applicant','hr_letter')");
                } elseif ($action === 'unarchive' && isset($_POST['notif_id'])) {
                    $posPdo->prepare("UPDATE notifications SET is_archived = 0 WHERE id = ? AND category IN ('performance','applicant','hr_letter')")
                        ->execute([$_POST['notif_id']]);
                }
            } catch (PDOException $e) {
                error_log("hr_notifications action error: " . $e->getMessage());
                $error_message = "Could not update that notification.";
            }
            header('Location: hr_notifications.php' . ((($_GET['view'] ?? '') === 'archived') ? '?view=archived' : ''));
            exit();
        }
        $error_message = "Could not connect to the POS database, so that notification could not be updated.";
    }

    if (!$posPdo) {
        $error_message = "Could not connect to the POS database, so notifications can't be loaded right now.";
    } else {
        try {
            $stmt = $posPdo->prepare("
                SELECT * FROM notifications
                WHERE category IN ('performance','applicant','hr_letter') AND is_archived = :archived
                ORDER BY date_created DESC
            ");
            $stmt->execute([':archived' => $view === 'archived' ? 1 : 0]);
            $notifications = $stmt->fetchAll();
            $unread_count = ($view === 'active')
                ? array_reduce($notifications, fn($c, $n) => $c + ($n['is_read'] == 0 ? 1 : 0), 0)
                : (int) $posPdo->query("SELECT COUNT(*) FROM notifications WHERE category IN ('performance','applicant','hr_letter') AND is_archived = 0 AND is_read = 0")->fetchColumn();
        } catch (PDOException $e) {
            error_log("hr_notifications load error: " . $e->getMessage());
            $error_message = "Could not load notifications right now.";
        }
    }
}

if ($role === 'supervisor') {
    $teamEmployeeIds = getSupervisorTeamEmployeeIds($pdo, (int) ($_SESSION['employee_id'] ?? 0));
    if ($teamEmployeeIds) {
        $placeholders = implode(',', array_fill(0, count($teamEmployeeIds), '?'));
        $requestSources = [
            ['leave_requests', 'Leave request', 'supervisor_leave.php', 'leave_type'],
            ['overtime_requests', 'Overtime request', 'supervisor_overtime.php', 'hours_requested'],
            ['official_business_requests', 'Official business request', 'supervisor_official_business.php', 'purpose'],
            ['late_timein_requests', 'Late time-in request', 'supervisor_late_timein.php', 'requested_time'],
        ];
        foreach ($requestSources as [$table, $label, $url, $detailColumn]) {
            $stmt = $pdo->prepare("
                SELECT r.*, e.first_name, e.last_name
                FROM {$table} r
                JOIN employees e ON e.employee_id = r.employee_id
                WHERE r.status = 'pending' AND r.employee_id IN ({$placeholders})
                ORDER BY r.requested_at DESC
            ");
            $stmt->execute($teamEmployeeIds);
            foreach ($stmt->fetchAll() as $request) {
                $employeeName = trim($request['first_name'] . ' ' . $request['last_name']);
                $detail = match ($table) {
                    'leave_requests' => ucfirst((string) $request[$detailColumn]) . ' · ' . date('M j', strtotime($request['date_from'])) . '–' . date('M j, Y', strtotime($request['date_to'])),
                    'overtime_requests' => number_format((float) $request[$detailColumn], 2) . ' hours · ' . date('M j, Y', strtotime($request['ot_date'])),
                    'official_business_requests' => (string) $request[$detailColumn] . ' · ' . date('M j, Y', strtotime($request['ob_date'])),
                    default => date('M j, Y', strtotime($request['request_date'])) . ' · ' . date('g:i A', strtotime($request[$detailColumn])),
                };
                $notifications[] = [
                    'message' => $employeeName . ' submitted a ' . strtolower($label) . '. ' . $detail,
                    'date_created' => $request['requested_at'],
                    'category' => $table,
                    'link_url' => $url,
                    'status_label' => 'Needs review',
                ];
            }
        }
    }

    if ($posPdo && !empty($_SESSION['employee_id'])) {
        try {
            $stmt = $posPdo->prepare("
                SELECT item_name, quantity, status, requested_at, decided_at
                FROM inventory_requests
                WHERE requested_by_employee_id = ?
                ORDER BY COALESCE(decided_at, requested_at) DESC
                LIMIT 20
            ");
            $stmt->execute([(int) $_SESSION['employee_id']]);
            foreach ($stmt->fetchAll() as $request) {
                $notifications[] = [
                    'message' => 'Restock request for ' . $request['item_name'] . ' (' . (int) $request['quantity'] . ' units) is ' . str_replace('_', ' ', $request['status']) . '.',
                    'date_created' => $request['decided_at'] ?: $request['requested_at'],
                    'category' => 'inventory',
                    'link_url' => 'supervisor_inventory.php',
                    'status_label' => ucfirst(str_replace('_', ' ', $request['status'])),
                ];
            }
        } catch (PDOException $e) {
            error_log("hr_notifications supervisor inventory load error: " . $e->getMessage());
            $error_message = "Team notifications loaded, but inventory updates could not be loaded.";
        }
    }
    if ($teamEmployeeIds) {
        $placeholders = implode(',', array_fill(0, count($teamEmployeeIds), '?'));
        $stmt = $pdo->prepare("
            SELECT p.record_type, p.period_label, p.rating, p.comments, p.created_at, e.first_name, e.last_name
            FROM performance_records p
            JOIN employees e ON e.employee_id = p.employee_id
            WHERE p.employee_id IN ({$placeholders})
              AND p.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            ORDER BY p.created_at DESC
            LIMIT 30
        ");
        $stmt->execute($teamEmployeeIds);
        foreach ($stmt->fetchAll() as $record) {
            $notifications[] = [
                'message' => trim($record['first_name'] . ' ' . $record['last_name']) . ' received a ' . ucfirst($record['record_type']) . ' for ' . $record['period_label'] . '.',
                'date_created' => $record['created_at'],
                'category' => 'performance',
                'link_url' => 'supervisor_performance.php',
                'status_label' => ucfirst($record['record_type']),
            ];
        }
    }
    usort($notifications, fn($a, $b) => strtotime($b['date_created']) <=> strtotime($a['date_created']));
    $unread_count = count(array_filter($notifications, fn($n) => $n['status_label'] === 'Needs review'));
} elseif ($role === 'employee') {
    $employeeId = (int) ($_SESSION['employee_id'] ?? 0);
    if ($employeeId > 0) {
        $requestSources = [
            ['leave_requests', 'Leave request', "CONCAT(leave_type, ' leave · ', DATE_FORMAT(date_from, '%b %e'), '–', DATE_FORMAT(date_to, '%b %e, %Y'))"],
            ['overtime_requests', 'Overtime request', "CONCAT(hours_requested, ' hours · ', DATE_FORMAT(ot_date, '%b %e, %Y'))"],
            ['official_business_requests', 'Official business request', "CONCAT(purpose, ' · ', DATE_FORMAT(ob_date, '%b %e, %Y'))"],
            ['late_timein_requests', 'Late time-in request', "CONCAT(DATE_FORMAT(request_date, '%b %e, %Y'), ' · ', TIME_FORMAT(requested_time, '%h:%i %p'))"],
        ];
        foreach ($requestSources as [$table, $label, $detailSql]) {
            $stmt = $pdo->prepare("
                SELECT status, requested_at, decided_at, {$detailSql} AS detail
                FROM {$table}
                WHERE employee_id = ?
                  AND (status = 'pending' OR COALESCE(decided_at, requested_at) >= DATE_SUB(NOW(), INTERVAL 90 DAY))
                ORDER BY COALESCE(decided_at, requested_at) DESC
                LIMIT 25
            ");
            $stmt->execute([$employeeId]);
            foreach ($stmt->fetchAll() as $request) {
                $notifications[] = [
                    'message' => $label . ': ' . $request['detail'],
                    'date_created' => $request['decided_at'] ?: $request['requested_at'],
                    'category' => $table,
                    'link_url' => 'employee_dashboard.php',
                    'status_label' => ucfirst((string) $request['status']),
                ];
            }
        }
        $stmt = $pdo->prepare("
            SELECT record_type, period_label, rating, comments, created_at
            FROM performance_records
            WHERE employee_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            ORDER BY created_at DESC
            LIMIT 15
        ");
        $stmt->execute([$employeeId]);
        foreach ($stmt->fetchAll() as $record) {
            $notifications[] = [
                'message' => ucfirst($record['record_type']) . ' recorded for ' . $record['period_label'] . '.',
                'date_created' => $record['created_at'],
                'category' => 'performance',
                'link_url' => 'employee_dashboard.php',
                'status_label' => ucfirst($record['record_type']),
            ];
        }
        $stmt = $pdo->prepare("
            SELECT date, status, late_minutes
            FROM attendance
            WHERE employee_id = ?
              AND date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
              AND (status = 'absent' OR late_minutes > 0)
            ORDER BY date DESC
            LIMIT 10
        ");
        $stmt->execute([$employeeId]);
        foreach ($stmt->fetchAll() as $attendance) {
            $statusLabel = $attendance['status'] === 'absent'
                ? 'Absent'
                : 'Late by ' . (int) $attendance['late_minutes'] . ' minutes';
            $notifications[] = [
                'message' => 'Attendance recorded as ' . $statusLabel . ' for ' . date('M j, Y', strtotime($attendance['date'])) . '.',
                'date_created' => $attendance['date'] . ' 23:59:59',
                'category' => 'attendance',
                'link_url' => 'employee_dashboard.php#attendanceToday',
                'status_label' => $statusLabel,
            ];
        }
        $upcomingShifts = [];
        for ($dayOffset = 0; $dayOffset < 14 && count($upcomingShifts) < 3; $dayOffset++) {
            $shiftDate = date('Y-m-d', strtotime('+' . $dayOffset . ' days'));
            $shift = getEmployeeShiftForDate($pdo, $employeeId, $shiftDate);
            if ($shift && strtotime($shiftDate . ' ' . $shift['start_time']) > time()) {
                $upcomingShifts[] = [
                    'message' => 'Upcoming shift: ' . $shift['shift_name'] . ' (' . date('g:i A', strtotime($shift['start_time'])) . '–' . date('g:i A', strtotime($shift['end_time'])) . ') on ' . date('M j, Y', strtotime($shiftDate)) . '.',
                    'date_created' => $shiftDate . ' ' . $shift['start_time'],
                    'category' => 'schedule',
                    'link_url' => 'employee_dashboard.php#attendanceToday',
                    'status_label' => 'Upcoming',
                ];
            }
        }
        $notifications = array_merge($notifications, $upcomingShifts);
        $stmt = $pdo->prepare("
            SELECT pay_period_start, pay_period_end, payout_date, payment_status
            FROM payroll_records
            WHERE employee_id = ? AND approval_status = 'approved'
              AND (
                (payment_status = 'paid' AND payout_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY))
                OR (payment_status = 'unpaid' AND payout_date > CURDATE())
              )
            ORDER BY payout_date DESC
            LIMIT 10
        ");
        $stmt->execute([$employeeId]);
        foreach ($stmt->fetchAll() as $payroll) {
            $notifications[] = [
                'message' => ($payroll['payment_status'] === 'paid' ? 'Payslip released' : 'Upcoming payslip') . ' for ' . date('M j', strtotime($payroll['pay_period_start'])) . '–' . date('M j, Y', strtotime($payroll['pay_period_end'])) . '.',
                'date_created' => $payroll['payout_date'],
                'category' => 'payroll',
                'link_url' => 'employee_dashboard.php',
                'status_label' => $payroll['payment_status'] === 'paid' ? 'Released' : 'Upcoming',
            ];
        }
    }
    usort($notifications, function ($a, $b) {
        if ($a['category'] === 'schedule' && $b['category'] === 'schedule') {
            return strtotime($a['date_created']) <=> strtotime($b['date_created']);
        }
        return strtotime($b['date_created']) <=> strtotime($a['date_created']);
    });
    $unread_count = count(array_filter($notifications, fn($n) => strtolower($n['status_label']) === 'pending'));
} elseif ($role === 'accountant') {
    foreach (getPendingPayrollApprovals($pdo) as $batch) {
        $notifications[] = [
            'message' => 'Payroll for ' . $batch['periodLabel'] . ' is awaiting your review (' . count($batch['rows']) . ' employees).',
            'date_created' => $batch['submittedAt'] ?: $batch['periodEnd'],
            'category' => 'payroll',
            'link_url' => 'payroll/accountant.php',
            'status_label' => 'Needs review',
        ];
    }
    $unread_count = count($notifications);
}

$activeNav = $role === 'supervisor' ? 'notifications' : 'hr_notifications';
$useHrSidebar = in_array($role, ['admin', 'hr', 'hr_manager', 'accountant'], true);
$useSupervisorSidebar = $role === 'supervisor';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications — HRMS &amp; Payroll</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php include 'includes/supervisor_style.php'; ?>
<style>
.role-notifications--employee .main-area { margin-left: 0; }
.role-notifications--employee .container { width: 100%; max-width: 1100px; margin: 0 auto; }
.role-notifications--employee .topbar { gap: 12px; }
.notification-description { color: var(--ink-soft); font-size: 13.5px; line-height: 1.6; margin: 0 0 18px; }
.notification-item { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; padding: 14px 16px; border: 1.5px solid var(--line); border-radius: 12px; background: var(--white); }
.notification-item + .notification-item { margin-top: 10px; }
.notification-copy { min-width: 0; }
.notification-message { color: var(--ink); font-size: 14px; font-weight: 600; line-height: 1.5; overflow-wrap: anywhere; }
.notification-meta { color: var(--ink-soft); font-size: 12px; margin-top: 5px; }
.notification-status { flex: 0 0 auto; padding: 5px 9px; border-radius: 999px; background: var(--green-pale); color: var(--text-accent); font-size: 11px; font-weight: 700; white-space: nowrap; }
.notification-status--pending { background: var(--mustard-pale); color: #80550e; }
html[data-theme="dark"] .notification-status--pending { color: #F5C66E; }
.notification-view { flex: 0 0 auto; align-self: center; color: var(--green-mid); font-size: 12.5px; font-weight: 700; white-space: nowrap; }
.notification-heading { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
@media (max-width: 640px) {
    .role-notifications--employee .topbar { padding: 14px 16px; }
    .role-notifications--employee .topbar .user-info { width: 100%; flex-wrap: wrap; gap: 10px; }
    .role-notifications--employee .topbar .user-info > span:last-child { min-width: 0; overflow-wrap: anywhere; }
    .role-notifications--employee .container { padding: 20px 16px 40px; }
    .notification-item { flex-wrap: wrap; padding: 13px; }
    .notification-view { margin-left: auto; }
}
</style>
</head>
<body>
<div class="app-shell<?= $role === 'employee' ? ' role-notifications--employee' : '' ?>">
    <?php if ($useHrSidebar): ?>
        <?php include 'includes/hr_sidebar.php'; ?>
    <?php elseif ($useSupervisorSidebar): ?>
        <?php include 'includes/supervisor_sidebar.php'; ?>
    <?php endif; ?>
    <div class="main-area">
        <div class="topbar">
            <h1 class="notification-heading">Notifications <?php if ($view === 'active' && $unread_count > 0): ?><span class="badge" style="background:var(--mustard); color: #FFFFFF;"><?= $unread_count ?> new</span><?php endif; ?></h1>
            <?php if ($role === 'employee'): ?>
                <div class="user-info">
                    <a href="employee_dashboard.php" class="btn btn-success btn-sm">Back to dashboard</a>
                    <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span>
                    <span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($role)) ?>)</span>
                </div>
            <?php else: ?>
                <div class="user-info"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span><span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($role)) ?>)</span></div>
            <?php endif; ?>
        </div>
        <div class="container">

            <?php if (!empty($success_message)): ?><div class="alert alert--success"><?= clean($success_message) ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="alert alert--error"><?= clean($error_message) ?></div><?php endif; ?>

            <?php if ($liveInbox): ?>
                <p class="notification-description">
                    <?php if ($role === 'supervisor'): ?>
                        Current team approvals, recent team performance updates, and your restock requests, scoped to your assigned employees.
                    <?php elseif ($role === 'employee'): ?>
                        Your request decisions, attendance exceptions, upcoming shifts, performance records, and payslip updates from your own HRMS records.
                    <?php else: ?>
                        Payroll batches currently awaiting Accountant review.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <div class="filter-tabs" style="margin-bottom:14px; display:flex; gap:8px;">
                    <a href="hr_notifications.php" class="tab-btn <?= $view === 'active' ? 'active' : '' ?>">Active</a>
                    <a href="hr_notifications.php?view=archived" class="tab-btn <?= $view === 'archived' ? 'active' : '' ?>">Archived</a>
                </div>
            <?php endif; ?>

            <div class="section">
                <div class="section-header">
                    <h2><?= count($notifications) ?> <?= $liveInbox ? ($role === 'accountant' ? 'pending ' : 'recent ') . $liveItemLabel : (($view === 'archived' ? 'archived ' : '') . 'notification') ?><?= count($notifications) !== 1 ? 's' : '' ?></h2>
                    <?php if (!$liveInbox): ?><div style="display:flex; gap:10px;">
                        <?php if ($posPdo && $view === 'active' && $unread_count > 0): ?>
                            <form method="POST">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="mark_read">
                                <button type="submit" class="btn btn-success btn-sm">Mark All as Read</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($posPdo && $view === 'active' && count($notifications) > 0): ?>
                            <form method="POST" data-confirm="Archive all read notifications?" data-confirm-title="Archive Read" data-confirm-danger="1" data-confirm-ok="Yes, archive">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="delete_read">
                                <button type="submit" class="btn btn-danger btn-sm">Archive Read</button>
                            </form>
                        <?php endif; ?>
                    </div><?php endif; ?>
                </div>

                <?php if (empty($notifications)): ?>
                    <div class="empty-state">
                        <?php if ($role === 'supervisor'): ?>
                            No pending team approvals or inventory request updates right now.
                        <?php elseif ($role === 'employee'): ?>
                            No recent requests or payslip updates are available.
                        <?php elseif ($role === 'accountant'): ?>
                            No payroll batches are awaiting review.
                        <?php else: ?>
                            No <?= $view === 'archived' ? 'archived ' : '' ?>notifications yet. Merit/Demerit records and applicant requirement submissions will show up here.
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-card" style="padding:16px; display:flex; flex-direction:column; gap:10px;">
                        <?php foreach ($notifications as $n): ?>
                            <div class="<?= $liveInbox ? 'notification-item' : '' ?>" style="<?= !$liveInbox ? 'display:flex; align-items:flex-start; gap:14px; padding:14px 16px; border:1.5px solid var(--line); border-radius:12px; ' . (($view === 'active' && $n['is_read'] == 0) ? 'border-color:var(--mustard); background:var(--mustard-pale);' : '') : '' ?>">
                                <div style="flex:1;">
                                    <div class="<?= $liveInbox ? 'notification-message' : '' ?>" style="<?= !$liveInbox ? 'font-size:14.5px; font-weight:500; color:var(--ink);' : '' ?>"><?= clean($n['message']) ?></div>
                                    <div style="font-size:12px; color:var(--ink-soft); margin-top:4px;"><?= clean(date('F d, Y — h:i A', strtotime($n['date_created']))) ?></div>
                                </div>
                                <?php if ($liveInbox): ?>
                                    <span class="notification-status<?= strtolower((string) $n['status_label']) === 'pending' || $n['status_label'] === 'Needs review' ? ' notification-status--pending' : '' ?>"><?= clean($n['status_label']) ?></span>
                                    <?php if (!empty($n['link_url'])): ?><a class="notification-view" href="<?= htmlspecialchars($n['link_url'], ENT_QUOTES, 'UTF-8') ?>">View →</a><?php endif; ?>
                                <?php elseif ($view === 'archived'): ?>
                                    <form method="POST">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="unarchive">
                                        <input type="hidden" name="notif_id" value="<?= $n['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">Restore</button>
                                    </form>
                                <?php elseif ($n['is_read'] == 0): ?>
                                    <form method="POST">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="mark_one_read">
                                        <input type="hidden" name="notif_id" value="<?= $n['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">Mark as read</button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size:12px; color:var(--green-mid); font-weight:600;">&#10003; Read</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>
<?php include 'includes/sweetalert.php'; ?>
</body>
</html>