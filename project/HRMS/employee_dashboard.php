<?php
require_once 'includes/config.php';
requireEmployee();

$employee_id = $_SESSION['employee_id'] ?? null;
$notificationPendingCount = getHrmsNotificationBadgeCount($pdo, $posPdo, 'employee', $employee_id);
$employee    = null;

// =====================================================
// UPDATE PERSONAL INFORMATION — same "Personal Information" edit
// card as my_profile.php (see includes/personal_info_card.php),
// so Cashiers and other rank-and-file employees can fill in/correct
// their Email, Contact Number, and application-form details too —
// not just HR Staff/HR Manager/Supervisor. Handled here (before the
// SELECT below) so the page immediately shows the saved values.
// =====================================================
$profile_message = "";
$profile_error   = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') === 'update_personal_info') {
    $__pi = processPersonalInfoUpdate($pdo, $employee_id);
    $profile_message = $__pi['message'];
    $profile_error   = $__pi['error'];
}

if ($employee_id) {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch();
}

// Announcements relevant to this Employee — company-wide plus their
// own department, published and not expired (see
// getVisibleAnnouncementsForHrmsUser() in includes/config.php; same
// query logic used by the dedicated announcements.php page).
$myAnnouncementsPreview = getVisibleAnnouncementsForHrmsUser($pdo, $_SESSION['role'], $employee['department'] ?? null);
$myAnnouncementsPreview = array_slice($myAnnouncementsPreview, 0, 3);

// =====================================================
// SELF TIME IN / TIME OUT (one click, real-time time straight
// from the server — no manual entry of time). Uses the
// SHARED processSelfTimeInOut() (includes/config.php) — this
// is also used by the HR Staff/HR Manager dashboards — so the
// shift-aware "Late" computation is automatically applied
// (based on the employee's Weekly Shift Schedule, if any) instead
// of always assuming 8:00 AM for everyone.
// =====================================================
$__att = processSelfTimeInOut($pdo, $employee_id);
$timein_message = $__att['message'];
$timein_error   = $__att['error'];
$todayRow       = $__att['todayRow'];
$todayShift     = $__att['todayShift']; // shift assigned today, or null
$approvedSwap   = $__att['approvedSwap'] ?? null;
$shiftEnded       = $__att['shiftEnded'] ?? false;
$lateRequestToday = $__att['lateRequestToday'] ?? null;
$timeInWindowOpen = $__att['timeInWindowOpen'] ?? true;   // false = too early pa, hindi pa bukas ang Time In
$timeInOpensAt    = $__att['timeInOpensAt'] ?? null;       // H:i:s kung kailan bubukas ang Time In
$shiftNotStartedYet = $__att['shiftNotStartedYet'] ?? false; // timed in na pero bago pa mag-shift start — Time Out disabled muna
$unfinishedRecord  = $__att['unfinishedRecord'] ?? null; // previous day timed in pero hindi natimeout — blocks new Time In
$activeShifts      = $__att['activeShifts'] ?? []; // for the Time In Request shift picker — dapat sa mga schedule na meron tayo, hindi sariling oras
$__canTimeInLate    = $lateRequestToday && $lateRequestToday['status'] === 'approved';
$__needsLateRequest = ($todayShift || $approvedSwap) && $shiftEnded && !$__canTimeInLate;
$__timeInTooEarly   = ($todayShift || $approvedSwap) && !$shiftEnded && !$timeInWindowOpen && !$__canTimeInLate;
$today          = date('Y-m-d');

// =====================================================
// MY ATTENDANCE — selected month (default: current month)
// =====================================================
$month = isset($_GET['month']) ? trim($_GET['month']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month) || $month > date('Y-m')) {
    $month = date('Y-m');
}
$prev_month = date('Y-m', strtotime($month . '-01 -1 month'));
$next_month = date('Y-m', strtotime($month . '-01 +1 month'));
$is_current_month = $month === date('Y-m');

$my_attendance   = [];
$attendance_sum  = ['present' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0, 'day_off' => 0, 'late' => 0];

if ($employee_id) {
    $stmt = $pdo->prepare("
        SELECT * FROM attendance
        WHERE employee_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
        ORDER BY date DESC
    ");
    $stmt->execute([$employee_id, $month]);
    $my_attendance = $stmt->fetchAll();

    foreach ($my_attendance as $row) {
        if (isset($attendance_sum[$row['status']])) {
            $attendance_sum[$row['status']]++;
        }
        if ((int) $row['late_minutes'] > 0) {
            $attendance_sum['late']++;
        }
    }
}

// =====================================================
// MY PAYSLIPS
// =====================================================
$my_payslips = [];
if ($employee_id) {
    // Employees can see released payslips and an approved preview for an
    // upcoming payout. Unapproved and overdue unpaid payroll stays hidden.
    $stmt = $pdo->prepare("
        SELECT * FROM payroll_records
        WHERE employee_id = ?
          AND (
              (approval_status IN ('approved', 'n/a') AND payment_status = 'paid')
              OR (approval_status = 'approved' AND payment_status = 'unpaid' AND payout_date > CURDATE())
          )
        ORDER BY pay_period_end DESC
    ");
    $stmt->execute([$employee_id]);
    $my_payslips = $stmt->fetchAll();
}

// Split into "Current Payslip" (payout date hasn't arrived yet — this
// cutoff's pay, still just a preview) vs "Salary History" (payout date
// has already come and gone, i.e. actually released/paid). Same
// >today cutoff payslip.php already uses to gate viewing/printing —
// once that payout day is done, it moves out of "current" and into
// history. Multiple pay periods can be pre-generated ahead of time
// (e.g. HR runs both the 15th and the 30th early), so this is a list,
// soonest payout first, not just a single record.
$current_payslips = [];
$salary_history    = [];
foreach ($my_payslips as $pay) {
    $isUpcoming = $pay['approval_status'] === 'approved'
        && $pay['payment_status'] === 'unpaid'
        && !empty($pay['payout_date'])
        && strtotime($pay['payout_date']) > strtotime('today');
    if ($isUpcoming) {
        $current_payslips[] = $pay;
    } elseif ($pay['payment_status'] === 'paid') {
        $salary_history[] = $pay;
    }
}
usort($current_payslips, function ($a, $b) {
    return strtotime($a['payout_date']) <=> strtotime($b['payout_date']);
});


$optional_payslip_fields = [
    'basic_pay'            => 'Basic Pay',
    'overtime_pay'         => 'Overtime Pay',
    'allowances'           => 'Allowances',
    'sss_deduction'        => 'SSS Deduction',
    'philhealth_deduction' => 'PhilHealth Deduction',
    'pagibig_deduction'    => 'Pag-IBIG Deduction',
    'tax_deduction'        => 'Withholding Tax',
    'other_deductions'     => 'Other Deductions',
];

function attendanceBadge($status) {
    $map = [
        'present'   => ['label' => 'Present',   'class' => 'badge-present'],
        'absent'    => ['label' => 'Absent',    'class' => 'badge-absent'],
        'leave'     => ['label' => 'On Leave',  'class' => 'badge-leave'],
        'holiday'   => ['label' => 'Holiday',   'class' => 'badge-holiday'],
        'day_off'   => ['label' => 'Day Off (Rest Day)', 'class' => 'badge-day_off'],
    ];
    return $map[$status] ?? ['label' => ucfirst($status), 'class' => 'badge-none'];
}

function getLeaveTypeLabel($type) {
    $map = ['sick' => 'Sick Leave', 'vacation' => 'Vacation Leave', 'emergency' => 'Emergency Leave', 'others' => 'Others'];
    return $map[$type] ?? ucfirst($type);
}

function leaveStatusBadge($status) {
    $map = [
        'pending'  => ['label' => 'Pending',  'class' => 'badge-none'],
        'approved' => ['label' => 'Approved', 'class' => 'badge-present'],
        'rejected' => ['label' => 'Rejected', 'class' => 'badge-absent'],
    ];
    return $map[$status] ?? ['label' => ucfirst($status), 'class' => 'badge-none'];
}

$ot_message = "";
$ot_error   = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') === 'request_overtime') {
    requireCSRF();

    $ot_date = trim($_POST['ot_date'] ?? '');
    $hours   = trim($_POST['hours_requested'] ?? '');
    $reason  = trim($_POST['ot_reason'] ?? '');
    $today   = date('Y-m-d');

    if (!$employee_id) {
        $ot_error = "No employee record is linked to your account.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ot_date)) {
        $ot_error = "Please enter a valid date.";
    } elseif ($ot_date > $today) {
        $ot_error = "Overtime date cannot be in the future — request it once the shift has happened, or on the day itself.";
    } elseif (!is_numeric($hours) || (float) $hours <= 0 || (float) $hours > 12) {
        $ot_error = "Please enter a valid number of hours (up to 12).";
    } elseif (mb_strlen($reason) < 3) {
        $ot_error = "Please enter a reason for the overtime.";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO overtime_requests (employee_id, ot_date, hours_requested, reason, status, requested_at)
                VALUES (?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([$employee_id, $ot_date, (float) $hours, $reason]);
            $ot_message = "Your overtime request has been submitted. Just wait for approval from your Supervisor.";
        } catch (PDOException $e) {
            error_log("Overtime request error: " . $e->getMessage());
            $ot_error = "The overtime request could not be submitted. Please try again.";
        }
    }
}

$ob_message = "";
$ob_error   = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') === 'request_ob') {
    requireCSRF();

    $ob_date     = trim($_POST['ob_date'] ?? '');
    $time_from   = trim($_POST['time_from'] ?? '');
    $time_to     = trim($_POST['time_to'] ?? '');
    $purpose     = trim($_POST['purpose'] ?? '');
    $destination = trim($_POST['destination'] ?? '');

    if (!$employee_id) {
        $ob_error = "No employee record is linked to your account.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ob_date)) {
        $ob_error = "Please enter a valid date.";
    } elseif ($time_from !== '' && $time_to !== '' && $time_to <= $time_from) {
        $ob_error = "The 'To' time must come after the 'From' time.";
    } elseif (mb_strlen($purpose) < 3) {
        $ob_error = "Please enter the purpose of your Official Business.";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO official_business_requests (employee_id, ob_date, time_from, time_to, purpose, destination, status, requested_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $employee_id,
                $ob_date,
                $time_from ?: null,
                $time_to ?: null,
                $purpose,
                $destination ?: null,
            ]);
            $ob_message = "Your Official Business request has been submitted. Just wait for approval from your Supervisor.";
        } catch (PDOException $e) {
            error_log("OB request error: " . $e->getMessage());
            $ob_error = "The Official Business request could not be submitted. Please try again.";
        }
    }
}

