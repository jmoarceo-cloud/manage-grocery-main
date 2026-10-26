<?php
require_once 'includes/config.php';
requireAdmin(); // Admin only — assigning org structure is an Admin decision.

$success_message = "";
$error_message   = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'assign_member') {
        $supervisor_employee_id = (int) ($_POST['supervisor_employee_id'] ?? 0);
        $member_employee_id     = (int) ($_POST['member_employee_id'] ?? 0);

        if (empty($supervisor_employee_id) || empty($member_employee_id)) {
            $error_message = "Please select both a Supervisor and an employee to assign.";
        } elseif ($supervisor_employee_id === $member_employee_id) {
            $error_message = "A Supervisor cannot be assigned to their own team.";
        } else {
            try {
                // One supervisor per member — re-assigning just moves them.
                $stmt = $pdo->prepare("
                    INSERT INTO supervisor_teams (supervisor_employee_id, member_employee_id, assigned_by)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE supervisor_employee_id = VALUES(supervisor_employee_id), assigned_by = VALUES(assigned_by), assigned_at = NOW()
                ");
                $stmt->execute([$supervisor_employee_id, $member_employee_id, $_SESSION['user_id']]);
                redirect('manage_supervisor_teams.php?saved=1');
            } catch (PDOException $e) {
                error_log("Assign supervisor team member error: " . $e->getMessage());
                $error_message = "Could not save this assignment. Please try again.";
            }
        }
    } elseif ($action === 'remove_member') {
        $team_row_id = (int) ($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM supervisor_teams WHERE id = ?")->execute([$team_row_id]);
            redirect('manage_supervisor_teams.php?removed=1');
        } catch (PDOException $e) {
            error_log("Remove supervisor team member error: " . $e->getMessage());
            $error_message = "Could not remove this assignment. Please try again.";
        }
    }
}

if (isset($_GET['saved']))   { $success_message = "Team assignment saved."; }
if (isset($_GET['removed'])) { $success_message = "Employee removed from that Supervisor's team."; }

