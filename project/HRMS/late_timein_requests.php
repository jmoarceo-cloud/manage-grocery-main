<?php
require_once 'includes/config.php';
requireHR(); // admin, hr, hr_manager — same tier chain as attendance scoping

$__role = $_SESSION['role'];
$scopeIds = getLateTimeInApprovalScopeEmployeeIds($pdo, $__role);

$success_message = "";
$error_message   = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_timein' || $action === 'reject_timein') {
        $request_id = $_POST['request_id'] ?? 0;
        $new_status = $action === 'approve_timein' ? 'approved' : 'rejected';
        $result = processLateTimeInDecisionGeneric($pdo, $request_id, $new_status, $_SESSION['user_id'], $scopeIds);
        if ($result['ok']) { $success_message = $result['message']; } else { $error_message = $result['message']; }
    }
}

$pendingRequests = [];
$decidedRequests = [];
if (!empty($scopeIds)) {
    $ph = implode(',', array_fill(0, count($scopeIds), '?'));

    $stmt = $pdo->prepare("
        SELECT r.*, e.first_name, e.last_name, e.employee_code, s.shift_name AS requested_shift_name
        FROM late_timein_requests r JOIN employees e ON e.employee_id = r.employee_id
        LEFT JOIN shifts s ON s.shift_id = r.requested_shift_id
        WHERE r.status = 'pending' AND r.employee_id IN ($ph)
        ORDER BY r.requested_at DESC
    ");
    $stmt->execute($scopeIds);
    $pendingRequests = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT r.*, e.first_name, e.last_name, e.employee_code, u.full_name AS approver_name, s.shift_name AS requested_shift_name
        FROM late_timein_requests r JOIN employees e ON e.employee_id = r.employee_id
        LEFT JOIN users u ON u.id = r.approved_by
        LEFT JOIN shifts s ON s.shift_id = r.requested_shift_id
        WHERE r.status != 'pending' AND r.employee_id IN ($ph)
        ORDER BY r.decided_at DESC LIMIT 30
    ");
    $stmt->execute($scopeIds);
    $decidedRequests = $stmt->fetchAll();
}

$__pageHeading = $__role === 'hr' ? 'Time In Requests — Supervisors'
    : ($__role === 'hr_manager' ? 'Time In Requests — HR Staff'
    : 'Time In Requests — All Employees');

$activeNav = 'late_timein';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Time In Requests — HRMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php include 'includes/supervisor_style.php'; ?>
</head>
<body>
<div class="app-shell">
    <?php include 'includes/hr_sidebar.php'; ?>
    <div class="main-area">
        <div class="topbar">
            <h1><?= clean($__pageHeading) ?></h1>
            <div class="user-info"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span><span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span></div>
        </div>
        <div class="container">

            <?php if (!empty($success_message)): ?><div class="alert alert--success"><?= clean($success_message) ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="alert alert--error"><?= clean($error_message) ?></div><?php endif; ?>
            <?php if ($__role === 'admin'): ?><div class="alert" style="background:var(--blue-bg, #EAF2FB); color:var(--blue-text, #1F4E8C);">Viewing Time In requests from every employee, across every tier.</div><?php endif; ?>

            <div class="section">
                <div class="section-header"><h2>Pending Requests</h2></div>
                <div class="table-card"><div class="table-scroll">
                    <table>
                        <thead><tr><th>Employee</th><th>Date</th><th>Requested Shift</th><th>Reason</th><th>Requested</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php if (empty($pendingRequests)): ?>
                            <tr><td colspan="6"><div class="empty-state">No pending Time In requests.</div></td></tr>
                        <?php else: foreach ($pendingRequests as $r): ?>
                            <tr>
                                <td><?= clean($r['last_name'] . ', ' . $r['first_name']) ?><br><span style="color:var(--ink-soft); font-size:12px;"><?= clean($r['employee_code']) ?></span></td>
                                <td><?= clean(date('M d, Y', strtotime($r['request_date']))) ?></td>
                                <td><?= clean($r['requested_shift_name'] ?? '—') ?> <span style="color:var(--ink-soft); font-size:12px;">(<?= clean(date('h:i A', strtotime($r['requested_time']))) ?>)</span></td>
                                <td style="max-width:220px;"><?= clean($r['reason'] ?: '—') ?></td>
                                <td><?= clean(date('M d, Y g:i A', strtotime($r['requested_at']))) ?></td>
                                <td class="actions-cell">
                                    <form method="POST" style="display:inline;">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="approve_timein">
                                        <input type="hidden" name="request_id" value="<?= $r['request_id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                    </form>
                                    <form method="POST" style="display:inline;" data-confirm="Reject this Time In request?" data-confirm-title="Reject Time In Request" data-confirm-danger="1" data-confirm-ok="Yes, reject">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="reject_timein">
                                        <input type="hidden" name="request_id" value="<?= $r['request_id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div></div>
            </div>

            <div class="section">
                <div class="section-header"><h2>Recent Decisions</h2></div>
                <div class="table-card"><div class="table-scroll">
                    <table>
                        <thead><tr><th>Employee</th><th>Date</th><th>Requested Shift</th><th>Status</th><th>Decided On</th><th>Decided By</th></tr></thead>
                        <tbody>
                        <?php if (empty($decidedRequests)): ?>
                            <tr><td colspan="6"><div class="empty-state">No processed Time In requests yet.</div></td></tr>
                        <?php else: foreach ($decidedRequests as $r): ?>
                            <tr>
                                <td><?= clean($r['last_name'] . ', ' . $r['first_name']) ?></td>
                                <td><?= clean(date('M d, Y', strtotime($r['request_date']))) ?></td>
                                <td><?= clean($r['requested_shift_name'] ?? '—') ?> <span style="color:var(--ink-soft); font-size:12px;">(<?= clean(date('h:i A', strtotime($r['requested_time']))) ?>)</span></td>
                                <td><span class="badge" style="background:<?= $r['status'] == 'approved' ? 'var(--green-pale)' : 'var(--red-pale)' ?>; color:<?= $r['status'] == 'approved' ? 'var(--text-accent)' : 'var(--red-alert)' ?>;"><?= ucfirst($r['status']) ?></span></td>
                                <td><?= !empty($r['decided_at']) ? clean(date('M d, Y g:i A', strtotime($r['decided_at']))) : '—' ?></td>
                                <td><?= clean($r['approver_name'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div></div>
            </div>

        </div>
    </div>
</div>
<?php include 'includes/sweetalert.php'; ?>
</body>
</html>
