<?php
require_once 'includes/config.php';
requireSupervisorRole();

// Same team-scoping pattern as supervisor_attendance.php: a Supervisor
// only sees their own team's schedule; Admin can preview a specific
// Supervisor's team via ?supervisor_id=, or see every team combined
// if none is picked.
$supervisorEmployeeId = isSupervisorRole() ? (int) ($_SESSION['employee_id'] ?? 0) : dec_id($_GET['supervisor_id'] ?? '');
$viewingAllTeams = isAdminRole() && $supervisorEmployeeId === 0;
$teamIds = $viewingAllTeams ? getAllSupervisedEmployeeIds($pdo) : getSupervisorTeamEmployeeIds($pdo, $supervisorEmployeeId);

$team = [];
if (!empty($teamIds)) {
    $ph = implode(',', array_fill(0, count($teamIds), '?'));
    $stmt = $pdo->prepare("SELECT employee_id, employee_code, first_name, last_name, department, position, schedule_type FROM employees WHERE employee_id IN ($ph) ORDER BY last_name");
    $stmt->execute($teamIds);
    $team = $stmt->fetchAll();
}

// Weekly schedule per team member, keyed by employee_id => [dow => shift row|null]
$scheduleByEmployee = [];
foreach ($team as $t) {
    $scheduleByEmployee[$t['employee_id']] = getEmployeeWeeklySchedule($pdo, $t['employee_id']);
}

$DAY_NAMES = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];

// Stable color per shift NAME (not per cell) — same shift always gets
// the same color everywhere in the grid, so it reads like a proper
// scheduler/roster (e.g. every "Opening" block is teal, every
// "Closing" block is mustard) instead of a plain text table.
$SHIFT_COLOR_PALETTE = [
    ['bg' => '#E3F2F6', 'text' => '#123C5C'], // teal/green
    ['bg' => '#FBEFD8', 'text' => '#7A5A1E'], // mustard
    ['bg' => '#EDEBFC', 'text' => '#4B3FA0'], // purple
    ['bg' => '#E6F4EA', 'text' => '#1A7A3B'], // leaf green
    ['bg' => '#FBE7E2', 'text' => '#C8472D'], // coral
    ['bg' => '#E7EFFB', 'text' => '#2A4E8C'], // blue
];
function shiftColor($shiftName, $palette) {
    $i = crc32($shiftName) % count($palette);
    return $palette[$i];
}
function supervisorScheduleTime($time) {
    $time = (string) $time;
    if (!preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $time)) {
        return '-';
    }
    $parts = array_map('intval', explode(':', $time));
    if ($parts[0] > 23 || $parts[1] > 59 || (isset($parts[2]) && $parts[2] > 59)) {
        return '-';
    }
    return date('g:i A', mktime($parts[0], $parts[1]));
}
function supervisorScheduleShiftLabel($label) {
    return str_replace('?', '-', (string) $label);
}

$activeNav = 'schedule';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Team Schedule — Supervisor</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php include 'includes/supervisor_style.php'; ?>
<style>
.schedule-table-wrap { background: var(--white); border: 1px solid var(--line); border-radius: 16px; padding: 10px; }
.schedule-table { width: 100%; table-layout: fixed; border-collapse: separate; border-spacing: 6px; }
.schedule-table thead th { background: var(--cream); border-radius: 8px; padding: 10px 8px; font-size: 11.5px; letter-spacing: .03em; }
.schedule-table th.name-col, .schedule-table td.name-col { width: 168px; }
.schedule-table td { padding: 0; vertical-align: middle; height: 56px; }
.schedule-name-cell { background: var(--cream); border-radius: 8px; padding: 10px 14px; height: 100%; box-sizing: border-box; display: flex; flex-direction: column; justify-content: center; }
.schedule-name-cell .emp-name { font-weight: 600; font-size: 13.5px; color: var(--ink); }
.schedule-name-cell .emp-meta { font-size: 11.5px; color: var(--ink-soft); margin-top: 2px; }
.shift-chip { border-radius: 8px; padding: 7px 8px; font-size: 11px; line-height: 1.35; text-align: center; height: 100%; box-sizing: border-box; display: flex; flex-direction: column; justify-content: center; }
.shift-chip .shift-name { font-weight: 700; }
.shift-chip .shift-time { opacity: .85; font-size: 10px; margin-top: 1px; }
.day-off-chip { border-radius: 8px; padding: 7px 4px; font-size: 11px; text-align: center; color: var(--ink-soft); background: var(--cream); box-sizing: border-box; height: 100%; display: flex; align-items: center; justify-content: center; }
</style>
</head>
<body>
<div class="app-shell">
    <?php include 'includes/supervisor_sidebar.php'; ?>
    <div class="main-area">
        <div class="topbar">
            <h1>Team Schedule<?= $viewingAllTeams ? ' — All Teams' : '' ?></h1>
            <div class="user-info"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span><span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span></div>
        </div>
        <div class="container">

            <div class="section">
                <div class="section-header">
                    <h2>Weekly Shift Schedule</h2>
                </div>
                <p style="color:var(--ink-soft); font-size:13.5px; margin-bottom:14px;">
                    View-only — each team member's weekly shift schedule, set from Employee Management by HR/Admin.
                </p>
                <div class="table-scroll schedule-table-wrap">
                    <table class="schedule-table">
                        <thead>
                            <tr>
                                <th class="name-col">Team Member</th>
                                <?php foreach ($DAY_NAMES as $dayLabel): ?>
                                <th style="text-align:center;"><?= clean($dayLabel) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($team)): ?>
                            <tr><td colspan="8"><div class="empty-state">No team members assigned yet.</div></td></tr>
                        <?php else: foreach ($team as $t): $weekly = $scheduleByEmployee[$t['employee_id']] ?? []; ?>
                            <tr>
                                <td class="name-col">
                                    <div class="schedule-name-cell">
                                        <div class="emp-name"><?= clean($t['last_name'] . ', ' . $t['first_name']) ?></div>
                                        <div class="emp-meta"><?= clean($t['employee_code']) ?> · <?= clean($t['position'] ?: '—') ?></div>
                                    </div>
                                </td>
                                <?php foreach (array_keys($DAY_NAMES) as $dow): $sh = $weekly[$dow] ?? null; ?>
                                    <?php if ($sh): $c = shiftColor($sh['shift_name'], $SHIFT_COLOR_PALETTE); ?>
                                        <td>
                                            <div class="shift-chip" style="background:<?= $c['bg'] ?>; color:<?= $c['text'] ?>;">
                                                <div class="shift-name"><?= clean(supervisorScheduleShiftLabel($sh['shift_name'])) ?></div>
                                                <div class="shift-time"><?= clean(supervisorScheduleTime($sh['start_time'] ?? null)) ?>–<?= clean(supervisorScheduleTime($sh['end_time'] ?? null)) ?></div>
                                            </div>
                                        </td>
                                    <?php else: ?>
                                        <td>
                                            <div class="day-off-chip">Day Off</div>
                                        </td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>
<?php include 'includes/sweetalert.php'; ?>
</body>
</html>