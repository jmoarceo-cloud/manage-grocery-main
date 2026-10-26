<?php
require_once 'includes/config.php';
require_once 'includes/mailer.php';
require_once 'includes/application_form.php';
requireHRStaff(); // HR Staff + Admin only — HR Manager has their own dashboard (hr_manager_dashboard.php)

$field_label_map = getFieldLabelMap($pdo);

$success_message = "";
$error_message   = "";

// =====================================================
// HANDLE FORM SUBMISSIONS
// (HR scope only: can approve/reject leave requests.
//  Job Applicants now lives in the Employees module — employees.php.)
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

    // -------------------------------------------------
    // REQUEST OWN LEAVE (HR Staff themself is the one
    // requesting — this goes to 'pending' and HR Manager or
    // Admin will approve/reject it, not HR Staff themself)
    // -------------------------------------------------
    elseif ($action == 'request_leave') {

        $own_employee_id = $_SESSION['employee_id'] ?? null;
        $leave_type = trim($_POST['leave_type'] ?? '');
        $date_from  = trim($_POST['date_from'] ?? '');
        $date_to    = trim($_POST['date_to'] ?? '');
        $reason     = trim($_POST['reason'] ?? '');
        $today      = date('Y-m-d');

        $valid_types = ['sick', 'vacation', 'emergency', 'others'];

        if (!$own_employee_id) {
            $error_message = "No linked employee record was found for your account.";
        } elseif (!in_array($leave_type, $valid_types, true)) {
            $error_message = "Please select a valid leave type.";
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $error_message = "Please enter a valid date.";
        } elseif ($date_from < $today) {
            $error_message = "Past-date leave requests are not permitted.";
        } elseif ($date_to < $date_from) {
            $error_message = "The end date must be the same as or later than the start date.";
        } elseif (mb_strlen($reason) < 3) {
            $error_message = "Please provide the reason for your leave.";
        } else {
            $requested_days = (strtotime($date_to) - strtotime($date_from)) / 86400 + 1;
            $leave_year      = (int) date('Y', strtotime($date_from));
            $remaining_days  = null;

            if (in_array($leave_type, LEAVE_TYPES_WITH_LIMIT, true)) {
                $credit = getOrCreateLeaveCredit($pdo, $own_employee_id, $leave_type, $leave_year);
                $remaining_days = (float) $credit['allocated_days'] - (float) $credit['used_days'];
            }

            if ($remaining_days !== null && $requested_days > $remaining_days) {
                $error_message = "Cannot be submitted: you're requesting {$requested_days} day(s) of "
                    . getLeaveTypeLabel($leave_type) . ", but you only have {$remaining_days} day(s) remaining in your balance for "
                    . $leave_year . ".";
            } else {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO leave_requests (employee_id, leave_type, date_from, date_to, reason, status, requested_at)
                        VALUES (?, ?, ?, ?, ?, 'pending', NOW())
                    ");
                    $stmt->execute([$own_employee_id, $leave_type, $date_from, $date_to, $reason]);
                    $success_message = "Your leave request has been submitted. Just wait for approval from HR Manager/Admin.";
                } catch (PDOException $e) {
                    error_log("HR Staff leave request error: " . $e->getMessage());
                    $error_message = "The leave request could not be submitted. Please try again.";
                }
            }
        }
    }

}

