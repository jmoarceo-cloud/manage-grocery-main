<?php
require_once 'includes/config.php';
requireSupervisorRole();

$supervisorEmployeeId = isSupervisorRole() ? (int) ($_SESSION['employee_id'] ?? 0) : dec_id($_GET['supervisor_id'] ?? '');
// Admin browsing without picking a specific Supervisor sees every
// Supervisor's team combined, instead of an empty page.
$viewingAllTeams = isAdminRole() && $supervisorEmployeeId === 0;
$teamIds = $viewingAllTeams ? getAllSupervisedEmployeeIds($pdo) : getSupervisorTeamEmployeeIds($pdo, $supervisorEmployeeId);

$success_message = "";
$error_message   = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';
    if ($action === 'approve_leave' || $action === 'reject_leave') {
        $leave_id   = $_POST['leave_id'] ?? 0;
        $new_status = $action === 'approve_leave' ? 'approved' : 'rejected';
        $result = processLeaveDecisionForSupervisor($pdo, $leave_id, $new_status, $_SESSION['user_id'], $supervisorEmployeeId, $viewingAllTeams);
        if ($result['ok']) { $success_message = $result['message']; } else { $error_message = $result['message']; }
    }
}

function getLeaveTypeLabel($type) {
    $map = ['sick' => 'Sick Leave', 'vacation' => 'Vacation Leave', 'emergency' => 'Emergency Leave', 'others' => 'Others'];
    return $map[$type] ?? ucfirst($type);
}
function daysBetween($from, $to) { return (strtotime($to) - strtotime($from)) / 86400 + 1; }

$pendingLeaves = [];
$decidedLeaves = [];
if (!empty($teamIds)) {
    $ph = implode(',', array_fill(0, count($teamIds), '?'));
    $stmt = $pdo->prepare("
        SELECT lr.*, e.first_name, e.last_name, e.employee_code
        FROM leave_requests lr JOIN employees e ON e.employee_id = lr.employee_id
        WHERE lr.status = 'pending' AND lr.employee_id IN ($ph)
        ORDER BY lr.requested_at DESC
    ");
    $stmt->execute($teamIds);
    $pendingLeaves = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT lr.*, e.first_name, e.last_name, e.employee_code, u.full_name AS approver_name
        FROM leave_requests lr JOIN employees e ON e.employee_id = lr.employee_id
        LEFT JOIN users u ON u.id = lr.approved_by
        WHERE lr.status != 'pending' AND lr.employee_id IN ($ph)
        ORDER BY lr.decided_at DESC LIMIT 30
    ");
    $stmt->execute($teamIds);
    $decidedLeaves = $stmt->fetchAll();
}

$activeNav = 'leave';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leave Requests — Supervisor</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php include 'includes/supervisor_style.php'; ?>
</head>
<body>
<div class="app-shell">
    <?php include 'includes/supervisor_sidebar.php'; ?>
    <div class="main-area">
        <div class="topbar">
            <h1>Leave Requests — <?= $viewingAllTeams ? 'All Teams' : 'My Team' ?></h1>
            <div class="user-info"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span><span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span></div>
        </div>
        <div class="container">

            <?php if (!empty($success_message)): ?><div class="alert alert--success"><?= clean($success_message) ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="alert alert--error"><?= clean($error_message) ?></div><?php endif; ?>
            <?php if ($viewingAllTeams): ?><div class="alert" style="background:var(--blue-bg, #EAF2FB); color:var(--blue-text, #1F4E8C);">Viewing every Supervisor's team combined. Approve/reject works as usual — just open a specific Supervisor's page (via Supervisor Teams) if you need to see who approved what.</div><?php endif; ?>

            <div class="section">
                <div class="section-header"><h2>Pending Requests</h2></div>
                <div class="table-card"><div class="table-scroll">
                    <table>
                        <thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th>Days</th><th>Reason</th><th>Requested</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php if (empty($pendingLeaves)): ?>
                            <tr><td colspan="7"><div class="empty-state">No pending leave requests from your team.</div></td></tr>
                        <?php else: foreach ($pendingLeaves as $lv): ?>
                            <tr>
                                <td><?= clean($lv['last_name'] . ', ' . $lv['first_name']) ?><br><span style="color:var(--ink-soft); font-size:12px;"><?= clean($lv['employee_code']) ?></span></td>
                                <td><?= clean(getLeaveTypeLabel($lv['leave_type'])) ?></td>
                                <td><?= clean(date('M d', strtotime($lv['date_from']))) ?>–<?= clean(date('M d, Y', strtotime($lv['date_to']))) ?></td>
                                <td><?= daysBetween($lv['date_from'], $lv['date_to']) ?></td>
                                <td style="max-width:220px;"><?= clean($lv['reason'] ?: '—') ?></td>
                                <td><?= clean(date('M d, Y', strtotime($lv['requested_at']))) ?></td>
                                <td class="actions-cell">
                                    <form method="POST" style="display:inline;">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="approve_leave">
                                        <input type="hidden" name="leave_id" value="<?= $lv['leave_id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                    </form>
                                    <form method="POST" style="display:inline;" data-confirm="Reject this leave request?" data-confirm-title="Reject Leave" data-confirm-danger="1" data-confirm-ok="Yes, reject">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="reject_leave">
                                        <input type="hidden" name="leave_id" value="<?= $lv['leave_id'] ?>">
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
                        <thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th>Status</th><th>Decided On</th><th>Decided By</th></tr></thead>
                        <tbody>
                        <?php if (empty($decidedLeaves)): ?>
                            <tr><td colspan="6"><div class="empty-state">No processed leave requests yet.</div></td></tr>
                        <?php else: foreach ($decidedLeaves as $lv): ?>
                            <tr>
                                <td><?= clean($lv['last_name'] . ', ' . $lv['first_name']) ?></td>
                                <td><?= clean(getLeaveTypeLabel($lv['leave_type'])) ?></td>
                                <td><?= clean(date('M d', strtotime($lv['date_from']))) ?>–<?= clean(date('M d, Y', strtotime($lv['date_to']))) ?></td>
                                <td><span class="badge" style="background:<?= $lv['status'] == 'approved' ? 'var(--green-pale)' : 'var(--red-pale)' ?>; color:<?= $lv['status'] == 'approved' ? 'var(--text-accent)' : 'var(--red-alert)' ?>;"><?= ucfirst($lv['status']) ?></span></td>
                                <td><?= !empty($lv['decided_at']) ? clean(date('M d, Y g:i A', strtotime($lv['decided_at']))) : '—' ?></td>
                                <td><?= clean($lv['approver_name'] ?: '—') ?></td>
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