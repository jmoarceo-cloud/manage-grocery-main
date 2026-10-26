<?php
// ============================================================
// my_profile.php — "My Profile" page for the Cashier (POS side).
// ------------------------------------------------------------
// Shows the logged-in cashier's own HRMS profile, attendance
// history, leave/overtime requests, and payslip list, and lets
// them Time In/Out and submit their own leave/overtime requests
// right here instead of digging through the HRMS side.
// Uses the Cashier-side self-service functions in includes/config.php
// (processCashierSelfTimeInOut(), getCashierLeaveBalancesForYear(),
// processCashierOvertimeRequest(), processCashierLeaveRequest()) —
// same shape as HRMS/employee_dashboard.php's processSelfTimeInOut()
// / getLeaveBalancesForYear(), just against $hrmsPdo instead of the
// HRMS side's own $pdo, so the shift-aware Late computation and
// leave-credit checks stay consistent everywhere.
// ============================================================
require_once 'includes/config.php';
requireLogin();

function getLeaveTypeLabel($type) {
    $map = ['sick' => 'Sick Leave', 'vacation' => 'Vacation Leave', 'emergency' => 'Emergency Leave', 'others' => 'Others'];
    return $map[$type] ?? ucfirst($type);
}

function leaveStatusBadgeClass($status) {
    $map = ['pending' => 'badge--pending', 'approved' => 'badge--approved', 'rejected' => 'badge--rejected'];
    return $map[$status] ?? 'badge--pending';
}

function attendanceCellClass(array $row): string {
    if ($row['status'] === 'day_off')   return 'cal-dayoff';
    if ($row['status'] === 'absent')    return 'cal-absent';
    if ($row['status'] === 'present')   return ((int) $row['late_minutes'] > 0) ? 'cal-late' : 'cal-present';
    if ($row['status'] === 'future')    return 'cal-future';
    if ($row['status'] === 'not_hired') return 'cal-notyet';
    if ($row['status'] === 'pending')   return 'cal-pending';
    return 'cal-other'; // leave / holiday
}

function attendanceCellLabel(array $row): string {
    switch ($row['status']) {
        case 'day_off':   return 'Day Off (Rest Day)';
        case 'future':    return 'Upcoming';
        case 'not_hired': return 'Not Yet Employed';
        case 'pending':   return 'Ongoing (Not Yet Timed In)';
        case 'holiday':   return 'Holiday' . (!empty($row['holiday_name']) ? ' (' . $row['holiday_name'] . ')' : '');
        case 'leave':     return 'On Leave' . (!empty($row['leave_type']) ? ' (' . ucfirst($row['leave_type']) . ')' : '');
        case 'present':   return ((int) ($row['late_minutes'] ?? 0) > 0) ? 'Late' : 'Present';
        default:          return ucfirst($row['status']);
    }
}

$myEmployeeId = $_SESSION['employee_id'] ?? null;

$__myEmployee     = null;
$my_attendance    = [];
$my_payroll       = [];
$current_payslips = [];
$salary_history   = [];
// BUGFIX: dati $pdo (grocery_pos db, walang `employees`/`attendance`/etc
// tables) ang ginagamit dito — dapat $hrmsPdo (koneksyon sa `hrms` db,
// see includes/config.php), kaya Fatal error / walang laman ang buong
// page dati sa Cashier accounts.
$hrms_unavailable = ($hrmsPdo === null);

// =====================================================
// SELF TIME IN / TIME OUT — SHARED processSelfTimeInOut()
// (includes/config.php), same one employee_dashboard.php uses,
// so the shift-aware Late computation is applied automatically.
// =====================================================
$__att          = processCashierSelfTimeInOut($hrmsPdo, $myEmployeeId);
$timein_message = $__att['message'];
$timein_error   = $__att['error'];
$todayRow       = $__att['todayRow'];
$todayShift     = $__att['todayShift'];
$approvedSwap   = $__att['approvedSwap'] ?? null;
$shiftEnded       = $__att['shiftEnded'] ?? false;
$shiftNotStartedYet = $__att['shiftNotStartedYet'] ?? false; // timed in na pero bago pa mag-shift start — Time Out disabled muna
$lateRequestToday = $__att['lateRequestToday'] ?? null;
$timeInWindowOpen = $__att['timeInWindowOpen'] ?? true;   // false = too early pa, hindi pa bukas ang Time In
$timeInOpensAt    = $__att['timeInOpensAt'] ?? null;       // H:i:s kung kailan bubukas ang Time In
$unfinishedRecord = $__att['unfinishedRecord'] ?? null; // previous day timed in pero hindi natimeout — blocks new Time In
$activeShifts     = $__att['activeShifts'] ?? []; // for the Time In Request shift picker — dapat sa mga schedule na meron tayo, hindi sariling oras
$__canTimeInLate    = $lateRequestToday && $lateRequestToday['status'] === 'approved';
// Suspension (HRMS Suspend action) — see getCashierSuspensionStatus()
// in includes/config.php. While active, Time In (and requesting one)
// stays closed no matter what the shift schedule says; it re-opens on
// its own once suspension_end_date passes, same day-based rule the
// backend enforces in processCashierSelfTimeInOut().
$__suspension  = $__att['suspension'] ?? ['active' => false, 'days_remaining' => 0, 'reason' => null, 'end_date' => null];
$__isSuspended = $__suspension['active'];
// Once the shift is over (e.g. 8am–2pm shift, past 2pm na), isara ang
// Time In Now — dapat "Time In Request" (Supervisor approval) na lang
// ang lumabas, hindi na yung normal na Time In Now button. Ganito rin
// ang ginagawa ng employee_dashboard.php / self_attendance_card.php.
$__needsLateRequest = ($todayShift || $approvedSwap) && $shiftEnded && !$__canTimeInLate;
$__timeInTooEarly   = ($todayShift || $approvedSwap) && !$shiftEnded && !$timeInWindowOpen && !$__canTimeInLate;
$canTimeInToday = ($todayShift || $approvedSwap) && (!$shiftEnded || $__canTimeInLate) && !$unfinishedRecord && !$__isSuspended;

// =====================================================
// REQUEST OVERTIME (submitted after the fact — goes to the
// employee's Supervisor for approval, same as employee_dashboard.php)
// ------------------------------------------------------
// BUGFIX: this used to be its own copy-pasted validation block that
// insert-ed straight into $pdo (grocery_pos db — wrong db, no
// overtime_requests table there) and never checked
// CASHIER_LEAVE_TYPES_WITH_LIMIT. processCashierOvertimeRequest()
// in includes/config.php already exists (built specifically for this
// page — see its doc comment) and does the same validation against
// $hrmsPdo, so we just call it instead of duplicating the logic.
// =====================================================
$__ot        = processCashierOvertimeRequest($hrmsPdo, $myEmployeeId);
$ot_message  = $__ot['message'];
$ot_error    = $__ot['error'];

// =====================================================
// REQUEST LEAVE (goes to HR/Admin for approval)
// ------------------------------------------------------
// BUGFIX: same issue as above — was inserting into $pdo instead of
// $hrmsPdo, and referenced the undefined LEAVE_TYPES_WITH_LIMIT /
// getOrCreateLeaveCredit (those only exist on the HRMS side, not
// here). processCashierLeaveRequest() already implements the correct
// Cashier-side version (CASHIER_LEAVE_TYPES_WITH_LIMIT,
// getCashierLeaveCredit, vacation cooldown checks) against $hrmsPdo.
// =====================================================
$__lv           = processCashierLeaveRequest($hrmsPdo, $myEmployeeId);
$leave_message  = $__lv['message'];
$leave_error    = $__lv['error'];

// =====================================================
// UPDATE PERSONAL INFORMATION — lets the logged-in employee fill in
// / correct the details normally captured on the application form
// (apply.php), plus their own Email and Contact Number. New hires
// arrive with the application fields already filled in (see
// confirm_hire in includes/applicant_actions.php); employees hired
// before this existed (or added directly on Employees -> Add) start
// blank and can fill them in here themselves. Shared with
// employee_dashboard.php (see includes/personal_info_card.php).
// =====================================================
$profile_message = "";
$profile_error   = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') === 'update_personal_info') {
    $__pi = processPersonalInfoUpdate($hrmsPdo, $myEmployeeId);
    $profile_message = $__pi['message'];
    $profile_error   = $__pi['error'];
}

// =====================================================
// PROFILE PICTURE — see processProfilePictureUpload() in
// includes/config.php (POS side). Signature is ($pdo, $hrmsPdo): $pdo
// updates the local `users` row (grocery_pos db), $hrmsPdo mirrors the
// picture to the linked HRMS account. BUGFIX: was calling this with
// an undefined $posPdo — the mirror-to-HRMS step silently never ran.
// =====================================================
$avatar_message = "";
$avatar_error   = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') === 'update_profile_picture') {
    $__ap = processProfilePictureUpload($pdo, $hrmsPdo);
    $avatar_message = $__ap['message'];
    $avatar_error   = $__ap['error'];
} elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') === 'remove_profile_picture') {
    $__ap = removeProfilePicture($pdo, $hrmsPdo);
    $avatar_message = $__ap['message'];
    $avatar_error   = $__ap['error'];
}

$__myAvatarFile = $_SESSION['profile_picture'] ?? null;

$leave_balances    = $myEmployeeId ? getCashierLeaveBalancesForYear($hrmsPdo, $myEmployeeId, (int) date('Y')) : [];
$my_leave_requests = [];
$my_overtime_requests = [];

