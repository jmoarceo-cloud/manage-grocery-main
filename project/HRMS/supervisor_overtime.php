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

    if ($action === 'approve_ot' || $action === 'reject_ot') {
        $ot_id      = $_POST['ot_id'] ?? 0;
        $new_status = $action === 'approve_ot' ? 'approved' : 'rejected';
        $result = processOvertimeDecisionForSupervisor($pdo, $ot_id, $new_status, $_SESSION['user_id'], $supervisorEmployeeId, $viewingAllTeams);
        if ($result['ok']) { $success_message = $result['message']; } else { $error_message = $result['message']; }
    } elseif ($action === 'log_ot') {
        // A Supervisor can also log an OT request on behalf of a team
        // member (e.g. they asked verbally / it's for a shift that just
        // happened) — it still needs a decision, so it starts pending too,
        // and can't be self-approved in the same submit.
        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        $ot_date     = $_POST['ot_date'] ?? '';
        $hours       = $_POST['hours_requested'] ?? '';
        $reason      = trim($_POST['reason'] ?? '');

        if ($viewingAllTeams) {
            $error_message = "Pick a specific Supervisor (via Supervisor Teams) to log overtime on their behalf.";
        } elseif (!in_array($employee_id, $teamIds, true)) {
            $error_message = "That employee is not part of your team.";
        } elseif (empty($ot_date) || !is_numeric($hours) || (float) $hours <= 0) {
            $error_message = "Please provide a valid date and number of hours.";
        } else {
            try {
                $pdo->prepare("INSERT INTO overtime_requests (employee_id, ot_date, hours_requested, reason) VALUES (?, ?, ?, ?)")
                    ->execute([$employee_id, $ot_date, (float) $hours, $reason ?: null]);
                $success_message = "Overtime request logged and is now pending approval.";
            } catch (PDOException $e) {
                error_log("Log OT error: " . $e->getMessage());
                $error_message = "Could not log this overtime request.";
            }
        }
    }
}

