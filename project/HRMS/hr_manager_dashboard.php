<?php
require_once 'includes/config.php';
requireHRManagerRole(); // Admin + HR Manager only

$success_message = "";
$error_message   = "";

// =====================================================
// HANDLE FORM SUBMISSIONS
// (HR Manager scope: can approve/reject leave requests from HR Staff
//  themselves — not from Employees, that's view-only here)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    requireCSRF();
    $action = $_POST['action'] ?? '';

    if ($action == 'approve_leave' || $action == 'reject_leave') {

        $leave_id   = $_POST['leave_id'] ?? 0;
        $new_status = $action == 'approve_leave' ? 'approved' : 'rejected';

        $result = processLeaveDecision($pdo, $leave_id, $new_status, $_SESSION['user_id']);
        if ($result['ok']) {
            $success_message = $result['message'];
        } else {
            $error_message = $result['message'];
        }
    }
}

function getLeaveTypeLabel($type) {
    $map = [
        'sick'      => 'Sick Leave',
        'vacation'  => 'Vacation Leave',
        'emergency' => 'Emergency Leave',
        'others'    => 'Others',
    ];
    return $map[$type] ?? ucfirst($type);
}

function daysBetween($from, $to) {
    return (strtotime($to) - strtotime($from)) / 86400 + 1;
}

