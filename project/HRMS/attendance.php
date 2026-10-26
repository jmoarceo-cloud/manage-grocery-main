<?php
require_once 'includes/config.php';
requireHR(); // Both Admin and HR can encode attendance

$success_message = "";
$error_message   = "";

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'attendance'
      AND column_name = 'encoded_by'
");
$stmt->execute();
$missing_encoded_by = ((int) $stmt->fetchColumn()) === 0;

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'attendance'
      AND index_name = 'uniq_employee_date'
");
$stmt->execute();
$missing_unique_key = ((int) $stmt->fetchColumn()) === 0;

$needs_fix = $missing_encoded_by || $missing_unique_key;


if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') === 'fix_attendance_table') {
    requireCSRF();
    try {
        if ($missing_encoded_by) {
            $pdo->exec("ALTER TABLE attendance ADD COLUMN encoded_by INT NULL AFTER late_minutes");
            $missing_encoded_by = false;
        }
        if ($missing_unique_key) {
            // Keep only the newest row (highest attendance_id)
            // per employee_id + date, and delete the duplicates.
            $pdo->exec("
                DELETE a1 FROM attendance a1
                INNER JOIN attendance a2
                    ON a1.employee_id = a2.employee_id
                   AND a1.date = a2.date
                   AND a1.attendance_id < a2.attendance_id
            ");
            $pdo->exec("ALTER TABLE attendance ADD UNIQUE KEY uniq_employee_date (employee_id, date)");
            $missing_unique_key = false;
        }
        $needs_fix = false;
        $success_message = "The attendance table structure has been updated and duplicate records have been cleaned up. You can now Time In.";
    } catch (PDOException $e) {
        error_log("Attendance table fix error: " . $e->getMessage());
        $error_message = "Failed to update the table. Error: " . $e->getMessage();
    }
}

// =====================================================
// HOLIDAYS (Admin only) — add/remove a company-wide holiday.
// This replaces the old manual "Holiday" override.
// =====================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && in_array($_POST['action'] ?? '', ['add_holiday', 'delete_holiday'], true)) {
    requireCSRF();

    if (!isAdminRole()) {
        $error_message = "Only admins can manage holidays.";
    } elseif (($_POST['action'] ?? '') === 'add_holiday') {

        $hdate = trim($_POST['holiday_date'] ?? '');
        $hname = trim($_POST['holiday_name'] ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hdate)) {
            $error_message = "Please select a valid holiday date.";
        } elseif ($hname === '') {
            $error_message = "Please enter the holiday name.";
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO holidays (holiday_date, name, created_by)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE name = VALUES(name)
                ")->execute([$hdate, mb_substr($hname, 0, 100), $_SESSION['user_id']]);
                $success_message = "Holiday set: " . date('M d, Y', strtotime($hdate)) . " — " . $hname . ".";
            } catch (PDOException $e) {
                error_log("Add holiday error: " . $e->getMessage());
                $error_message = "Failed to save the holiday. Please try again.";
            }
        }
    } else { // delete_holiday
        $hid = (int) ($_POST['holiday_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM holidays WHERE holiday_id = ?")->execute([$hid]);
            $success_message = "The holiday has been deleted.";
        } catch (PDOException $e) {
            error_log("Delete holiday error: " . $e->getMessage());
            $error_message = "Failed to delete the holiday. Please try again.";
        }
    }
}

// =====================================================
// SHIFT SWAPS ("Palit-Shift") — HR/Admin processes these directly:
// pick who's COVERING (will be allowed to Time In on their Day Off),
// the date, optionally who they're covering FOR, and a reason. It's
// recorded as already 'approved' since HR is the one doing the
// processing — there's no separate self-serve approval step (an
// employee can never approve their own swap).
// =====================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && in_array($_POST['action'] ?? '', ['add_shift_swap', 'delete_shift_swap'], true)) {
    requireCSRF();

    if (($_POST['action'] ?? '') === 'add_shift_swap') {
        $swapDate   = trim($_POST['swap_date'] ?? '');
        $coveringId = (int) ($_POST['covering_employee_id'] ?? 0);
        $originalId = (int) ($_POST['original_employee_id'] ?? 0);
        $shiftId    = (int) ($_POST['swap_shift_id'] ?? 0);
        $reason     = trim($_POST['swap_reason'] ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $swapDate)) {
            $error_message = "Please select a valid date for the shift swap.";
        } elseif ($coveringId <= 0) {
            $error_message = "Please select the employee who will cover the shift.";
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO shift_swaps (swap_date, covering_employee_id, original_employee_id, shift_id, reason, status, requested_by, processed_by, processed_at)
                    VALUES (?, ?, ?, ?, ?, 'approved', ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        original_employee_id = VALUES(original_employee_id),
                        shift_id = VALUES(shift_id),
                        reason = VALUES(reason),
                        status = 'approved',
                        processed_by = VALUES(processed_by),
                        processed_at = NOW()
                ")->execute([
                    $swapDate,
                    $coveringId,
                    $originalId > 0 ? $originalId : null,
                    $shiftId > 0 ? $shiftId : null,
                    $reason !== '' ? mb_substr($reason, 0, 255) : null,
                    $_SESSION['user_id'],
                    $_SESSION['user_id'],
                ]);
                $success_message = "Shift swap approved — that employee can now Time In on " . date('M d, Y', strtotime($swapDate)) . " even though it's their Day Off.";
            } catch (PDOException $e) {
                error_log("Add shift swap error: " . $e->getMessage());
                $error_message = "Failed to save the shift swap. Please try again.";
            }
        }
    } else { // delete_shift_swap
        $swapId = (int) ($_POST['swap_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM shift_swaps WHERE swap_id = ?")->execute([$swapId]);
            $success_message = "The shift swap has been removed.";
        } catch (PDOException $e) {
            error_log("Delete shift swap error: " . $e->getMessage());
            $error_message = "Failed to remove the shift swap. Please try again.";
        }
    }
}

