<?php
require_once 'includes/config.php';
requireHRManagerRole(); // Admin + HR Manager only — same scope as the dashboard

// =====================================================
// PERIOD SELECTION (month picker, defaults to current month)
// =====================================================
$month_param = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month_param)) {
    $month_param = date('Y-m');
}
$period_start = $month_param . '-01';
$period_end   = date('Y-m-t', strtotime($period_start)); // last day of that month
$today        = date('Y-m-d');
$query_end    = ($period_end > $today) ? $today : $period_end; // never query into the future
$period_label = date('F Y', strtotime($period_start));

// Adjust to your actual company policy — kept identical to the dashboard
// so the two views never disagree with each other.
define('HR_POLICY_LATE_THRESHOLD', 3);
define('HR_POLICY_ABSENT_THRESHOLD', 2);

// =====================================================
// 1) HR PERFORMANCE (HR Staff + HR Manager)
// =====================================================
$stmt = $pdo->prepare("
    SELECT u.id, u.full_name, e.employee_id, e.employee_code, e.department, e.status,
           SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_count,
           SUM(CASE WHEN a.late_minutes > 0 THEN 1 ELSE 0 END) AS late_count,
           SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
           SUM(CASE WHEN a.status = 'leave' THEN 1 ELSE 0 END) AS on_leave_count,
           COUNT(a.attendance_id) AS days_recorded
    FROM users u
    LEFT JOIN employees e ON e.employee_id = u.employee_id
    LEFT JOIN attendance a ON a.employee_id = e.employee_id AND a.date BETWEEN ? AND ?
    WHERE u.role IN ('hr', 'hr_manager') AND u.is_active = 1
    GROUP BY u.id
    ORDER BY u.full_name
");
$stmt->execute([$period_start, $query_end]);
$hr_staff_performance = $stmt->fetchAll();

$hr_staff_totals = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0];
foreach ($hr_staff_performance as $row) {
    $hr_staff_totals['present'] += (int) $row['present_count'];
    $hr_staff_totals['late']    += (int) $row['late_count'];
    $hr_staff_totals['absent']  += (int) $row['absent_count'];
    $hr_staff_totals['leave']   += (int) $row['on_leave_count'];
}

// =====================================================
// 2) EMPLOYEE HR POLICY COMPLIANCE
// =====================================================
$stmt = $pdo->prepare("
    SELECT e.employee_id, e.employee_code, e.first_name, e.last_name, e.department,
           SUM(CASE WHEN a.late_minutes > 0 THEN 1 ELSE 0 END) AS late_count,
           SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
           SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_count
    FROM attendance a
    INNER JOIN employees e ON e.employee_id = a.employee_id
    INNER JOIN users u ON u.employee_id = e.employee_id
    WHERE a.date BETWEEN ? AND ? AND u.role = 'employee' AND u.is_active = 1
    GROUP BY e.employee_id
    ORDER BY (SUM(CASE WHEN a.late_minutes > 0 THEN 1 ELSE 0 END) + SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END)) DESC, e.last_name
");
$stmt->execute([$period_start, $query_end]);
$employee_attendance = $stmt->fetchAll();

$policy_flagged = array_filter($employee_attendance, function ($row) {
    return $row['late_count'] >= HR_POLICY_LATE_THRESHOLD || $row['absent_count'] >= HR_POLICY_ABSENT_THRESHOLD;
});

