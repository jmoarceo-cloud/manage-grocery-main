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
    if ($action === 'approve_ob' || $action === 'reject_ob') {
        $ob_id      = $_POST['ob_id'] ?? 0;
        $new_status = $action === 'approve_ob' ? 'approved' : 'rejected';
        $result = processOfficialBusinessDecisionForSupervisor($pdo, $ob_id, $new_status, $_SESSION['user_id'], $supervisorEmployeeId, $viewingAllTeams);
        if ($result['ok']) { $success_message = $result['message']; } else { $error_message = $result['message']; }
    }
}

$pendingOB = [];
$decidedOB = [];
if (!empty($teamIds)) {
    $ph = implode(',', array_fill(0, count($teamIds), '?'));
    $stmt = $pdo->prepare("
        SELECT ob.*, e.first_name, e.last_name, e.employee_code
        FROM official_business_requests ob JOIN employees e ON e.employee_id = ob.employee_id
        WHERE ob.status = 'pending' AND ob.employee_id IN ($ph)
        ORDER BY ob.requested_at DESC
    ");
    $stmt->execute($teamIds);
    $pendingOB = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT ob.*, e.first_name, e.last_name, e.employee_code, u.full_name AS approver_name
        FROM official_business_requests ob JOIN employees e ON e.employee_id = ob.employee_id
        LEFT JOIN users u ON u.id = ob.approved_by
        WHERE ob.status != 'pending' AND ob.employee_id IN ($ph)
        ORDER BY ob.decided_at DESC LIMIT 30
    ");
    $stmt->execute($teamIds);
    $decidedOB = $stmt->fetchAll();
}

function obTimeRange($ob) {
    if (empty($ob['time_from']) || empty($ob['time_to'])) return '—';
    return date('g:i A', strtotime($ob['time_from'])) . '–' . date('g:i A', strtotime($ob['time_to']));
}

$activeNav = 'official_business';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Official Business Requests — Supervisor</title>
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
            <h1>Official Business Requests — <?= $viewingAllTeams ? 'All Teams' : 'My Team' ?></h1>
            <div class="user-info"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span><span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span></div>
        </div>
        <div class="container">

            <?php if (!empty($success_message)): ?><div class="alert alert--success"><?= clean($success_message) ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="alert alert--error"><?= clean($error_message) ?></div><?php endif; ?>
            <?php if ($viewingAllTeams): ?><div class="alert" style="background:var(--blue-bg, #EAF2FB); color:var(--blue-text, #1F4E8C);">Viewing every Supervisor's team combined. Approve/reject works as usual — just open a specific Supervisor's page (via Supervisor Teams) if you need to see who approved what.</div><?php endif; ?>
            <div class="alert" style="background:var(--blue-bg, #EAF2FB); color:var(--blue-text, #1F4E8C);">Approving counts that day as <strong>Present</strong> for payroll — it is not Leave, and it does not create Overtime on its own. If the employee also ends up working past their shift because of it, that's still a separate Overtime request.</div>

            <div class="section">
                <div class="section-header"><h2>Pending Requests</h2></div>
                <div class="table-card"><div class="table-scroll">
                    <table>
                        <thead><tr><th>Employee</th><th>Date</th><th>Time</th><th>Destination</th><th>Purpose</th><th>Requested</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php if (empty($pendingOB)): ?>
                            <tr><td colspan="7"><div class="empty-state">No pending Official Business requests from your team.</div></td></tr>
                        <?php else: foreach ($pendingOB as $ob): ?>
                            <tr>
                                <td><?= clean($ob['last_name'] . ', ' . $ob['first_name']) ?><br><span style="color:var(--ink-soft); font-size:12px;"><?= clean($ob['employee_code']) ?></span></td>
                                <td><?= clean(date('M d, Y', strtotime($ob['ob_date']))) ?></td>
                                <td><?= clean(obTimeRange($ob)) ?></td>
                                <td><?= clean($ob['destination'] ?: '—') ?></td>
                                <td style="max-width:220px;"><?= clean($ob['purpose'] ?: '—') ?></td>
                                <td><?= clean(date('M d, Y', strtotime($ob['requested_at']))) ?></td>
                                <td class="actions-cell">
                                    <form method="POST" style="display:inline;">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="approve_ob">
                                        <input type="hidden" name="ob_id" value="<?= $ob['ob_id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                    </form>
                                    <form method="POST" style="display:inline;" data-confirm="Reject this Official Business request?" data-confirm-title="Reject Official Business" data-confirm-danger="1" data-confirm-ok="Yes, reject">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="reject_ob">
                                        <input type="hidden" name="ob_id" value="<?= $ob['ob_id'] ?>">
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
                        <thead><tr><th>Employee</th><th>Date</th><th>Destination</th><th>Status</th><th>Decided On</th><th>Decided By</th></tr></thead>
                        <tbody>
                        <?php if (empty($decidedOB)): ?>
                            <tr><td colspan="6"><div class="empty-state">No processed Official Business requests yet.</div></td></tr>
                        <?php else: foreach ($decidedOB as $ob): ?>
                            <tr>
                                <td><?= clean($ob['last_name'] . ', ' . $ob['first_name']) ?></td>
                                <td><?= clean(date('M d, Y', strtotime($ob['ob_date']))) ?></td>
                                <td><?= clean($ob['destination'] ?: '—') ?></td>
                                <td><span class="badge" style="background:<?= $ob['status'] == 'approved' ? 'var(--green-pale)' : 'var(--red-pale)' ?>; color:<?= $ob['status'] == 'approved' ? 'var(--text-accent)' : 'var(--red-alert)' ?>;"><?= ucfirst($ob['status']) ?></span></td>
                                <td><?= !empty($ob['decided_at']) ? clean(date('M d, Y g:i A', strtotime($ob['decided_at']))) : '—' ?></td>
                                <td><?= clean($ob['approver_name'] ?: '—') ?></td>
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