// =====================================================
// CORRECT ATTENDANCE — lets HR Staff, HR Manager, and Admin fix a
// monitored employee's record (most commonly used to close an
// "unfinished" record where the employee never Timed Out on their
// last duty). Same monitoring chain as getAttendanceScopedEmployees():
// HR Staff can fix Employees + Supervisors; HR Manager can fix HR
// Staff; Admin can fix everyone. A reason is REQUIRED and kept as an
// audit trail (corrected_by / correction_reason / corrected_at — see
// ensureAttendanceCorrectionColumns() in includes/config.php).
// Mirrors the identical action on supervisor_attendance.php, which
// handles the Supervisor -> Cashier/Inventory link of the same chain.
// =====================================================
$correct_message = '';
$correct_error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'correct_attendance') {
    requireCSRF();

    $targetEmployeeId = (int) ($_POST['employee_id'] ?? 0);
    $targetDate       = trim($_POST['record_date'] ?? '');
    $newTimeIn        = trim($_POST['time_in'] ?? '');
    $newTimeOut       = trim($_POST['time_out'] ?? '');
    $newStatus        = trim($_POST['status'] ?? 'present');
    $reason           = trim($_POST['correction_reason'] ?? '');

    $editableIds = array_column(
        array_filter(getAttendanceScopedEmployees($pdo), fn($e) => !empty($e['attendance_editable'])),
        'employee_id'
    );

    if (!in_array($targetEmployeeId, $editableIds, true)) {
        $correct_error = "You don't have permission to correct attendance for this employee.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
        $correct_error = "Invalid record date.";
    } elseif (mb_strlen($reason) < 3) {
        $correct_error = "Please enter a reason for this correction (e.g. \"employee forgot to time out\").";
    } elseif ($newTimeIn !== '' && !isValidTime($newTimeIn)) {
        $correct_error = "Please enter a valid Time In.";
    } elseif ($newTimeOut !== '' && !isValidTime($newTimeOut)) {
        $correct_error = "Please enter a valid Time Out.";
    } elseif (!in_array($newStatus, ['present', 'absent', 'leave', 'holiday', 'day_off'], true)) {
        $correct_error = "Invalid status.";
    }
    // NOTE: no "Time Out must be later than Time In" check here on
    // purpose — Time In/Time Out are plain TIME columns (no date), so
    // for a shift crossing midnight the Time Out is numerically
    // EARLIER than the Time In. Same handling as supervisor_attendance.php.
    else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO attendance (employee_id, date, time_in, time_out, status, corrected_by, correction_reason, corrected_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    time_in = VALUES(time_in),
                    time_out = VALUES(time_out),
                    status = VALUES(status),
                    corrected_by = VALUES(corrected_by),
                    correction_reason = VALUES(correction_reason),
                    corrected_at = VALUES(corrected_at)
            ");
            $stmt->execute([
                $targetEmployeeId,
                $targetDate,
                $newTimeIn !== '' ? $newTimeIn : null,
                $newTimeOut !== '' ? $newTimeOut : null,
                $newStatus,
                $_SESSION['user_id'],
                mb_substr($reason, 0, 255),
            ]);
            $correct_message = "Attendance record for " . date('M d, Y', strtotime($targetDate)) . " has been corrected.";
        } catch (PDOException $e) {
            error_log("correct_attendance error (attendance.php): " . $e->getMessage());
            $correct_error = "Could not save the correction. Please try again.";
        }
    }
}


$date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
    $date = date('Y-m-d');
}

$employees = getAttendanceScopedEmployees($pdo);
$scopedIds = array_column($employees, 'employee_id');
$editableIds = array_column(array_filter($employees, fn($e) => !empty($e['attendance_editable'])), 'employee_id');

