<?php
// ============================================================
// employee_schedule.php — Company-wide "Employee Schedule" module.
// View-only, shared by HR Staff, HR Manager, and Admin (requireHR()).
// Unlike supervisor_schedule.php (which only shows a single
// Supervisor's own team), this shows EVERY active employee, grouped
// into sections by role/position — Admin, HR Manager, HR Staff,
// Supervisor, Cashier, Inventory Staff, Finance, Employee — so every
// role is visible side by side within one module, separated cleanly
// per section instead of scattered across separate pages.
// ============================================================

require_once 'includes/config.php';
requireHR(); // Admin + HR Manager + HR Staff — view-only for all three

// Account role (admin/hr/hr_manager/supervisor/employee) comes from
// users.role, same pattern used by getAttendanceScopedEmployees() /
// hr_reports.php. Accounts with acct_role='employee' get split
// further by employees.position (Cashier / Inventory Staff /
// Finance / plain Employee) — same convention as attendanceRoleLabel().
$roleExpr = acctRoleCaseSQL('u');
$stmt = $pdo->query("
    SELECT e.employee_id, e.employee_code, e.first_name, e.last_name,
           e.position, e.department, $roleExpr AS acct_role
    FROM employees e
    LEFT JOIN users u ON u.employee_id = e.employee_id
    WHERE e.status IN ('active', 'on-leave')
    ORDER BY e.last_name ASC, e.first_name ASC
");
$allEmployees = $stmt->fetchAll();

// The order sections appear in, top to bottom. Only sections that
// actually have someone in them get rendered below.
$GROUP_ORDER = ['Admin', 'HR Manager', 'HR Staff', 'Supervisor', 'Cashier', 'Inventory Staff', 'Finance', 'Employee'];

function scheduleGroupLabel($acctRole, $position) {
    switch ($acctRole) {
        case 'admin':      return 'Admin';
        case 'hr_manager': return 'HR Manager';
        case 'hr':         return 'HR Staff';
        case 'supervisor': return 'Supervisor';
        default:
            switch ($position) {
                case 'Cashier':         return 'Cashier';
                case 'Inventory Staff': return 'Inventory Staff';
                case 'Accountant':      return 'Finance';
                default:                return 'Employee';
            }
    }
}

$groups = array_fill_keys($GROUP_ORDER, []);
foreach ($allEmployees as $e) {
    $label = scheduleGroupLabel($e['acct_role'], $e['position']);
    $e['weekly'] = getEmployeeWeeklySchedule($pdo, $e['employee_id']);
    $groups[$label][] = $e;
}

$DAY_NAMES = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];

// Same stable per-shift-name color logic as supervisor_schedule.php —
// kept identical (same palette, same crc32 hashing) so a given shift
// (e.g. "Opening") always renders in the same color on both pages.
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
function employeeScheduleTime($time) {
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
function employeeScheduleShiftLabel($label) {
    return str_replace('?', '-', (string) $label);
}

$activeNav = 'employee_schedule';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee Schedule — HRMS &amp; Payroll</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --green-deep:    #123C5C;
    --text-accent:   var(--green-deep);
    --green-mid:     #1978A8;
    --green-leaf:    #4FC3D1;
    --green-pale:    #E3F2F6;
    --mustard:       #E0A23B;
    --mustard-pale:  #FBEFD8;
    --red-alert:     #C8472D;
    --red-pale:      #FBE7E2;
    --cream:         #F3F6F9;
    --white:         #FFFFFF;
    --ink:           #17222E;
    --ink-soft:      #3D4C5C;
    --line:          #DCE5EC;
    --font-display: 'Sora', sans-serif;
    --font-body: 'Inter', sans-serif;
}

html, body { min-height: 100vh; font-family: var(--font-body); color: var(--ink); background: var(--cream); }
a { text-decoration: none; color: inherit; }

