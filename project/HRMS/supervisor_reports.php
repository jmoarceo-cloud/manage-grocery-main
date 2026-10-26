<?php
require_once 'includes/config.php';
requireSupervisorRole();

$supervisorEmployeeId = isSupervisorRole() ? (int) ($_SESSION['employee_id'] ?? 0) : dec_id($_GET['supervisor_id'] ?? '');
// Admin browsing without picking a specific Supervisor sees every
// Supervisor's team combined, instead of an empty page.
$viewingAllTeams = isAdminRole() && $supervisorEmployeeId === 0;
$teamIds = $viewingAllTeams ? getAllSupervisedEmployeeIds($pdo) : getSupervisorTeamEmployeeIds($pdo, $supervisorEmployeeId);

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $dateFrom = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   { $dateTo   = date('Y-m-d'); }

$team = [];
$attendanceByEmployee = [];
$leaveByEmployee = [];
$otByEmployee = [];
$salesByEmployee = [];

if (!empty($teamIds)) {
    $ph = implode(',', array_fill(0, count($teamIds), '?'));

    $stmt = $pdo->prepare("SELECT employee_id, employee_code, first_name, last_name, department, position, schedule_type FROM employees WHERE employee_id IN ($ph) ORDER BY last_name");
    $stmt->execute($teamIds);
    $team = $stmt->fetchAll();

    // Attendance summary per employee within range
    $stmt = $pdo->prepare("
        SELECT employee_id,
               SUM(status = 'present') AS present_days,
               SUM(status = 'absent')  AS absent_days,
               SUM(status = 'leave')   AS leave_days,
               COALESCE(SUM(late_minutes),0) AS late_minutes
        FROM attendance
        WHERE employee_id IN ($ph) AND date BETWEEN ? AND ?
        GROUP BY employee_id
    ");
    $stmt->execute(array_merge($teamIds, [$dateFrom, $dateTo]));
    foreach ($stmt->fetchAll() as $row) { $attendanceByEmployee[$row['employee_id']] = $row; }

    // Leave requests within range (by date_from)
    $stmt = $pdo->prepare("
        SELECT employee_id,
               SUM(status = 'approved') AS approved,
               SUM(status = 'pending')  AS pending,
               SUM(status = 'rejected') AS rejected
        FROM leave_requests
        WHERE employee_id IN ($ph) AND date_from BETWEEN ? AND ?
        GROUP BY employee_id
    ");
    $stmt->execute(array_merge($teamIds, [$dateFrom, $dateTo]));
    foreach ($stmt->fetchAll() as $row) { $leaveByEmployee[$row['employee_id']] = $row; }

    // Overtime within range
    $stmt = $pdo->prepare("
        SELECT employee_id,
               SUM(CASE WHEN status = 'approved' THEN hours_requested ELSE 0 END) AS approved_hours,
               SUM(status = 'pending') AS pending_count
        FROM overtime_requests
        WHERE employee_id IN ($ph) AND ot_date BETWEEN ? AND ?
        GROUP BY employee_id
    ");
    $stmt->execute(array_merge($teamIds, [$dateFrom, $dateTo]));
    foreach ($stmt->fetchAll() as $row) { $otByEmployee[$row['employee_id']] = $row; }

    // Sales per team cashier within range
    $posUserMap = getPosUserIdsForEmployees($posPdo, $teamIds);
    if ($posPdo && !empty($posUserMap)) {
        try {
            $phPos = implode(',', array_fill(0, count($posUserMap), '?'));
            $stmt = $posPdo->prepare("
                SELECT cashier_id, SUM(total_amount) AS total_sales, COUNT(*) AS tx_count
                FROM sales
                WHERE status = 'completed' AND cashier_id IN ($phPos) AND DATE(transaction_date) BETWEEN ? AND ?
                GROUP BY cashier_id
            ");
            $stmt->execute(array_merge(array_values($posUserMap), [$dateFrom, $dateTo]));
            $byPosUser = [];
            foreach ($stmt->fetchAll() as $row) { $byPosUser[$row['cashier_id']] = $row; }
            foreach ($posUserMap as $empId => $posUserId) {
                if (isset($byPosUser[$posUserId])) { $salesByEmployee[$empId] = $byPosUser[$posUserId]; }
            }
        } catch (PDOException $e) {
            error_log("Supervisor report sales query skipped: " . $e->getMessage());
        }
    }
}

$teamTotals = [
    'present' => 0, 'absent' => 0, 'leave_approved' => 0, 'ot_hours' => 0, 'sales' => 0,
];
foreach ($team as $t) {
    $eid = $t['employee_id'];
    $teamTotals['present'] += (int) ($attendanceByEmployee[$eid]['present_days'] ?? 0);
    $teamTotals['absent']  += (int) ($attendanceByEmployee[$eid]['absent_days'] ?? 0);
    $teamTotals['leave_approved'] += (int) ($leaveByEmployee[$eid]['approved'] ?? 0);
    $teamTotals['ot_hours'] += (float) ($otByEmployee[$eid]['approved_hours'] ?? 0);
    $teamTotals['sales'] += (float) ($salesByEmployee[$eid]['total_sales'] ?? 0);
}

$activeNav = 'reports';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Team Report — Supervisor</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php include 'includes/supervisor_style.php'; ?>
<style>
@page {
    size: landscape;
    margin: 12mm;
}
@media print {
    .sidebar, .topbar, .no-print { display: none !important; }
    .main-area { margin-left: 0 !important; }
    body { background: var(--white) !important; }
    .table-scroll { max-height: none !important; overflow: visible !important; }
    .table-scroll thead th { position: static !important; }
    .section { break-inside: avoid; }
    /* The table has 8 columns and doesn't fit natural-width in portrait —
       force it to respect the page width instead of overflowing/getting
       cut off on the right edge. */
    table { table-layout: fixed; width: 100% !important; }
    th, td {
        font-size: 11px !important;
        padding: 8px 8px !important;
        white-space: normal !important;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
}

</style>
</head>
<body>
<div class="app-shell">
    <?php include 'includes/supervisor_sidebar.php'; ?>
    <div class="main-area">
        <div class="topbar">
            <h1>Team Report<?= $viewingAllTeams ? ' — All Teams' : '' ?></h1>
            <div class="user-info"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span><span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span></div>
        </div>
        <div class="container">

            <div class="panel no-print">
                <form method="GET" class="form-row">
                    <div class="field-group"><label>From</label><input type="date" name="date_from" value="<?= clean($dateFrom) ?>" max="<?= date('Y-m-d') ?>"></div>
                    <div class="field-group"><label>To</label><input type="date" name="date_to" value="<?= clean($dateTo) ?>" max="<?= date('Y-m-d') ?>"></div>
                    <button type="submit" class="btn btn-primary">Generate</button>
                    <button type="button" class="btn btn-success" onclick="window.print()">Print / Save as PDF</button>
                </form>
            </div>

            <h2 style="font-family:var(--font-display); color:var(--text-accent); margin-bottom:4px;">Team Performance Report</h2>
            <p style="color:var(--ink-soft); font-size:13.5px; margin-bottom:22px;">
                <?= clean(date('F d, Y', strtotime($dateFrom))) ?> – <?= clean(date('F d, Y', strtotime($dateTo))) ?>
                · Supervisor: <?= clean($_SESSION['full_name']) ?>
            </p>

            <div class="kpi-grid">
                <div class="kpi-card"><div class="kpi-label">Team Size</div><div class="kpi-value"><?= count($team) ?></div></div>
                <div class="kpi-card"><div class="kpi-label">Total Present-Days</div><div class="kpi-value"><?= $teamTotals['present'] ?></div></div>
                <div class="kpi-card accent-red"><div class="kpi-label">Total Absent-Days</div><div class="kpi-value"><?= $teamTotals['absent'] ?></div></div>
                <div class="kpi-card"><div class="kpi-label">Approved Leaves</div><div class="kpi-value"><?= $teamTotals['leave_approved'] ?></div></div>
                <div class="kpi-card accent-mustard"><div class="kpi-label">Approved OT Hours</div><div class="kpi-value"><?= number_format($teamTotals['ot_hours'], 1) ?></div></div>
                <div class="kpi-card"><div class="kpi-label">Team Sales</div><div class="kpi-value" style="font-size:22px;"><?= peso($teamTotals['sales']) ?></div></div>
            </div>

            <div class="section">
                <div class="section-header"><h2>Per-Employee Breakdown</h2></div>
                <div class="table-card"><div class="table-scroll">
                    <table>
                        <thead><tr>
                            <th>Employee</th><th>Present</th><th>Absent</th><th>Late (min)</th>
                            <th>Leaves (App/Pend/Rej)</th><th>OT Hours</th><th>Sales</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($team)): ?>
                            <tr><td colspan="7"><div class="empty-state">No team members assigned yet.</div></td></tr>
                        <?php else: foreach ($team as $t):
                            $eid = $t['employee_id'];
                            $att  = $attendanceByEmployee[$eid] ?? [];
                            $lv   = $leaveByEmployee[$eid] ?? [];
                            $ot   = $otByEmployee[$eid] ?? [];
                            $sal  = $salesByEmployee[$eid] ?? [];
                        ?>
                            <tr>
                                <td><?= clean($t['last_name'] . ', ' . $t['first_name']) ?><br><span style="color:var(--ink-soft); font-size:12px;"><?= clean($t['employee_code']) ?> · <?= clean($t['position'] ?: '—') ?></span></td>
                                <td><?= (int) ($att['present_days'] ?? 0) ?></td>
                                <td><?= (int) ($att['absent_days'] ?? 0) ?></td>
                                <td><?= (int) ($att['late_minutes'] ?? 0) ?></td>
                                <td><?= (int) ($lv['approved'] ?? 0) ?> / <?= (int) ($lv['pending'] ?? 0) ?> / <?= (int) ($lv['rejected'] ?? 0) ?></td>
                                <td><?= number_format((float) ($ot['approved_hours'] ?? 0), 1) ?></td>
                                <td><?= isset($sal['total_sales']) ? peso($sal['total_sales']) : '—' ?></td>
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