if ($hrmsPdo && $myEmployeeId) {

    // Profile info
    $stmt = $hrmsPdo->prepare("
        SELECT employee_id, employee_code, first_name, last_name,
               department, position, employment_type, status, date_hired,
               bank_name, bank_account_number, bank_account_name,
               email, contact_number, address,
               birthdate, place_of_birth, sex, civil_status,
               highest_educational_attainment, year_graduated
        FROM employees
        WHERE employee_id = ?
    ");
    $stmt->execute([$myEmployeeId]);
    $__myEmployee = $stmt->fetch();

    // ---- Full month calendar (My Attendance) ----
    // Every day of the selected month is shown — not just days that
    // happen to have an attendance row — so Day Off (Rest Day),
    // Holidays, and upcoming days all render with the correct color,
    // matching the real calendar for the year instead of only what's
    // been recorded so far.
    $cal_month = isset($_GET['cal_month']) ? trim($_GET['cal_month']) : date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $cal_month)) { $cal_month = date('Y-m'); }
    $cal_month_start = $cal_month . '-01';
    $cal_month_end   = date('Y-m-t', strtotime($cal_month_start));
    $cal_prev_month  = date('Y-m', strtotime($cal_month_start . ' -1 month'));
    $cal_next_month  = date('Y-m', strtotime($cal_month_start . ' +1 month'));
    $cal_is_current  = ($cal_month === date('Y-m'));
    $__todayStr      = date('Y-m-d');

    // Recorded attendance for this month
    $stmt = $hrmsPdo->prepare("
        SELECT date, time_in, time_out, status, late_minutes
        FROM attendance
        WHERE employee_id = ? AND date BETWEEN ? AND ?
    ");
    $stmt->execute([$myEmployeeId, $cal_month_start, $cal_month_end]);
    $__attByDate = [];
    foreach ($stmt->fetchAll() as $r) {
        $__attByDate[$r['date']] = $r;
    }

    // Company holidays this month
    $__holidayByDate = [];
    try {
        $stmt = $hrmsPdo->prepare("SELECT holiday_date, name FROM holidays WHERE holiday_date BETWEEN ? AND ?");
        $stmt->execute([$cal_month_start, $cal_month_end]);
        foreach ($stmt->fetchAll() as $h) { $__holidayByDate[$h['holiday_date']] = $h['name']; }
    } catch (PDOException $e) { error_log("my_profile holiday map error: " . $e->getMessage()); }

    // Approved leave covering this month
    $__leaveByDate = [];
    try {
        $stmt = $hrmsPdo->prepare("
            SELECT leave_type, date_from, date_to FROM leave_requests
            WHERE employee_id = ? AND status = 'approved' AND date_from <= ? AND date_to >= ?
        ");
        $stmt->execute([$myEmployeeId, $cal_month_end, $cal_month_start]);
        foreach ($stmt->fetchAll() as $lv) {
            $cursor = max(strtotime($lv['date_from']), strtotime($cal_month_start));
            $stop   = min(strtotime($lv['date_to']),   strtotime($cal_month_end));
            while ($cursor <= $stop) { $__leaveByDate[date('Y-m-d', $cursor)] = $lv['leave_type']; $cursor = strtotime('+1 day', $cursor); }
        }
    } catch (PDOException $e) { error_log("my_profile leave map error: " . $e->getMessage()); }

    // This employee's Weekly Shift Schedule (day_of_week => shift row
    // or null for Day Off) — the source of truth for Rest Day coloring
    // on days that haven't been auto-marked into `attendance` yet.
    $__weeklySchedule    = [0 => null, 1 => null, 2 => null, 3 => null, 4 => null, 5 => null, 6 => null];
    $__hasScheduleSetup  = false;
    try {
        $stmt = $hrmsPdo->prepare("
            SELECT ews.day_of_week, s.shift_id, s.shift_name, s.start_time, s.end_time
            FROM employee_weekly_shifts ews
            LEFT JOIN shifts s ON s.shift_id = ews.shift_id
            WHERE ews.employee_id = ?
        ");
        $stmt->execute([$myEmployeeId]);
        foreach ($stmt->fetchAll() as $r) {
            $__weeklySchedule[(int) $r['day_of_week']] = $r['shift_id'] ? $r : null;
            $__hasScheduleSetup = true;
        }
    } catch (PDOException $e) { error_log("my_profile weekly schedule error: " . $e->getMessage()); }

    // Build one row per day of the month, resolving status in the
    // same priority order as the HRMS side: recorded present > holiday
    // > approved leave > recorded status (incl. day_off/absent) >
    // Day Off (from weekly schedule, for days not yet auto-marked) >
    // future (not yet happened) > no record.
    // Date Hired — days before this are never shown as Absent (the
    // cashier wasn't employed / hadn't started duty yet).
    $__dateHired = !empty($__myEmployee['date_hired']) ? $__myEmployee['date_hired'] : null;

    $my_attendance  = [];
    $cursor = strtotime($cal_month_start);
    $stop   = strtotime($cal_month_end);
    while ($cursor <= $stop) {
        $d   = date('Y-m-d', $cursor);
        $dow = (int) date('w', $cursor);
        $row = $__attByDate[$d] ?? null;

        if ($row && !empty($row['time_in'])) {
            $my_attendance[] = $row;
        } elseif ($__dateHired && $d < $__dateHired) {
            // Not yet hired / hadn't started duty yet — never Absent.
            $my_attendance[] = ['date' => $d, 'time_in' => null, 'time_out' => null, 'status' => 'not_hired', 'late_minutes' => 0];
        } elseif (isset($__holidayByDate[$d])) {
            $my_attendance[] = ['date' => $d, 'time_in' => null, 'time_out' => null, 'status' => 'holiday', 'late_minutes' => 0, 'holiday_name' => $__holidayByDate[$d]];
        } elseif (isset($__leaveByDate[$d])) {
            $my_attendance[] = ['date' => $d, 'time_in' => null, 'time_out' => null, 'status' => 'leave', 'late_minutes' => 0, 'leave_type' => $__leaveByDate[$d]];
        } elseif ($row) {
            $my_attendance[] = $row; // saved status (absent / day_off) with no time_in
        } elseif ($__hasScheduleSetup && empty($__weeklySchedule[$dow])) {
            $my_attendance[] = ['date' => $d, 'time_in' => null, 'time_out' => null, 'status' => 'day_off', 'late_minutes' => 0];
        } elseif ($d > $__todayStr) {
            $my_attendance[] = ['date' => $d, 'time_in' => null, 'time_out' => null, 'status' => 'future', 'late_minutes' => 0];
        } elseif ($d === $__todayStr) {
            // Today: don't flip to Absent (red) the moment the day
            // starts — only once their shift has actually ended and
            // they still never timed in. Before that, show "Ongoing"
            // (neutral), not Absent.
            $__shiftToday = $__weeklySchedule[$dow] ?? null;
            $__todayEnded = false;
            if ($__shiftToday && !empty($__shiftToday['start_time']) && !empty($__shiftToday['end_time'])) {
                $__shiftStartTs = strtotime('1970-01-01 ' . $__shiftToday['start_time']);
                $__shiftEndTs   = strtotime('1970-01-01 ' . $__shiftToday['end_time']);
                $__nowTs        = strtotime('1970-01-01 ' . date('H:i:s'));
                if ($__shiftStartTs !== false && $__shiftEndTs !== false && $__nowTs !== false) {
                    if ($__shiftEndTs <= $__shiftStartTs) { $__shiftEndTs += 86400; } // overnight shift
                    $__todayEnded = ($__nowTs >= $__shiftEndTs);
                }
            }
            $my_attendance[] = $__todayEnded
                ? ['date' => $d, 'time_in' => null, 'time_out' => null, 'status' => 'absent', 'late_minutes' => 0]
                : ['date' => $d, 'time_in' => null, 'time_out' => null, 'status' => 'pending', 'late_minutes' => 0];
        } else {
            $my_attendance[] = ['date' => $d, 'time_in' => null, 'time_out' => null, 'status' => 'absent', 'late_minutes' => 0];
        }
        $cursor = strtotime('+1 day', $cursor);
    }

    // Pad into a Sun–Sat grid.
    $calendar_cells = [];
    $leadingBlanks = (int) date('w', strtotime($cal_month_start));
    for ($i = 0; $i < $leadingBlanks; $i++) { $calendar_cells[] = null; }
    foreach ($my_attendance as $row) { $calendar_cells[] = $row; }

    // Month totals — shown next to the calendar instead of leaving
    // that space empty.
    $cal_month_totals = ['present' => 0, 'late' => 0, 'absent' => 0, 'day_off' => 0, 'leave_holiday' => 0];
    foreach ($my_attendance as $row) {
        if ($row['status'] === 'present') {
            $cal_month_totals['present']++;
            if ((int) ($row['late_minutes'] ?? 0) > 0) { $cal_month_totals['late']++; }
        } elseif ($row['status'] === 'absent') {
            $cal_month_totals['absent']++;
        } elseif ($row['status'] === 'day_off') {
            $cal_month_totals['day_off']++;
        } elseif (in_array($row['status'], ['leave', 'holiday'], true)) {
            $cal_month_totals['leave_holiday']++;
        }
    }

    // Show released payslips and approved previews for upcoming payouts.
    $stmt = $hrmsPdo->prepare("
        SELECT payroll_id, pay_period_start, pay_period_end, payout_date,
               basic_pay, overtime_pay, allowances, gross_pay,
               sss_deduction, philhealth_deduction, pagibig_deduction,
               tax_deduction, other_deductions, net_pay,
               approval_status, payment_status, generated_at
        FROM payroll_records
        WHERE employee_id = ?
          AND (
              (approval_status = 'approved' AND payment_status = 'paid')
              OR (approval_status = 'approved' AND payment_status = 'unpaid' AND payout_date > CURDATE())
          )
        ORDER BY pay_period_end DESC
        LIMIT 12
    ");
    $stmt->execute([$myEmployeeId]);
    $my_payroll = $stmt->fetchAll();

    // Keep the existing view grouping; only released rows enter it.
    // Mirrors the same split on HRMS/my_profile.php.
    $current_payslips = [];
    $salary_history    = [];
    foreach ($my_payroll as $pay) {
        $isUpcoming = $pay['payment_status'] === 'unpaid'
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

    // Leave request history
    $stmt = $hrmsPdo->prepare("SELECT * FROM leave_requests WHERE employee_id = ? ORDER BY requested_at DESC LIMIT 10");
    $stmt->execute([$myEmployeeId]);
    $my_leave_requests = $stmt->fetchAll();

    // Overtime request history
    $stmt = $hrmsPdo->prepare("SELECT * FROM overtime_requests WHERE employee_id = ? ORDER BY requested_at DESC LIMIT 10");
    $stmt->execute([$myEmployeeId]);
    $my_overtime_requests = $stmt->fetchAll();
}

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

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — HRMS &amp; Payroll</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<!-- BUGFIX: this page never linked the shared POS stylesheet even
     though it now includes includes/sidebar.php (same as
     dashboard.php/pos.php/etc.) — the sidebar markup needs
     style.css's .sidebar/.sidebar-toggle/.brand-mark/.nav-section-label/
     .sidebar-account-group/.theme-toggle-btn/dark-mode rules, none of
     which existed in this page's own <style> block, so the collapse
     button, section label, and theme toggle all rendered unstyled. -->
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">

<style>
/* BUGFIX: this page used to define its own :root palette +
   app-shell/sidebar/main-area CSS inline (copied from the HRMS side,
   which doesn't share an external stylesheet). Now that this page
   includes the shared includes/sidebar.php (see below) and links
   style.css above, that duplicate block only conflicted with it (old
   flat green palette vs. style.css's cyan/violet sidebar accent, no
   dark-mode support, no collapse-state rules) — removed so style.css
   is the single source of truth for :root / .app-shell / .sidebar /
   .main-area, same as dashboard.php, pos.php, etc. Page-specific CSS
   only starts below (.pos-topbar and everything after it). */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { font-family: var(--font-body); color: var(--ink); background: var(--cream); }
a { text-decoration: none; color: inherit; }

.pos-topbar {
    display: flex; align-items: center; gap: 14px;
    padding: 18px 32px;
    background: var(--white);
    border-bottom: 1px solid var(--line);
}
.topbar-icon {
    width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0;
    background: var(--green-pale); color: var(--text-accent);
    display: flex; align-items: center; justify-content: center;
}
.topbar-icon svg { width: 20px; height: 20px; }
.pos-topbar h1 {
    font-family: var(--font-display);
    font-size: 20px; font-weight: 700;
    color: var(--text-accent);
    margin: 0;
}
.pos-topbar .topbar-sub { font-size: 12.5px; color: var(--ink-soft); margin-top: 1px; }
.pos-topbar .btn-primary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    min-height: 40px;
    width: auto;
    margin-left: auto !important;
    padding: 10px 18px;
    border: 1px solid var(--green-mid);
    border-radius: 10px;
    background: var(--green-mid);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    line-height: 1.2;
    white-space: nowrap;
    box-shadow: 0 4px 12px rgba(37, 99, 235, .18);
    transition: background .15s ease, border-color .15s ease, transform .15s ease;
}
.pos-topbar .btn-primary:hover,
.pos-topbar .btn-primary:focus-visible {
    background: var(--green-deep);
    border-color: var(--green-deep);
    color: #fff;
    transform: translateY(-1px);
}

.wrap { width: 100%; padding: 30px 32px 64px; }

h1 {
    font-family: var(--font-display); color: var(--text-accent);
    font-size: 24px; margin-bottom: 20px;
}

.card {
    background: var(--white); border: 1px solid var(--line); border-radius: 18px;
    padding: 26px 30px; margin-bottom: 22px;
    box-shadow: 0 1px 3px rgba(18,60,92,.05);
}

.card h2 {
    font-family: var(--font-display); color: var(--text-accent); font-size: 17px; margin-bottom: 14px;
    display: flex; align-items: center; gap: 10px;
}
.section-icon {
    width: 30px; height: 30px; border-radius: 9px; flex-shrink: 0;
    background: var(--green-pale); color: var(--text-accent);
    display: inline-flex; align-items: center; justify-content: center;
}
.section-icon svg { width: 16px; height: 16px; }

.notice {
    background: var(--mustard-pale); color: var(--mustard-text2, #97661f); padding: 12px 14px;
    border-radius: 10px; font-size: 13.5px; margin-bottom: 20px;
}

table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
th, td { text-align: left; padding: 9px 10px; border-bottom: 1px solid var(--line); }
th { color: var(--ink-soft); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
tr:last-child td { border-bottom: none; }

.badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.badge--present { background: var(--green-pale); color: var(--text-accent); }
.badge--absent  { background: var(--red-pale); color: var(--red-alert); }
.badge--leave, .badge--holiday { background: var(--mustard-pale); color: var(--mustard-text2, #97661f); }
.badge--approved { background: var(--green-pale); color: var(--text-accent); }
.badge--pending, .badge--draft { background: var(--mustard-pale); color: var(--mustard-text2, #97661f); }
.badge--rejected { background: var(--red-pale); color: var(--red-alert); }

.empty-row { color: var(--ink-soft); font-style: italic; padding: 12px 0; }

/* ── Attendance calendar (My Attendance — Monthly) ── */
.attendance-layout { display: grid; grid-template-columns: minmax(340px, 460px) minmax(300px, 1fr); gap: 40px; align-items: start; }
.attendance-calendar { min-width: 0; }
.attendance-summary { min-width: 0; background: var(--cream); border: 1px solid var(--line); border-radius: 16px; padding: 22px; align-self: stretch; }
.attendance-summary h3 {
    font-family: var(--font-display); color: var(--text-accent); font-size: 14.5px; margin: 0 0 16px 0;
}
.summary-stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 20px; }
.summary-stat {
    background: var(--white); border: 1px solid var(--line); border-radius: 12px;
    padding: 16px 18px; display: flex; align-items: center; gap: 12px;
}
.summary-stat .stat-icon {
    width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    background: var(--green-pale); color: var(--text-accent);
}
.summary-stat .stat-icon svg { width: 17px; height: 17px; }
.summary-stat .stat-num { font-family: var(--font-display); font-size: 22px; font-weight: 700; color: var(--ink); line-height: 1; }
.summary-stat .stat-label { font-size: 11.5px; color: var(--ink-soft); font-weight: 600; margin-top: 3px; }
.summary-stat.st-present .stat-num { color: var(--text-accent); }
.summary-stat.st-present .stat-icon { background: var(--green-pale); color: var(--text-accent); }
.summary-stat.st-late    .stat-num { color: var(--mustard-text2, #8a5800); }
.summary-stat.st-late    .stat-icon { background: var(--mustard-pale); color: var(--mustard-text2, #8a5800); }
.summary-stat.st-absent  .stat-num { color: var(--red-alert); }
.summary-stat.st-absent  .stat-icon { background: var(--red-pale); color: var(--red-alert); }
.summary-stat.st-dayoff  .stat-num { color: var(--purple-text, #5B4E9C); }
.summary-stat.st-dayoff  .stat-icon { background: var(--purple-bg, #ECE9FA); color: var(--purple-text, #5B4E9C); }
.attendance-summary-tip {
    background: var(--white); border: 1px solid var(--line); color: var(--text-accent); border-radius: 12px;
    padding: 14px 16px; font-size: 13px; line-height: 1.55;
}
@media (max-width: 760px) {
    .attendance-layout { grid-template-columns: 1fr; }
}
.cal-legend { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 14px; }
.legend-item { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--ink-soft); font-weight: 600; }
.legend-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
.dot-present { background: var(--green-mid); }
.dot-late    { background: var(--mustard); }
.dot-absent  { background: var(--red-alert); }
.dot-dayoff  { background: #7A6BC7; }
.dot-other   { background: var(--gray-text, #9AA0AE); }

.cal-month-nav { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 14px; }
.cal-month-nav .cal-month-label { font-family: var(--font-display); font-weight: 700; color: var(--text-accent); font-size: 15px; }
.cal-month-nav a.cal-nav-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; border-radius: 8px; background: var(--cream);
    border: 1px solid var(--line); color: var(--ink); font-weight: 700; font-size: 15px;
}
.cal-month-nav a.cal-nav-btn:hover { background: var(--green-pale); color: var(--text-accent); }
.cal-month-nav a.cal-nav-btn.is-disabled { opacity: .4; pointer-events: none; }

.cal-weekdays {
    display: grid; grid-template-columns: repeat(7, 1fr);
    gap: 6px; margin-bottom: 6px;
}
.cal-weekdays span {
    text-align: center; font-size: 10.5px; font-weight: 700;
    color: var(--ink-soft); text-transform: uppercase; letter-spacing: .04em;
}
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
.cal-day {
    aspect-ratio: 1; width: 100%;
    border-radius: 10px; border: 1.5px solid transparent;
    display: flex; align-items: center; justify-content: center;
    font-family: var(--font-body); font-size: 13px; font-weight: 700;
    cursor: pointer; transition: transform .1s ease, box-shadow .15s ease, border-color .15s ease;
    color: var(--ink);
}
.cal-day.blank { visibility: hidden; cursor: default; }
.cal-day:hover  { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(18,60,92,0.12); }
.cal-day.is-selected { border-color: var(--ink); box-shadow: 0 0 0 2px rgba(18,60,92,.18); }
.cal-day.is-today { border-color: var(--green-mid); }
.cal-present { background: var(--green-pale); color: var(--text-accent); }
.cal-late    { background: var(--mustard-pale); color: var(--mustard-text2, #8a5800); }
.cal-absent  { background: var(--red-pale); color: var(--red-alert); }
.cal-dayoff  { background: var(--purple-bg, #ECE9FA); color: var(--purple-text, #5B4E9C); }
.cal-other   { background: #EDEDF1; color: #5C6270; }
.cal-future  { background: var(--white); color: var(--ink-soft); border: 1.5px dashed var(--line); cursor: default; }
.cal-future:hover { transform: none; box-shadow: none; }
.cal-notyet  { background:var(--gray-bg, #F5F5F3); color:var(--gray-text, #C7CAD1); cursor: default; }
.cal-notyet:hover { transform: none; box-shadow: none; }
.cal-pending { background:var(--blue-bg, #EAF1FB); color:var(--blue-text, #2A5C8A); }

.attendance-detail {
    margin-top: 16px; padding: 14px 16px;
    background: var(--cream); border: 1px solid var(--line); border-radius: 12px;
    min-height: 74px;
}
.attendance-detail .ad-placeholder { color: var(--ink-soft); font-size: 13px; font-style: italic; }
.attendance-detail .ad-date {
    font-family: var(--font-display); font-weight: 700; color: var(--text-accent);
    font-size: 14.5px; margin-bottom: 10px;
}
.attendance-detail .ad-status { color: var(--ink-soft); font-family: var(--font-body); font-weight: 600; font-size: 13px; }
.attendance-detail .ad-rows { display: flex; gap: 28px; flex-wrap: wrap; }
.attendance-detail .ad-label { font-size: 11px; text-transform: uppercase; letter-spacing: .03em; color: var(--ink-soft); }
.attendance-detail .ad-value { font-weight: 700; font-size: 14px; margin-top: 3px; }
.attendance-detail .ad-value--late { color: var(--red-alert); }

.profile-card { padding: 0; overflow: hidden; position: relative; box-shadow: 0 1px 3px rgba(18,60,92,.06); }
.profile-banner {
    height: 128px;
    position: relative;
    background:
        radial-gradient(560px 200px at 88% -30%, rgba(79,195,209,.35), transparent 60%),
        linear-gradient(120deg, var(--green-deep), var(--green-mid) 65%, var(--green-leaf) 130%);
    overflow: hidden;
}
.profile-banner::before {
    content: '';
    position: absolute; inset: 0;
    background-image: radial-gradient(rgba(255,255,255,.14) 1.5px, transparent 1.5px);
    background-size: 16px 16px;
    opacity: .5;
}
.profile-banner::after {
    content: '';
    position: absolute; right: -60px; bottom: -80px;
    width: 220px; height: 220px; border-radius: 50%;
    background: rgba(255,255,255,.07);
}
.profile-edit-btn {
    position: absolute; top: 16px; right: 20px; z-index: 2;
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(255,255,255,.16); color: #FFFFFF;
    border: 1px solid rgba(255,255,255,.35); backdrop-filter: blur(2px);
    padding: 7px 14px; border-radius: 999px; font-size: 12.5px; font-weight: 600;
    transition: background .15s ease;
}
.profile-edit-btn:hover { background: rgba(255,255,255,.28); }
.profile-avatar-wrap {
    position: absolute; top: 78px; left: 32px; z-index: 2;
    width: 100px; height: 100px;
}
.profile-avatar {
    width: 100px; height: 100px; border-radius: 50%;
    background: linear-gradient(150deg, var(--green-mid), var(--green-deep));
    border: 5px solid var(--white);
    box-shadow: 0 6px 18px rgba(18,60,92,.22);
    display: flex; align-items: center; justify-content: center;
    font-family: var(--font-display); font-weight: 700; font-size: 32px; color: #FFFFFF;
    letter-spacing: .02em;
    overflow: hidden;
}
.profile-avatar img {
    width: 100%; height: 100%; object-fit: cover; border-radius: 50%;
}
.avatar-edit-btn {
    position: absolute; right: -3px; bottom: -3px; z-index: 3;
    width: 32px; height: 32px; border-radius: 50%;
    background: var(--green-mid); border: 3px solid var(--white);
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; cursor: pointer; box-shadow: 0 2px 8px rgba(18,60,92,.3);
    transition: background .15s ease; padding: 0;
}
.avatar-edit-btn:hover { background: var(--green-deep); }
.avatar-edit-btn svg { width: 15px; height: 15px; }
.avatar-upload-form input[type="file"] { display: none; }
.avatar-edit-menu {
    display: none; position: absolute; left: 0; top: 108px; z-index: 4;
    background: var(--white); border: 1px solid var(--line); border-radius: 10px;
    box-shadow: 0 8px 22px rgba(18,60,92,.18); overflow: hidden; min-width: 150px;
}
.avatar-edit-menu.open { display: block; }
.avatar-edit-menu-item {
    display: block; width: 100%; text-align: left; padding: 10px 14px;
    background: none; border: none; font-size: 13.5px; font-weight: 600;
    color: var(--ink); cursor: pointer; font-family: inherit;
}
.avatar-edit-menu-item:hover { background: var(--cream); }
.avatar-edit-menu-item-danger { color: var(--red-alert, #C8472D); }
.profile-head {
    padding: 16px 28px 22px 152px; /* left padding clears the overlapping avatar */
    min-height: 46px;
    display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px;
}
.profile-head h2 { margin: 0 0 4px 0; color: var(--ink); font-size: 22px; }
.profile-sub { color: var(--ink-soft); font-size: 13.5px; display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
.profile-sub .dot-sep { width: 3px; height: 3px; border-radius: 50%; background: var(--ink-soft); opacity: .6; display: inline-block; }
.profile-status-pill {
    display: inline-flex; align-items: center; gap: 6px; margin-top: 9px;
    background: var(--green-pale); color: var(--text-accent); font-weight: 700; font-size: 11.5px;
    padding: 4px 12px; border-radius: 999px; text-transform: uppercase; letter-spacing: .03em;
}
.profile-status-pill::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: var(--green-mid); box-shadow: 0 0 0 3px rgba(79,195,209,.28); }
.profile-status-pill.is-inactive { background: var(--red-pale); color: var(--red-alert); }
.profile-status-pill.is-inactive::before { background: var(--red-alert); box-shadow: 0 0 0 3px rgba(200,71,45,.18); }
.profile-meta {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 2px; padding: 6px 22px 14px; border-top: 1px solid var(--line);
    background: linear-gradient(180deg, var(--cream), var(--white) 40%);
}
.meta-item {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 14px 6px; border-radius: 12px;
    transition: background .15s ease;
}
.meta-item:hover { background: rgba(25,120,168,.06); }
.meta-icon {
    flex-shrink: 0; width: 34px; height: 34px; border-radius: 10px;
    background: var(--green-pale); color: var(--text-accent);
    display: flex; align-items: center; justify-content: center;
}
.meta-icon svg { width: 17px; height: 17px; }
.meta-text { min-width: 0; }
@media (max-width: 560px) {
    .profile-avatar-wrap { position: static; margin: -46px 0 12px 24px; }
    .profile-head { padding: 0 24px 20px 24px; }
    .profile-edit-btn { top: 12px; right: 14px; }
}
.meta-label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-soft); font-weight: 700; }
.meta-value { font-weight: 600; font-size: 14px; margin-top: 3px; color: var(--ink); word-break: break-word; }

.view-link { color: var(--green-mid); font-weight: 600; font-size: 13px; }

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
.view-link:hover { text-decoration: underline; }

/* "Current Payslip" — the one still-upcoming pay period (payout date
   hasn't arrived yet), shown as its own highlight card above the
   Salary History table instead of buried as just another row in it.
   Mirrors HRMS/my_profile.php. */
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

.notice--success { background: var(--green-succ-bg, #E6F4EA); color: var(--green-succ-text, #1A7A3B); }
.notice--error   { background: var(--red-pale); color: var(--red-alert); }

.attendance-today {
    display: grid;
    grid-template-columns: 1fr minmax(260px, 320px);
    align-items: start;
    gap: 24px;
}
@media (max-width: 700px) {
    .attendance-today { grid-template-columns: 1fr; }
}
.attendance-today .shift-line { color: var(--ink-soft); font-size: 13.5px; margin-top: 4px; }
.attendance-today .status-line { font-size: 14.5px; margin-top: 8px; font-weight: 600; }
.dayoff-badge {
    display: inline-block; background: var(--purple-bg, #ECE9FA); color: var(--purple-text, #5B4E9C);
    font-weight: 700; font-size: 11.5px; padding: 2px 9px; border-radius: 999px;
    text-transform: uppercase; letter-spacing: .03em; margin-right: 4px;
}
.location-line {
    display: flex; align-items: flex-start; gap: 6px;
    font-size: 13px; color: var(--ink-soft); font-weight: 500;
    margin-top: 8px; padding: 8px 12px;
    background: var(--cream); border: 1px solid var(--line); border-radius: 10px;
    max-width: 460px;
}
.location-line svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; color: var(--green-mid); }
.location-line .loc-text { min-width: 0; word-break: break-word; }
.location-line .loc-map-link { color: var(--green-mid); font-weight: 700; white-space: nowrap; margin-left: 4px; }
.location-line .loc-map-link:hover { text-decoration: underline; }
.location-line.no-location { color: var(--ink-soft); font-style: italic; }
.attendance-action-panel {
    background: var(--cream); border: 1px solid var(--line); border-radius: 14px;
    padding: 18px 24px; width: 100%; box-sizing: border-box; text-align: center;
}
.attendance-action-panel form { width: 100%; max-width: none; }

.btn {
    border: none; padding: 12px 22px; border-radius: 10px; font-weight: 600;
    cursor: pointer; font-size: 14px; font-family: var(--font-body);
}
.btn--time-in  { background: var(--green-mid); color: #FFFFFF; }
.btn--time-out { background: var(--mustard); color: #FFFFFF; }
.btn:disabled  { opacity: .6; cursor: not-allowed; }

.geo-status { font-size: 12.5px; margin-top: 8px; color: var(--ink-soft); }

.complete-badge {
    background: var(--green-pale); color: var(--text-accent); padding: 6px 12px;
    border-radius: 999px; font-size: 12.5px; font-weight: 600;
}

.balance-row { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
.balance-card {
    flex: 1; min-width: 150px; background: var(--green-pale); border-radius: 12px; padding: 12px 16px;
}
.balance-label { font-size: 12.5px; color: var(--ink-soft); font-weight: 600; text-transform: uppercase; letter-spacing: .02em; }
.balance-value { font-family: var(--font-display); font-size: 20px; color: var(--text-accent); margin-top: 4px; }
.balance-value span { font-size: 12.5px; color: var(--ink-soft); font-family: var(--font-body); font-weight: 500; }

.leave-form {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 18px;
}
.leave-form .field-group.full { grid-column: 1 / -1; }
.leave-form label {
    display: block; font-size: 12.5px; font-weight: 600; color: var(--ink-soft);
    margin-bottom: 6px; text-transform: uppercase; letter-spacing: .02em;
}
.leave-form input, .leave-form select, .leave-form textarea {
    width: 100%; padding: 9px 11px; border: 1px solid var(--line); border-radius: 8px;
    font-family: var(--font-body); font-size: 13.5px; color: var(--ink); background: var(--white);
}
.leave-form textarea { min-height: 60px; resize: vertical; }
.leave-form .btn { grid-column: 1 / -1; justify-self: start; background: var(--green-mid); color: #FFFFFF; }

.bank-line { font-size: 13px; color: var(--ink-soft); margin-bottom: 14px; }
.payslip-period { font-weight: 600; }
.payslip-details {
    display: flex; flex-wrap: wrap; gap: 4px 14px; margin-top: 4px;
    font-size: 12px; color: var(--ink-soft);
}

@media (max-width: 640px) {
    .leave-form { grid-template-columns: 1fr; }
}
@media (max-width: 900px) {
    .pos-topbar { padding: 16px 20px; }
}
@media (max-width: 560px) {
    .pos-topbar { flex-wrap: wrap; }
    .pos-topbar .btn-primary { width: 100%; margin-left: 0 !important; }
}
</style>
</head>
<body>

<div class="app-shell">

    <?php
        // BUGFIX: was including HRMS-only 'includes/supervisor_sidebar.php'
        // / 'includes/hr_sidebar.php' — those don't exist under the POS
        // root includes/ folder (only includes/sidebar.php does), so the
        // include silently failed and the whole sidebar disappeared.
        // includes/sidebar.php already branches internally by
        // $_SESSION['role'] (cashier/finance/inventory_staff/admin/etc.)
        // and expects $active, not $activeNav.
        $active = 'my_profile';
        include 'includes/sidebar.php';
    ?>

    <div class="main-area">
        <div class="pos-topbar">
            <div class="topbar-icon"><svg viewBox="0 0 20 20" fill="none"><circle cx="10" cy="7" r="3.2" stroke="currentColor" stroke-width="1.6"/><path d="M3.5 17c1-3.5 4-5.2 6.5-5.2s5.5 1.7 6.5 5.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></div>
            <div>
                <h1>My Profile</h1>
                <div class="topbar-sub">Your info, attendance, leave and payslips in one place</div>
            </div>
            <a href="change_password.php" class="btn btn-primary" style="margin-left:auto">Change Password</a>
        </div>

        <div class="wrap">

    <?php if ($hrms_unavailable): ?>
        <div class="notice">The HRMS database can't be reached right now, so profile/attendance/payslip info isn't available. Please try again later, or let the Admin know.</div>
    <?php elseif (!$__myEmployee): ?>
        <div class="notice">No HRMS employee record is linked to this account. Please contact the Admin/HR to have your account linked.</div>
        <div class="card profile-card profile-card--standalone">
            <div class="profile-banner"></div>
            <div class="profile-avatar-wrap">
                <div class="profile-avatar">
                    <?php if (!empty($__myAvatarFile)): ?>
                        <img src="uploads/avatars/<?= clean($__myAvatarFile) ?>" alt="Profile picture">
                    <?php else: ?>
                        <?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? 'User'), 0, 1))) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="profile-card-body">
                <h2><?= clean($_SESSION['full_name'] ?? 'User') ?></h2>
                <p><?= clean(ucfirst((string) ($_SESSION['role'] ?? 'user'))) ?></p>
                <p class="field-hint">Your profile photo is saved to your POS account. Link an HRMS employee record to enable attendance, leave, and payslip details.</p>
            </div>
        </div>
    <?php else: ?>

        <?php $__statusLower = strtolower($__myEmployee['status'] ?: 'active'); ?>
        <div class="card profile-card">
            <div class="profile-banner"></div>
            <div class="profile-avatar-wrap">
                <div class="profile-avatar">
                    <?php if (!empty($__myAvatarFile)): ?>
                        <img src="uploads/avatars/<?= clean($__myAvatarFile) ?>" alt="Profile picture">
                    <?php else: ?>
                        <?= clean(mb_strtoupper(mb_substr($__myEmployee['first_name'], 0, 1) . mb_substr($__myEmployee['last_name'], 0, 1))) ?>
                    <?php endif; ?>
                </div>
                <button type="button" class="avatar-edit-btn" id="avatarEditBtn" title="Edit profile picture">
                    <svg viewBox="0 0 20 20" fill="none"><path d="M13.8 3.7l2.5 2.5-8.4 8.4-3.1.6.6-3.1 8.4-8.4z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" stroke-linecap="round"/><path d="M12 5.5l2.5 2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                </button>
                <div class="avatar-edit-menu" id="avatarEditMenu">
                    <button type="button" class="avatar-edit-menu-item" id="avatarChangeBtn">Change Photo</button>
                    <?php if (!empty($__myAvatarFile)): ?>
                    <button type="button" class="avatar-edit-menu-item avatar-edit-menu-item-danger" id="avatarRemoveBtn">Remove Photo</button>
                    <?php endif; ?>
                </div>
                <form method="POST" enctype="multipart/form-data" id="avatarUploadForm" class="avatar-upload-form">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="update_profile_picture">
                    <input type="file" id="avatarFileInput" name="profile_picture" accept=".jpg,.jpeg,.png,.webp" onchange="document.getElementById('avatarUploadForm').submit()">
                </form>
                <?php if (!empty($__myAvatarFile)): ?>
                <form method="POST" id="avatarRemoveForm">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="remove_profile_picture">
                </form>
                <?php endif; ?>
            </div>
            <script>
            (function () {
                var editBtn    = document.getElementById('avatarEditBtn');
                var menu       = document.getElementById('avatarEditMenu');
                var changeBtn  = document.getElementById('avatarChangeBtn');
                var removeBtn  = document.getElementById('avatarRemoveBtn');
                var fileInput  = document.getElementById('avatarFileInput');
                var removeForm = document.getElementById('avatarRemoveForm');
                if (!editBtn || !menu) return;

                editBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    menu.classList.toggle('open');
                });
                document.addEventListener('click', function (e) {
                    if (menu.classList.contains('open') && !menu.contains(e.target) && e.target !== editBtn) {
                        menu.classList.remove('open');
                    }
                });
                if (changeBtn) {
                    changeBtn.addEventListener('click', function () {
                        menu.classList.remove('open');
                        fileInput.click();
                    });
                }
                if (removeBtn && removeForm) {
                    removeBtn.addEventListener('click', function () {
                        menu.classList.remove('open');
                        Swal.fire({
                            title: 'Remove profile picture?',
                            text: 'Your current profile picture will be removed.',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonText: 'Yes, remove',
                            cancelButtonText: 'Cancel',
                            confirmButtonColor: '#C8472D'
                        }).then(function (result) {
                            if (result.isConfirmed) removeForm.submit();
                        });
                    });
                }
            })();
            </script>
            <div class="profile-head">
                <div>
                    <h2><?= clean($__myEmployee['first_name'] . ' ' . $__myEmployee['last_name']) ?></h2>
                    <div class="profile-sub">
                        <span><?= clean($__myEmployee['position'] ?: '—') ?></span>
                        <span class="dot-sep"></span>
                        <span><?= clean($__myEmployee['employee_code']) ?></span>
                    </div>
                    <div class="profile-status-pill<?= $__statusLower === 'active' ? '' : ' is-inactive' ?>"><?= clean(ucfirst($__myEmployee['status'] ?: 'Active')) ?></div>
                </div>
            </div>
            <div class="profile-meta">
                <div class="meta-item">
                    <div class="meta-icon"><svg viewBox="0 0 20 20" fill="none"><path d="M4 17V5.5A1.5 1.5 0 015.5 4h5A1.5 1.5 0 0112 5.5V17M4 17h12M4 17H2.5M16 17H12M12 17v-4.5A1.5 1.5 0 0113.5 11h1a1.5 1.5 0 011.5 1.5V17m0 0h1.5M6.5 7h1M6.5 10h1M9.5 7h1M9.5 10h1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                    <div class="meta-text">
                        <div class="meta-label">Department</div>
                        <div class="meta-value"><?= clean($__myEmployee['department'] ?: '—') ?></div>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-icon"><svg viewBox="0 0 20 20" fill="none"><rect x="3" y="6.5" width="14" height="9.5" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M7 6.5V5a1.5 1.5 0 011.5-1.5h3A1.5 1.5 0 0113 5v1.5M3 10.5h14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></div>
                    <div class="meta-text">
                        <div class="meta-label">Employment Type</div>
                        <div class="meta-value"><?= clean(ucfirst($__myEmployee['employment_type'] ?? '') ?: '—') ?></div>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-icon"><svg viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="13" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M3 8h14M6.5 2.5v3M13.5 2.5v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></div>
                    <div class="meta-text">
                        <div class="meta-label">Date Hired</div>
                        <div class="meta-value"><?= !empty($__myEmployee['date_hired']) ? clean(date('M d, Y', strtotime($__myEmployee['date_hired']))) : '—' ?></div>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-icon"><svg viewBox="0 0 20 20" fill="none"><rect x="2.5" y="4.5" width="15" height="11" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M3 5.5l7 5.5 7-5.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                    <div class="meta-text">
                        <div class="meta-label">Email</div>
                        <div class="meta-value"><?= clean($__myEmployee['email'] ?: '—') ?></div>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-icon"><svg viewBox="0 0 20 20" fill="none"><path d="M4.5 3.5h2.4l1.1 3.4-1.7 1.2a9 9 0 004.6 4.6l1.2-1.7 3.4 1.1v2.4c0 .8-.7 1.5-1.6 1.4-6-.6-9.9-4.5-10.5-10.5-.1-.9.6-1.6 1.4-1.6z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg></div>
                    <div class="meta-text">
                        <div class="meta-label">Contact Number</div>
                        <div class="meta-value"><?= clean($__myEmployee['contact_number'] ?: '—') ?></div>
                    </div>
                </div>
                <?php if (!empty($__myEmployee['address'])): ?>
                <div class="meta-item">
                    <div class="meta-icon"><svg viewBox="0 0 20 20" fill="none"><path d="M10 17s6-4.98 6-9.5A6 6 0 004 7.5C4 12.02 10 17 10 17z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="10" cy="7.5" r="2" stroke="currentColor" stroke-width="1.5"/></svg></div>
                    <div class="meta-text">
                        <div class="meta-label">Address</div>
                        <div class="meta-value"><?= clean($__myEmployee['address']) ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php require 'includes/personal_info_card.php'; ?>
        <?php if (!empty($avatar_message)): ?>
            <script>
            Swal.fire({ icon: 'success', title: <?= json_encode(clean($avatar_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 2200, timerProgressBar: true });
            </script>
        <?php endif; ?>
        <?php if (!empty($avatar_error)): ?>
            <script>
            Swal.fire({ icon: 'error', title: <?= json_encode(clean($avatar_error)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 3200, timerProgressBar: true });
            </script>
        <?php endif; ?>

        <div class="card">
            <?php if (!empty($timein_message)): ?>
                <script>
                Swal.fire({ icon: 'success', title: <?= json_encode(clean($timein_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 2200, timerProgressBar: true });
                </script>
            <?php endif; ?>
            <?php if (!empty($timein_error)): ?>
                <script>
                Swal.fire({ icon: 'error', title: <?= json_encode(clean($timein_error)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 3200, timerProgressBar: true });
                </script>
            <?php endif; ?>

            <div class="attendance-today">
                <div>
                    <h2><span class="section-icon"><svg viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="13" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M3 8h14M6.5 2.5v3M13.5 2.5v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span> Today's Attendance — <?= clean(date('D, M d, Y')) ?></h2>
                    <div class="shift-line">
                        <?php if ($todayShift): ?>
                            Current shift: <strong><?= clean($todayShift['shift_name']) ?></strong>
                            (<?= clean(date('h:i A', strtotime($todayShift['start_time']))) ?>–<?= clean(date('h:i A', strtotime($todayShift['end_time']))) ?>)
                        <?php elseif ($approvedSwap): ?>
                            <span class="dayoff-badge">Day Off</span> — but HR approved you to cover
                            <?php if (!empty($approvedSwap['shift_name'])): ?> the <strong><?= clean($approvedSwap['shift_name']) ?></strong> shift<?php endif; ?> today (Shift Swap).
                        <?php else: ?>
                            <span class="dayoff-badge">Day Off</span> — no shift assigned today. Time In is disabled unless HR processes a Shift Swap for you.
                        <?php endif; ?>
                    </div>
                    <div class="status-line">
                        <?php if ($todayRow && !empty($todayRow['time_in'])): ?>
                            Time In: <strong><?= clean(date('h:i A', strtotime($todayRow['time_in']))) ?></strong>
                            <?php if (!empty($todayRow['time_out'])): ?> &middot; Time Out: <strong><?= clean(date('h:i A', strtotime($todayRow['time_out']))) ?></strong><?php endif; ?>
                        <?php elseif ($__isSuspended): ?>
                            You are currently suspended (<?= $__suspension['days_remaining'] ?> day<?= $__suspension['days_remaining'] == 1 ? '' : 's' ?> remaining, until <strong><?= clean(date('M d, Y', strtotime($__suspension['end_date']))) ?></strong>). Time In is closed for the duration.
                        <?php elseif ($unfinishedRecord): ?>
                            You have an unfinished attendance record from <strong><?= clean(date('M d, Y', strtotime($unfinishedRecord['date']))) ?></strong> (Timed In but never Timed Out). Time In is closed until it's corrected.
                        <?php elseif ($__needsLateRequest): ?>
                            Your shift has already ended — Time In is closed for today.
                        <?php elseif ($__timeInTooEarly): ?>
                            It's too early to Time In — it opens at <strong><?= clean(date('h:i A', strtotime($timeInOpensAt))) ?></strong>.
                        <?php elseif ($canTimeInToday): ?>
                            You haven't timed in yet today.
                        <?php else: ?>
                            You're not scheduled to work today.
                        <?php endif; ?>
                    </div>
                    <?php if ($todayRow && !empty($todayRow['time_in'])): ?>
                        <?php $__lat = $todayRow['time_in_latitude'] ?? null; $__lng = $todayRow['time_in_longitude'] ?? null; $__addr = $todayRow['time_in_address'] ?? ''; ?>
                        <?php if (!empty($__addr) || ($__lat !== null && $__lng !== null)): ?>
                        <div class="location-line">
                            <svg viewBox="0 0 20 20" fill="none"><path d="M10 18s6-5.6 6-10.2A6 6 0 004 7.8C4 12.4 10 18 10 18z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="10" cy="7.8" r="2.1" stroke="currentColor" stroke-width="1.5"/></svg>
                            <span class="loc-text"><?= !empty($__addr) ? clean($__addr) : 'Location captured (' . number_format((float)$__lat, 5) . ', ' . number_format((float)$__lng, 5) . ')' ?></span>
                            <?php if ($__lat !== null && $__lng !== null): ?>
                                <a class="loc-map-link" href="https://www.google.com/maps?q=<?= urlencode($__lat . ',' . $__lng) ?>" target="_blank" rel="noopener">View map</a>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="location-line no-location">
                            <svg viewBox="0 0 20 20" fill="none"><path d="M10 18s6-5.6 6-10.2A6 6 0 004 7.8C4 12.4 10 18 10 18z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="10" cy="7.8" r="2.1" stroke="currentColor" stroke-width="1.5"/></svg>
                            <span class="loc-text">No location was captured for this Time In.</span>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="attendance-action-panel">
                    <?php if ((!$todayRow || empty($todayRow['time_in'])) && $__isSuspended): ?>
                        <button type="button" class="btn btn--time-in" disabled title="Time In is disabled while suspended">Time In Disabled (Suspended)</button>
                        <div class="geo-status">
                            Suspended until <strong><?= clean(date('M d, Y', strtotime($__suspension['end_date']))) ?></strong>
                            (<?= $__suspension['days_remaining'] ?> day<?= $__suspension['days_remaining'] == 1 ? '' : 's' ?> remaining)<?php if (!empty($__suspension['reason'])): ?> — Reason: <?= clean($__suspension['reason']) ?><?php endif; ?>.
                            Please contact your Admin or HR if you believe this is a mistake.
                        </div>
                    <?php elseif ((!$todayRow || empty($todayRow['time_in'])) && $unfinishedRecord): ?>
                        <button type="button" class="btn btn--time-in" disabled title="Unfinished attendance record must be corrected first">Time In Disabled</button>
                        <div class="geo-status">You have an unfinished attendance record. Please contact your supervisor or HR to correct your previous Time Out.</div>
                    <?php elseif ((!$todayRow || empty($todayRow['time_in'])) && $__needsLateRequest && $lateRequestToday && $lateRequestToday['status'] === 'pending'): ?>
                        <span class="badge badge--pending">Time In request pending Supervisor approval</span>
                        <div class="geo-status">Requested shift: <strong><?= clean($lateRequestToday['requested_shift_name'] ?? date('h:i A', strtotime($lateRequestToday['requested_time']))) ?></strong> (<?= clean(date('h:i A', strtotime($lateRequestToday['requested_time']))) ?>)</div>
                    <?php elseif ((!$todayRow || empty($todayRow['time_in'])) && $__needsLateRequest): ?>
                        <form method="POST">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="request_late_timein">
                            <?php if ($lateRequestToday && $lateRequestToday['status'] === 'rejected'): ?>
                                <div class="geo-status" style="color:var(--red-alert);">Your last request was rejected. You may submit a new one.</div>
                            <?php endif; ?>
                            <label style="display:block; font-size:12.5px; color:var(--ink-soft); margin-bottom:4px;">Which shift would you like to Time In for</label>
                            <select name="requested_shift_id" required style="width:100%; padding:8px 10px; border:1px solid var(--line, var(--line)); border-radius:8px; margin-bottom:8px; font-size:13.5px;">
                                <option value="">Select a shift…</option>
                                <?php foreach ($activeShifts as $__as): ?>
                                    <option value="<?= (int) $__as['shift_id'] ?>"><?= clean($__as['shift_name']) ?> (<?= clean(date('h:i A', strtotime($__as['start_time']))) ?>–<?= clean(date('h:i A', strtotime($__as['end_time']))) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <label style="display:block; font-size:12.5px; color:var(--ink-soft); margin-bottom:4px;">Reason</label>
                            <input type="text" name="late_reason" required minlength="3" placeholder="e.g. Traffic, personal emergency" style="width:100%; padding:8px 10px; border:1px solid var(--line, var(--line)); border-radius:8px; margin-bottom:10px; font-size:13.5px;">
                            <button type="submit" class="btn btn--time-out" style="width:100%;">Request Time In</button>
                            <div class="geo-status">This will be sent to your Supervisor for approval.</div>
                        </form>
                    <?php elseif ((!$todayRow || empty($todayRow['time_in'])) && $__timeInTooEarly): ?>
                        <button type="button" class="btn btn--time-in" disabled title="Time In opens at <?= clean(date('h:i A', strtotime($timeInOpensAt))) ?>">Time In Closed</button>
                        <div class="geo-status">Opens at <strong><?= clean(date('h:i A', strtotime($timeInOpensAt))) ?></strong> — come back then.</div>
                    <?php elseif ((!$todayRow || empty($todayRow['time_in'])) && $canTimeInToday): ?>
                        <form method="POST" id="selfTimeInForm">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="self_time_in">
                            <input type="hidden" name="latitude" id="tiLatitude" value="">
                            <input type="hidden" name="longitude" id="tiLongitude" value="">
                            <input type="hidden" name="location_address" id="tiAddress" value="">
                            <button type="submit" id="timeInBtn" class="btn btn--time-in">Time In Now</button>
                            <div id="geoStatus" class="geo-status">📍 Your GPS location will be captured automatically when you Time In. Please <strong>Allow</strong> location access in the browser.</div>
                        </form>
                    <?php elseif (!$todayRow || empty($todayRow['time_in'])): ?>
                        <button type="button" class="btn btn--time-in" disabled title="Time In is disabled on your Day Off">Time In Disabled (Day Off)</button>
                        <div class="geo-status">Ask HR to process a Shift Swap if you're covering for someone today.</div>
                    <?php elseif (empty($todayRow['time_out']) && $shiftNotStartedYet): ?>
                        <button type="button" class="btn btn--time-out" disabled title="Time Out opens once your shift starts">Time Out Closed</button>
                        <div class="geo-status">You timed in early — Time Out opens once your shift starts<?php if ($todayShift): ?> at <strong><?= clean(date('h:i A', strtotime($todayShift['start_time']))) ?></strong><?php endif; ?>.</div>
                    <?php elseif (empty($todayRow['time_out'])): ?>
                        <form method="POST">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="self_time_out">
                            <button type="submit" class="btn btn--time-out">Time Out Now</button>
                        </form>
                    <?php else: ?>
                        <span class="complete-badge">✓ Attendance complete for today</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <script>
        (function () {
            var form = document.getElementById('selfTimeInForm');
            if (!form) return;
            var btn      = document.getElementById('timeInBtn');
            var statusEl = document.getElementById('geoStatus');

            form.addEventListener('submit', function (e) {
                if (form.dataset.ready === '1') return;
                e.preventDefault();

                if (!navigator.geolocation) {
                    statusEl.style.color = 'var(--red-alert)';
                    statusEl.textContent = 'Location is not supported in this browser, so Time In cannot proceed.';
                    return;
                }

                btn.disabled = true;
                statusEl.style.color = 'var(--ink-soft)';
                statusEl.textContent = 'Getting your location… please allow location access.';

                navigator.geolocation.getCurrentPosition(
                    function (pos) {
                        var lat = pos.coords.latitude, lng = pos.coords.longitude;
                        document.getElementById('tiLatitude').value  = lat;
                        document.getElementById('tiLongitude').value = lng;

                        function finishSubmit() {
                            form.dataset.ready = '1';
                            statusEl.style.color = 'var(--ink-soft)';
                            statusEl.textContent = 'Location captured. Timing in…';
                            form.submit();
                        }

                        var url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' +
                                  encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng);
                        fetch(url, { headers: { 'Accept': 'application/json' } })
                            .then(function (r) { return r.json(); })
                            .then(function (d) { if (d && d.display_name) document.getElementById('tiAddress').value = d.display_name; })
                            .catch(function () { /* offline / failed — just use coords */ })
                            .then(finishSubmit, finishSubmit);
                    },
                    function (err) {
                        btn.disabled = false;
                        statusEl.style.color = 'var(--red-alert)';
                        statusEl.textContent = (err && err.code === err.PERMISSION_DENIED)
                            ? 'Location (GPS) access is required before Time In. Please allow location in the browser and try again.'
                            : 'Could not get location. Please make sure GPS/location is turned ON, then try again.';
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                );
            });
        })();
        </script>

        <div class="card" id="attendance-calendar">
            <h2><span class="section-icon"><svg viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="13" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M3 8h14M6.5 2.5v3M13.5 2.5v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span> My Attendance</h2>
            <div class="attendance-layout">
                <div class="attendance-calendar">
                    <div class="cal-month-nav">
                        <a class="cal-nav-btn" href="?cal_month=<?= clean($cal_prev_month) ?>#attendance-calendar">&#8249;</a>
                        <span class="cal-month-label"><?= clean(date('F Y', strtotime($cal_month_start))) ?></span>
                        <a class="cal-nav-btn <?= $cal_is_current ? 'is-disabled' : '' ?>" href="?cal_month=<?= clean($cal_next_month) ?>#attendance-calendar">&#8250;</a>
                    </div>

                    <div class="cal-legend">
                        <span class="legend-item"><i class="legend-dot dot-present"></i>Present</span>
                        <span class="legend-item"><i class="legend-dot dot-late"></i>Late</span>
                        <span class="legend-item"><i class="legend-dot dot-absent"></i>Absent</span>
                        <span class="legend-item"><i class="legend-dot dot-dayoff"></i>Day Off (Rest Day)</span>
                        <span class="legend-item"><i class="legend-dot dot-other"></i>Leave / Holiday</span>
                    </div>

                    <div class="cal-weekdays">
                        <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                    </div>

                    <div class="cal-grid">
                        <?php foreach ($calendar_cells as $cell): ?>
                            <?php if ($cell === null): ?>
                                <div class="cal-day blank"></div>
                            <?php else:
                                $dateObj  = new DateTime($cell['date']);
                                $cellCls  = attendanceCellClass($cell);
                                $isFuture = in_array($cell['status'], ['future', 'not_hired'], true);
                                $isToday  = ($cell['date'] === date('Y-m-d'));
                                $timeIn   = !empty($cell['time_in'])  ? date('h:i A', strtotime($cell['time_in']))  : '';
                                $timeOut  = !empty($cell['time_out']) ? date('h:i A', strtotime($cell['time_out'])) : '';
                            ?>
                                <button type="button" class="cal-day <?= $cellCls ?> <?= $isToday ? 'is-today' : '' ?>"
                                        <?= $isFuture ? 'disabled tabindex="-1"' : '' ?>
                                        data-date="<?= clean($dateObj->format('M d, Y (D)')) ?>"
                                        data-status="<?= clean(attendanceCellLabel($cell)) ?>"
                                        data-timein="<?= clean($timeIn) ?>"
                                        data-timeout="<?= clean($timeOut) ?>"
                                        data-late="<?= (int) ($cell['late_minutes'] ?? 0) ?>">
                                    <?= (int) $dateObj->format('j') ?>
                                </button>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="attendance-detail" id="attDetail">
                        <div class="ad-placeholder">Tap a day above to see the time in / time out for that day.</div>
                    </div>
                </div>

                <div class="attendance-summary">
                    <h3><?= clean(date('F Y', strtotime($cal_month_start))) ?> Summary</h3>
                    <div class="summary-stat-grid">
                        <div class="summary-stat st-present">
                            <div class="stat-icon"><svg viewBox="0 0 20 20" fill="none"><path d="M4 10.5l3.5 3.5L16 5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                            <div>
                                <div class="stat-num"><?= (int) $cal_month_totals['present'] ?></div>
                                <div class="stat-label">Present</div>
                            </div>
                        </div>
                        <div class="summary-stat st-late">
                            <div class="stat-icon"><svg viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.6"/><path d="M10 6v4l3 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                            <div>
                                <div class="stat-num"><?= (int) $cal_month_totals['late'] ?></div>
                                <div class="stat-label">Late</div>
                            </div>
                        </div>
                        <div class="summary-stat st-absent">
                            <div class="stat-icon"><svg viewBox="0 0 20 20" fill="none"><path d="M6 6l8 8M14 6l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></div>
                            <div>
                                <div class="stat-num"><?= (int) $cal_month_totals['absent'] ?></div>
                                <div class="stat-label">Absent</div>
                            </div>
                        </div>
                        <div class="summary-stat st-dayoff">
                            <div class="stat-icon"><svg viewBox="0 0 20 20" fill="none"><path d="M4 8.5l6-5 6 5V16a1 1 0 01-1 1h-3v-5H8v5H5a1 1 0 01-1-1V8.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg></div>
                            <div>
                                <div class="stat-num"><?= (int) $cal_month_totals['day_off'] ?></div>
                                <div class="stat-label">Day Off (Rest Day)</div>
                            </div>
                        </div>
                    </div>
                    <div class="attendance-summary-tip">
                        💡 Rest Days are never counted as Absent and are never deducted from your pay. Tap any day on the calendar to see its Time In / Time Out details.
                    </div>
                </div>
            </div>
                <script>
                (function () {
                    var days   = document.querySelectorAll('.cal-day:not(.blank):not([disabled])');
                    var detail = document.getElementById('attDetail');
                    days.forEach(function (el) {
                        el.addEventListener('click', function () {
                            days.forEach(function (d) { d.classList.remove('is-selected'); });
                            this.classList.add('is-selected');

                            var status  = this.dataset.status;
                            var timeIn  = this.dataset.timein  || 'No record';
                            var timeOut = this.dataset.timeout || 'No record';
                            var late    = parseInt(this.dataset.late, 10) || 0;

                            var lateBlock = '';
                            if (late > 0) {
                                lateBlock = '<div class="ad-item"><div class="ad-label">Late</div>' +
                                            '<div class="ad-value ad-value--late">' + late + ' min</div></div>';
                            }

                            detail.innerHTML =
                                '<div class="ad-date">' + this.dataset.date + ' &middot; <span class="ad-status">' + status + '</span></div>' +
                                '<div class="ad-rows">' +
                                    '<div class="ad-item"><div class="ad-label">Time In</div><div class="ad-value">' + timeIn + '</div></div>' +
                                    '<div class="ad-item"><div class="ad-label">Time Out</div><div class="ad-value">' + timeOut + '</div></div>' +
                                    lateBlock +
                                '</div>';
                        });
                    });
                })();
                </script>
        </div>

        <div class="card">
            <h2><span class="section-icon"><svg viewBox="0 0 20 20" fill="none"><path d="M4.5 3.5h11a1 1 0 011 1V15a1 1 0 01-1 1h-11a1 1 0 01-1-1V4.5a1 1 0 011-1z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M4 7.5h12M8 3.5v-1M12 3.5v-1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span> Request Leave</h2>

            <?php if (!empty($leave_message)): ?>
                <script>
                Swal.fire({ icon: 'success', title: <?= json_encode(clean($leave_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 2200, timerProgressBar: true });
                </script>
            <?php endif; ?>
            <?php if (!empty($leave_error)): ?>
                <script>
                Swal.fire({ icon: 'error', title: <?= json_encode(clean($leave_error)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 3200, timerProgressBar: true });
                </script>
            <?php endif; ?>

            <div class="balance-row">
                <?php foreach ($leave_balances as $type => $bal): ?>
                <div class="balance-card">
                    <div class="balance-label"><?= clean(getLeaveTypeLabel($type)) ?></div>
                    <?php if (in_array($type, CASHIER_LEAVE_TYPES_WITH_LIMIT, true)): ?>
                    <div class="balance-value"><?= clean(number_format($bal['remaining'], 1)) ?> <span>/ <?= clean(number_format($bal['allocated'], 1)) ?> days</span></div>
                    <?php else: ?>
                    <div class="balance-value"><?= clean(number_format($bal['used'], 1)) ?> <span>day(s) used</span></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

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
                <button type="submit" class="btn">Submit Leave Request</button>
            </form>

            <?php if (empty($my_leave_requests)): ?>
                <div class="empty-row">You haven't submitted any leave requests yet.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Type</th><th>Dates</th><th>Reason</th><th>Requested</th><th>Status</th><th>Decided On</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_leave_requests as $lv): ?>
                            <tr>
                                <td><?= clean(getLeaveTypeLabel($lv['leave_type'])) ?></td>
                                <td><?= clean(date('M d', strtotime($lv['date_from']))) ?>–<?= clean(date('M d, Y', strtotime($lv['date_to']))) ?></td>
                                <td style="max-width:220px;"><?= clean($lv['reason'] ?: '—') ?></td>
                                <td><?= clean(date('M d, Y', strtotime($lv['requested_at']))) ?></td>
                                <td><span class="badge <?= clean(leaveStatusBadgeClass($lv['status'])) ?>"><?= clean(ucfirst($lv['status'])) ?></span></td>
                                <td><?= !empty($lv['decided_at']) ? clean(date('M d, Y', strtotime($lv['decided_at']))) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2><span class="section-icon"><svg viewBox="0 0 20 20" fill="none"><path d="M10 5.5v5l3.5 2M17 10.5a7 7 0 11-14 0 7 7 0 0114 0z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span> Request Overtime</h2>

            <?php if (!empty($ot_message)): ?>
                <script>
                Swal.fire({ icon: 'success', title: <?= json_encode(clean($ot_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 2200, timerProgressBar: true });
                </script>
            <?php endif; ?>
            <?php if (!empty($ot_error)): ?>
                <script>
                Swal.fire({ icon: 'error', title: <?= json_encode(clean($ot_error)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 3200, timerProgressBar: true });
                </script>
            <?php endif; ?>

            <p style="color:var(--ink-soft); font-size:13px; margin-bottom:12px;">Submit this once the overtime has already happened (or on the day itself). Your Supervisor will review and approve or reject it.</p>

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
                <button type="submit" class="btn">Submit Overtime Request</button>
            </form>

            <?php if (empty($my_overtime_requests)): ?>
                <div class="empty-row">You haven't submitted any overtime requests yet.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Date</th><th>Hours</th><th>Reason</th><th>Requested</th><th>Status</th><th>Decided On</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_overtime_requests as $ot): ?>
                            <tr>
                                <td><?= clean(date('M d, Y', strtotime($ot['ot_date']))) ?></td>
                                <td><?= clean($ot['hours_requested']) ?> hrs</td>
                                <td style="max-width:220px;"><?= clean($ot['reason'] ?: '—') ?></td>
                                <td><?= clean(date('M d, Y', strtotime($ot['requested_at']))) ?></td>
                                <td><span class="badge <?= clean(leaveStatusBadgeClass($ot['status'])) ?>"><?= clean(ucfirst($ot['status'])) ?></span></td>
                                <td><?= !empty($ot['decided_at']) ? clean(date('M d, Y', strtotime($ot['decided_at']))) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2><span class="section-icon"><svg viewBox="0 0 20 20" fill="none"><path d="M5 2.5h7l3 3V17a.5.5 0 01-.5.5h-9a.5.5 0 01-.5-.5V3a.5.5 0 01.5-.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M7 9.5h6M7 12.5h6M7 6.5h3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span> My Payslips</h2>
            <?php
                $__acctFull   = preg_replace('/\s+/', '', (string) ($__myEmployee['bank_account_number'] ?? ''));
                $__acctMasked = $__acctFull !== ''
                    ? ((strlen($__acctFull) > 4 ? str_repeat('•', strlen($__acctFull) - 4) : '') . substr($__acctFull, -4))
                    : '';
            ?>
            <?php if (!empty($__myEmployee['bank_name']) || $__acctFull !== ''): ?>
                <div class="bank-line">
                    💳 Salary deposited to: <strong><?= clean($__myEmployee['bank_name'] ?: '—') ?></strong>
                    <?php if ($__acctMasked !== ''): ?> &middot; <?= clean($__acctMasked) ?><?php endif; ?>
                    <?php if (!empty($__myEmployee['bank_account_name'])): ?> &middot; <?= clean($__myEmployee['bank_account_name']) ?><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="bank-line">💳 No bank/card details set yet. Please contact HR to set this up.</div>
            <?php endif; ?>

            <?php if (empty($current_payslips)): ?>
                <div class="empty-row">No payslip is being prepared for you right now.</div>
            <?php else: ?>
                <?php foreach ($current_payslips as $cp): ?>
                <div class="current-payslip-card">
                    <div class="cp-head">
                        <div>
                            <div class="cp-period"><?= clean(date('M d', strtotime($cp['pay_period_start']))) ?> – <?= clean(date('M d, Y', strtotime($cp['pay_period_end']))) ?></div>
                            <div class="cp-payout">Payout on <?= clean(date('M d, Y', strtotime($cp['payout_date']))) ?> · <span class="badge badge--pending">Not yet released</span></div>
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
                        <a class="view-link" href="payslip_view.php?id=<?= enc_id($cp['payroll_id']) ?>" onclick="return openPayslipModal(this.href);">View Payslip</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <h3 style="font-family:var(--font-display); color:var(--text-accent); font-size:15px; margin:18px 0 10px;">Salary History</h3>

            <?php if (empty($salary_history)): ?>
                <div class="empty-row">No released payslips yet.</div>
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
                        <?php foreach ($salary_history as $p):
                            $__extras = [];
                            foreach ($optional_payslip_fields as $col => $label) {
                                if (isset($p[$col]) && $p[$col] !== null) {
                                    $__extras[] = $label . ': ' . peso($p[$col]);
                                }
                            }
                        ?>
                            <tr>
                                <td>
                                    <span class="payslip-period"><?= clean(date('M d', strtotime($p['pay_period_start']))) ?> – <?= clean(date('M d, Y', strtotime($p['pay_period_end']))) ?></span>
                                    <?php if (!empty($__extras)): ?>
                                        <div class="payslip-details">
                                            <?php foreach ($__extras as $ex): ?><span><?= clean($ex) ?></span><?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= !empty($p['payout_date']) ? clean(date('M d, Y', strtotime($p['payout_date']))) : '—' ?></td>
                                <td><?= peso($p['gross_pay']) ?></td>
                                <td><strong><?= peso($p['net_pay']) ?></strong></td>
                                <td><?= !empty($p['generated_at']) ? clean(date('M d, Y', strtotime($p['generated_at']))) : '—' ?></td>
                                <td>
                                    <a class="view-link" href="payslip_view.php?id=<?= enc_id($p['payroll_id']) ?>" onclick="return openPayslipModal(this.href);">View Payslip</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    <?php endif; ?>
        </div>
    </div>
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
function confirmNav(e, url, opts) {
    e.preventDefault();
    Swal.fire(Object.assign({
        showCancelButton: true,
        cancelButtonText: 'Cancel'
    }, opts)).then(function (result) {
        if (result.isConfirmed) window.location.href = url;
    });
    return false;
}

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
</script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>