$leave_message = "";
$leave_error   = "";
$show_vacation_cooldown_popup = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') === 'request_leave') {
    requireCSRF();

    $leave_type = trim($_POST['leave_type'] ?? '');
    $date_from  = trim($_POST['date_from'] ?? '');
    $date_to    = trim($_POST['date_to'] ?? '');
    $reason     = trim($_POST['reason'] ?? '');
    $today      = date('Y-m-d');

    $valid_types = ['sick', 'vacation', 'emergency', 'others'];

    if (!$employee_id) {
        $leave_error = "No employee record is linked to your account.";
    } elseif (!in_array($leave_type, $valid_types, true)) {
        $leave_error = "Please select a valid leave type.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $leave_error = "Please enter a valid date.";
    } elseif ($date_from < $today) {
        $leave_error = "You cannot request leave for a past date.";
    } elseif ($date_to < $date_from) {
        $leave_error = "The 'To' date must come after or be the same as the 'From' date.";
    } elseif (mb_strlen($reason) < 3) {
        $leave_error = "Please enter a reason for your leave.";
    } else {
      
        $requested_days = (strtotime($date_to) - strtotime($date_from)) / 86400 + 1;
        $leave_year      = (int) date('Y', strtotime($date_from));
        $remaining_days  = null;

        if (in_array($leave_type, LEAVE_TYPES_WITH_LIMIT, true)) {
            $credit = getOrCreateLeaveCredit($pdo, $employee_id, $leave_type, $leave_year);
            $remaining_days = (float) $credit['allocated_days'] - (float) $credit['used_days'];
        }

        $cooldown_error = ($leave_type === 'vacation')
            ? checkVacationLeaveCooldown($pdo, $employee_id, $date_from, $date_to)
            : null;

        if ($cooldown_error === null && $leave_type === 'vacation') {
            $cooldown_error = checkVacationLeaveRequestCooldown($pdo, $employee_id);
        }

        if ($remaining_days !== null && $requested_days > $remaining_days) {
            $leave_error = "Cannot be submitted: you're requesting {$requested_days} day(s) of "
                . getLeaveTypeLabel($leave_type) . ", but you only have {$remaining_days} day(s) remaining in your balance for "
                . $leave_year . ".";
        } elseif ($cooldown_error !== null) {
            $leave_error = $cooldown_error;
            $show_vacation_cooldown_popup = true;
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO leave_requests (employee_id, leave_type, date_from, date_to, reason, status, requested_at)
                    VALUES (?, ?, ?, ?, ?, 'pending', NOW())
                ");
                $stmt->execute([$employee_id, $leave_type, $date_from, $date_to, $reason]);
                $leave_message = "Your leave request has been submitted. Just wait for approval from HR/Admin.";
            } catch (PDOException $e) {
                error_log("Leave request error: " . $e->getMessage());
                $leave_error = "The leave request could not be submitted. Please try again.";
            }
        }
    }
}

$leave_balances = $employee_id ? getLeaveBalancesForYear($pdo, $employee_id, (int) date('Y')) : [];

$my_leave_requests = [];
if ($employee_id) {
    $stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE employee_id = ? ORDER BY requested_at DESC LIMIT 10");
    $stmt->execute([$employee_id]);
    $my_leave_requests = $stmt->fetchAll();
}