/* ── Sidebar (same look/size as the rest of the HRMS module) ── */
.app-shell { display: flex; min-height: 100vh; }
.sidebar {
    width: 288px; flex-shrink: 0;
    background: radial-gradient(420px 260px at 100% 0%, rgba(79,195,209,.16), transparent 60%), var(--green-deep);
    color: #FFFFFF;
    display: flex; flex-direction: column; padding: 30px 24px;
    position: fixed; top: 0; left: 0; height: 100vh; overflow-y: auto;
    z-index: 100;
    box-shadow: 2px 0 12px rgba(0,0,0,0.18);
}
.sidebar-brand { display: flex; align-items: center; gap: 14px; padding: 0 4px 26px; margin-bottom: 22px; border-bottom: 1px solid rgba(255,255,255,0.14); }
.brand-mark { width: 46px; height: 46px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: var(--white); border-radius: 13px; box-shadow: 0 4px 10px rgba(0,0,0,.14); }
.brand-mark .brand-icon, .brand-icon { width: 28px; height: 28px; }
.brand-text { display: flex; flex-direction: column; line-height: 1.35; }
.brand-name { font-family: var(--font-display); font-weight: 700; font-size: 17px; }
.brand-tagline { font-size: 11px; color: var(--green-leaf); font-weight: 600; letter-spacing: .02em; }
.nav-section-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: rgba(255,255,255,.42); padding: 0 14px; margin: 4px 0 10px; }
.sidebar-nav { display: flex; flex-direction: column; gap: 5px; }
.nav-link { display: flex; align-items: center; gap: 13px; padding: 13px 15px; border-radius: 12px; color: rgba(255,255,255,0.82); font-size: 14.5px; font-weight: 600; transition: background 0.15s ease, color 0.15s ease; }
.nav-icon-box { width: 30px; height: 30px; border-radius: 9px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,.08); }
.nav-link.is-active .nav-icon-box { background: rgba(255,255,255,.35); }
.nav-icon { width: 17px; height: 17px; flex-shrink: 0; }
.nav-link:hover { background: rgba(255,255,255,0.1); color: #FFFFFF; }
.nav-link.is-active { background: var(--green-leaf); color: var(--green-deep); box-shadow: 0 4px 12px rgba(0,0,0,.16); }
.nav-link--logout { margin-top: 16px; border-top: 1px solid rgba(255,255,255,0.14); padding-top: 18px; color: rgba(255,255,255,0.65); }
.nav-link--logout:hover { background: rgba(200,71,45,0.18); color: #ffb3a3; }
.sidebar-foot { margin-top: auto; padding: 14px; border-radius: 14px; background: rgba(255,255,255,.06); display: flex; align-items: center; gap: 12px; }
.sidebar-foot .foot-avatar { width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0; background: linear-gradient(150deg, var(--green-leaf), #2E9BAE); color: var(--green-deep); display: flex; align-items: center; justify-content: center; font-family: var(--font-display); font-weight: 700; font-size: 15px; overflow: hidden; }
.sidebar-foot .foot-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.sidebar-foot .foot-text { display: flex; flex-direction: column; line-height: 1.3; min-width: 0; }
.sidebar-foot .foot-name { font-size: 13.5px; font-weight: 700; color: #FFFFFF; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sidebar-foot .foot-role { font-size: 11.5px; color: rgba(255,255,255,0.6); font-weight: 600; }
.main-area { margin-left: 288px; flex: 1; min-width: 0; display: flex; flex-direction: column; position: relative; z-index: 1; }
@media (max-width: 900px) {
    .sidebar { width: 76px; padding: 24px 12px; }
    .brand-text, .nav-link span, .sidebar-foot .foot-text { display: none; }
    .sidebar-brand { justify-content: center; padding-bottom: 18px; }
    .nav-link { justify-content: center; padding: 12px; gap: 0; }
    .sidebar-foot { justify-content: center; padding: 10px; }
    .main-area { margin-left: 76px; }
}

.topbar { background: var(--white); border-bottom: 1px solid var(--line); padding: 18px 32px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
.topbar h1 { font-family: var(--font-display); font-size: 20px; color: var(--text-accent); }
.topbar .user-info { font-size: 13.5px; color: var(--ink-soft); display: flex; align-items: center; gap: 16px; }
.user-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--mustard); color: #FFFFFF; display: flex; align-items: center; justify-content: center; font-family: var(--font-display); font-weight: 700; font-size: 13px; flex-shrink: 0; overflow: hidden; }
.user-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }

.container { padding: 30px 32px 64px; }
.page-intro { color: var(--ink-soft); font-size: 13.5px; margin-bottom: 22px; }

.role-section { margin-bottom: 26px; }
.role-section-header { display: flex; align-items: baseline; gap: 10px; margin-bottom: 12px; }
.role-section-header h2 { font-family: var(--font-display); font-size: 16px; color: var(--text-accent); }
.role-section-header .count-pill { background: var(--green-pale); color: var(--green-deep); font-size: 11.5px; font-weight: 700; padding: 3px 10px; border-radius: 999px; }
.empty-state { text-align: center; padding: 30px 24px; color: var(--ink-soft); font-size: 13.5px; background: var(--white); border: 1px solid var(--line); border-radius: 16px; }

/* ── Schedule grid — same tile design as supervisor_schedule.php ── */
.schedule-table-wrap { background: var(--white); border: 1px solid var(--line); border-radius: 16px; padding: 10px; overflow-x: auto; }
.schedule-table { width: 100%; min-width: 760px; table-layout: fixed; border-collapse: separate; border-spacing: 6px; }
.schedule-table thead th { background: var(--cream); border-radius: 8px; padding: 10px 8px; font-size: 11.5px; letter-spacing: .03em; color: var(--ink-soft); text-transform: uppercase; }
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
    <?php include 'includes/hr_sidebar.php'; ?>
    <div class="main-area">
        <div class="topbar">
            <h1>Employee Schedule</h1>
            <div class="user-info"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span><span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span></div>
        </div>
        <div class="container">
            <p class="page-intro">View-only — every active employee's weekly shift schedule, grouped by role. Set from Employee Management by HR/Admin.</p>

            <?php if (empty($allEmployees)): ?>
                <div class="empty-state">No active employees found.</div>
            <?php else: foreach ($GROUP_ORDER as $groupLabel):
                $members = $groups[$groupLabel];
                if (empty($members)) continue;
            ?>
                <div class="role-section">
                    <div class="role-section-header">
                        <h2><?= clean($groupLabel) ?></h2>
                        <span class="count-pill"><?= count($members) ?></span>
                    </div>
                    <div class="schedule-table-wrap">
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
                            <?php foreach ($members as $m): ?>
                                <tr>
                                    <td class="name-col">
                                        <div class="schedule-name-cell">
                                            <div class="emp-name"><?= clean($m['last_name'] . ', ' . $m['first_name']) ?></div>
                                            <div class="emp-meta"><?= clean($m['employee_code']) ?> · <?= clean($m['position'] ?: '—') ?></div>
                                        </div>
                                    </td>
                                    <?php foreach (array_keys($DAY_NAMES) as $dow): $sh = $m['weekly'][$dow] ?? null; ?>
                                        <?php if ($sh): $c = shiftColor($sh['shift_name'], $SHIFT_COLOR_PALETTE); ?>
                                            <td>
                                                <div class="shift-chip" style="background:<?= $c['bg'] ?>; color:<?= $c['text'] ?>;">
                                                    <div class="shift-name"><?= clean(employeeScheduleShiftLabel($sh['shift_name'])) ?></div>
                                                    <div class="shift-time"><?= clean(employeeScheduleTime($sh['start_time'] ?? null)) ?>–<?= clean(employeeScheduleTime($sh['end_time'] ?? null)) ?></div>
                                                </div>
                                            </td>
                                        <?php else: ?>
                                            <td>
                                                <div class="day-off-chip">Day Off</div>
                                            </td>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>
<?php include 'includes/sweetalert.php'; ?>
</body>
</html>