// All active Supervisor accounts
$supervisors = $pdo->query("
    SELECT u.id AS user_id, u.employee_id, u.full_name, e.employee_code, e.department, e.schedule_type
    FROM users u
    JOIN employees e ON e.employee_id = u.employee_id
    WHERE u.role = 'supervisor' AND u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();

// All active employees eligible to be on a team (anyone active, minus Supervisors/Admin/HR-tier accounts)
$eligibleEmployees = $pdo->query("
    SELECT e.employee_id, e.employee_code, e.first_name, e.last_name, e.department, e.position, e.schedule_type,
           st.supervisor_employee_id
    FROM employees e
    LEFT JOIN supervisor_teams st ON st.member_employee_id = e.employee_id
    WHERE e.status = 'active'
      AND e.employee_id NOT IN (SELECT employee_id FROM users WHERE role IN ('admin','hr','hr_manager','supervisor') AND employee_id IS NOT NULL)
    ORDER BY e.department, e.last_name
")->fetchAll();

// Current team rosters, grouped by supervisor
$teamRows = $pdo->query("
    SELECT st.id, st.supervisor_employee_id, e.employee_id, e.employee_code, e.first_name, e.last_name,
           e.department, e.position, e.schedule_type
    FROM supervisor_teams st
    JOIN employees e ON e.employee_id = st.member_employee_id
    ORDER BY e.last_name
")->fetchAll();
$teamsBySupervisor = [];
foreach ($teamRows as $row) {
    $teamsBySupervisor[$row['supervisor_employee_id']][] = $row;
}

$activeNav = 'supervisor_teams';
$pageTitle = 'Supervisor Teams — HRMS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Supervisor Teams — HRMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php include 'includes/supervisor_style.php'; ?>
<style>
/* A few extras specific to this page (the rest comes from supervisor_style.php above) */
.supervisor-block {
    background: var(--white);
    border: 1px solid var(--line);
    border-radius: 16px;
    overflow: hidden;
    margin-bottom: 22px;
}
.supervisor-block-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    padding: 18px 20px;
    border-bottom: 1px solid var(--line);
    background: var(--cream);
}
.supervisor-block-header h4 {
    font-family: var(--font-display);
    color: var(--text-accent);
    font-size: 15px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.supervisor-quicklinks { display: flex; flex-wrap: wrap; gap: 6px; }
.supervisor-quicklinks a {
    padding: 7px 13px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 600;
    background: var(--green-pale);
    color: var(--text-accent);
    transition: background .15s ease;
}
.supervisor-quicklinks a:hover { background: var(--green-succ-bg, #d5ebda); }
.supervisor-block .table-card { border: none; border-radius: 0; }
</style>
</head>
<body>
<div class="app-shell">
    <?php $activeNav = 'supervisor_teams'; include 'includes/hr_sidebar.php'; ?>
    <div class="main-area">
        <div class="topbar">
            <h1>Supervisor Teams</h1>
            <div class="user-info"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</div>
        </div>
        <div class="container">

            <?php if (!empty($success_message)): ?><div class="alert alert--success"><?= clean($success_message) ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="alert alert--error"><?= clean($error_message) ?></div><?php endif; ?>

            <div class="panel">
                <h3>Assign an Employee to a Supervisor's Team</h3>
                <p style="color:var(--ink-soft); font-size:13.5px; margin-bottom:14px;">
                    Each Supervisor only sees, approves, and reports on the employees assigned to them here —
                    e.g. one Supervisor can hold the Full Time roster while another holds Part Time, or split by department.
                    An employee can only belong to one Supervisor at a time; re-assigning moves them.
                </p>
                <?php if (empty($supervisors)): ?>
                    <div class="empty-state">No active Supervisor accounts yet. Create one first from <a href="admin_dashboard.php#user-accounts" style="color:var(--green-mid); font-weight:600;">User Accounts</a> (role: Supervisor).</div>
                <?php else: ?>
                <form method="POST">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="assign_member">
                    <div class="form-row">
                        <div class="field-group">
                            <label>Supervisor</label>
                            <select name="supervisor_employee_id" required>
                                <option value="">— Select Supervisor —</option>
                                <?php foreach ($supervisors as $sup): ?>
                                <option value="<?= $sup['employee_id'] ?>"><?= clean($sup['full_name']) ?> (<?= clean($sup['employee_code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field-group">
                            <label>Employee to assign</label>
                            <select name="member_employee_id" required>
                                <option value="">— Select Employee —</option>
                                <?php foreach ($eligibleEmployees as $emp): ?>
                                <option value="<?= $emp['employee_id'] ?>">
                                    <?= clean($emp['last_name'] . ', ' . $emp['first_name']) ?> — <?= clean($emp['position'] ?: 'No position') ?>
                                    (<?= clean(ucwords(str_replace('_',' ', $emp['schedule_type']))) ?>)<?= $emp['supervisor_employee_id'] ? ' · currently assigned' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Assign</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>

            <div class="section">
                <div class="section-header"><h2>Current Teams</h2></div>
                <?php if (empty($supervisors)): ?>
                    <div class="table-card"><div class="empty-state">No Supervisors yet.</div></div>
                <?php else: foreach ($supervisors as $sup):
                    $team = $teamsBySupervisor[$sup['employee_id']] ?? [];
                ?>
                <div class="supervisor-block">
                    <div class="supervisor-block-header">
                        <h4><?= clean($sup['full_name']) ?> <span class="badge"><?= count($team) ?> member<?= count($team) === 1 ? '' : 's' ?></span></h4>
                    </div>
                    <div class="table-card"><table>
                        <thead><tr><th>Code</th><th>Name</th><th>Department</th><th>Position</th><th>Schedule</th><th></th></tr></thead>
                        <tbody>
                        <?php if (empty($team)): ?>
                            <tr><td colspan="6"><div class="empty-state">No employees assigned yet.</div></td></tr>
                        <?php else: foreach ($team as $t): ?>
                            <tr>
                                <td><?= clean($t['employee_code']) ?></td>
                                <td><?= clean($t['last_name'] . ', ' . $t['first_name']) ?></td>
                                <td><?= clean($t['department'] ?: '—') ?></td>
                                <td><?= clean($t['position'] ?: '—') ?></td>
                                <td><?= clean(ucwords(str_replace('_',' ', $t['schedule_type']))) ?></td>
                                <td>
                                    <form method="POST" data-confirm="Remove this employee from the team?" data-confirm-title="Remove Member" data-confirm-danger="1" data-confirm-ok="Yes, remove">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="remove_member">
                                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table></div>
                </div>
                <?php endforeach; endif; ?>
            </div>

        </div>
    </div>
</div>
<?php include 'includes/sweetalert.php'; ?>
</body>
</html>