// Every OPEN record (Timed In, never Timed Out) among employees the
// current role can actually fix, regardless of the $date filter below
// — so a forgotten Time Out from days ago isn't missed just because
// nobody happened to browse to that date. Same panel/behavior as the
// "Unfinished Attendance Records" section on supervisor_attendance.php.
$unfinishedRecords = [];
if (!empty($editableIds)) {
    $ph3 = implode(',', array_fill(0, count($editableIds), '?'));
    $stmt = $pdo->prepare("
        SELECT a.*, e.employee_code, e.first_name, e.last_name
        FROM attendance a JOIN employees e ON e.employee_id = a.employee_id
        WHERE a.time_in IS NOT NULL AND a.time_out IS NULL AND a.date < CURDATE() AND a.employee_id IN ($ph3)
        ORDER BY a.date DESC
    ");
    $stmt->execute($editableIds);
    $unfinishedRecords = $stmt->fetchAll();
}

function fmtAttTime($t) { return $t ? date('g:i A', strtotime($t)) : '—'; }

// Role filter for the Daily View table — purely a display slice on
// top of the scope above (shift-swap dropdowns, leave/holiday maps,
// etc. below still use the full $employees list). Most useful for
// Admin, who sees every role at once and may want to isolate one
// (e.g. "just Finance" or "just Supervisors").
$roleFilter = trim($_GET['role'] ?? '');
$availableRoleFilters = [];
foreach ($employees as $__e) {
    $availableRoleFilters[attendanceRoleLabel($__e['acct_role'], $__e['position'] ?? null)] = true;
}
$availableRoleFilters = array_keys($availableRoleFilters);
sort($availableRoleFilters);
$employees_display = $roleFilter === ''
    ? $employees
    : array_values(array_filter($employees, function ($e) use ($roleFilter) {
        return attendanceRoleLabel($e['acct_role'], $e['position'] ?? null) === $roleFilter;
    }));

// Which shift is assigned to each employee for this $date (based on
// its day-of-week) — shown in the Daily View table. Batch-fetched to
// avoid an N+1 query per employee.
$__dow = (int) date('w', strtotime($date));
$shiftByEmpForDate         = [];
$weeklyScheduleConfigured  = [];
foreach ($scopedIds as $__eid) {
    $__wk = getEmployeeWeeklySchedule($pdo, $__eid);
    $shiftByEmpForDate[$__eid]        = $__wk[$__dow] ?? null;
    $weeklyScheduleConfigured[$__eid] = count(array_filter($__wk)) > 0;
}

$stmt = $pdo->prepare("SELECT * FROM attendance WHERE date = ?");
$stmt->execute([$date]);
$existingRows = [];
foreach ($stmt->fetchAll() as $row) {
    $existingRows[$row['employee_id']] = $row;
}

$today_str = date('Y-m-d');

// Approved leaves + company-wide holidays — this replaces the old
// manual "Override" (comes from approval / Admin holiday list, not manual).
$leaveMap   = getApprovedLeaveMap($pdo, $scopedIds, $date, $date);
$holidayMap = getHolidayMap($pdo, $date, $date);
$isHoliday  = isset($holidayMap[$date]);


$dayStatus       = [];
$summary         = ['present' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0, 'day_off' => 0, 'late' => 0];
$no_record_count = 0;

foreach ($employees as $emp) {
    $eid = (int) $emp['employee_id'];
    $row = $existingRows[$eid] ?? null;
    // Whether this employee actually has a Weekly Shift Schedule
    // configured for today (used to tell a real Day Off apart from
    // "no shift assigned yet" for a brand-new employee).
    $curShiftToday = $shiftByEmpForDate[$eid] ?? null;

    if ($row && !empty($row['time_in'])) {
        $dayStatus[$eid] = ['key' => 'present', 'label' => 'Present'];
        $summary['present']++;
        if ((int) $row['late_minutes'] > 0) {
            $summary['late']++;
        }
    } elseif ($isHoliday) {
        $dayStatus[$eid] = ['key' => 'holiday', 'label' => 'Holiday (' . $holidayMap[$date] . ')'];
        $summary['holiday']++;
    } elseif (isset($leaveMap[$eid][$date])) {
        $dayStatus[$eid] = ['key' => 'leave', 'label' => 'On Leave (' . ucfirst($leaveMap[$eid][$date]) . ')'];
        $summary['leave']++;
    } elseif (($row['status'] ?? null) === 'day_off' || (!$curShiftToday && !empty($weeklyScheduleConfigured[$eid]))) {
        $dayStatus[$eid] = ['key' => 'day_off', 'label' => 'Day Off (Rest Day)'];
        $summary['day_off']++;
    } elseif ($date < $today_str) {
        $dayStatus[$eid] = ['key' => 'absent', 'label' => 'Absent'];
        $summary['absent']++;
    } else {
        $dayStatus[$eid] = ['key' => 'none', 'label' => 'No Record'];
        $no_record_count++;
    }
}

// List of holidays for the Admin panel
$holiday_list = [];
if (isAdminRole()) {
    try {
        $holiday_list = $pdo->query("SELECT * FROM holidays ORDER BY holiday_date DESC LIMIT 24")->fetchAll();
    } catch (PDOException $e) {
        error_log("Holiday list error: " . $e->getMessage());
    }
}

// Recent shift swaps (all HR-tier roles can view/process this — same
// gate as the rest of this page via requireHR() above) + the active
// shifts list, used to populate the "Shift Swap Requests" panel.
$shift_swap_list = [];
try {
    $shift_swap_list = $pdo->query("
        SELECT sw.*,
               ce.employee_code AS covering_code, ce.first_name AS covering_first, ce.last_name AS covering_last,
               oe.employee_code AS original_code, oe.first_name AS original_first, oe.last_name AS original_last,
               s.shift_name, s.start_time, s.end_time,
               u.full_name AS processed_by_name
        FROM shift_swaps sw
        JOIN employees ce ON ce.employee_id = sw.covering_employee_id
        LEFT JOIN employees oe ON oe.employee_id = sw.original_employee_id
        LEFT JOIN shifts s ON s.shift_id = sw.shift_id
        LEFT JOIN users u ON u.id = sw.processed_by
        ORDER BY sw.swap_date DESC, sw.created_at DESC
        LIMIT 30
    ")->fetchAll();
} catch (PDOException $e) {
    error_log("Shift swap list error: " . $e->getMessage());
}
$activeShiftsForSwap = getActiveShifts($pdo);

// Approved swaps for the specific $date being viewed (Daily View) —
// so the Shift column can show "Day Off — Covering via Shift Swap"
// instead of a plain "Day Off" for whoever is approved to work.
$swapsForDate = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM shift_swaps WHERE swap_date = ? AND status = 'approved'");
    $stmt->execute([$date]);
    foreach ($stmt->fetchAll() as $sw) {
        $swapsForDate[(int) $sw['covering_employee_id']] = $sw;
    }
} catch (PDOException $e) {
    error_log("swapsForDate error: " . $e->getMessage());
}

$prev_date = date('Y-m-d', strtotime($date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($date . ' +1 day'));
$is_today  = $date === date('Y-m-d');

// =====================================================
// VIEW: 'daily' (default, per-day) or 'history' (monthly)
// =====================================================
$view = (isset($_GET['view']) && $_GET['view'] === 'history') ? 'history' : 'daily';

$month = isset($_GET['month']) ? trim($_GET['month']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month) || $month > date('Y-m')) {
    $month = date('Y-m');
}
$prev_month       = date('Y-m', strtotime($month . '-01 -1 month'));
$next_month       = date('Y-m', strtotime($month . '-01 +1 month'));
$is_current_month = ($month === date('Y-m'));

// Monthly history — all saved attendance for the selected month,
// scoped only to the employees visible to the current role.
$history_rows = [];
if ($view === 'history' && !empty($employees)) {
    $ph = implode(',', array_fill(0, count($scopedIds), '?'));
    $stmt = $pdo->prepare("
        SELECT a.*, e.employee_code, e.first_name, e.last_name, e.department
        FROM attendance a
        JOIN employees e ON e.employee_id = a.employee_id
        WHERE DATE_FORMAT(a.date, '%Y-%m') = ?
          AND a.employee_id IN ($ph)
        ORDER BY a.date DESC, e.last_name ASC, e.first_name ASC
    ");
    $stmt->execute(array_merge([$month], $scopedIds));
    $history_rows = $stmt->fetchAll();

    // Also include days that are HOLIDAY or have an APPROVED leave but
    // no attendance record — so they still show up in the history.
    $monthStart      = $month . '-01';
    $monthEnd        = date('Y-m-t', strtotime($monthStart));
    $holidayMapMonth = getHolidayMap($pdo, $monthStart, $monthEnd);
    $leaveMapMonth   = getApprovedLeaveMap($pdo, $scopedIds, $monthStart, $monthEnd);

    $usedKeys = [];
    foreach ($history_rows as $h) {
        $usedKeys[$h['employee_id'] . '|' . $h['date']] = true;
    }
    $empById = [];
    foreach ($employees as $e) {
        $empById[(int) $e['employee_id']] = $e;
    }

    // Helper: build a synthetic row (no attendance record)
    $makeRow = function ($eid, $d, $status, $extra) use ($empById) {
        return array_merge([
            'employee_id'       => $eid,
            'date'              => $d,
            'time_in'           => null,
            'time_out'          => null,
            'late_minutes'      => 0,
            'status'            => $status,
            'time_in_latitude'  => null,
            'time_in_longitude' => null,
            'time_in_address'   => null,
            'employee_code'     => $empById[$eid]['employee_code'],
            'first_name'        => $empById[$eid]['first_name'],
            'last_name'         => $empById[$eid]['last_name'],
            'department'        => $empById[$eid]['department'],
        ], $extra);
    };

    // Holidays first (higher priority than leave)
    foreach ($holidayMapMonth as $hd => $hname) {
        foreach ($empById as $eid => $e) {
            if (isset($usedKeys[$eid . '|' . $hd])) {
                continue;
            }
            $history_rows[] = $makeRow($eid, $hd, 'holiday', ['holiday_name' => $hname]);
            $usedKeys[$eid . '|' . $hd] = true;
        }
    }

    // Then the approved leaves
    foreach ($leaveMapMonth as $eid => $dates) {
        foreach ($dates as $d => $type) {
            if (isset($usedKeys[$eid . '|' . $d]) || !isset($empById[$eid])) {
                continue;
            }
            $history_rows[] = $makeRow($eid, $d, 'leave', ['leave_type' => $type]);
            $usedKeys[$eid . '|' . $d] = true;
        }
    }

    // Then Day Off (Rest Day) — past days this month that haven't been
    // auto-marked yet (autoMarkPastAbsences() runs incrementally) but
    // fall on a day the employee has no shift assigned, per their
    // configured Weekly Shift Schedule. Never shown for future dates.
    $__historyYesterday = date('Y-m-d', strtotime('-1 day'));
    foreach ($empById as $eid => $e) {
        $wk = getEmployeeWeeklySchedule($pdo, $eid);
        if (count(array_filter($wk)) === 0) {
            continue; // no schedule configured yet — don't guess
        }
        $cursor = strtotime($monthStart);
        $stop   = min(strtotime($monthEnd), strtotime($__historyYesterday));
        while ($cursor <= $stop) {
            $d = date('Y-m-d', $cursor);
            if (!isset($usedKeys[$eid . '|' . $d])) {
                $dow = (int) date('w', $cursor);
                if (empty($wk[$dow])) {
                    $history_rows[] = $makeRow($eid, $d, 'day_off', []);
                    $usedKeys[$eid . '|' . $d] = true;
                }
            }
            $cursor = strtotime('+1 day', $cursor);
        }
    }

    usort($history_rows, function ($a, $b) {
        if ($a['date'] !== $b['date']) {
            return strcmp($b['date'], $a['date']); // most recent first
        }
        return strcmp($a['last_name'] . $a['first_name'], $b['last_name'] . $b['first_name']);
    });

    // Group rows by date for the accordion view below — the date is
    // shown once per group (as the <summary>) instead of being
    // repeated on every row, and only the most recent date starts
    // expanded so older dates don't take up scroll space by default.
    $history_grouped = [];
    foreach ($history_rows as $h) {
        $history_grouped[$h['date']][] = $h;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance — HRMS &amp; Payroll</title>
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

/* ── HR sidebar (shared across HR Staff / HR Manager module pages) ── */
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
.topbar h1 {
    font-family: var(--font-display);
    font-size: 20px;
    color: var(--text-accent);
}
.topbar .user-info {
    font-size: 13.5px;
    color: var(--ink-soft);
    display: flex;
    align-items: center;
    gap: 16px;
}
.user-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--mustard); color: #FFFFFF; display: flex; align-items: center; justify-content: center; font-family: var(--font-display); font-weight: 700; font-size: 13px; flex-shrink: 0; overflow: hidden; }
.user-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.topbar a.logout { color: var(--red-alert); font-weight: 600; font-size: 13.5px; }

.container {
    padding: 30px 32px 64px;
}

.alert {
    padding: 12px 16px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 20px;
}
.alert--success { background: var(--green-succ-bg, #e6f4ea); color: var(--green-succ-text, #1a7a3b); }
.alert--error   { background: var(--red-pale); color: var(--red-alert); }

.unfinished-panel { background: var(--red-pale); border: 1.5px solid var(--salmon-bg, #F3C6BA); border-radius: 16px; padding: 18px 22px; margin-bottom: 20px; }
.unfinished-panel h2 { color: var(--red-alert); font-size: 16px; margin-bottom: 4px; }
.unfinished-panel .sub { font-size: 13px; color: var(--ink-soft); margin-bottom: 14px; }
.unfinished-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; background: var(--white); border-radius: 12px; padding: 12px 16px; margin-bottom: 8px; flex-wrap: wrap; }
.unfinished-row:last-child { margin-bottom: 0; }
.unfinished-row .who { font-weight: 600; color: var(--ink); font-size: 13.5px; }
.unfinished-row .meta { font-size: 12.5px; color: var(--ink-soft); }
.btn-fix { background: var(--red-alert); color: #FFFFFF; border: none; padding: 9px 16px; border-radius: 9px; font-weight: 600; font-size: 13px; cursor: pointer; }
.btn-edit-att { background: var(--cream); color: var(--text-accent); border: 1.5px solid var(--line); padding: 6px 12px; border-radius: 8px; font-weight: 600; font-size: 12.5px; cursor: pointer; }
.modal-overlay { position: fixed; inset: 0; background: rgba(18,60,92,.25); display: flex; align-items: center; justify-content: center; padding: 24px; z-index: 100; }
.modal-card { background: var(--white); border-radius: 20px; padding: 28px; max-width: 440px; width: 100%; max-height: 88vh; overflow-y: auto; }
.modal-card h2 { color: var(--text-accent); font-size: 18px; margin-bottom: 4px; }
.modal-card .modal-sub { font-size: 13px; color: var(--ink-soft); margin-bottom: 18px; }
.modal-card .field-group { margin-bottom: 14px; }
.modal-card .field-group label { display: block; font-size: 12.5px; font-weight: 600; margin-bottom: 6px; color: var(--ink); }
.modal-card .field-group input, .modal-card .field-group select, .modal-card .field-group textarea {
    width: 100%; padding: 10px 12px; border: 1.5px solid var(--line); border-radius: 10px;
    font-family: inherit; font-size: 13.5px; box-sizing: border-box;
}
.modal-card .field-row { display: flex; gap: 12px; }
.modal-card .field-row .field-group { flex: 1; }
.modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; }
.btn-secondary-modal { background: var(--cream); color: var(--ink); border: 1.5px solid var(--line); padding: 10px 18px; border-radius: 10px; font-weight: 600; font-size: 13.5px; cursor: pointer; }

.summary-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}
.summary-card {
    position: relative;
    background: var(--white);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 17px 18px 16px;
    display: flex;
    align-items: center;
    gap: 13px;
    overflow: hidden;
    box-shadow: 0 1px 2px rgba(18,60,92,0.05), 0 6px 16px rgba(18,60,92,0.05);
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
.summary-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 14px 28px rgba(18,60,92,0.14);
    border-color: color-mix(in srgb, var(--accent, var(--green-mid)) 35%, var(--line));
}
.summary-card .icon-box {
    width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(150deg, var(--accent-pale, var(--green-pale)), color-mix(in srgb, var(--accent-pale, var(--green-pale)) 55%, white));
    color: var(--accent, var(--green-mid));
    box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--accent, var(--green-mid)) 18%, transparent);
}
.summary-card .icon-box svg { width: 20px; height: 20px; stroke-width: 2.25; }
.summary-card .label { font-size: 11.5px; font-weight: 700; color: var(--ink-soft); text-transform: uppercase; letter-spacing: .04em; }
.summary-card .value { font-family: var(--font-display); font-size: 26px; font-weight: 600; color: var(--ink); line-height: 1.15; margin-top: 3px; }

.field-input {
    padding: 10px 13px;
    height: 42px;
    border: 1.5px solid var(--line);
    border-radius: 10px;
    font-family: var(--font-body);
    font-size: 13.5px;
    color: var(--ink);
    background: var(--white);
    box-shadow: 0 1px 2px rgba(18,60,92,0.04);
    transition: border-color .15s ease, box-shadow .15s ease;
    min-width: 0;
}
.field-input::placeholder { color: #93A4B2; }
.field-input:hover { border-color: var(--gray-bg, #C2D0DA); }
.field-input:focus {
    outline: none;
    border-color: var(--green-mid);
    box-shadow: 0 0 0 3px rgba(25,120,168,0.14);
}
select.field-input {
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none' stroke='%233D4C5C' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M5 7.5l5 5 5-5'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    background-size: 15px;
    padding-right: 34px;
    cursor: pointer;
}

.field-input::-webkit-calendar-picker-indicator {
    cursor: pointer;
    opacity: .55;
    filter: invert(24%) sepia(31%) saturate(1181%) hue-rotate(163deg) brightness(94%) contrast(92%);
}
.field-input::-webkit-calendar-picker-indicator:hover { opacity: .9; }

.date-nav {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.date-nav .today-badge {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--green-mid);
    background: var(--green-pale);
    padding: 6px 12px;
    border-radius: 999px;
}

/* ── Segmented view-toggle (Daily / History) ── */
.view-toggle {
    display: inline-flex;
    padding: 5px;
    background: var(--cream);
    border: 1px solid var(--line);
    border-radius: 13px;
    gap: 4px;
    margin-bottom: 24px;
    box-shadow: inset 0 1px 3px rgba(18,60,92,0.06);
}
.view-toggle .seg {
    padding: 10px 20px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--ink-soft);
    transition: background .18s ease, color .18s ease, box-shadow .18s ease, transform .18s ease;
}
.view-toggle .seg.is-active {
    background: linear-gradient(135deg, var(--green-mid), var(--green-deep));
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(18,60,92,0.32);
}
.view-toggle .seg:not(.is-active):hover { background: var(--white); color: var(--ink); box-shadow: 0 2px 6px rgba(18,60,92,0.08); }

/* ── Info panels (Holidays / Shift Swap): icon + accent-bordered card ── */
.info-panel {
    display: flex;
    gap: 15px;
    background: linear-gradient(165deg, var(--white), color-mix(in srgb, var(--accent-pale, var(--mustard-pale)) 30%, white));
    border: 1px solid var(--line);
    border-left: 5px solid var(--accent, var(--mustard));
    border-radius: 14px;
    padding: 19px 22px;
    margin-bottom: 18px;
    box-shadow: 0 2px 4px rgba(18,60,92,0.04), 0 10px 24px rgba(18,60,92,0.06);
}
.info-panel .icon-box {
    width: 40px; height: 40px; border-radius: 11px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(150deg, var(--accent-pale, var(--mustard-pale)), color-mix(in srgb, var(--accent-pale, var(--mustard-pale)) 55%, white));
    color: var(--accent, var(--mustard-text2, #97661F));
    box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--accent, var(--mustard-text2, #97661F)) 18%, transparent), 0 2px 6px rgba(18,60,92,0.08);
}
.info-panel .icon-box svg { width: 19px; height: 19px; stroke-width: 2.25; }
.info-panel .panel-body { flex: 1; min-width: 0; }
.info-panel .panel-title { font-family: var(--font-display); font-weight: 600; font-size: 15px; color: var(--ink); margin-bottom: 4px; }
.info-panel .panel-desc { font-size: 13px; color: var(--ink-soft); line-height: 1.6; }
.info-panel form { margin-top: 13px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.info-panel .chip-row { margin-top: 12px; }

.btn {
    padding: 10px 18px;
    border: none;
    border-radius: 10px;
    font-family: var(--font-body);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}
.btn-primary { background: linear-gradient(135deg, var(--green-mid), var(--green-deep)); color: #FFFFFF; box-shadow: 0 2px 8px rgba(18,60,92,0.22); }
.btn-primary:hover { filter: brightness(1.08); box-shadow: 0 5px 16px rgba(18,60,92,0.32); transform: translateY(-2px); }
.btn-primary:active { transform: translateY(0); filter: brightness(0.97); }
.btn-secondary { background: var(--white); color: var(--ink); border: 1.5px solid var(--line); box-shadow: 0 1px 2px rgba(18,60,92,0.05); }
.btn-secondary:hover { background: var(--cream); border-color: var(--green-leaf); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(18,60,92,0.10); }
.btn-secondary:active { transform: translateY(0); box-shadow: none; }
.btn-sm { padding: 8px 14px; font-size: 13px; }

.table-card {
    background: var(--white);
    border: 1px solid var(--line);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(18,60,92,0.04), 0 10px 24px rgba(18,60,92,0.06);
}
table { width: 100%; border-collapse: collapse; }
th {
    text-align: left;
    font-size: 12.5px;
    text-transform: uppercase;
    letter-spacing: .03em;
    color: var(--ink-soft);
    background: var(--cream);
    padding: 12px 14px;
    border-bottom: 1px solid var(--line);
}
td {
    padding: 10px 14px;
    font-size: 13.5px;
    border-bottom: 1px solid var(--line);
    vertical-align: middle;
}
tr:last-child td { border-bottom: none; }

/* ── History (Monthly): accordion grouped by date ── */
.history-date-group {
    background: var(--white);
    border: 1px solid var(--line);
    border-radius: 14px;
    margin-bottom: 10px;
    overflow: hidden;
}
.history-date-summary {
    list-style: none;
    cursor: pointer;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    font-family: var(--font-display);
    color: var(--text-accent);
    user-select: none;
}
.history-date-summary::-webkit-details-marker { display: none; }
.history-date-summary::before {
    content: '▸';
    display: inline-block;
    margin-right: 10px;
    transition: transform 0.15s ease;
    color: var(--green-mid);
}
.history-date-group[open] > .history-date-summary::before { transform: rotate(90deg); }
.history-date-label { font-size: 15px; font-weight: 700; }
.history-date-count { margin-left: auto; font-size: 12px; font-weight: 600; color: var(--ink-soft); font-family: var(--font-body); text-transform: none; letter-spacing: 0; }
.history-date-group .table-card { border-radius: 0; border: none; border-top: 1px solid var(--line); }

input[type="time"], select.row-input {
    padding: 7px 8px;
    border: 1.5px solid var(--line);
    border-radius: 8px;
    font-family: var(--font-body);
    font-size: 13px;
    width: 100%;
}

.badge {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 600;
}
.badge-present   { background: var(--green-pale); color: var(--text-accent); }
.badge-absent    { background: var(--red-pale); color: var(--red-alert); }
.badge-leave     { background: var(--mustard-pale); color: var(--mustard-text2, #97661F); }
.badge-holiday   { background: var(--blue-bg, #E7EEFB); color: var(--blue-text, #2D5FC8); }
.badge-day_off   { background: var(--purple-bg, #EAE7F6); color: var(--purple-text, #5B4E9C); }
.badge-none      { background: var(--gray-bg, #EFEFEF); color: var(--ink-soft); }
.badge-late      { background: var(--mustard-pale); color: var(--mustard-text2, #97661F); margin-left: 4px; }

.row-error { color: var(--red-alert); font-size: 11.5px; margin-top: 3px; }

.hint-banner {
    background: var(--mustard-pale);
    color: var(--mustard-text2, #97661F);
    border-radius: 12px;
    padding: 12px 16px;
    font-size: 13px;
    margin-bottom: 20px;
}

.save-bar {
    display: flex;
    justify-content: flex-end;
    padding: 16px;
    background: var(--white);
    border-top: 1px solid var(--line);
}

@media (max-width: 1100px) { .summary-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 640px) { .summary-grid { grid-template-columns: repeat(2, 1fr); } .container { padding: 20px 16px; } .topbar { padding: 14px 16px; flex-wrap: wrap; gap: 8px; } }
</style>
</head>
<body>

<?php $__showHrSidebar = in_array($_SESSION['role'] ?? '', ['hr', 'hr_manager', 'admin'], true); ?>
<?php if ($__showHrSidebar): ?>
<div class="app-shell">
<?php $activeNav = 'attendance'; include 'includes/hr_sidebar.php'; ?>
<div class="main-area">
<?php endif; ?>

<div class="topbar">
    <h1>HRMS &amp; Payroll — Attendance</h1>
    <div class="user-info">
        <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span>
        <span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span>
    </div>
</div>

<div class="container">

    <?php if (!empty($success_message)): ?>
        <div class="alert alert--success"><?= clean($success_message) ?></div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert--error"><?= clean($error_message) ?></div>
    <?php endif; ?>

    <?php if ($correct_message): ?><div class="alert alert--success"><?= clean($correct_message) ?></div><?php endif; ?>
    <?php if ($correct_error): ?><div class="alert alert--error"><?= clean($correct_error) ?></div><?php endif; ?>

    <?php if (!empty($unfinishedRecords)): ?>
    <div class="unfinished-panel">
        <h2>⚠️ Unfinished Attendance Records</h2>
        <div class="sub">These employees Timed In but never Timed Out. They cannot Time In again until this is corrected.</div>
        <?php foreach ($unfinishedRecords as $u): ?>
            <div class="unfinished-row">
                <div>
                    <div class="who"><?= clean($u['last_name'] . ', ' . $u['first_name']) ?> (<?= clean($u['employee_code']) ?>)</div>
                    <div class="meta"><?= clean(date('M d, Y (D)', strtotime($u['date']))) ?> &middot; Timed In: <?= fmtAttTime($u['time_in']) ?> &middot; Time Out: — never recorded</div>
                </div>
                <button type="button" class="btn-fix" onclick='openCorrectModal(<?= json_encode([
                    "employee_id" => (int) $u["employee_id"],
                    "name"        => $u["last_name"] . ", " . $u["first_name"],
                    "date"        => $u["date"],
                    "time_in"     => $u["time_in"] ? substr($u["time_in"], 0, 5) : "",
                    "time_out"    => $u["time_out"] ? substr($u["time_out"], 0, 5) : "",
                    "status"      => $u["status"],
                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Fix Time Out</button>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($needs_fix): ?>
        <div class="alert alert--error" style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
            <span>
             The attendance table structure in your database is incomplete
                (<?php
                    $missing_parts = [];
                    if ($missing_encoded_by) $missing_parts[] = "missing <code>encoded_by</code> column";
                    if ($missing_unique_key) $missing_parts[] = "missing UNIQUE constraint (there may be duplicate records)";
                    echo implode(', ', $missing_parts);
                ?>), This may prevent attendance records from being saved correctly.
            </span>
            <form method="POST" style="margin:0;">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="fix_attendance_table">
                <button type="submit" class="btn btn-primary btn-sm">Fix Database</button>
            </form>
        </div>
    <?php endif; ?>

    <!-- VIEW TOGGLE: Daily (per-day) vs History (monthly) -->
    <div class="view-toggle">
        <a href="?view=daily&amp;date=<?= clean($date) ?>" class="seg <?= $view === 'daily' ? 'is-active' : '' ?>">Daily View</a>
        <a href="?view=history&amp;month=<?= clean($month) ?>" class="seg <?= $view === 'history' ? 'is-active' : '' ?>">History (Month)</a>
    </div>

    <?php if (isAdminRole()): ?>
    <!-- ADMIN ONLY: company-wide holidays -->
    <div class="info-panel" style="--accent: var(--mustard); --accent-pale: var(--mustard-pale);">
        <div class="icon-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M3 9h18"></path><path d="M8 3v4"></path><path d="M16 3v4"></path></svg>
        </div>
        <div class="panel-body">
            <div class="panel-title">Holidays (company-wide)</div>
            <div class="panel-desc">Only admins can view these dates <strong>Holiday</strong> in attendance records and will not be marked as Absent.</div>
            <form method="POST" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="add_holiday">
                <input type="date" name="holiday_date" class="field-input" style="flex:0 0 170px;" required>
                <input type="text" name="holiday_name" class="field-input" style="flex:0 1 320px;" placeholder="Holiday Name (e.g., Day of Valor)" maxlength="100" required>
                <button type="submit" class="btn btn-primary" style="padding:10px 26px; height:42px;">Add</button>
            </form>
            <?php if (!empty($holiday_list)): ?>
            <div class="chip-row" style="display:flex; gap:8px; flex-wrap:wrap;">
                <?php foreach ($holiday_list as $hl): ?>
                <span style="display:inline-flex; align-items:center; gap:6px; background:var(--mustard-pale); color:var(--mustard-text2, #97661F); padding:5px 12px; border-radius:999px; font-size:12.5px; font-weight:600;">
                    <?= clean(date('M d, Y', strtotime($hl['holiday_date']))) ?> — <?= clean($hl['name']) ?>
                    <form method="POST" style="display:inline; margin:0;" data-confirm="Remove this holiday?" data-confirm-title="Remove the Holiday" data-confirm-danger="1" data-confirm-ok="Yes, Remove">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="delete_holiday">
                        <input type="hidden" name="holiday_id" value="<?= (int) $hl['holiday_id'] ?>">
                        <button type="submit" title="Remove" style="background:none; border:none; color:var(--red-alert); cursor:pointer; font-weight:700; font-size:14px; line-height:1; padding:0;">&times;</button>
                    </form>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- HR/Admin: Shift Swap Requests ("Palit-Shift") -->
    <div class="info-panel" style="--accent: var(--purple-text, #5B4E9C); --accent-pale: var(--purple-bg, #EAE7F6);">
        <div class="icon-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 2l4 4-4 4"></path><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><path d="M7 22l-4-4 4-4"></path><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>
        </div>
        <div class="panel-body">
            <div class="panel-title">Shift Swap Requests</div>
            <div class="panel-desc">Employees can't Time In on their own Day Off. If someone is covering for a co-worker who can't come in, process the swap here first: pick who's <strong>covering</strong>, the date, and (optionally) who they're covering for and which shift. Once approved, the covering employee can Time In that day like normal.</div>
            <form method="POST" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="add_shift_swap">
                <input type="date" name="swap_date" class="field-input" style="flex:0 0 155px;" required max="<?= date('Y-m-d', strtotime('+60 days')) ?>">
                <select name="covering_employee_id" class="field-input" style="flex:1 1 190px;" required>
                    <option value="">Covering employee…</option>
                    <?php foreach ($employees as $__e): ?>
                        <option value="<?= (int) $__e['employee_id'] ?>"><?= clean($__e['last_name'] . ', ' . $__e['first_name']) ?> (<?= clean($__e['employee_code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <select name="original_employee_id" class="field-input" style="flex:1 1 190px;">
                    <option value="">Covering for… (optional)</option>
                    <?php foreach ($employees as $__e): ?>
                        <option value="<?= (int) $__e['employee_id'] ?>"><?= clean($__e['last_name'] . ', ' . $__e['first_name']) ?> (<?= clean($__e['employee_code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <select name="swap_shift_id" class="field-input" style="flex:1 1 160px;">
                    <option value="">Shift to use (optional)</option>
                    <?php foreach ($activeShiftsForSwap as $__s): ?>
                        <option value="<?= (int) $__s['shift_id'] ?>"><?= clean($__s['shift_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="swap_reason" class="field-input" style="flex:1 1 160px;" placeholder="Reason (optional)" maxlength="255">
                <button type="submit" class="btn btn-primary btn-sm">Approve Swap</button>
            </form>
            <?php if (!empty($shift_swap_list)): ?>
            <div class="chip-row" style="display:flex; gap:8px; flex-wrap:wrap;">
                <?php foreach ($shift_swap_list as $sw): ?>
                <span style="display:inline-flex; align-items:center; gap:6px; background:var(--purple-bg, #EAE7F6); color:var(--purple-text, #5B4E9C); padding:5px 12px; border-radius:999px; font-size:12.5px; font-weight:600;">
                    <?= clean(date('M d, Y', strtotime($sw['swap_date']))) ?> —
                    <?= clean($sw['covering_last'] . ', ' . $sw['covering_first']) ?> covers
                    <?php if (!empty($sw['original_last'])): ?> for <?= clean($sw['original_last'] . ', ' . $sw['original_first']) ?><?php endif; ?>
                    <?php if (!empty($sw['shift_name'])): ?> (<?= clean($sw['shift_name']) ?>)<?php endif; ?>
                    <form method="POST" style="display:inline; margin:0;" data-confirm="Remove this shift swap? The employee will no longer be able to Time In on that date." data-confirm-title="Remove the Shift Swap" data-confirm-danger="1" data-confirm-ok="Yes, Remove">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="delete_shift_swap">
                        <input type="hidden" name="swap_id" value="<?= (int) $sw['swap_id'] ?>">
                        <button type="submit" title="Remove" style="background:none; border:none; color:var(--red-alert); cursor:pointer; font-weight:700; font-size:14px; line-height:1; padding:0;">&times;</button>
                    </form>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($view === 'daily'): ?>

    <div class="summary-grid">
        <div class="summary-card" style="--accent: var(--green-mid); --accent-pale: var(--green-pale);">
            <div class="icon-box"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg></div>
            <div><div class="label">Present</div><div class="value"><?= $summary['present'] ?></div></div>
        </div>
        <div class="summary-card" style="--accent: var(--mustard-text2, #97661F); --accent-pale: var(--mustard-pale);">
            <div class="icon-box"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 3"></path></svg></div>
            <div><div class="label">Late</div><div class="value"><?= $summary['late'] ?></div></div>
        </div>
        <div class="summary-card" style="--accent: var(--red-alert); --accent-pale: var(--red-pale);">
            <div class="icon-box"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M15 9l-6 6M9 9l6 6"></path></svg></div>
            <div><div class="label">Absent</div><div class="value"><?= $summary['absent'] ?></div></div>
        </div>
        <div class="summary-card" style="--accent: var(--blue-text, #2D5FC8); --accent-pale: var(--blue-bg, #E7EEFB);">
            <div class="icon-box"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M3 9h18"></path><path d="M8 3v4"></path><path d="M16 3v4"></path></svg></div>
            <div><div class="label">On Leave / Holiday</div><div class="value"><?= $summary['leave'] + $summary['holiday'] ?></div></div>
        </div>
        <div class="summary-card" style="--accent: var(--purple-text, #5B4E9C); --accent-pale: var(--purple-bg, #EAE7F6);">
            <div class="icon-box"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19h16"></path><path d="M4 15h10"></path><path d="M4 11h16"></path><path d="M4 7h10"></path></svg></div>
            <div><div class="label">Day Off (Rest Day)</div><div class="value"><?= $summary['day_off'] ?></div></div>
        </div>
        <div class="summary-card" style="--accent: var(--ink-soft); --accent-pale: var(--gray-bg, #EFEFEF);">
            <div class="icon-box"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6M12 9v6"></path><circle cx="12" cy="12" r="9" stroke-dasharray="3 3"></circle></svg></div>
            <div><div class="label">No Record</div><div class="value"><?= $no_record_count ?></div></div>
        </div>
    </div>

    <div class="date-nav">
        <a href="?date=<?= $prev_date ?>" class="btn btn-secondary btn-sm">&larr; Prev Day</a>
        <form method="GET" style="display:inline-flex; gap:8px;">
            <input type="date" name="date" class="field-input" value="<?= clean($date) ?>" max="<?= date('Y-m-d') ?>">
            <button type="submit" class="btn btn-secondary btn-sm">Proceed</button>
        </form>
        <a href="?date=<?= $next_date ?>" class="btn btn-secondary btn-sm" <?= $next_date > date('Y-m-d') ? 'style="pointer-events:none; opacity:.4;"' : '' ?>>Next Day &rarr;</a>
        <?php if (!$is_today): ?>
            <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-secondary btn-sm">Today</a>
        <?php else: ?>
            <span class="today-badge">Today</span>
        <?php endif; ?>
        <?php if (count($availableRoleFilters) > 1): ?>
        <form method="GET" style="display:inline-flex; gap:8px; margin-left:auto;">
            <input type="hidden" name="date" value="<?= clean($date) ?>">
            <select name="role" class="field-input" onchange="this.form.submit()">
                <option value="">All Roles</option>
                <?php foreach ($availableRoleFilters as $rf): ?>
                    <option value="<?= clean($rf) ?>" <?= $roleFilter === $rf ? 'selected' : '' ?>><?= clean($rf) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
    </div>

        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Department</th>
                        <th>Shift</th>
                        <th>Time In</th>
                        <th>Time In Location</th>
                        <th>Time Out</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees_display)): ?>
                    <tr><td colspan="10" style="text-align:center; padding:40px; color:var(--ink-soft);"><?= empty($employees) ? 'No active employees found.' : 'No employees match this role filter.' ?></td></tr>
                    <?php else: ?>
                    <?php foreach ($employees_display as $emp):
                        $eid = $emp['employee_id'];
                        $existing = $existingRows[$eid] ?? null;
                        $curTimeIn  = $existing ? substr($existing['time_in'] ?? '', 0, 5) : '';
                        $curTimeOut = $existing ? substr($existing['time_out'] ?? '', 0, 5) : '';
                        $curStatus  = $existing['status'] ?? null;
                        $curLate    = $existing ? (int) $existing['late_minutes'] : 0;
                        $curShift   = $shiftByEmpForDate[$eid] ?? null;
                    ?>
                    <tr>
                        <td><?= clean($emp['employee_code']) ?></td>
                        <td><?= clean($emp['last_name'] . ', ' . $emp['first_name']) ?></td>
                        <td><span class="badge" style="background:var(--green-pale); color:var(--text-accent);"><?= clean(attendanceRoleLabel($emp['acct_role'], $emp['position'] ?? null)) ?></span></td>
                        <td><?= clean($emp['department'] ?: '—') ?></td>
                        <td style="font-size:12.5px;">
                            <?php if ($curShift): ?>
                                <?= clean($curShift['shift_name']) ?><br>
                                <span style="color:var(--ink-soft);"><?= clean(date('h:i A', strtotime($curShift['start_time']))) ?>–<?= clean(date('h:i A', strtotime($curShift['end_time']))) ?></span>
                            <?php elseif (isset($swapsForDate[$eid])): ?>
                                <span style="color:var(--purple-text, #5B4E9C); font-weight:600;">Day Off — Covering (Shift Swap)</span>
                            <?php else: ?>
                                <span style="color:var(--ink-soft);">Day Off</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $curTimeIn !== '' ? clean(date('h:i A', strtotime($curTimeIn))) : '—' ?></td>
                        <td style="max-width:240px;">
                            <?php if ($existing && isset($existing['time_in_latitude']) && $existing['time_in_latitude'] !== null): ?>
                                <?php if (!empty($existing['time_in_address'])): ?>
                                    <span style="font-size:12px;"><?= clean($existing['time_in_address']) ?></span><br>
                                <?php endif; ?>
                                <a href="https://www.google.com/maps?q=<?= urlencode($existing['time_in_latitude'] . ',' . $existing['time_in_longitude']) ?>" target="_blank" rel="noopener" style="font-size:12px; color:var(--green-mid); font-weight:600;">View on Map</a>
                            <?php else: ?>
                                <span style="color:var(--ink-soft); font-size:12.5px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $curTimeOut !== '' ? clean(date('h:i A', strtotime($curTimeOut))) : '—' ?></td>
                        <td>
                            <?php $ds = $dayStatus[$eid] ?? ['key' => 'none', 'label' => 'No Records']; ?>
                            <span class="badge badge-<?= clean($ds['key']) ?>"><?= clean($ds['label']) ?></span>
                            <?php if ($curLate > 0): ?>
                                <span class="badge badge-late"><?= $curLate ?> min late</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($emp['attendance_editable'])): ?>
                            <button type="button" class="btn-edit-att" onclick='openCorrectModal(<?= json_encode([
                                "employee_id" => (int) $eid,
                                "name"        => $emp["last_name"] . ", " . $emp["first_name"],
                                "date"        => $date,
                                "time_in"     => $curTimeIn,
                                "time_out"    => $curTimeOut,
                                "status"      => $curStatus ?: "present",
                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><?= $curTimeIn !== '' && $curTimeOut === '' ? 'Fix Time Out' : 'Edit' ?></button>
                            <?php else: ?>
                                <span style="color:var(--ink-soft); font-size:12px;">View only</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php else: ?>

    <!-- ============================================= -->
    <!-- HISTORY (MONTHLY)                               -->
    <!-- ============================================= -->
    <div class="date-nav">
        <a href="?view=history&amp;month=<?= $prev_month ?>" class="btn btn-secondary btn-sm">&larr; <?= date('M Y', strtotime($prev_month . '-01')) ?></a>
        <form method="GET" style="display:inline-flex; gap:8px;">
            <input type="hidden" name="view" value="history">
            <input type="month" name="month" class="field-input" value="<?= clean($month) ?>" max="<?= date('Y-m') ?>">
            <button type="submit" class="btn btn-secondary btn-sm">Go</button>
        </form>
        <a href="?view=history&amp;month=<?= $next_month ?>" class="btn btn-secondary btn-sm" <?= $next_month > date('Y-m') ? 'style="pointer-events:none; opacity:.4;"' : '' ?>><?= date('M Y', strtotime($next_month . '-01')) ?> &rarr;</a>
        <?php if (!$is_current_month): ?>
            <a href="?view=history&amp;month=<?= date('Y-m') ?>" class="btn btn-secondary btn-sm">This Month</a>
        <?php else: ?>
            <span class="today-badge">This Month</span>
        <?php endif; ?>
    </div>

    <?php if (empty($history_grouped)): ?>
    <div class="table-card">
        <div style="text-align:center; padding:40px; color:var(--ink-soft);">No attendance record found for <?= clean(date('F Y', strtotime($month . '-01'))) ?>.</div>
    </div>
    <?php else: ?>
        <?php $__isFirstDateGroup = true; ?>
        <?php foreach ($history_grouped as $__gDate => $__gRows): ?>
        <details class="history-date-group" <?= $__isFirstDateGroup ? 'open' : '' ?>>
            <summary class="history-date-summary">
                <span class="history-date-label"><?= clean(date('D, M d, Y', strtotime($__gDate))) ?></span>
                <span class="history-date-count"><?= count($__gRows) ?> record<?= count($__gRows) === 1 ? '' : 's' ?></span>
            </summary>
            <div class="table-card" style="margin-top:8px;">
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Time In</th>
                            <th>Time In Location</th>
                            <th>Time Out</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($__gRows as $h): ?>
                    <tr>
                        <td><?= clean($h['employee_code']) ?></td>
                        <td><?= clean($h['last_name'] . ', ' . $h['first_name']) ?></td>
                        <td><?= !empty($h['time_in']) ? clean(date('h:i A', strtotime($h['time_in']))) : '—' ?></td>
                        <td style="max-width:240px;">
                            <?php if (isset($h['time_in_latitude']) && $h['time_in_latitude'] !== null): ?>
                                <?php if (!empty($h['time_in_address'])): ?>
                                    <span style="font-size:12px;"><?= clean($h['time_in_address']) ?></span><br>
                                <?php endif; ?>
                                <a href="https://www.google.com/maps?q=<?= urlencode($h['time_in_latitude'] . ',' . $h['time_in_longitude']) ?>" target="_blank" rel="noopener" style="font-size:12px; color:var(--green-mid); font-weight:600;">View on Map</a>
                            <?php else: ?>
                                <span style="color:var(--ink-soft); font-size:12.5px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= !empty($h['time_out']) ? clean(date('h:i A', strtotime($h['time_out']))) : '—' ?></td>
                        <td>
                            <?php
                                $hLabel = ucfirst($h['status']);
                                if ($h['status'] === 'leave') {
                                    $hLabel = 'On Leave' . (!empty($h['leave_type']) ? ' (' . ucfirst($h['leave_type']) . ')' : '');
                                } elseif ($h['status'] === 'holiday') {
                                    $hLabel = 'Holiday' . (!empty($h['holiday_name']) ? ' (' . $h['holiday_name'] . ')' : '');
                                } elseif ($h['status'] === 'day_off') {
                                    $hLabel = 'Day Off (Rest Day)';
                                }
                            ?>
                            <span class="badge badge-<?= clean($h['status']) ?>"><?= clean($hLabel) ?></span>
                            <?php if ((int) $h['late_minutes'] > 0): ?>
                                <span class="badge badge-late"><?= (int) $h['late_minutes'] ?> min late</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php $__isFirstDateGroup = false; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php endif; // end view toggle ?>
</div>

<?php if ($__showHrSidebar): ?>
</div>
</div>
<?php endif; ?>

<!-- CORRECT ATTENDANCE modal — manual Time In/Time Out edit with a
     required reason, kept as an audit trail (corrected_by /
     correction_reason / corrected_at). Used both for closing an
     "Unfinished" record (employee forgot to Time Out) and for
     general edits to a monitored employee's attendance record.
     Same modal/behavior as supervisor_attendance.php. -->
<div class="modal-overlay" id="correctModal" style="display:none;">
    <div class="modal-card">
        <h2>Correct Attendance</h2>
        <div class="modal-sub" id="correctModalWho"></div>
        <form method="POST">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="correct_attendance">
            <input type="hidden" name="employee_id" id="cmEmployeeId">
            <input type="hidden" name="record_date" id="cmDate">
            <div class="field-row">
                <div class="field-group">
                    <label>Time In</label>
                    <input type="time" name="time_in" id="cmTimeIn">
                </div>
                <div class="field-group">
                    <label>Time Out</label>
                    <input type="time" name="time_out" id="cmTimeOut">
                </div>
            </div>
            <div class="field-group">
                <label>Status</label>
                <select name="status" id="cmStatus">
                    <option value="present">Present</option>
                    <option value="absent">Absent</option>
                    <option value="leave">Leave</option>
                    <option value="holiday">Holiday</option>
                    <option value="day_off">Day Off</option>
                </select>
            </div>
            <div class="field-group">
                <label>Reason for correction (required)</label>
                <textarea name="correction_reason" rows="2" required minlength="3" placeholder="e.g. Employee forgot to time out"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary-modal" onclick="closeCorrectModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Correction</button>
            </div>
        </form>
    </div>
</div>
<script>
function openCorrectModal(data) {
    document.getElementById('cmEmployeeId').value = data.employee_id;
    document.getElementById('cmDate').value        = data.date;
    document.getElementById('cmTimeIn').value       = data.time_in || '';
    document.getElementById('cmTimeOut').value      = data.time_out || '';
    document.getElementById('cmStatus').value       = data.status || 'present';
    document.getElementById('correctModalWho').textContent =
        data.name + ' — ' + data.date;
    document.getElementById('correctModal').style.display = 'flex';
}
function closeCorrectModal() {
    document.getElementById('correctModal').style.display = 'none';
}
</script>

<?php include 'includes/sweetalert.php'; ?>

</body>
</html>