// =====================================================
// OWN LEAVE (HR Staff themselves — request + history)
// =====================================================
$own_employee_id      = $_SESSION['employee_id'] ?? null;
$own_leave_balances   = $own_employee_id ? getLeaveBalancesForYear($pdo, $own_employee_id, (int) date('Y')) : [];
$my_leave_requests    = [];
if ($own_employee_id) {
    $stmt = $pdo->prepare("
        SELECT lr.*, u.full_name as approver_name, u.role as approver_role
        FROM leave_requests lr
        LEFT JOIN users u ON lr.approved_by = u.id
        WHERE lr.employee_id = ?
        ORDER BY lr.requested_at DESC LIMIT 10
    ");
    $stmt->execute([$own_employee_id]);
    $my_leave_requests = $stmt->fetchAll();
}

// =====================================================
// EMPLOYEE STATS
// =====================================================
$employee_stats = ['active' => 0, 'on-leave' => 0, 'resigned' => 0, 'terminated' => 0];
$stmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM employees GROUP BY status");
foreach ($stmt->fetchAll() as $row) {
    $employee_stats[$row['status']] = (int) $row['cnt'];
}
$total_employees = array_sum($employee_stats);

// =====================================================
// ATTENDANCE TODAY
// (JOIN with employees and filter to 'active'/'on-leave' only
// so stale/test records linked to a resigned/terminated employee,
// or an employee_id with no matching row in the employees table,
// don't get counted.)
// =====================================================
$attendance_stats = ['present' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0, 'day_off' => 0];
$stmt = $pdo->prepare("
    SELECT a.status, COUNT(*) as cnt
    FROM attendance a
    INNER JOIN employees e ON e.employee_id = a.employee_id
    WHERE a.date = CURDATE() AND e.status IN ('active', 'on-leave')
    GROUP BY a.status
");
$stmt->execute();
foreach ($stmt->fetchAll() as $row) {
    $attendance_stats[$row['status']] = (int) $row['cnt'];
}
$late_today      = (int) $pdo->query("
    SELECT COUNT(*)
    FROM attendance a
    INNER JOIN employees e ON e.employee_id = a.employee_id
    WHERE a.date = CURDATE() AND a.late_minutes > 0 AND e.status IN ('active', 'on-leave')
")->fetchColumn();
$no_record_today = max(0, $employee_stats['active'] - array_sum($attendance_stats));

// =====================================================
// PAYROLL OVERVIEW (view-only for HR — no generate/finalize here)
// =====================================================
$latest_period = $pdo->query("
    SELECT pay_period_start, pay_period_end, payout_date
    FROM payroll_records
    ORDER BY pay_period_end DESC, generated_at DESC
    LIMIT 1
")->fetch();

$payroll_summary           = null;
$employees_pending_payroll = 0;

if ($latest_period) {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT employee_id) as paid_count, SUM(gross_pay) as total_gross, SUM(net_pay) as total_net
        FROM payroll_records
        WHERE pay_period_end = ?
    ");
    $stmt->execute([$latest_period['pay_period_end']]);
    $payroll_summary           = $stmt->fetch();
    $employees_pending_payroll = max(0, $employee_stats['active'] - (int) $payroll_summary['paid_count']);
}

// =====================================================
// DATE FILTER HELPER
// (used by several sections below so a different date can be
// picked using the date navigation, instead of always only
// showing today)
// =====================================================
function resolveDashboardDate($getKey) {
    $d = isset($_GET[$getKey]) ? trim($_GET[$getKey]) : date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        $d = date('Y-m-d');
    }
    if ($d > date('Y-m-d')) {
        $d = date('Y-m-d');
    }
    return [
        'date'      => $d,
        'prev'      => date('Y-m-d', strtotime($d . ' -1 day')),
        'next'      => date('Y-m-d', strtotime($d . ' +1 day')),
        'is_today'  => ($d === date('Y-m-d')),
    ];
}

// =====================================================
// PENDING LEAVE REQUESTS
// =====================================================
// Default view is per-day (based on the request date), but you can
// still go back to previous days using the date navigation.
// A separate COUNT (unfiltered) is used for the KPI card above.
$lp = resolveDashboardDate('leave_pending_date');
$leave_pending_date      = $lp['date'];
$prev_leave_pending_date = $lp['prev'];
$next_leave_pending_date = $lp['next'];
$is_today_leave_pending  = $lp['is_today'];

// Employee and Supervisor leave requests appear here — the leave
// requests of HR Staff themself (including our own leave) should no
// longer appear in this list, since HR Manager/Admin will be the one
// to approve those (see the "Request Leave" section below, and
// hr_manager_dashboard.php for the approval side).
//
// Employees who now have a Supervisor assigned (supervisor_teams) are
// excluded — those leave requests are approved/rejected by their
// Supervisor now (see supervisor_leave.php). HR Staff still processes
// requests for employees without a Supervisor yet, plus Supervisors'
// own leave requests (Supervisors don't have a Supervisor of their
// own, so they're not gated by supervisor_teams).
$total_pending_leaves_count = (int) $pdo->query("
    SELECT COUNT(*)
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.employee_id
    LEFT JOIN users u ON u.employee_id = e.employee_id
    LEFT JOIN supervisor_teams st ON st.member_employee_id = e.employee_id
    WHERE lr.status = 'pending'
      AND (
        (COALESCE(u.role, 'employee') = 'employee' AND st.id IS NULL)
        OR COALESCE(u.role, 'employee') = 'supervisor'
      )
")->fetchColumn();

$stmt = $pdo->prepare("
    SELECT lr.*, e.first_name, e.last_name, e.employee_code
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.employee_id
    LEFT JOIN users u ON u.employee_id = e.employee_id
    LEFT JOIN supervisor_teams st ON st.member_employee_id = e.employee_id
    WHERE lr.status = 'pending'
      AND (
        (COALESCE(u.role, 'employee') = 'employee' AND st.id IS NULL)
        OR COALESCE(u.role, 'employee') = 'supervisor'
      )
      AND DATE(lr.requested_at) = ?
    ORDER BY lr.requested_at ASC
");
$stmt->execute([$leave_pending_date]);
$pending_leaves = $stmt->fetchAll();

// =====================================================
// RECENT LEAVE DECISIONS (includes requests they've processed themself)
// =====================================================
// We now have a "decided_at" column — we use this as the basis
// for the date filter (more accurate than requested_at, since
// that's the actual date of the decision). For old records
// from before this column was added (so decided_at is still NULL),
// we fall back to requested_at.
$ld = resolveDashboardDate('leave_decisions_date');
$leave_decisions_date      = $ld['date'];
$prev_leave_decisions_date = $ld['prev'];
$next_leave_decisions_date = $ld['next'];
$is_today_leave_decisions  = $ld['is_today'];

$stmt = $pdo->prepare("
    SELECT lr.*, e.first_name, e.last_name, e.employee_code, ua.full_name as approver_name, ua.role as approver_role
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.employee_id
    LEFT JOIN users req ON req.employee_id = e.employee_id
    LEFT JOIN users ua ON lr.approved_by = ua.id
    WHERE lr.status IN ('approved', 'rejected')
      AND COALESCE(req.role, 'employee') IN ('employee', 'supervisor')
      AND DATE(COALESCE(lr.decided_at, lr.requested_at)) = ?
    ORDER BY COALESCE(lr.decided_at, lr.requested_at) DESC
");
$stmt->execute([$leave_decisions_date]);
$recent_leave_decisions = $stmt->fetchAll();

// =====================================================
// HELPERS
// =====================================================
function getLeaveTypeLabel($type) {
    $map = ['sick' => 'Sick Leave', 'vacation' => 'Vacation Leave', 'emergency' => 'Emergency Leave', 'others' => 'Others'];
    return $map[$type] ?? ucfirst($type);
}

function daysBetween($from, $to) {
    $d1 = new DateTime($from);
    $d2 = new DateTime($to);
    return $d1->diff($d2)->days + 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HR Dashboard — HRMS &amp; Payroll</title>
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
@media (max-width: 640px) { .container { padding: 20px 16px; } .topbar { padding: 14px 16px; flex-wrap: wrap; gap: 8px; } }
</style>
<link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>">
</head>
<body class="dashboard-page dashboard-page--hr-staff">

<div class="app-shell">

    <?php $activeNav = 'dashboard'; include 'includes/hr_sidebar.php'; ?>

    <div class="main-area">

    <div class="topbar">
        <h1>HRMS &amp; Payroll — HR Dashboard</h1>
        <div class="user-info">
            <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span>
        <span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span>
        </div>
    </div>

    <div class="container">
        <section class="dashboard-hero" aria-labelledby="dashboard-greeting">
            <div class="dashboard-hero__copy">
                <span class="dashboard-hero__label">HR Staff</span>
                <h2 id="dashboard-greeting"><?= date('G') < 12 ? 'Good morning' : (date('G') < 18 ? 'Good afternoon' : 'Good evening') ?>, <?= clean($_SESSION['full_name'] ?? 'HR team') ?>.</h2>
                <p>Daily HR operations — attendance, leave, and records.</p>
            </div>
            <a class="dashboard-primary-action" href="attendance.php">Open attendance <span aria-hidden="true">→</span></a>
        </section>

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

    <!-- ============================================= -->
    <!-- KPI OVERVIEW                                   -->
    <!-- ============================================= -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Total Employees</div>
            <div class="kpi-value"><?= $total_employees ?></div>
            <div class="kpi-sub"><?= $employee_stats['active'] ?> active</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">On Leave</div>
            <div class="kpi-value"><?= $employee_stats['on-leave'] ?></div>
            <div class="kpi-sub">right now</div>
        </div>
        <div class="kpi-card <?= $total_pending_leaves_count > 0 ? 'accent-mustard' : '' ?>">
            <div class="kpi-label">Pending Leave Requests</div>
            <div class="kpi-value"><?= $total_pending_leaves_count ?></div>
            <div class="kpi-sub">need approval</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Present Today</div>
            <div class="kpi-value"><?= $attendance_stats['present'] ?></div>
            <div class="kpi-sub"><?= $late_today ?> late · <?= $attendance_stats['absent'] ?> absent</div>
        </div>
    </div>

    <!-- ============================================= -->
    <!-- ATTENDANCE + PAYROLL PANELS                    -->
    <!-- ============================================= -->
    <div class="section">
        <div class="panel-grid">
            <div class="panel">
                <h3><a href="attendance.php" style="color:inherit;">Attendance Today (<?= date('M d, Y') ?>) &rarr;</a></h3>
                <div class="stat-row"><span class="stat-label">Present</span><span class="stat-value"><?= $attendance_stats['present'] ?></span></div>
                <div class="stat-row"><span class="stat-label">Late</span><span class="stat-value"><?= $late_today ?></span></div>
                <div class="stat-row"><span class="stat-label">Absent</span><span class="stat-value"><?= $attendance_stats['absent'] ?></span></div>
                <div class="stat-row"><span class="stat-label">On Leave</span><span class="stat-value"><?= $attendance_stats['leave'] ?></span></div>
                <div class="stat-row"><span class="stat-label">Holiday</span><span class="stat-value"><?= $attendance_stats['holiday'] ?></span></div>
                <div class="stat-row"><span class="stat-label">Day Off (Rest Day)</span><span class="stat-value"><?= $attendance_stats['day_off'] ?></span></div>
                <div class="stat-row"><span class="stat-label">No Record Yet</span><span class="stat-value"><?= $no_record_today ?></span></div>
            </div>
            <div class="panel">
                <h3>Payroll Overview <span style="font-size:11px; font-weight:600; color:var(--ink-soft); text-transform:uppercase;">(view only)</span></h3>
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
                    <div class="stat-row"><span class="stat-label">Employees Paid</span><span class="stat-value"><?= (int)$payroll_summary['paid_count'] ?></span></div>
                    <div class="stat-row"><span class="stat-label">Total Gross Pay</span><span class="stat-value"><?= peso($payroll_summary['total_gross'] ?? 0) ?></span></div>
                    <div class="stat-row"><span class="stat-label">Total Net Pay</span><span class="stat-value"><?= peso($payroll_summary['total_net'] ?? 0) ?></span></div>
                    <div class="stat-row"><span class="stat-label">Not Yet Paid This Period</span><span class="stat-value"><?= $employees_pending_payroll ?></span></div>
                <?php else: ?>
                    <div class="empty-state">No payroll records have been generated yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ============================================= -->
    <!-- PENDING LEAVE REQUESTS                         -->
    <!-- ============================================= -->
    <div class="section" id="leave-pending">
        <div class="section-header">
            <h2>Pending Leave Requests <span style="color:var(--ink-soft); font-weight:500; font-size:14px;">— <?= clean(date('M d, Y', strtotime($leave_pending_date))) ?><?= $is_today_leave_pending ? ' (Today)' : '' ?></span></h2>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <a href="?leave_pending_date=<?= $prev_leave_pending_date ?>#leave-pending" class="btn btn-secondary btn-sm">◀ Prev Day</a>
                <form method="GET" style="display:inline-flex;">
                    <input type="date" name="leave_pending_date" value="<?= clean($leave_pending_date) ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()" style="padding:8px 10px; border:1.5px solid var(--line); border-radius:10px; font-size:13px; font-family:var(--font-body);">
                </form>
                <?php if (!$is_today_leave_pending): ?>
                <a href="?leave_pending_date=<?= $next_leave_pending_date ?>#leave-pending" class="btn btn-secondary btn-sm">Next Day ▶</a>
                <a href="?leave_pending_date=<?= date('Y-m-d') ?>#leave-pending" class="btn btn-primary btn-sm">Today</a>
                <?php endif; ?>
            </div>
        </div>
        <div style="background:var(--mustard-pale); border:1px solid var(--mustard); border-radius:10px; padding:12px 16px; margin-bottom:16px; font-size:13.5px; color:var(--mustard-text2, #7A5A1E);">
            Employees who are assigned to a Supervisor no longer appear here — their leave requests are now approved or rejected directly by their Supervisor. You'll still see the outcome of those decisions in <a href="#leave-decisions" style="color:var(--mustard-text2, #7A5A1E); font-weight:600;">Recent Leave Decisions</a> below. This list shows employees who don't have a Supervisor assigned yet, plus Supervisors' own leave requests.
        </div>
        <div class="table-card">
            <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Days</th>
                        <th>Reason</th>
                        <th>Requested</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pending_leaves)): ?>
                    <tr><td colspan="7"><div class="empty-state">No pending leave requests were made on this date (<?= clean(date('M d, Y', strtotime($leave_pending_date))) ?>).</div></td></tr>
                    <?php else: ?>
                    <?php foreach ($pending_leaves as $lv): ?>
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
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- ============================================= -->
    <!-- RECENT LEAVE DECISIONS                         -->
    <!-- ============================================= -->
    <div class="section" id="leave-decisions">
        <div class="section-header">
            <h2>Recent Leave Decisions <span style="color:var(--ink-soft); font-weight:500; font-size:14px;">— <?= clean(date('M d, Y', strtotime($leave_decisions_date))) ?><?= $is_today_leave_decisions ? ' (Today)' : '' ?></span></h2>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <a href="?leave_decisions_date=<?= $prev_leave_decisions_date ?>#leave-decisions" class="btn btn-secondary btn-sm">◀ Prev Day</a>
                <form method="GET" style="display:inline-flex;">
                    <input type="date" name="leave_decisions_date" value="<?= clean($leave_decisions_date) ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()" style="padding:8px 10px; border:1.5px solid var(--line); border-radius:10px; font-size:13px; font-family:var(--font-body);">
                </form>
                <?php if (!$is_today_leave_decisions): ?>
                <a href="?leave_decisions_date=<?= $next_leave_decisions_date ?>#leave-decisions" class="btn btn-secondary btn-sm">Next Day ▶</a>
                <a href="?leave_decisions_date=<?= date('Y-m-d') ?>#leave-decisions" class="btn btn-primary btn-sm">Today</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="table-card">
            <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Status</th>
                        <th>Decided On</th>
                        <th>Decided By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_leave_decisions)): ?>
                    <tr><td colspan="6"><div class="empty-state">No processed leave requests on this date (<?= clean(date('M d, Y', strtotime($leave_decisions_date))) ?>).</div></td></tr>
                    <?php else: ?>
                    <?php foreach ($recent_leave_decisions as $lv): ?>
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
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- ============================================= -->
    <!-- REQUEST OWN LEAVE (HR Staff)                    -->
    <!-- ============================================= -->
    <div class="section" id="my-leave">
        <div class="section-header">
            <h2>Request Leave <span style="color:var(--ink-soft); font-weight:500; font-size:14px;">— for yourself</span></h2>
        </div>
        <div style="background:var(--mustard-pale); border:1px solid var(--mustard); border-radius:10px; padding:12px 16px; margin-bottom:16px; font-size:13.5px; color:var(--mustard-text2, #7A5A1E);">
           Your leave request here will be forwarded directly to the <strong>HR Manager</strong> (or Admin) for approval — you will not be the one approving it yourself.
        </div>

        <div class="table-card" style="padding:18px 22px; margin-bottom:16px;">
            <div style="display:flex; gap:14px; flex-wrap:wrap;">
                <?php foreach ($own_leave_balances as $type => $bal): ?>
                <div style="flex:1; min-width:150px; background:var(--green-pale); border-radius:12px; padding:12px 16px;">
                    <div style="font-size:12.5px; color:var(--ink-soft); font-weight:600; text-transform:uppercase; letter-spacing:.02em;"><?= clean(getLeaveTypeLabel($type)) ?></div>
                    <?php if (in_array($type, LEAVE_TYPES_WITH_LIMIT, true)): ?>
                    <div style="font-family:var(--font-display); font-size:20px; color:var(--text-accent); margin-top:4px;"><?= clean(number_format($bal['remaining'], 1)) ?> <span style="font-size:12.5px; color:var(--ink-soft); font-family:var(--font-body); font-weight:500;">/ <?= clean(number_format($bal['allocated'], 1)) ?> days</span></div>
                    <?php else: ?>
                    <div style="font-family:var(--font-display); font-size:20px; color:var(--text-accent); margin-top:4px;"><?= clean(number_format($bal['used'], 1)) ?> <span style="font-size:12.5px; color:var(--ink-soft); font-family:var(--font-body); font-weight:500;">day(s) used</span></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="table-card" style="padding:20px 22px; margin-bottom:16px;">
            <form method="POST" style="display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end;">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="request_leave">

                <div style="flex:1; min-width:150px;">
                    <label style="display:block; font-size:12.5px; font-weight:600; color:var(--ink-soft); margin-bottom:6px;">Type of Leave *</label>
                    <select name="leave_type" required style="width:100%; padding:10px 12px; border:1.5px solid var(--line); border-radius:10px; font-size:14px; font-family:var(--font-body);">
                        <option value="">-- Choose --</option>
                        <option value="sick">Sick Leave</option>
                        <option value="vacation">Vacation Leave</option>
                        <option value="emergency">Emergency Leave</option>
                        <option value="others">Others</option>
                    </select>
                </div>
                <div style="flex:1; min-width:140px;">
                    <label style="display:block; font-size:12.5px; font-weight:600; color:var(--ink-soft); margin-bottom:6px;">From *</label>
                    <input type="date" name="date_from" min="<?= date('Y-m-d') ?>" required style="width:100%; padding:9px 12px; border:1.5px solid var(--line); border-radius:10px; font-size:14px; font-family:var(--font-body);">
                </div>
                <div style="flex:1; min-width:140px;">
                    <label style="display:block; font-size:12.5px; font-weight:600; color:var(--ink-soft); margin-bottom:6px;">To *</label>
                    <input type="date" name="date_to" min="<?= date('Y-m-d') ?>" required style="width:100%; padding:9px 12px; border:1.5px solid var(--line); border-radius:10px; font-size:14px; font-family:var(--font-body);">
                </div>
                <div style="flex:2; min-width:220px;">
                    <label style="display:block; font-size:12.5px; font-weight:600; color:var(--ink-soft); margin-bottom:6px;">Reason *</label>
                    <input type="text" name="reason" placeholder="Reason" required style="width:100%; padding:9px 12px; border:1.5px solid var(--line); border-radius:10px; font-size:14px; font-family:var(--font-body);">
                </div>
                <button type="submit" class="btn btn-primary">Submit</button>
            </form>
        </div>

        <div class="table-card">
            <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Reason</th>
                        <th>Requested</th>
                        <th>Status</th>
                        <th>Decided By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($my_leave_requests)): ?>
                    <tr><td colspan="6"><div class="empty-state">You haven’t submitted a leave request yet.</div></td></tr>
                    <?php else: ?>
                    <?php foreach ($my_leave_requests as $lv): ?>
                    <tr>
                        <td><?= clean(getLeaveTypeLabel($lv['leave_type'])) ?></td>
                        <td><?= clean(date('M d', strtotime($lv['date_from']))) ?>–<?= clean(date('M d, Y', strtotime($lv['date_to']))) ?></td>
                        <td style="max-width:220px;"><?= clean($lv['reason'] ?: '—') ?></td>
                        <td><?= clean(date('M d, Y', strtotime($lv['requested_at']))) ?></td>
                        <td>
                            <?php if ($lv['status'] === 'pending'): ?>
                                <span class="badge" style="background:var(--mustard-pale); color:var(--mustard-text2, #97661F);">Pending</span>
                            <?php else: ?>
                                <span class="badge" style="background:<?= $lv['status'] == 'approved' ? 'var(--green-pale)' : 'var(--red-pale)' ?>; color:<?= $lv['status'] == 'approved' ? 'var(--text-accent)' : 'var(--red-alert)' ?>;"><?= ucfirst($lv['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= clean($lv['approver_name'] ?: '—') ?><?= $lv['approver_role'] ? ' (' . clean(roleLabel($lv['approver_role'])) . ')' : '' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

</div>
    <footer class="dashboard-footer">
        <span>HR operations workspace · <?= clean(date('F j, Y')) ?></span>
        <a href="attendance.php">Open attendance</a>
    </footer>
    </div>
</div>

<?php include 'includes/sweetalert.php'; ?>

</body>
</html>