// =====================================================
// HR STAFF STATS (the people under the HR Manager's oversight)
// =====================================================
$hr_staff_count = (int) $pdo->query("
    SELECT COUNT(*) FROM users WHERE role = 'hr' AND is_active = 1
")->fetchColumn();

$hr_staff_roster = $pdo->query("
    SELECT u.id, u.full_name, u.username, e.employee_id, e.employee_code, e.department, e.status
    FROM users u
    LEFT JOIN employees e ON e.employee_id = u.employee_id
    WHERE u.role = 'hr' AND u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();

// =====================================================
// LEAVE REQUESTS FROM HR STAFF (this is what the HR Manager decides on —
// Employee leave requests are handled by HR Staff/Admin instead)
// =====================================================
$stmt = $pdo->prepare("
    SELECT lr.*, e.first_name, e.last_name, e.employee_code
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.employee_id
    JOIN users u ON u.employee_id = e.employee_id
    WHERE lr.status = 'pending' AND u.role = 'hr'
    ORDER BY lr.requested_at ASC
");
$stmt->execute();
$hr_staff_pending_leaves = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT lr.*, e.first_name, e.last_name, e.employee_code, ua.full_name as approver_name, ua.role as approver_role
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.employee_id
    JOIN users u ON u.employee_id = e.employee_id
    LEFT JOIN users ua ON lr.approved_by = ua.id
    WHERE lr.status IN ('approved', 'rejected') AND u.role = 'hr'
    ORDER BY COALESCE(lr.decided_at, lr.requested_at) DESC
    LIMIT 15
");
$stmt->execute();
$hr_staff_leave_decisions = $stmt->fetchAll();

// =====================================================
// ATTENDANCE TODAY — HR Staff only (Employees are excluded, since
// that's view-only for the HR Manager; the attendance the HR
// Manager can encode/edit is HR Staff's own attendance)
// =====================================================
$attendance_stats = ['present' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0, 'day_off' => 0];
$stmt = $pdo->prepare("
    SELECT a.status, COUNT(*) as cnt
    FROM attendance a
    INNER JOIN employees e ON e.employee_id = a.employee_id
    INNER JOIN users u ON u.employee_id = e.employee_id
    WHERE a.date = CURDATE() AND u.role = 'hr' AND u.is_active = 1
    GROUP BY a.status
");
$stmt->execute();
foreach ($stmt->fetchAll() as $row) {
    $attendance_stats[$row['status']] = (int) $row['cnt'];
}
$late_today = (int) $pdo->query("
    SELECT COUNT(*)
    FROM attendance a
    INNER JOIN employees e ON e.employee_id = a.employee_id
    INNER JOIN users u ON u.employee_id = e.employee_id
    WHERE a.date = CURDATE() AND a.late_minutes > 0 AND u.role = 'hr' AND u.is_active = 1
")->fetchColumn();
$no_record_today = max(0, $hr_staff_count - array_sum($attendance_stats));

// =====================================================
// PAYROLL OVERVIEW — view-only, the payroll runs made by HR Staff
// FOR the Employees (this is the "what HR Staff has been doing"
// view the HR Manager should see)
// =====================================================
$latest_period = $pdo->query("
    SELECT pr.pay_period_start, pr.pay_period_end, pr.payout_date
    FROM payroll_records pr
    INNER JOIN employees e ON e.employee_id = pr.employee_id
    LEFT JOIN users u ON u.employee_id = e.employee_id
    WHERE COALESCE(u.role, 'employee') = 'employee'
    ORDER BY pr.pay_period_end DESC, pr.generated_at DESC
    LIMIT 1
")->fetch();

$payroll_summary = null;
if ($latest_period) {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT pr.employee_id) as paid_count, SUM(pr.gross_pay) as total_gross, SUM(pr.net_pay) as total_net
        FROM payroll_records pr
        INNER JOIN employees e ON e.employee_id = pr.employee_id
        LEFT JOIN users u ON u.employee_id = e.employee_id
        WHERE pr.pay_period_end = ? AND COALESCE(u.role, 'employee') = 'employee'
    ");
    $stmt->execute([$latest_period['pay_period_end']]);
    $payroll_summary = $stmt->fetch();
}

// =====================================================
// HR STAFF PERFORMANCE (this month) — augments the roster
// below with actual oversight data (attendance + leave usage),
// not just names. This is what "oversee HR staff" means here.
// =====================================================
$month_start = date('Y-m-01');
$month_today = date('Y-m-d'); // up to today only — future dates have no attendance yet

$stmt = $pdo->prepare("
    SELECT e.employee_id,
           SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_count,
           SUM(CASE WHEN a.late_minutes > 0 THEN 1 ELSE 0 END) AS late_count,
           SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
           COUNT(a.attendance_id) AS days_recorded
    FROM attendance a
    INNER JOIN employees e ON e.employee_id = a.employee_id
    INNER JOIN users u ON u.employee_id = e.employee_id
    WHERE a.date BETWEEN ? AND ? AND u.role = 'hr'
    GROUP BY e.employee_id
");
$stmt->execute([$month_start, $month_today]);
$hr_staff_perf_by_emp = [];
foreach ($stmt->fetchAll() as $row) {
    $hr_staff_perf_by_emp[$row['employee_id']] = $row;
}

$stmt = $pdo->prepare("
    SELECT e.employee_id, COUNT(*) AS approved_leave_count
    FROM leave_requests lr
    JOIN employees e ON e.employee_id = lr.employee_id
    JOIN users u ON u.employee_id = e.employee_id
    WHERE lr.status = 'approved' AND u.role = 'hr' AND lr.date_from BETWEEN ? AND ?
    GROUP BY e.employee_id
");
$stmt->execute([$month_start, $month_today]);
$hr_staff_leave_count_by_emp = [];
foreach ($stmt->fetchAll() as $row) {
    $hr_staff_leave_count_by_emp[$row['employee_id']] = (int) $row['approved_leave_count'];
}

// =====================================================
// EMPLOYEE HR POLICY COMPLIANCE (this month) — flags Employees
// whose attendance record breaches the company's late/absence
// policy. This is the "Monitor employee performance vs. HR
// Policies" view; Employees themselves stay read-only for the
// HR Manager everywhere else, this panel is read-only too.
// Adjust the two thresholds below to match your actual company policy.
// =====================================================
define('HR_POLICY_LATE_THRESHOLD', 3);   // flag once an employee is late this many times in the month
define('HR_POLICY_ABSENT_THRESHOLD', 2); // flag once an employee is absent this many times in the month

$stmt = $pdo->prepare("
    SELECT * FROM (
        SELECT e.employee_id, e.employee_code, e.first_name, e.last_name, e.department,
               SUM(CASE WHEN a.late_minutes > 0 THEN 1 ELSE 0 END) AS late_count,
               SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
               SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_count
        FROM attendance a
        INNER JOIN employees e ON e.employee_id = a.employee_id
        INNER JOIN users u ON u.employee_id = e.employee_id
        WHERE a.date BETWEEN ? AND ? AND u.role = 'employee' AND u.is_active = 1
        GROUP BY e.employee_id
    ) policy_agg
    WHERE late_count >= ? OR absent_count >= ?
    ORDER BY (late_count + absent_count) DESC
");
$stmt->execute([$month_start, $month_today, HR_POLICY_LATE_THRESHOLD, HR_POLICY_ABSENT_THRESHOLD]);
$policy_flagged_employees = $stmt->fetchAll();

$policy_employee_total = (int) $pdo->query("
    SELECT COUNT(*) FROM employees e
    INNER JOIN users u ON u.employee_id = e.employee_id
    WHERE u.role = 'employee' AND u.is_active = 1
")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HR Manager Dashboard — HRMS &amp; Payroll</title>
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

html, body {
    min-height: 100vh;
    font-family: var(--font-body);
    color: var(--ink);
    background: var(--cream);
}

a { text-decoration: none; color: inherit; }

/* ── Sidebar (same look/size as the POS "My Profile" sidebar) ── */
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

.topbar {
    background: var(--white);
    border-bottom: 1px solid var(--line);
    padding: 18px 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.topbar h1 { font-family: var(--font-display); font-size: 20px; color: var(--text-accent); }
.topbar .user-info { font-size: 13.5px; color: var(--ink-soft); display: flex; align-items: center; gap: 16px; }
.user-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--mustard); color: #FFFFFF; display: flex; align-items: center; justify-content: center; font-family: var(--font-display); font-weight: 700; font-size: 13px; flex-shrink: 0; overflow: hidden; }
.user-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.topbar a.logout { color: var(--red-alert); font-weight: 600; font-size: 13.5px; }

.container { padding: 30px 32px 64px; }

body.dashboard-page--hr-manager .main-area > .container {
    width: 100%;
    max-width: none;
    margin: 0;
    padding: clamp(20px, 2vw, 32px) clamp(18px, 2.2vw, 36px) 48px;
}

.alert { padding: 12px 16px; border-radius: 12px; font-size: 14px; font-weight: 500; margin-bottom: 20px; }
.alert--success { background: var(--green-succ-bg, #e6f4ea); color: var(--green-succ-text, #1a7a3b); }
.alert--error   { background: var(--red-pale); color: var(--red-alert); }

.btn {
    padding: 10px 18px;
    border: none;
    border-radius: 10px;
    font-family: var(--font-body);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: filter .15s ease, box-shadow .15s ease, transform .15s ease, background .15s ease, border-color .15s ease;
}
.btn-success { background: var(--green-pale); color: var(--text-accent); }
.btn-success:hover { background: var(--green-succ-bg, #d5ebda); transform: translateY(-2px); box-shadow: 0 3px 10px rgba(18,60,92,0.1); }
.btn-success:active { transform: translateY(0); box-shadow: none; }
.btn-danger { background: var(--red-pale); color: var(--red-alert); }
.btn-danger:hover { background: var(--salmon-bg, #f5d3ca); transform: translateY(-2px); box-shadow: 0 3px 10px rgba(200,71,45,0.16); }
.btn-danger:active { transform: translateY(0); box-shadow: none; }
.btn-sm { padding: 6px 12px; font-size: 12.5px; }

/* KPI cards */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}
body.dashboard-page--hr-manager .kpi-grid {
    grid-template-columns: repeat(5, minmax(0, 1fr));
}
.kpi-card {
    background: var(--white);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 20px;
    border-top: 3px solid var(--green-mid);
}
.kpi-card.accent-mustard { border-top-color: var(--mustard); }
.kpi-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--ink-soft);
    font-weight: 600;
    margin-bottom: 10px;
}
.kpi-value { font-family: var(--font-display); font-size: 30px; color: var(--text-accent); line-height: 1; }
.kpi-sub { font-size: 12.5px; color: var(--ink-soft); margin-top: 6px; }

/* Sections & panels */
.section { margin-bottom: 28px; }
.section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
.section-header h2 { font-family: var(--font-display); font-size: 18px; color: var(--text-accent); }

.panel-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.panel { background: var(--white); border: 1px solid var(--line); border-radius: 16px; padding: 24px; }
.panel h3 { font-family: var(--font-display); font-size: 16px; color: var(--text-accent); margin-bottom: 14px; }
.stat-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--line); font-size: 14px; }
.stat-row:last-child { border-bottom: none; }
.stat-row .stat-label { color: var(--ink-soft); }
.stat-row .stat-value { font-weight: 700; }

.table-card { background: var(--white); border: 1px solid var(--line); border-radius: 16px; overflow: hidden; }
.table-scroll { max-height: 420px; overflow-y: auto; }
.table-scroll thead th { position: sticky; top: 0; z-index: 1; }
.table-scroll-compact { max-height: 260px; overflow-y: auto; }
.table-scroll-compact thead th { position: sticky; top: 0; z-index: 1; }
table { width: 100%; border-collapse: collapse; }
th {
    text-align: left;
    font-size: 12.5px;
    text-transform: uppercase;
    letter-spacing: .03em;
    color: var(--ink-soft);
    background: var(--cream);
    padding: 12px 16px;
    border-bottom: 1px solid var(--line);
}
td { padding: 14px 16px; font-size: 14px; border-bottom: 1px solid var(--line); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--gray-bg, #FAFAF7); }

.badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.actions-cell { display: flex; gap: 6px; flex-wrap: wrap; }
.empty-state { text-align: center; padding: 40px 24px; color: var(--ink-soft); font-size: 14px; }

.payslip-details { font-size: 12.5px; color: var(--ink-soft); margin-top: 4px; }
.payslip-details span { display: inline-block; margin-right: 14px; }

.note-banner {
    background: var(--mustard-pale);
    color: var(--mustard-text2, #97661F);
    border-radius: 12px;
    padding: 12px 16px;
    font-size: 13px;
    margin-bottom: 24px;
}

@media (max-width: 900px) { .panel-grid { grid-template-columns: 1fr; } }
@media (max-width: 1100px) {
    body.dashboard-page--hr-manager .kpi-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}
@media (max-width: 700px) {
    body.dashboard-page--hr-manager .kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 480px) {
    body.dashboard-page--hr-manager .kpi-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
@media (max-width: 640px) { .container { padding: 20px 16px; } .topbar { padding: 14px 16px; flex-wrap: wrap; gap: 8px; } }
</style>
<link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>">
</head>
<body class="dashboard-page dashboard-page--hr-manager">

<div class="app-shell">

    <?php $activeNav = 'dashboard'; include 'includes/hr_sidebar.php'; ?>

    <div class="main-area">

    <div class="topbar">
        <h1>HRMS &amp; Payroll — HR Manager Dashboard</h1>
        <div class="user-info">
            <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span>
        <span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span>
        </div>
    </div>

    <div class="container">
        <section class="dashboard-hero" aria-labelledby="dashboard-greeting">
            <div class="dashboard-hero__copy">
                <span class="dashboard-hero__label">HR Manager</span>
                <h2 id="dashboard-greeting"><?= date('G') < 12 ? 'Good morning' : (date('G') < 18 ? 'Good afternoon' : 'Good evening') ?>, <?= clean($_SESSION['full_name'] ?? 'HR team') ?>.</h2>
                <p>Your people, positions, and pipelines at a glance.</p>
            </div>
            <a class="dashboard-primary-action" href="hr_reports.php">Open HR reports <span aria-hidden="true">→</span></a>
        </section>

        <!-- ============================================= -->
        <!-- KPI OVERVIEW                                   -->
        <!-- ============================================= -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">HR Staff (active)</div>
            <div class="kpi-value"><?= $hr_staff_count ?></div>
            <div class="kpi-sub">under your oversight</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Present Today</div>
            <div class="kpi-value"><?= $attendance_stats['present'] ?></div>
            <div class="kpi-sub"><?= $late_today ?> late · <?= $attendance_stats['absent'] ?> absent</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">On Leave / Holiday</div>
            <div class="kpi-value"><?= $attendance_stats['leave'] + $attendance_stats['holiday'] ?></div>
            <div class="kpi-sub">HR Staff today</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">No Record Yet</div>
            <div class="kpi-value"><?= $no_record_today ?></div>
            <div class="kpi-sub">HR Staff, today</div>
        </div>
        <div class="kpi-card accent-mustard">
            <div class="kpi-label">Policy-Flagged Employees</div>
            <div class="kpi-value"><?= count($policy_flagged_employees) ?></div>
            <div class="kpi-sub">of <?= $policy_employee_total ?> active, this month</div>
        </div>
    </div>

    <div style="background:var(--mustard-pale); border:1px solid var(--mustard); border-radius:10px; padding:12px 16px; margin-bottom:20px; font-size:13.5px; color:var(--mustard-text2, #7A5A1E);">
        You are logged in as <strong>HR Manager</strong>. This role has view-only access to Employee attendance/payroll
        (what HR Staff has done) — but you can encode attendance for HR Staff themselves, and you can also
        create a <strong>Payroll Run</strong> and <strong>13th Month Pay</strong>.
    </div>

    <?php if (!empty($success_message)): ?>
        <div class="alert alert--success"><?= clean($success_message) ?></div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="alert alert--error"><?= clean($error_message) ?></div>
    <?php endif; ?>

    <div style="background:var(--white); border:1px solid var(--line); border-radius:16px; padding:18px 24px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <div style="font-family:'Sora',sans-serif; color:var(--text-accent); font-size:16px; font-weight:700;">My Profile</div>
            <div style="color:var(--ink-soft); font-size:13.5px; margin-top:2px;">Time In/Out, your details, attendance calendar, and payslips moved to their own page.</div>
        </div>
        <a href="my_profile.php" style="background:var(--green-mid); color: #FFFFFF; padding:10px 18px; border-radius:10px; font-weight:600; font-size:14px;">Go to My Profile →</a>
    </div>

    <div style="background:var(--white); border:1px solid var(--line); border-radius:16px; padding:18px 24px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <div style="font-family:'Sora',sans-serif; color:var(--text-accent); font-size:16px; font-weight:700;">HR Reports</div>
            <div style="color:var(--ink-soft); font-size:13.5px; margin-top:2px;">Generate a printable monthly report: HR Staff performance, employee policy compliance, and leave activity.</div>
        </div>
        <a href="hr_reports.php" style="background:var(--green-mid); color: #FFFFFF; padding:10px 18px; border-radius:10px; font-weight:600; font-size:14px;">Generate Report →</a>
    </div>

    <!-- ============================================= -->
    <!-- ATTENDANCE + PAYROLL PANELS                    -->
    <!-- ============================================= -->
    <div class="section">
        <div class="panel-grid">
            <div class="panel">
                <h3><a href="attendance.php" style="color:inherit;">HR Staff Attendance Today (<?= date('M d, Y') ?>) &rarr;</a></h3>
                <div class="stat-row"><span class="stat-label">Present</span><span class="stat-value"><?= $attendance_stats['present'] ?></span></div>
                <div class="stat-row"><span class="stat-label">Late</span><span class="stat-value"><?= $late_today ?></span></div>
                <div class="stat-row"><span class="stat-label">Absent</span><span class="stat-value"><?= $attendance_stats['absent'] ?></span></div>
                <div class="stat-row"><span class="stat-label">On Leave</span><span class="stat-value"><?= $attendance_stats['leave'] ?></span></div>
                <div class="stat-row"><span class="stat-label">Holiday</span><span class="stat-value"><?= $attendance_stats['holiday'] ?></span></div>
                <div class="stat-row"><span class="stat-label">Day Off (Rest Day)</span><span class="stat-value"><?= $attendance_stats['day_off'] ?></span></div>
                <div class="stat-row"><span class="stat-label">No record yet</span><span class="stat-value"><?= $no_record_today ?></span></div>
                <p style="margin-top:10px; font-size:12px; color:var(--ink-soft);">Note: You'll also see Employee attendance encoded by HR Staff here — but this is view-only for you.</p>
            </div>
            <div class="panel">
                <h3><a href="payroll/payroll.php" style="color:inherit;">Employee Payroll &rarr;</a></h3>
                <?php if ($latest_period): ?>
                    <div class="stat-row">
                        <span class="stat-label">Latest Period</span>
                        <span class="stat-value" style="font-weight:600;">
                            <?= clean(date('M d', strtotime($latest_period['pay_period_start']))) ?>–<?= clean(date('M d, Y', strtotime($latest_period['pay_period_end']))) ?>
                        </span>
                    </div>
                    <?php if (!empty($latest_period['payout_date'])): ?>
                    <div class="stat-row"><span class="stat-label">Payout Date</span><span class="stat-value"><?= clean(date('M d, Y', strtotime($latest_period['payout_date']))) ?></span></div>
                    <?php endif; ?>
                    <div class="stat-row"><span class="stat-label">Employees Paid</span><span class="stat-value"><?= (int)($payroll_summary['paid_count'] ?? 0) ?></span></div>
                    <div class="stat-row"><span class="stat-label">Total Gross Pay</span><span class="stat-value"><?= peso($payroll_summary['total_gross'] ?? 0) ?></span></div>
                    <div class="stat-row"><span class="stat-label">Total Net Pay</span><span class="stat-value"><?= peso($payroll_summary['total_net'] ?? 0) ?></span></div>
                <?php else: ?>
                    <div class="empty-state">No payroll records generated by HR Staff yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ============================================= -->
    <!-- PENDING LEAVE REQUESTS FROM HR STAFF           -->
    <!-- ============================================= -->
    <div class="section" id="leave-pending">
        <div class="section-header"><h2>Pending Leave Requests <span style="color:var(--ink-soft); font-weight:500; font-size:14px;">— HR Staff</span></h2></div>
        <div class="table-card"><div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>HR Staff</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Days</th>
                        <th>Reason</th>
                        <th>Requested</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($hr_staff_pending_leaves)): ?>
                    <tr><td colspan="7"><div class="empty-state">No pending leave requests from HR Staff.</div></td></tr>
                    <?php else: foreach ($hr_staff_pending_leaves as $lv): ?>
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

    <!-- ============================================= -->
    <!-- RECENT LEAVE DECISIONS — HR STAFF              -->
    <!-- ============================================= -->
    <div class="section" id="leave-decisions">
        <div class="section-header"><h2>Recent Leave Decisions <span style="color:var(--ink-soft); font-weight:500; font-size:14px;">— HR Staff</span></h2></div>
        <div class="table-card"><div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>HR Staff</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Status</th>
                        <th>Decided On</th>
                        <th>Decided By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($hr_staff_leave_decisions)): ?>
                    <tr><td colspan="6"><div class="empty-state">No processed leave requests from HR Staff yet.</div></td></tr>
                    <?php else: foreach ($hr_staff_leave_decisions as $lv): ?>
                    <tr>
                        <td><?= clean($lv['last_name'] . ', ' . $lv['first_name']) ?></td>
                        <td><?= clean(getLeaveTypeLabel($lv['leave_type'])) ?></td>
                        <td><?= clean(date('M d', strtotime($lv['date_from']))) ?>–<?= clean(date('M d, Y', strtotime($lv['date_to']))) ?></td>
                        <td>
                            <span class="badge" style="background:<?= $lv['status'] == 'approved' ? 'var(--green-pale)' : 'var(--red-pale)' ?>; color:<?= $lv['status'] == 'approved' ? 'var(--text-accent)' : 'var(--red-alert)' ?>;">
                                <?= ucfirst($lv['status']) ?>
                            </span>
                        </td>
                        <td><?= !empty($lv['decided_at']) ? clean(date('M d, Y g:i A', strtotime($lv['decided_at']))) : '—' ?></td>
                        <td><?= clean($lv['approver_name'] ?: '—') ?><?= $lv['approver_role'] ? ' (' . clean(roleLabel($lv['approver_role'])) . ')' : '' ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div></div>
    </div>

    <!-- ============================================= -->
    <!-- HR STAFF ROSTER                                -->
    <!-- ============================================= -->
    <div class="section">
        <div class="section-header">
            <h2>HR Staff Roster <span style="color:var(--ink-soft); font-weight:500; font-size:14px;">— performance, <?= clean(date('F Y')) ?></span></h2>
        </div>
        <div class="table-card"><div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Code</th><th>Name</th><th>Department</th><th>Employee Status</th>
                        <th>Present</th><th>Late</th><th>Absent</th><th>Leaves Taken</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($hr_staff_roster)): ?>
                    <tr><td colspan="8"><div class="empty-state">No active HR Staff account.</div></td></tr>
                    <?php else: foreach ($hr_staff_roster as $hs):
                        $perf = $hr_staff_perf_by_emp[$hs['employee_id']] ?? null;
                    ?>
                    <tr>
                        <td><?= clean($hs['employee_code'] ?? '—') ?></td>
                        <td><?= clean($hs['full_name']) ?></td>
                        <td><?= clean($hs['department'] ?: '—') ?></td>
                        <td><?= clean(ucfirst($hs['status'] ?? '—')) ?></td>
                        <td><?= (int) ($perf['present_count'] ?? 0) ?></td>
                        <td><?= (int) ($perf['late_count'] ?? 0) ?></td>
                        <td><?= (int) ($perf['absent_count'] ?? 0) ?></td>
                        <td><?= (int) ($hr_staff_leave_count_by_emp[$hs['employee_id']] ?? 0) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div></div>
    </div>

    <!-- ============================================= -->
    <!-- EMPLOYEE HR POLICY COMPLIANCE                  -->
    <!-- ============================================= -->
    <div class="section" id="policy-compliance">
        <div class="section-header">
            <h2>Employee HR Policy Compliance <span style="color:var(--ink-soft); font-weight:500; font-size:14px;">— flagged this month</span></h2>
        </div>
        <div class="note-banner">
            Flags an Employee once they hit <strong><?= HR_POLICY_LATE_THRESHOLD ?>+ late</strong> arrivals or
            <strong><?= HR_POLICY_ABSENT_THRESHOLD ?>+ absences</strong> this month (<?= clean(date('F Y')) ?>), based on attendance encoded
            by HR Staff. View-only here — attendance itself is still encoded/corrected from the Attendance page.
        </div>
        <div class="table-card"><div class="table-scroll">
            <table>
                <thead>
                    <tr><th>Code</th><th>Name</th><th>Department</th><th>Late</th><th>Absent</th><th>Present</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($policy_flagged_employees)): ?>
                    <tr><td colspan="7"><div class="empty-state">No Employees currently breach the attendance policy this month.</div></td></tr>
                    <?php else: foreach ($policy_flagged_employees as $pf): ?>
                    <tr>
                        <td><?= clean($pf['employee_code'] ?? '—') ?></td>
                        <td><?= clean($pf['last_name'] . ', ' . $pf['first_name']) ?></td>
                        <td><?= clean($pf['department'] ?: '—') ?></td>
                        <td><?= (int) $pf['late_count'] ?></td>
                        <td><?= (int) $pf['absent_count'] ?></td>
                        <td><?= (int) $pf['present_count'] ?></td>
                        <td><span class="badge" style="background:var(--red-pale); color:var(--red-alert);">Needs Review</span></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div></div>
    </div>

</div>
    <footer class="dashboard-footer">
        <span>HR management workspace · <?= clean(date('F j, Y')) ?></span>
        <a href="hr_reports.php">Open HR reports</a>
    </footer>
    </div>
</div>

<?php include 'includes/sweetalert.php'; ?>

</body>
</html>