$team = [];
$pendingOT = [];
$decidedOT = [];
if (!empty($teamIds)) {
    $ph = implode(',', array_fill(0, count($teamIds), '?'));

    $stmt = $pdo->prepare("SELECT employee_id, employee_code, first_name, last_name FROM employees WHERE employee_id IN ($ph) ORDER BY last_name");
    $stmt->execute($teamIds);
    $team = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT ot.*, e.first_name, e.last_name, e.employee_code
        FROM overtime_requests ot JOIN employees e ON e.employee_id = ot.employee_id
        WHERE ot.status = 'pending' AND ot.employee_id IN ($ph)
        ORDER BY ot.requested_at DESC
    ");
    $stmt->execute($teamIds);
    $pendingOT = $stmt->fetchAll();
    // Item 11 — attach the actual/verified attendance punch for that
    // exact date so the Supervisor sees real clocked time next to the
    // employee's requested hours before deciding. Purely informational;
    // does not change what gets saved on approve/reject.
    foreach ($pendingOT as &$__ot) {
        $__ot['actual'] = getActualOvertimeForEmployeeDate($pdo, (int) $__ot['employee_id'], $__ot['ot_date']);
    }
    unset($__ot);

    $stmt = $pdo->prepare("
        SELECT ot.*, e.first_name, e.last_name, e.employee_code, u.full_name AS approver_name
        FROM overtime_requests ot JOIN employees e ON e.employee_id = ot.employee_id
        LEFT JOIN users u ON u.id = ot.approved_by
        WHERE ot.status != 'pending' AND ot.employee_id IN ($ph)
        ORDER BY ot.decided_at DESC LIMIT 30
    ");
    $stmt->execute($teamIds);
    $decidedOT = $stmt->fetchAll();
    foreach ($decidedOT as &$__ot) {
        $__ot['actual'] = getActualOvertimeForEmployeeDate($pdo, (int) $__ot['employee_id'], $__ot['ot_date']);
    }
    unset($__ot);
}

$activeNav = 'overtime';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Overtime Requests — Supervisor</title>
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
            <h1>Overtime Requests — <?= $viewingAllTeams ? 'All Teams' : 'My Team' ?></h1>
            <div class="user-info"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span><span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span></div>
        </div>
        <div class="container">

            <?php if (!empty($success_message)): ?><div class="alert alert--success"><?= clean($success_message) ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="alert alert--error"><?= clean($error_message) ?></div><?php endif; ?>
            <?php if ($viewingAllTeams): ?><div class="alert" style="background:var(--blue-bg, #EAF2FB); color:var(--blue-text, #1F4E8C);">Viewing every Supervisor's team combined. Approve/reject works as usual; logging OT on someone's behalf needs a specific Supervisor selected (via Supervisor Teams), since it's recorded under that Supervisor.</div><?php endif; ?>

            <div class="panel">
                <h3>Log an Overtime Request</h3>
                <p style="color:var(--ink-soft); font-size:13px; margin-bottom:12px;">For a team member who asked for OT directly, or worked overtime that still needs approval on record. This still needs to be approved below — it won't self-approve.</p>
                <form method="POST" class="form-row">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="log_ot">
                    <div class="field-group">
                        <label>Employee</label>
                        <select name="employee_id" required>
                            <option value="">— Select —</option>
                            <?php foreach ($team as $t): ?>
                            <option value="<?= $t['employee_id'] ?>"><?= clean($t['last_name'] . ', ' . $t['first_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-group">
                        <label>Date</label>
                        <input type="date" name="ot_date" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="field-group" style="min-width:120px;">
                        <label>Hours</label>
                        <input type="number" name="hours_requested" min="0.5" max="12" step="0.5" required>
                    </div>
                    <div class="field-group" style="flex:1;">
                        <label>Reason</label>
                        <input type="text" name="reason" placeholder="e.g. Inventory count, restock day">
                    </div>
                    <button type="submit" class="btn btn-primary">Log Request</button>
                </form>
            </div>

            <div class="section">
                <div class="section-header"><h2>Pending Requests</h2></div>
                <div class="table-card"><div class="table-scroll">
                    <table>
                        <thead><tr><th>Employee</th><th>Date</th><th>Requested</th><th>Actual Clocked</th><th>Reason</th><th>Submitted</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php if (empty($pendingOT)): ?>
                            <tr><td colspan="7"><div class="empty-state">No pending overtime requests from your team.</div></td></tr>
                        <?php else: foreach ($pendingOT as $ot): $a = $ot['actual']; ?>
                            <tr>
                                <td><?= clean($ot['last_name'] . ', ' . $ot['first_name']) ?><br><span style="color:var(--ink-soft); font-size:12px;"><?= clean($ot['employee_code']) ?></span></td>
                                <td><?= clean(date('M d, Y', strtotime($ot['ot_date']))) ?></td>
                                <td><?= clean($ot['hours_requested']) ?> hrs</td>
                                <td>
                                    <?php if (!$a['has_attendance']): ?>
                                        <span style="color:var(--ink-soft); font-size:12px;">Not clocked yet</span>
                                    <?php else: ?>
                                        <span style="font-size:12px;"><?= clean(date('g:i A', strtotime($a['time_in']))) ?>–<?= clean(date('g:i A', strtotime($a['time_out']))) ?></span><br>
                                        <span style="font-size:12px; color:<?= $a['actual_ot_hours'] > 0 ? 'var(--text-accent)' : 'var(--ink-soft)' ?>;"><?= clean($a['actual_ot_hours']) ?> hrs over shift</span>
                                    <?php endif; ?>
                                </td>
                                <td style="max-width:220px;"><?= clean($ot['reason'] ?: '—') ?></td>
                                <td><?= clean(date('M d, Y', strtotime($ot['requested_at']))) ?></td>
                                <td class="actions-cell">
                                    <form method="POST" style="display:inline;">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="approve_ot">
                                        <input type="hidden" name="ot_id" value="<?= $ot['ot_id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                    </form>
                                    <form method="POST" style="display:inline;" data-confirm="Reject this overtime request?" data-confirm-title="Reject Overtime" data-confirm-danger="1" data-confirm-ok="Yes, reject">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="reject_ot">
                                        <input type="hidden" name="ot_id" value="<?= $ot['ot_id'] ?>">
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
                        <thead><tr><th>Employee</th><th>Date</th><th>Requested</th><th>Actual Clocked</th><th>Status</th><th>Decided On</th><th>Decided By</th></tr></thead>
                        <tbody>
                        <?php if (empty($decidedOT)): ?>
                            <tr><td colspan="7"><div class="empty-state">No processed overtime requests yet.</div></td></tr>
                        <?php else: foreach ($decidedOT as $ot): $a = $ot['actual']; ?>
                            <tr>
                                <td><?= clean($ot['last_name'] . ', ' . $ot['first_name']) ?></td>
                                <td><?= clean(date('M d, Y', strtotime($ot['ot_date']))) ?></td>
                                <td><?= clean($ot['hours_requested']) ?> hrs</td>
                                <td>
                                    <?php if (!$a['has_attendance']): ?>
                                        <span style="color:var(--ink-soft); font-size:12px;">Not clocked</span>
                                    <?php else: ?>
                                        <span style="font-size:12px;"><?= clean($a['actual_ot_hours']) ?> hrs over shift</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge" style="background:<?= $ot['status'] == 'approved' ? 'var(--green-pale)' : 'var(--red-pale)' ?>; color:<?= $ot['status'] == 'approved' ? 'var(--text-accent)' : 'var(--red-alert)' ?>;"><?= ucfirst($ot['status']) ?></span></td>
                                <td><?= !empty($ot['decided_at']) ? clean(date('M d, Y g:i A', strtotime($ot['decided_at']))) : '—' ?></td>
                                <td><?= clean($ot['approver_name'] ?: '—') ?></td>
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