$my_overtime_requests = [];
if ($employee_id) {
    $stmt = $pdo->prepare("SELECT * FROM overtime_requests WHERE employee_id = ? ORDER BY requested_at DESC LIMIT 10");
    $stmt->execute([$employee_id]);
    $my_overtime_requests = $stmt->fetchAll();
}
$my_ob_requests = [];
if ($employee_id) {
    $stmt = $pdo->prepare("SELECT * FROM official_business_requests WHERE employee_id = ? ORDER BY requested_at DESC LIMIT 10");
    $stmt->execute([$employee_id]);
    $my_ob_requests = $stmt->fetchAll();
}
function otStatusBadge($status) {
    $map = [
        'pending'  => ['label' => 'Pending',  'class' => 'badge-none'],
        'approved' => ['label' => 'Approved', 'class' => 'badge-present'],
        'rejected' => ['label' => 'Rejected', 'class' => 'badge-absent'],
    ];
    return $map[$status] ?? ['label' => ucfirst($status), 'class' => 'badge-none'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee Dashboard — HRMS &amp; Payroll</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --green-deep:    #123C5C;
    --text-accent:   var(--green-deep);
    --green-mid:     #1978A8;
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

.topbar {
    background: var(--white);
    border-bottom: 1px solid var(--line);
    padding: 16px 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.topbar h1 { font-family: var(--font-display); font-size: 20px; color: var(--text-accent); }
.topbar .user-info { font-size: 13.5px; color: var(--ink-soft); display: flex; align-items: center; gap: 16px; }
.user-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--mustard); color: #FFFFFF; display: flex; align-items: center; justify-content: center; font-family: var(--font-display); font-weight: 700; font-size: 13px; flex-shrink: 0; overflow: hidden; }
.user-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.topbar a.logout { color: var(--red-alert); font-weight: 600; font-size: 13.5px; }
.employee-notifications { display: inline-flex; align-items: center; justify-content: center; gap: 7px; min-height: 36px; padding: 0 12px; border: 1px solid var(--line); border-radius: 10px; color: var(--text-accent); background: var(--white); font-size: 13px; font-weight: 700; white-space: nowrap; }
.employee-notifications:hover { background: var(--green-pale); }
.employee-notifications svg { width: 17px; height: 17px; flex: 0 0 auto; }
.employee-notifications__count { display: inline-grid; min-width: 19px; height: 19px; place-items: center; padding: 0 5px; border-radius: 999px; background: #dc2626; color: #fff; font-size: 10px; font-weight: 800; line-height: 1; }

.container { max-width: 1100px; margin: 0 auto; padding: 28px 32px; }

.profile-card {
    background: var(--white);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}
.profile-card h2 { font-family: var(--font-display); color: var(--text-accent); font-size: 22px; margin-bottom: 4px; }
.profile-card .sub { color: var(--ink-soft); font-size: 13.5px; }
.profile-meta { display: flex; gap: 24px; flex-wrap: wrap; }
.profile-meta .item .label { font-size: 11.5px; text-transform: uppercase; letter-spacing: .03em; color: var(--ink-soft); }
.profile-meta .item .value { font-weight: 600; font-size: 14px; margin-top: 2px; }

.section { margin-bottom: 28px; }
.section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
    flex-wrap: wrap;
    gap: 10px;
}
.section-head h3 { font-family: var(--font-display); color: var(--text-accent); font-size: 18px; }

.summary-row { display: flex; gap: 10px; flex-wrap: wrap; }
.summary-chip {
    background: var(--white);
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 8px 14px;
    font-size: 12.5px;
    color: var(--ink-soft);
}
.summary-chip strong { color: var(--ink); font-size: 15px; display: block; font-family: var(--font-display); }

.month-nav { display: flex; align-items: center; gap: 8px; }
.btn {
    padding: 8px 14px;
    border: 1.5px solid var(--line);
    border-radius: 10px;
    font-family: var(--font-body);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    background: var(--white);
    color: var(--ink);
}
.btn:hover { background: var(--cream); }
.btn.disabled { opacity: .4; pointer-events: none; }

.table-card { background: var(--white); border: 1px solid var(--line); border-radius: 16px; overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th {
    text-align: left;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .03em;
    color: var(--ink-soft);
    background: var(--cream);
    padding: 12px 14px;
    border-bottom: 1px solid var(--line);
}
td { padding: 12px 14px; font-size: 13.5px; border-bottom: 1px solid var(--line); }
tr:last-child td { border-bottom: none; }

.badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
.badge-present { background: #E3F2F6; color: #123C5C; }
.badge-absent  { background: #FBE7E2; color: #C8472D; }
.badge-leave   { background: #FBEFD8; color: #97661F; }
.badge-holiday { background: #E7EEFB; color: #2D5FC8; }
.badge-day_off { background: #EAE7F6; color: #5B4E9C; }
.badge-none    { background: #EFEFEF; color: #3D4C5C; }
.badge-late    { background: #FBEFD8; color: #97661F; margin-left: 4px; }

.empty-state { text-align: center; padding: 40px 20px; color: var(--ink-soft); font-size: 14px; }

.payslip-details { font-size: 12.5px; color: var(--ink-soft); }
.payslip-details span { display: inline-block; margin-right: 14px; }

/* "Current Payslip" — the one still-upcoming pay period (payout date
   hasn't arrived yet), shown as its own highlight card above the
   Salary History table instead of buried as just another row in it. */
.current-payslip-card {
    background: linear-gradient(135deg, var(--green-pale), var(--white));
    border: 1.5px solid var(--green-mid);
    border-radius: 16px;
    padding: 20px 22px;
    margin-bottom: 14px;
}
.current-payslip-card .cp-head {
    display: flex; justify-content: space-between; align-items: flex-start;
    flex-wrap: wrap; gap: 10px; margin-bottom: 14px;
}
.current-payslip-card .cp-period { font-family: var(--font-display); color: var(--text-accent); font-size: 16px; font-weight: 700; }
.current-payslip-card .cp-payout { font-size: 12.5px; color: var(--ink-soft); margin-top: 2px; }
.current-payslip-card .cp-net { text-align: right; }
.current-payslip-card .cp-net .label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-soft); }
.current-payslip-card .cp-net .value { font-family: var(--font-display); font-size: 26px; font-weight: 800; color: var(--text-accent); }
.current-payslip-card .cp-breakdown { display: flex; flex-wrap: wrap; gap: 8px 20px; font-size: 12.5px; color: var(--ink-soft); }
.current-payslip-card .cp-breakdown span strong { color: var(--ink); }
.current-payslip-card .cp-note { margin-top: 12px; font-size: 12px; color: var(--ink-soft); font-style: italic; }
.current-payslip-card .cp-actions { margin-top: 12px; }

.notice-banner {
    background: var(--mustard-pale);
    color: #97661F;
    border-radius: 12px;
    padding: 14px 18px;
    font-size: 13.5px;
    margin-bottom: 20px;
}

.leave-form { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; align-items: end; }
.leave-form .field-group.full { grid-column: 1 / -1; }
.leave-form label { display:block; font-size: 12.5px; font-weight: 600; color: var(--ink-soft); margin-bottom: 6px; }
.leave-form input, .leave-form select, .leave-form textarea {
    width: 100%; padding: 9px 10px; border: 1.5px solid var(--line); border-radius: 8px;
    font-family: var(--font-body); font-size: 13.5px;
}
.leave-form textarea { min-height: 70px; resize: vertical; }
.leave-form button {
    grid-column: 1 / -1; justify-self: start; background: var(--green-mid); color: #FFFFFF;
    border: none; padding: 11px 22px; border-radius: 10px; font-weight: 600; font-size: 14px; cursor: pointer;
}
.leave-form button:hover { background: var(--green-deep); }

@media (max-width: 640px) {
    .container { padding: 20px 16px; }
    .topbar { padding: 14px 16px; flex-wrap: wrap; gap: 8px; }
    .topbar .user-info { width: 100%; flex-wrap: wrap; gap: 10px; }
    .employee-notifications { margin-right: auto; }
    .profile-card { flex-direction: column; align-items: flex-start; }
    .leave-form { grid-template-columns: 1fr; }
}
/* Payslip preview modal — replaces the old window.open() popup, which
   looked bare/inconsistent (browser chrome, gets blocked by pop-up
   blockers). This renders the payslip inside a styled in-app dialog. */
.payslip-modal-overlay {
    display: none;
    position: fixed; inset: 0; background: rgba(11,33,49,.45);
    align-items: center; justify-content: center;
    padding: 24px; z-index: 200;
}
.payslip-modal-overlay.is-open { display: flex; }
.payslip-modal-card {
    background: var(--white); border-radius: 18px; padding: 0;
    max-width: 780px; width: 100%; max-height: 92vh;
    display: flex; flex-direction: column; overflow: hidden;
    box-shadow: 0 24px 64px rgba(0,0,0,.35);
}
.payslip-modal-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 22px; border-bottom: 1.5px solid var(--line); flex-shrink: 0;
}
.payslip-modal-head h2 { font-family: var(--font-display); color: var(--text-accent); font-size: 17px; margin: 0; }
.payslip-modal-actions { display: flex; align-items: center; gap: 8px; }
.payslip-modal-actions button {
    border: none; cursor: pointer; border-radius: 8px;
    padding: 8px 14px; font-size: 13px; font-weight: 600;
    display: inline-flex; align-items: center; gap: 5px;
    font-family: var(--font-body);
}
.payslip-modal-print { background: var(--green-pale); color: var(--text-accent); }
.payslip-modal-print:hover { filter: brightness(0.97); }
.payslip-modal-close { background: var(--cream); color: var(--ink-soft); }
.payslip-modal-close:hover { background: var(--red-pale); color: var(--red-alert); }
.payslip-modal-body { flex: 1; overflow: hidden; background: var(--cream); }
.payslip-modal-body iframe { width: 100%; height: 100%; min-height: 72vh; border: none; display: block; }
@media (max-width: 640px) {
    .payslip-modal-overlay { padding: 0; }
    .payslip-modal-card { max-width: 100%; max-height: 100%; height: 100%; border-radius: 0; }
    .payslip-modal-body iframe { min-height: 100%; }
}
</style>
<link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>">
</head>
<body class="dashboard-page dashboard-page--employee">

<div class="topbar">
    <h1>HRMS &amp; Payroll</h1>
    <div class="user-info">
        <a class="employee-notifications" href="hr_notifications.php" aria-label="Open my notifications">
            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M6 8a4 4 0 018 0c0 3.2 1 4.5 1.5 5H4.5C5 12.5 6 11.2 6 8Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M8.2 15.5a1.8 1.8 0 003.6 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            <span>Notifications</span>
            <?php if ($notificationPendingCount > 0): ?><span class="employee-notifications__count" aria-label="<?= (int) $notificationPendingCount ?> pending"><?= (int) min($notificationPendingCount, 99) ?><?= $notificationPendingCount > 99 ? '+' : '' ?></span><?php endif; ?>
        </a>
        <span class="user-avatar">
            <?php if (!empty($_SESSION['profile_picture'])): ?>
                <img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt="">
            <?php else: ?>
                <?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?>
            <?php endif; ?>
        </span>
        <span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span>
        <a class="logout" href="<?= logoutUrl() ?>" data-confirm="Are you sure you want to logout?" data-confirm-title="Log out?" data-confirm-ok="Yes, logout" data-confirm-color="#123C5C">Logout</a>
    </div>
</div>

<div class="container">
    <section class="dashboard-hero" aria-labelledby="dashboard-greeting">
        <div class="dashboard-hero__copy">
            <span class="dashboard-hero__label">Employee self-service</span>
            <h2 id="dashboard-greeting"><?= date('G') < 12 ? 'Good morning' : (date('G') < 18 ? 'Good afternoon' : 'Good evening') ?>, <?= clean($employee['first_name'] ?? $_SESSION['full_name'] ?? 'there') ?>.</h2>
            <p>Your attendance, requests, payslips, and company updates in one place.</p>
        </div>
        <a class="dashboard-primary-action" href="#attendanceToday">View today’s attendance <span aria-hidden="true">→</span></a>
    </section>

    <?php if (!$employee_id || !$employee): ?>
        <div class="notice-banner">
            No employee record is linked to your account. Please contact HR/Admin to resolve this issue.
        </div>
    <?php else: ?>

        <div class="profile-card">
            <div>
                <h2><?= clean($employee['first_name'] . ' ' . $employee['last_name']) ?></h2>
                <div class="sub"><?= clean($employee['employee_code']) ?> · <?= clean($employee['position'] ?: '—') ?></div>
            </div>
            <div class="profile-meta">
                <div class="item"><div class="label">Department</div><div class="value"><?= clean($employee['department'] ?: '—') ?></div></div>
                <div class="item"><div class="label">Employment Type</div><div class="value"><?= clean(ucfirst($employee['employment_type'])) ?></div></div>
                <div class="item"><div class="label">Date Hired</div><div class="value"><?= clean(date('M d, Y', strtotime($employee['date_hired']))) ?></div></div>
            </div>
        </div>

        <?php $__myEmployee = $employee; require 'includes/personal_info_card.php'; ?>

        <!-- ============================================= -->
        <!-- ANNOUNCEMENTS (company-wide + own department)  -->
        <!-- ============================================= -->
        <div class="section" id="announcements">
            <div class="profile-card" style="justify-content:space-between; align-items:center;">
                <h2 style="font-size:18px;">Announcements</h2>
                <a href="announcements.php" style="font-size:12.5px; font-weight:600; color:var(--text-accent);">View all &rarr;</a>
            </div>
            <?php if (empty($myAnnouncementsPreview)): ?>
                <div class="notice-banner" style="background:var(--cream); color:var(--ink-soft);">No announcements right now.</div>
            <?php else: ?>
                <?php foreach ($myAnnouncementsPreview as $a): ?>
                    <div class="profile-card" style="flex-direction:column; align-items:flex-start; gap:4px;">
                        <h2 style="font-size:15px;"><?= clean($a['title']) ?></h2>
                        <div class="sub"><?= clean(mb_strimwidth($a['content'], 0, 180, '...')) ?></div>
                        <div class="sub" style="font-size:11.5px;"><?= clean(date('F d, Y', strtotime($a['published_at'] ?: $a['created_at']))) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ============================================= -->
        <!-- TIME IN / TIME OUT (real-time, one click)      -->
        <!-- ============================================= -->
        <div class="section" id="attendanceToday">
            <?php if (!empty($timein_message)): ?>
                <div class="notice-banner" style="background:#e6f4ea; color:#1a7a3b;"><?= clean($timein_message) ?></div>
            <?php endif; ?>
            <?php if (!empty($timein_error)): ?>
                <div class="notice-banner" style="background:var(--red-pale); color:var(--red-alert);"><?= clean($timein_error) ?></div>
            <?php endif; ?>

            <div class="profile-card" style="justify-content:space-between;">
                <div>
                    <h2 style="font-size:18px;">Attendance Today — <?= clean(date('D, M d, Y', strtotime($today))) ?></h2>
                    <div class="sub">
                        <?php if ($todayShift): ?>
                            Current shift: <strong><?= clean($todayShift['shift_name']) ?></strong>
                            (<?= clean(date('h:i A', strtotime($todayShift['start_time']))) ?>–<?= clean(date('h:i A', strtotime($todayShift['end_time']))) ?>)
                            <br>
                        <?php else: ?>
                            <span style="color:var(--ink-soft);">No shift assigned for today (Day Off, or not yet set up by HR).</span>
                            <br>
                        <?php endif; ?>
                        <?php if ($todayRow && !empty($todayRow['time_in'])): ?>
                            Time In: <strong><?= clean(date('h:i A', strtotime($todayRow['time_in']))) ?></strong>
                            <?php if ((int) $todayRow['late_minutes'] > 0): ?>
                                <span class="badge badge-late"><?= (int) $todayRow['late_minutes'] ?> min late</span>
                            <?php endif; ?>
                            <?php if (!empty($todayRow['time_out'])): ?>
                                &nbsp;·&nbsp; Time Out: <strong><?= clean(date('h:i A', strtotime($todayRow['time_out']))) ?></strong>
                            <?php endif; ?>
                        <?php elseif ($unfinishedRecord): ?>
                            You have an unfinished attendance record from <strong><?= clean(date('M d, Y', strtotime($unfinishedRecord['date']))) ?></strong> (Timed In but never Timed Out). Time In is closed until it's corrected.
                        <?php elseif ($__needsLateRequest): ?>
                            Your shift has already ended — Time In is closed for today.
                        <?php elseif ($__timeInTooEarly): ?>
                            It's too early to Time In — it opens at <strong><?= clean(date('h:i A', strtotime($timeInOpensAt))) ?></strong>.
                        <?php else: ?>
                            You haven't timed in yet today.
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <?php if ((!$todayRow || empty($todayRow['time_in'])) && $unfinishedRecord): ?>
                        <div style="background:var(--red-pale, #FBE7E2); color:var(--red-alert, #C8472D); padding:10px 14px; border-radius:10px; font-size:13.5px; max-width:280px;">
                            You have an unfinished attendance record. Please contact your supervisor or HR to correct your previous Time Out.
                        </div>
                    <?php elseif ((!$todayRow || empty($todayRow['time_in'])) && $__needsLateRequest && $lateRequestToday && $lateRequestToday['status'] === 'pending'): ?>
                        <span class="badge" style="background:var(--mustard-pale); color:#8A6412;">Time In request pending Supervisor approval</span>
                        <div style="font-size:12.5px; margin-top:8px; color:var(--ink-soft);">Requested shift: <strong><?= clean($lateRequestToday['requested_shift_name'] ?? date('h:i A', strtotime($lateRequestToday['requested_time']))) ?></strong> (<?= clean(date('h:i A', strtotime($lateRequestToday['requested_time']))) ?>)</div>
                    <?php elseif ((!$todayRow || empty($todayRow['time_in'])) && $__needsLateRequest): ?>
                        <form method="POST" style="margin:0; max-width:280px;">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="request_late_timein">
                            <?php if ($lateRequestToday && $lateRequestToday['status'] === 'rejected'): ?>
                                <div style="font-size:12.5px; margin-bottom:8px; color:var(--red-alert);">Your last request was rejected. You may submit a new one.</div>
                            <?php endif; ?>
                            <label style="display:block; font-size:12.5px; color:var(--ink-soft); margin-bottom:4px;">Which shift would you like to Time In for</label>
                            <select name="requested_shift_id" required style="width:100%; padding:8px 10px; border:1px solid var(--line); border-radius:8px; margin-bottom:8px; font-size:13.5px;">
                                <option value="">Select a shift…</option>
                                <?php foreach ($activeShifts as $__as): ?>
                                    <option value="<?= (int) $__as['shift_id'] ?>"><?= clean($__as['shift_name']) ?> (<?= clean(date('h:i A', strtotime($__as['start_time']))) ?>–<?= clean(date('h:i A', strtotime($__as['end_time']))) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <label style="display:block; font-size:12.5px; color:var(--ink-soft); margin-bottom:4px;">Reason</label>
                            <input type="text" name="late_reason" required minlength="3" placeholder="e.g. Traffic, personal emergency" style="width:100%; padding:8px 10px; border:1px solid var(--line); border-radius:8px; margin-bottom:10px; font-size:13.5px;">
                            <button type="submit" class="btn btn-primary" style="background:var(--mustard); color: #FFFFFF; border:none; padding:12px 22px; border-radius:10px; font-weight:600; cursor:pointer; width:100%;">Request Time In</button>
                            <div style="font-size:12.5px; margin-top:8px; color:var(--ink-soft);">This will be sent to your Supervisor for approval.</div>
                        </form>
                    <?php elseif ((!$todayRow || empty($todayRow['time_in'])) && $__timeInTooEarly): ?>
                        <button type="button" disabled title="Time In opens at <?= clean(date('h:i A', strtotime($timeInOpensAt))) ?>" style="background:#EFEFEF; color:#9AA0AE; border:none; padding:12px 22px; border-radius:10px; font-weight:600; cursor:not-allowed;">Time In Closed</button>
                        <div style="font-size:12.5px; margin-top:8px; color:var(--ink-soft);">Opens at <strong><?= clean(date('h:i A', strtotime($timeInOpensAt))) ?></strong> — come back then.</div>
                    <?php elseif (!$todayRow || empty($todayRow['time_in'])): ?>
                        <form method="POST" style="margin:0;" id="selfTimeInForm">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="self_time_in">
                            <input type="hidden" name="latitude" id="tiLatitude" value="">
                            <input type="hidden" name="longitude" id="tiLongitude" value="">
                            <input type="hidden" name="location_accuracy" id="tiAccuracy" value="">
                            <input type="hidden" name="location_address" id="tiAddress" value="">
                            <button type="submit" id="timeInBtn" class="btn btn-primary" style="background:var(--green-mid); color: #FFFFFF; border:none; padding:12px 22px; border-radius:10px; font-weight:600; cursor:pointer;">Time In Now</button>
                            <div id="geoStatus" style="font-size:12.5px; margin-top:8px; color:var(--ink-soft);">📍 <?= ATTENDANCE_LOCATION_MODE === 'approximate' ? 'An approximate location will be captured' : 'Your GPS location will be captured automatically' ?> when you Time In. Please <strong>Allow</strong> location access in the browser.</div>
                        </form>
                    <?php elseif (empty($todayRow['time_out']) && $shiftNotStartedYet): ?>
                        <button type="button" disabled title="Time Out opens once your shift starts" style="background:#EFEFEF; color:#9AA0AE; border:none; padding:12px 22px; border-radius:10px; font-weight:600; cursor:not-allowed;">Time Out Closed</button>
                        <div style="font-size:12.5px; margin-top:8px; color:var(--ink-soft);">You timed in early — Time Out opens once your shift starts<?php if ($todayShift): ?> at <strong><?= clean(date('h:i A', strtotime($todayShift['start_time']))) ?></strong><?php endif; ?>.</div>
                    <?php elseif (empty($todayRow['time_out'])): ?>
                        <form method="POST" style="margin:0;">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="self_time_out">
                            <button type="submit" class="btn btn-primary" style="background:var(--mustard); color: #FFFFFF; border:none; padding:12px 22px; border-radius:10px; font-weight:600; cursor:pointer;">Time Out Now</button>
                        </form>
                    <?php else: ?>
                        <span class="badge badge-present">Attendance complete for today</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ============================================= -->
        <!-- MY ATTENDANCE                                  -->
        <!-- ============================================= -->
        <div class="section">
            <div class="section-head">
                <h3>My Attendance — <?= clean(date('F Y', strtotime($month . '-01'))) ?></h3>
                <div class="month-nav">
                    <a href="?month=<?= $prev_month ?>" class="btn">&larr; <?= date('M Y', strtotime($prev_month . '-01')) ?></a>
                    <a href="?month=<?= $next_month ?>" class="btn <?= $next_month > date('Y-m') ? 'disabled' : '' ?>"><?= date('M Y', strtotime($next_month . '-01')) ?> &rarr;</a>
                    <?php if (!$is_current_month): ?>
                        <a href="?month=<?= date('Y-m') ?>" class="btn">This Month</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="summary-row" style="margin-bottom:14px;">
                <div class="summary-chip"><strong><?= $attendance_sum['present'] ?></strong>Present</div>
                <div class="summary-chip"><strong><?= $attendance_sum['late'] ?></strong>Late</div>
                <div class="summary-chip"><strong><?= $attendance_sum['absent'] ?></strong>Absent</div>
                <div class="summary-chip"><strong><?= $attendance_sum['leave'] ?></strong>On Leave</div>
                <div class="summary-chip"><strong><?= $attendance_sum['holiday'] ?></strong>Holiday</div>
                <div class="summary-chip"><strong><?= $attendance_sum['day_off'] ?></strong>Day Off (Rest Day)</div>
            </div>

            <div class="table-card">
                <?php if (empty($my_attendance)): ?>
                    <div class="empty-state">No attendance record for this month.</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time In</th>
                            <th>Time In Location</th>
                            <th>Time Out</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_attendance as $row): $badge = attendanceBadge($row['status']); ?>
                        <tr>
                            <td><?= clean(date('D, M d, Y', strtotime($row['date']))) ?></td>
                            <td><?= $row['time_in'] ? clean(date('h:i A', strtotime($row['time_in']))) : '—' ?></td>
                            <td style="max-width:260px;">
                                <?php if (isset($row['time_in_latitude']) && $row['time_in_latitude'] !== null): ?>
                                    <?php if (!empty($row['time_in_address'])): ?>
                                        <span style="font-size:12.5px;"><?= clean($row['time_in_address']) ?></span><br>
                                    <?php endif; ?>
                                    <a href="https://www.google.com/maps?q=<?= urlencode($row['time_in_latitude'] . ',' . $row['time_in_longitude']) ?>" target="_blank" rel="noopener" style="font-size:12px; color:var(--green-mid); font-weight:600;">View on Map</a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?= $row['time_out'] ? clean(date('h:i A', strtotime($row['time_out']))) : '—' ?></td>
                            <td>
                                <span class="badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
                                <?php if ((int) $row['late_minutes'] > 0): ?>
                                    <span class="badge badge-late"><?= (int) $row['late_minutes'] ?> min late</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================= -->
        <!-- REQUEST LEAVE                                  -->
        <!-- ============================================= -->
        <div class="section">
            <div class="section-head">
                <h3>Request Leave</h3>
            </div>

            <?php if (!empty($leave_message)): ?>
                <div class="notice-banner" style="background:#e6f4ea; color:#1a7a3b;"><?= clean($leave_message) ?></div>
            <?php endif; ?>
            <?php if (!empty($leave_error)): ?>
                <div class="notice-banner" style="background:var(--red-pale); color:var(--red-alert);"><?= clean($leave_error) ?></div>
            <?php endif; ?>

            <div class="table-card" style="padding:18px 22px; margin-bottom:16px;">
                <div style="display:flex; gap:14px; flex-wrap:wrap;">
                    <?php foreach ($leave_balances as $type => $bal): ?>
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

            <div class="table-card" style="padding:20px 22px; margin-bottom:24px;">
                <form method="POST" class="leave-form">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="request_leave">

                    <div class="field-group">
                        <label>Leave Type *</label>
                        <select name="leave_type" required>
                            <option value="">-- Select --</option>
                            <option value="sick">Sick Leave</option>
                            <option value="vacation">Vacation Leave</option>
                            <option value="emergency">Emergency Leave</option>
                            <option value="others">Others</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label>From *</label>
                        <input type="date" name="date_from" min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="field-group">
                        <label>To *</label>
                        <input type="date" name="date_to" min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="field-group full">
                        <label>Reason *</label>
                        <textarea name="reason" placeholder="Brief explanation of why you're taking leave" required></textarea>
                    </div>
                    <button type="submit">Submit Leave Request</button>
                </form>
            </div>

            <div class="table-card">
                <?php if (empty($my_leave_requests)): ?>
                    <div class="empty-state">You haven't submitted any leave requests yet.</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Dates</th>
                            <th>Reason</th>
                            <th>Requested</th>
                            <th>Status</th>
                            <th>Decided On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_leave_requests as $lv): $lvBadge = leaveStatusBadge($lv['status']); ?>
                        <tr>
                            <td><?= clean(getLeaveTypeLabel($lv['leave_type'])) ?></td>
                            <td><?= clean(date('M d', strtotime($lv['date_from']))) ?>–<?= clean(date('M d, Y', strtotime($lv['date_to']))) ?></td>
                            <td style="max-width:220px;"><?= clean($lv['reason'] ?: '—') ?></td>
                            <td><?= clean(date('M d, Y', strtotime($lv['requested_at']))) ?></td>
                            <td><span class="badge <?= $lvBadge['class'] ?>"><?= $lvBadge['label'] ?></span></td>
                            <td><?= !empty($lv['decided_at']) ? clean(date('M d, Y', strtotime($lv['decided_at']))) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================= -->
        <!-- REQUEST OVERTIME                               -->
        <!-- ============================================= -->
        <div class="section">
            <div class="section-head">
                <h3>Request Overtime</h3>
            </div>

            <?php if (!empty($ot_message)): ?>
                <div class="notice-banner" style="background:#e6f4ea; color:#1a7a3b;"><?= clean($ot_message) ?></div>
            <?php endif; ?>
            <?php if (!empty($ot_error)): ?>
                <div class="notice-banner" style="background:var(--red-pale); color:var(--red-alert);"><?= clean($ot_error) ?></div>
            <?php endif; ?>

            <div class="table-card" style="padding:20px 22px; margin-bottom:24px;">
                <form method="POST" class="leave-form">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="request_overtime">

                    <div class="field-group">
                        <label>Date *</label>
                        <input type="date" name="ot_date" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="field-group">
                        <label>Hours *</label>
                        <input type="number" name="hours_requested" min="0.5" max="12" step="0.5" required>
                    </div>
                    <div class="field-group full">
                        <label>Reason *</label>
                        <textarea name="ot_reason" placeholder="Brief explanation of the overtime work" required></textarea>
                    </div>
                    <button type="submit">Submit Overtime Request</button>
                </form>
            </div>

            <div class="table-card">
                <?php if (empty($my_overtime_requests)): ?>
                    <div class="empty-state">You haven't submitted any overtime requests yet.</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Hours</th>
                            <th>Reason</th>
                            <th>Requested</th>
                            <th>Status</th>
                            <th>Decided On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_overtime_requests as $ot): $otBadge = otStatusBadge($ot['status']); ?>
                        <tr>
                            <td><?= clean(date('M d, Y', strtotime($ot['ot_date']))) ?></td>
                            <td><?= clean($ot['hours_requested']) ?> hrs</td>
                            <td style="max-width:220px;"><?= clean($ot['reason'] ?: '—') ?></td>
                            <td><?= clean(date('M d, Y', strtotime($ot['requested_at']))) ?></td>
                            <td><span class="badge <?= $otBadge['class'] ?>"><?= $otBadge['label'] ?></span></td>
                            <td><?= !empty($ot['decided_at']) ? clean(date('M d, Y', strtotime($ot['decided_at']))) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================= -->
        <!-- REQUEST OFFICIAL BUSINESS (Item 12)            -->
        <!-- ============================================= -->
        <div class="section">
            <div class="section-head">
                <h3>Request Official Business</h3>
            </div>

            <?php if (!empty($ob_message)): ?>
                <div class="notice-banner" style="background:#e6f4ea; color:#1a7a3b;"><?= clean($ob_message) ?></div>
            <?php endif; ?>
            <?php if (!empty($ob_error)): ?>
                <div class="notice-banner" style="background:var(--red-pale); color:var(--red-alert);"><?= clean($ob_error) ?></div>
            <?php endif; ?>

            <div class="table-card" style="padding:20px 22px; margin-bottom:24px;">
                <form method="POST" class="leave-form">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="request_ob">

                    <div class="field-group">
                        <label>Date *</label>
                        <input type="date" name="ob_date" required>
                    </div>
                    <div class="field-group">
                        <label>Time From</label>
                        <input type="time" name="time_from">
                    </div>
                    <div class="field-group">
                        <label>Time To</label>
                        <input type="time" name="time_to">
                    </div>
                    <div class="field-group">
                        <label>Destination</label>
                        <input type="text" name="destination" placeholder="e.g. BDO Main Branch">
                    </div>
                    <div class="field-group full">
                        <label>Purpose *</label>
                        <textarea name="purpose" placeholder="Brief explanation of the official business" required></textarea>
                    </div>
                    <button type="submit">Submit OB Request</button>
                </form>
            </div>

            <div class="table-card">
                <?php if (empty($my_ob_requests)): ?>
                    <div class="empty-state">You haven't submitted any Official Business requests yet.</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Destination</th>
                            <th>Purpose</th>
                            <th>Status</th>
                            <th>Decided On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_ob_requests as $ob): $obBadge = otStatusBadge($ob['status']); ?>
                        <tr>
                            <td><?= clean(date('M d, Y', strtotime($ob['ob_date']))) ?></td>
                            <td><?= (!empty($ob['time_from']) && !empty($ob['time_to'])) ? clean(date('g:i A', strtotime($ob['time_from'])) . '–' . date('g:i A', strtotime($ob['time_to']))) : '—' ?></td>
                            <td><?= clean($ob['destination'] ?: '—') ?></td>
                            <td style="max-width:220px;"><?= clean($ob['purpose'] ?: '—') ?></td>
                            <td><span class="badge <?= $obBadge['class'] ?>"><?= $obBadge['label'] ?></span></td>
                            <td><?= !empty($ob['decided_at']) ? clean(date('M d, Y', strtotime($ob['decided_at']))) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================= -->
        <!-- MY PAYSLIPS                                    -->
        <!-- ============================================= -->
        <div class="section">
            <div class="section-head">
                <h3>My Payslips</h3>
            </div>
            <?php
                $__acctFull   = preg_replace('/\s+/', '', (string) ($employee['bank_account_number'] ?? ''));
                $__acctMasked = $__acctFull !== ''
                    ? ((strlen($__acctFull) > 4 ? str_repeat('•', strlen($__acctFull) - 4) : '') . substr($__acctFull, -4))
                    : '';
            ?>
            <?php if (($employee['payment_method'] ?? 'bank') === 'cash'): ?>
            <div style="font-size:13px; color:var(--ink-soft); margin-bottom:12px;">💵 Salary payout: <strong>Cash (Walk-in)</strong> — claim from HR on your payout date.</div>
            <?php elseif (!empty($employee['bank_name']) || $__acctFull !== ''): ?>
            <div style="font-size:13px; color:var(--ink-soft); margin-bottom:12px;">
                💳 Salary deposited to: <strong><?= clean($employee['bank_name'] ?: '—') ?></strong>
                <?php if ($__acctMasked !== ''): ?> · <?= clean($__acctMasked) ?><?php endif; ?>
                <?php if (!empty($employee['bank_account_name'])): ?> · <?= clean($employee['bank_account_name']) ?><?php endif; ?>
            </div>
            <?php else: ?>
            <div style="font-size:13px; color:var(--ink-soft); margin-bottom:12px;">💳 No bank/card details set yet. Please contact HR to set this up.</div>
            <?php endif; ?>

            <!-- Current Payslip: this cutoff's pay, still upcoming (payout
                 date hasn't arrived yet) — a preview of what you'll be
                 receiving, not yet an official released payslip. -->
            <?php if (empty($current_payslips)): ?>
                <div class="table-card" style="margin-bottom:14px;">
                    <div class="empty-state">No payslip is being prepared for you right now.</div>
                </div>
            <?php else: ?>
                <?php foreach ($current_payslips as $cp): ?>
                <div class="current-payslip-card">
                    <div class="cp-head">
                        <div>
                            <div class="cp-period"><?= clean(date('M d', strtotime($cp['pay_period_start']))) ?> – <?= clean(date('M d, Y', strtotime($cp['pay_period_end']))) ?></div>
                            <div class="cp-payout">Payout on <?= clean(date('M d, Y', strtotime($cp['payout_date']))) ?> · <span class="badge badge-late">Not yet released</span></div>
                        </div>
                        <div class="cp-net">
                            <div class="label">Expected Net Pay</div>
                            <div class="value"><?= peso($cp['net_pay']) ?></div>
                        </div>
                    </div>
                    <div class="cp-breakdown">
                        <span>Basic: <strong><?= peso($cp['basic_pay']) ?></strong></span>
                        <?php if ((float) $cp['overtime_pay'] > 0): ?><span>Overtime: <strong><?= peso($cp['overtime_pay']) ?></strong></span><?php endif; ?>
                        <?php if ((float) $cp['allowances'] > 0): ?><span>Allowances: <strong><?= peso($cp['allowances']) ?></strong></span><?php endif; ?>
                        <span>Gross: <strong><?= peso($cp['gross_pay']) ?></strong></span>
                    </div>
                    <div class="cp-note">This is a preview of your <?= clean(date('F d', strtotime($cp['payout_date']))) ?> pay. You can view or print this clearly marked preview; it becomes final once Finance releases it.</div>
                    <div class="cp-actions">
                        <a href="payslip.php?id=<?= enc_id($cp['payroll_id']) ?>" onclick="return openPayslipModal(this.href);" style="font-size:12.5px; color:var(--green-mid, #1978A8); font-weight:600;">View Payslip</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="section-head" style="margin-top:22px;">
                <h3>Salary History</h3>
            </div>
            <div class="table-card">
                <?php if (empty($salary_history)): ?>
                    <div class="empty-state">No released payslips yet.</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Pay Period</th>
                            <th>Payout Date</th>
                            <th>Gross Pay</th>
                            <th>Net Pay</th>
                            <th>Generated</th>
                            <th>Payslip</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($salary_history as $pay): ?>
                        <tr>
                            <td>
                                <?= clean(date('M d', strtotime($pay['pay_period_start']))) ?> – <?= clean(date('M d, Y', strtotime($pay['pay_period_end']))) ?>
                                <?php
                                    $extras = [];
                                    foreach ($optional_payslip_fields as $col => $label) {
                                        if (isset($pay[$col]) && $pay[$col] !== null) {
                                            $extras[] = $label . ': ' . peso($pay[$col]);
                                        }
                                    }
                                ?>
                                <?php if (!empty($extras)): ?>
                                <div class="payslip-details">
                                    <?php foreach ($extras as $ex): ?><span><?= clean($ex) ?></span><?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td><?= (!empty($pay['payout_date'])) ? clean(date('M d, Y', strtotime($pay['payout_date']))) : '—' ?></td>
                            <td><?= peso($pay['gross_pay']) ?></td>
                            <td><strong><?= peso($pay['net_pay']) ?></strong></td>
                            <td><?= isset($pay['generated_at']) ? clean(date('M d, Y', strtotime($pay['generated_at']))) : '—' ?></td>
                            <td>
                                <a href="payslip.php?id=<?= enc_id($pay['payroll_id']) ?>" onclick="return openPayslipModal(this.href);" style="font-size:12.5px; color:var(--green-mid, #1978A8); font-weight:600;">View Payslip</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>

</div>

<div class="payslip-modal-overlay" id="payslipModalOverlay">
    <div class="payslip-modal-card">
        <div class="payslip-modal-head">
            <h2>Payslip</h2>
            <div class="payslip-modal-actions">
                <button type="button" class="payslip-modal-print" onclick="printPayslipModal()">🖨️ Print</button>
                <button type="button" class="payslip-modal-close" onclick="closePayslipModal()" aria-label="Close">✕ Close</button>
            </div>
        </div>
        <div class="payslip-modal-body">
            <iframe id="payslipModalFrame" title="Payslip"></iframe>
        </div>
    </div>
</div>

<script>
// Opens a single payslip inside a styled in-app modal (iframe) instead
// of a separate browser popup window — no pop-up-blocker issues, and it
// stays visually consistent with the rest of the app. payslip.php
// already hides its own toolbar under @media print, so the Print button
// below comes out as a clean one-page slip.
function openPayslipModal(url) {
    document.getElementById('payslipModalFrame').src = url;
    document.getElementById('payslipModalOverlay').classList.add('is-open');
    document.body.style.overflow = 'hidden';
    return false;
}
function closePayslipModal() {
    document.getElementById('payslipModalOverlay').classList.remove('is-open');
    document.body.style.overflow = '';
    document.getElementById('payslipModalFrame').src = 'about:blank';
}
function printPayslipModal() {
    const frame = document.getElementById('payslipModalFrame');
    if (frame && frame.contentWindow) {
        frame.contentWindow.focus();
        frame.contentWindow.print();
    }
}
document.getElementById('payslipModalOverlay').addEventListener('click', function (e) {
    if (e.target === this) closePayslipModal();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closePayslipModal();
});

// =====================================================
// SELF TIME IN — GPS LOCATION CAPTURE
// =====================================================
// When "Time In Now" is clicked: the employee's GPS location is
// captured first before submitting. If location is denied/unavailable,
// Time In does NOT proceed and a message is shown (requirement).
// Best-effort conversion of the coordinates to a readable address via
// OpenStreetMap (Nominatim); if that fails, the coordinates alone are
// still saved (with a Google Maps link in the view).
(function () {
    var form = document.getElementById('selfTimeInForm');
    if (!form) return;

    var btn      = document.getElementById('timeInBtn');
    var statusEl = document.getElementById('geoStatus');

    function captureBestPosition(done, failed) {
        var best = null;
        var watchId = null;
        var finished = false;
        var timer = setTimeout(function () { finish(best); }, 15000);

        function finish(position) {
            if (finished) return;
            finished = true;
            clearTimeout(timer);
            if (watchId !== null) navigator.geolocation.clearWatch(watchId);
            if (position) done(position);
            else failed({ code: 2 });
        }

        watchId = navigator.geolocation.watchPosition(function (position) {
            if (!best || position.coords.accuracy < best.coords.accuracy) best = position;
            statusEl.textContent = 'Improving GPS accuracy… current estimate about ' + Math.round(best.coords.accuracy) + ' meters.';
            if (best.coords.accuracy <= 50) finish(best);
        }, function (error) {
            if (!best && error.code === error.PERMISSION_DENIED) finish(null);
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    }

    form.addEventListener('submit', function (e) {
        // Second submit (location already captured) — let it proceed normally.
        if (form.dataset.ready === '1') return;

        e.preventDefault();

        if (!navigator.geolocation) {
            statusEl.style.color = '#C8472D';
            statusEl.textContent = 'Location is not supported in this browser, so you cannot Time In.';
            return;
        }

        btn.disabled = true;
        statusEl.style.color = 'var(--ink-soft)';
        statusEl.textContent = 'Getting your location… please allow location access.';

        captureBestPosition(
            function (pos) {
                var lat = pos.coords.latitude;
                var lng = pos.coords.longitude;
                var accuracy = pos.coords.accuracy;
                document.getElementById('tiLatitude').value  = lat;
                document.getElementById('tiLongitude').value = lng;
                document.getElementById('tiAccuracy').value = accuracy;

                function finishSubmit() {
                    form.dataset.ready = '1';
                    statusEl.style.color = 'var(--ink-soft)';
                    statusEl.textContent = 'GPS captured (accuracy about ' + Math.round(accuracy) + ' meters). Timing in now…';
                    form.submit();
                }

                // Reverse geocode (best-effort). If it fails, coords only.
                var url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' +
                          encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng);
                var controller = typeof AbortController === 'function' ? new AbortController() : null;
                var addressTimeout = controller ? setTimeout(function () { controller.abort(); }, 3000) : null;
                fetch(url, { headers: { 'Accept': 'application/json' }, signal: controller ? controller.signal : undefined })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.display_name) {
                            document.getElementById('tiAddress').value = data.display_name;
                        }
                    })
                    .catch(function () { /* offline / failed — coords only */ })
                    .then(function () { if (addressTimeout) clearTimeout(addressTimeout); finishSubmit(); }, finishSubmit);
            },
            function (err) {
                btn.disabled = false;
                statusEl.style.color = '#C8472D';
                if (err && err.code === err.PERMISSION_DENIED) {
                    statusEl.textContent = 'Location (GPS) access is required before you can Time In. Please allow location in your browser and try again.';
                } else {
                    statusEl.textContent = 'Could not get your location. Please make sure GPS/location is turned ON, then try again.';
                }
            },
            
        );
    });
})();
</script>

<?php include 'includes/sweetalert.php'; ?>

<?php if ($show_vacation_cooldown_popup): ?>
<script>
    Swal.fire({
        icon: 'error',
        title: 'Not Yet Available',
        text: <?= json_encode($leave_error) ?>,
        confirmButtonText: 'Okay',
        confirmButtonColor: '#123C5C'
    });
</script>
<?php endif; ?>

<footer class="dashboard-footer">
    <span>Employee workspace · <?= clean(date('F j, Y')) ?></span>
    <a href="my_profile.php">Open my profile</a>
    <button type="button" class="theme-toggle-btn dashboard-footer-theme-toggle" data-theme-storage-key="siaa_theme_<?= clean((string) ($_SESSION['user_id'] ?? 'guest')) ?>" aria-label="Switch light/dark mode">
        <svg class="tt-icon-light" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="4" stroke="currentColor" stroke-width="1.6"/><path d="M10 2.5v2M10 15.5v2M17.5 10h-2M4.5 10h-2M15.3 4.7l-1.4 1.4M6.1 13.9l-1.4 1.4M15.3 15.3l-1.4-1.4M6.1 6.1 4.7 4.7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        <svg class="tt-icon-dark" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M17 11.3A7 7 0 0 1 8.7 3 7 7 0 1 0 17 11.3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
        <span>Dark mode</span>
    </button>
</footer>
<script src="../theme-sync.js?v=4"></script>

</body>
</html>