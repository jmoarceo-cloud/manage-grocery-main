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

    if ($action === 'add_record') {
        $employee_id  = (int) ($_POST['employee_id'] ?? 0);
        $period_label = date('F j, Y'); // Period is now captured automatically at the moment of submission.
        $record_type  = ($_POST['record_type'] ?? '') === 'demerit' ? 'demerit' : 'merit';
        $rating       = (int) ($_POST['rating'] ?? 0);
        $comments     = trim($_POST['comments'] ?? '');

        if ($viewingAllTeams) {
            $error_message = "Pick a specific Supervisor (via Supervisor Teams) to add a performance record on their behalf.";
        } elseif (!in_array($employee_id, $teamIds, true)) {
            $error_message = "That employee is not part of your team.";
        } elseif ($rating < 1 || $rating > 5) {
            $error_message = "Please select the points for this record (1 to 5).";
        } else {
            try {
                $pdo->prepare("INSERT INTO performance_records (employee_id, rated_by, period_label, record_type, rating, comments) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$employee_id, $supervisorEmployeeId, $period_label, $record_type, $rating, $comments ?: null]);
                $success_message = "Performance record added.";

                $nameStmt = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE employee_id = ?");
                $nameStmt->execute([$employee_id]);
                $emp = $nameStmt->fetch();
                $employeeName = $emp ? $emp['last_name'] . ', ' . $emp['first_name'] : 'an employee';
                notifyPerformanceRecord($posPdo, $employeeName, $record_type, $rating, $_SESSION['full_name'] ?? 'Supervisor');
            } catch (PDOException $e) {
                error_log("Add performance record error: " . $e->getMessage());
                $error_message = "Could not save this performance record. Please try again.";
            }
        }
    } elseif ($action === 'delete_record') {
        $perf_id = (int) ($_POST['perf_id'] ?? 0);
        if ($viewingAllTeams) {
            $error_message = "Pick a specific Supervisor (via Supervisor Teams) to remove one of their performance records.";
        } else {
            try {
                // Only allow deleting records for employees on this Supervisor's own team.
                $stmt = $pdo->prepare("DELETE pr FROM performance_records pr JOIN supervisor_teams st ON st.member_employee_id = pr.employee_id WHERE pr.perf_id = ? AND st.supervisor_employee_id = ?");
                $stmt->execute([$perf_id, $supervisorEmployeeId]);
                $success_message = "Performance record removed.";
            } catch (PDOException $e) {
                error_log("Delete performance record error: " . $e->getMessage());
                $error_message = "Could not remove this record.";
            }
        }
    }
}

$team = [];
$records = [];
if (!empty($teamIds)) {
    $ph = implode(',', array_fill(0, count($teamIds), '?'));
    $stmt = $pdo->prepare("SELECT employee_id, employee_code, first_name, last_name, position FROM employees WHERE employee_id IN ($ph) ORDER BY last_name");
    $stmt->execute($teamIds);
    $team = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT pr.*, e.first_name, e.last_name, e.employee_code
        FROM performance_records pr JOIN employees e ON e.employee_id = pr.employee_id
        WHERE pr.employee_id IN ($ph)
        ORDER BY pr.created_at DESC
    ");
    $stmt->execute($teamIds);
    $records = $stmt->fetchAll();
}

function recordTypeBadge($type, $points) {
    $points = max(1, min(5, (int) $points));
    if ($type === 'demerit') {
        return '<span class="badge" style="background:var(--red-pale); color:var(--red-alert);">&#9660; Demerit &middot; ' . $points . ' pt' . ($points > 1 ? 's' : '') . '</span>';
    }
    return '<span class="badge" style="background:var(--green-pale); color:var(--green-mid);">&#9650; Merit &middot; ' . $points . ' pt' . ($points > 1 ? 's' : '') . '</span>';
}

$activeNav = 'performance';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Performance Records — Supervisor</title>
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
            <h1>Performance Records — <?= $viewingAllTeams ? 'All Teams' : 'My Team' ?></h1>
            <div class="user-info"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span><span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span></div>
        </div>
        <div class="container">

            <?php if (!empty($success_message)): ?><div class="alert alert--success"><?= clean($success_message) ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="alert alert--error"><?= clean($error_message) ?></div><?php endif; ?>
            <?php if ($viewingAllTeams): ?><div class="alert" style="background:var(--blue-bg, #EAF2FB); color:var(--blue-text, #1F4E8C);">Viewing every Supervisor's team combined. Adding or removing a performance record needs a specific Supervisor selected (via Supervisor Teams), since records are attributed to that Supervisor.</div><?php endif; ?>

            <div class="panel">
                <h3>Add a Performance Record</h3>
                <form method="POST" class="form-row">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="add_record">
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
                        <label>Period</label>
                        <input type="text" value="<?= clean(date('F j, Y')) ?>" disabled>
                    </div>
                    <div class="field-group" style="min-width:150px;">
                        <label>Type</label>
                        <select name="record_type" id="record_type" required>
                            <option value="merit">Merit</option>
                            <option value="demerit">Demerit</option>
                        </select>
                    </div>
                    <div class="field-group" style="min-width:170px;">
                        <label>Points (1–5)</label>
                        <select name="rating" required>
                            <option value="">—</option>
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                            <option value="<?= $i ?>"><?= $i ?> pt<?= $i > 1 ? 's' : '' ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="field-group" style="flex:1 1 260px;">
                        <label>Comments</label>
                        <input type="text" name="comments" placeholder="Notes on their performance this period">
                    </div>
                    <button type="submit" class="btn btn-primary">Add Record</button>
                </form>
            </div>

            <div class="section">
                <div class="section-header"><h2>Team Performance History</h2></div>
                <div class="table-card"><div class="table-scroll">
                    <table>
                        <thead><tr><th>Employee</th><th>Period</th><th>Type</th><th>Comments</th><th>Recorded On</th><th></th></tr></thead>
                        <tbody>
                        <?php if (empty($records)): ?>
                            <tr><td colspan="6"><div class="empty-state">No performance records yet.</div></td></tr>
                        <?php else: foreach ($records as $r): ?>
                            <tr>
                                <td><?= clean($r['last_name'] . ', ' . $r['first_name']) ?><br><span style="color:var(--ink-soft); font-size:12px;"><?= clean($r['employee_code']) ?></span></td>
                                <td><?= clean($r['period_label']) ?></td>
                                <td><?= recordTypeBadge($r['record_type'], $r['rating']) ?></td>
                                <td style="max-width:260px;"><?= clean($r['comments'] ?: '—') ?></td>
                                <td><?= clean(date('M d, Y', strtotime($r['created_at']))) ?></td>
                                <td>
                                    <form method="POST" data-confirm="Remove this performance record?" data-confirm-title="Remove Record" data-confirm-danger="1" data-confirm-ok="Yes, remove">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="delete_record">
                                        <input type="hidden" name="perf_id" value="<?= $r['perf_id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                                    </form>
                                </td>
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