$policy_employee_total = (int) $pdo->query("
    SELECT COUNT(*) FROM employees e
    INNER JOIN users u ON u.employee_id = e.employee_id
    WHERE u.role = 'employee' AND u.is_active = 1
")->fetchColumn();

// =====================================================
// 3) LEAVE REQUEST ACTIVITY FOR THE PERIOD (by requester type)
// =====================================================
$stmt = $pdo->prepare("
    SELECT
        COALESCE(u.role, 'employee') AS requester_role,
        lr.status,
        COUNT(*) AS cnt
    FROM leave_requests lr
    JOIN employees e ON e.employee_id = lr.employee_id
    LEFT JOIN users u ON u.employee_id = e.employee_id
    WHERE lr.requested_at BETWEEN ? AND ?
    GROUP BY requester_role, lr.status
");
$stmt->execute([$period_start . ' 00:00:00', $query_end . ' 23:59:59']);
$leave_activity_raw = $stmt->fetchAll();

$leave_summary = [
    'employee' => ['pending' => 0, 'approved' => 0, 'rejected' => 0],
    'hr'       => ['pending' => 0, 'approved' => 0, 'rejected' => 0],
];
foreach ($leave_activity_raw as $row) {
    $role = ($row['requester_role'] === 'hr') ? 'hr' : 'employee';
    if (isset($leave_summary[$role][$row['status']])) {
        $leave_summary[$role][$row['status']] = (int) $row['cnt'];
    }
}

function reportPct($num, $den) {
    if ($den <= 0) return '—';
    return round(($num / $den) * 100, 1) . '%';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HR Reports — <?= clean($period_label) ?></title>
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

/* ── Sidebar (same as the rest of HRMS) ── */
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
.nav-link--logout:hover { background: rgba(200,71,45,0.18); color: var(--salmon-bg, #ffb3a3); }
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

.topbar { background: var(--white); border-bottom: 1px solid var(--line); padding: 18px 32px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.topbar h1 { font-family: var(--font-display); font-size: 20px; color: var(--text-accent); }
.topbar .user-info { font-size: 13.5px; color: var(--ink-soft); display: flex; align-items: center; gap: 16px; }
.user-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--mustard); color: #FFFFFF; display: flex; align-items: center; justify-content: center; font-family: var(--font-display); font-weight: 700; font-size: 13px; flex-shrink: 0; overflow: hidden; }
.user-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }

.container { padding: 30px 32px 64px; }

.toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; }
.toolbar form { display: flex; align-items: center; gap: 10px; }
.toolbar label { font-size: 13.5px; font-weight: 600; color: var(--ink-soft); }
.toolbar input[type="month"] { padding: 9px 12px; border: 1px solid var(--line); border-radius: 10px; font-family: var(--font-body); font-size: 14px; }
.btn { padding: 10px 18px; border: none; border-radius: 10px; font-family: var(--font-body); font-size: 14px; font-weight: 600; cursor: pointer; transition: background .15s ease; }
.btn-primary { background: linear-gradient(135deg, var(--green-mid), var(--green-deep)); color: #FFFFFF; box-shadow: 0 2px 8px rgba(18,60,92,0.22); }
.btn-primary:hover { filter: brightness(1.08); box-shadow: 0 5px 16px rgba(18,60,92,0.32); transform: translateY(-2px); }
.btn-primary:active { transform: translateY(0); filter: brightness(0.97); }
.btn-outline { background: var(--white); color: var(--text-accent); border: 1px solid var(--green-mid); }
.btn-outline:hover { background: var(--green-pale); }

.report-head { background: var(--white); border: 1px solid var(--line); border-radius: 16px; padding: 24px; margin-bottom: 24px; }
.report-head h2 { font-family: var(--font-display); font-size: 22px; color: var(--text-accent); }
.report-head .meta { font-size: 13px; color: var(--ink-soft); margin-top: 6px; }

.kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 16px; margin-bottom: 28px; }
.kpi-card { background: var(--white); border: 1px solid var(--line); border-radius: 16px; padding: 20px; border-top: 3px solid var(--green-mid); }
.kpi-card.accent-mustard { border-top-color: var(--mustard); }
.kpi-label { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-soft); font-weight: 600; margin-bottom: 10px; }
.kpi-value { font-family: var(--font-display); font-size: 30px; color: var(--text-accent); line-height: 1; }
.kpi-sub { font-size: 12.5px; color: var(--ink-soft); margin-top: 6px; }

.section { margin-bottom: 28px; }
.section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
.section-header h2 { font-family: var(--font-display); font-size: 18px; color: var(--text-accent); }

.note-banner { background: var(--mustard-pale); color: var(--mustard-text2, #97661F); border-radius: 12px; padding: 12px 16px; font-size: 13px; margin-bottom: 16px; }

.table-card { background: var(--white); border: 1px solid var(--line); border-radius: 16px; overflow: hidden; }
.table-scroll { max-height: 460px; overflow-y: auto; }
.table-scroll thead th { position: sticky; top: 0; z-index: 1; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 12.5px; text-transform: uppercase; letter-spacing: .03em; color: var(--ink-soft); background: var(--cream); padding: 12px 16px; border-bottom: 1px solid var(--line); }
td { padding: 14px 16px; font-size: 14px; border-bottom: 1px solid var(--line); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--gray-bg, #FAFAF7); }
tfoot td { font-weight: 700; background: var(--cream); }

.badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.empty-state { text-align: center; padding: 40px 24px; color: var(--ink-soft); font-size: 14px; }

.panel-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.panel { background: var(--white); border: 1px solid var(--line); border-radius: 16px; padding: 24px; }
.panel h3 { font-family: var(--font-display); font-size: 16px; color: var(--text-accent); margin-bottom: 14px; }
.stat-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--line); font-size: 14px; }
.stat-row:last-child { border-bottom: none; }
.stat-row .stat-label { color: var(--ink-soft); }
.stat-row .stat-value { font-weight: 700; }

@media (max-width: 900px) { .panel-grid { grid-template-columns: 1fr; } }
@media (max-width: 640px) { .container { padding: 20px 16px; } .topbar { padding: 14px 16px; } }

@media print {
    .sidebar, .toolbar, .no-print { display: none !important; }
    .main-area { margin-left: 0 !important; }
    body { background: var(--white); }
    .table-scroll { max-height: none !important; overflow: visible !important; }
    .section { break-inside: avoid; }
}
</style>
</head>
<body>

<div class="app-shell">

    <?php $activeNav = 'hr_reports'; include 'includes/hr_sidebar.php'; ?>

    <div class="main-area">

    <div class="topbar">
        <h1>HRMS &amp; Payroll — HR Reports</h1>
        <div class="user-info">
            <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span>
        <span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span>
        </div>
    </div>

    <div class="container">

        <div class="toolbar">
            <form method="GET">
                <label for="month">Report Period</label>
                <input type="month" id="month" name="month" value="<?= clean($month_param) ?>" max="<?= date('Y-m') ?>">
                <button type="submit" class="btn btn-primary">Generate</button>
            </form>
            <div class="no-print" style="display:flex; gap:10px;">
                <button type="button" class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
            </div>
        </div>

        <div class="report-head">
            <h2>Monthly HR Report — <?= clean($period_label) ?></h2>
            <div class="meta">
                Generated by <?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)
                on <?= clean(date('M d, Y g:i A')) ?>
                <?php if ($query_end < $period_end): ?>
                    &middot; Period in progress — figures reflect <?= clean(date('M d, Y', strtotime($period_start))) ?>
                    to <?= clean(date('M d, Y', strtotime($query_end))) ?> so far.
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================= -->
        <!-- OVERVIEW KPIs                                  -->
        <!-- ============================================= -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">HR Staff & Manager (active)</div>
                <div class="kpi-value"><?= count($hr_staff_performance) ?></div>
                <div class="kpi-sub"><?= $hr_staff_totals['late'] ?> late &middot; <?= $hr_staff_totals['absent'] ?> absent this period</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Active Employees</div>
                <div class="kpi-value"><?= $policy_employee_total ?></div>
                <div class="kpi-sub">under HR policy monitoring</div>
            </div>
            <div class="kpi-card accent-mustard">
                <div class="kpi-label">Policy-Flagged Employees</div>
                <div class="kpi-value"><?= count($policy_flagged) ?></div>
                <div class="kpi-sub"><?= reportPct(count($policy_flagged), $policy_employee_total) ?> of active employees</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Leave Requests (period)</div>
                <div class="kpi-value"><?= array_sum($leave_summary['employee']) + array_sum($leave_summary['hr']) ?></div>
                <div class="kpi-sub"><?= $leave_summary['employee']['pending'] + $leave_summary['hr']['pending'] ?> still pending</div>
            </div>
        </div>

        <!-- ============================================= -->
        <!-- HR PERFORMANCE (HR Staff + HR Manager)          -->
        <!-- ============================================= -->
        <div class="section">
            <div class="section-header"><h2>HR Performance</h2></div>
            <div class="table-card"><div class="table-scroll">
                <table>
                    <thead>
                        <tr><th>Code</th><th>Name</th><th>Department</th><th>Present</th><th>Late</th><th>Absent</th><th>On Leave</th><th>Attendance Rate</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($hr_staff_performance)): ?>
                        <tr><td colspan="8"><div class="empty-state">No active HR Staff or HR Manager account.</div></td></tr>
                        <?php else: foreach ($hr_staff_performance as $hs): ?>
                        <tr>
                            <td><?= clean($hs['employee_code'] ?? '—') ?></td>
                            <td><?= clean($hs['full_name']) ?></td>
                            <td><?= clean($hs['department'] ?: '—') ?></td>
                            <td><?= (int) $hs['present_count'] ?></td>
                            <td><?= (int) $hs['late_count'] ?></td>
                            <td><?= (int) $hs['absent_count'] ?></td>
                            <td><?= (int) $hs['on_leave_count'] ?></td>
                            <td><?= reportPct((int) $hs['present_count'], (int) $hs['days_recorded']) ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <?php if (!empty($hr_staff_performance)): ?>
                    <tfoot>
                        <tr>
                            <td colspan="3">Totals</td>
                            <td><?= $hr_staff_totals['present'] ?></td>
                            <td><?= $hr_staff_totals['late'] ?></td>
                            <td><?= $hr_staff_totals['absent'] ?></td>
                            <td><?= $hr_staff_totals['leave'] ?></td>
                            <td>—</td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div></div>
        </div>

        <!-- ============================================= -->
        <!-- EMPLOYEE HR POLICY COMPLIANCE                  -->
        <!-- ============================================= -->
        <div class="section" id="policy-compliance">
            <div class="section-header"><h2>Employee HR Policy Compliance</h2></div>
            <div class="note-banner">
                Flagged once an Employee reaches <strong><?= HR_POLICY_LATE_THRESHOLD ?>+ late</strong> arrivals or
                <strong><?= HR_POLICY_ABSENT_THRESHOLD ?>+ absences</strong> within the period.
            </div>
            <div class="table-card"><div class="table-scroll">
                <table>
                    <thead>
                        <tr><th>Code</th><th>Name</th><th>Department</th><th>Present</th><th>Late</th><th>Absent</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employee_attendance)): ?>
                        <tr><td colspan="7"><div class="empty-state">No Employee attendance recorded for this period yet.</div></td></tr>
                        <?php else: foreach ($employee_attendance as $ea):
                            $flagged = $ea['late_count'] >= HR_POLICY_LATE_THRESHOLD || $ea['absent_count'] >= HR_POLICY_ABSENT_THRESHOLD;
                        ?>
                        <tr>
                            <td><?= clean($ea['employee_code'] ?? '—') ?></td>
                            <td><?= clean($ea['last_name'] . ', ' . $ea['first_name']) ?></td>
                            <td><?= clean($ea['department'] ?: '—') ?></td>
                            <td><?= (int) $ea['present_count'] ?></td>
                            <td><?= (int) $ea['late_count'] ?></td>
                            <td><?= (int) $ea['absent_count'] ?></td>
                            <td>
                                <?php if ($flagged): ?>
                                    <span class="badge" style="background:var(--red-pale); color:var(--red-alert);">Needs Review</span>
                                <?php else: ?>
                                    <span class="badge" style="background:var(--green-pale); color:var(--text-accent);">Within Policy</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div>

        <!-- ============================================= -->
        <!-- LEAVE REQUEST ACTIVITY                         -->
        <!-- ============================================= -->
        <div class="section">
            <div class="section-header"><h2>Leave Request Activity</h2></div>
            <div class="panel-grid">
                <div class="panel">
                    <h3>Employees</h3>
                    <div class="stat-row"><span class="stat-label">Pending</span><span class="stat-value"><?= $leave_summary['employee']['pending'] ?></span></div>
                    <div class="stat-row"><span class="stat-label">Approved</span><span class="stat-value"><?= $leave_summary['employee']['approved'] ?></span></div>
                    <div class="stat-row"><span class="stat-label">Rejected</span><span class="stat-value"><?= $leave_summary['employee']['rejected'] ?></span></div>
                </div>
                <div class="panel">
                    <h3>HR Staff</h3>
                    <div class="stat-row"><span class="stat-label">Pending</span><span class="stat-value"><?= $leave_summary['hr']['pending'] ?></span></div>
                    <div class="stat-row"><span class="stat-label">Approved</span><span class="stat-value"><?= $leave_summary['hr']['approved'] ?></span></div>
                    <div class="stat-row"><span class="stat-label">Rejected</span><span class="stat-value"><?= $leave_summary['hr']['rejected'] ?></span></div>
                </div>
            </div>
        </div>

    </div>
    </div>
</div>

<?php include 'includes/sweetalert.php'; ?>
</body>
</html>