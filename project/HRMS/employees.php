<?php

require_once 'includes/config.php';

require_once 'includes/mailer.php';           // applicant emails

require_once 'includes/application_form.php'; // getFieldLabelMap()/formatCustomResponses() for the Review modal

require_once 'includes/applicant_actions.php'; // shared applicant pipeline handler

requireHR(); // HR Staff + HR Manager + Admin

// Keep old applicant-tab bookmarks working while routing the queue to its
// dedicated Applicant Review section.
if (($_GET['tab'] ?? '') === 'applicants') {
    header('Location: applicant_review.php');
    exit;
}


$success_message = "";

$error_message = "";



// Flash message stored in the session — used after a redirect

// (e.g. after editing an employee, go straight back to the list with

// this message, instead of staying on the Edit form).

if (!empty($_SESSION['employees_flash_success'])) {

    $success_message = $_SESSION['employees_flash_success'];

    unset($_SESSION['employees_flash_success']);

}

if (!empty($_SESSION['employees_flash_error'])) {

    $error_message = $_SESSION['employees_flash_error'];

    unset($_SESSION['employees_flash_error']);

}



// =====================================================

// LIMITS

// =====================================================

define('MAX_SALARY', 999999.99);



// =====================================================

// VALIDATION HELPERS

// =====================================================



// Name: letters, spaces, hyphen, apostrophe, period only (no numbers/symbols)

function isValidName($value)
{

    return preg_match('/^[\p{L}\s.\'-]+$/u', $value) === 1;

}



// ID numbers (SSS, PhilHealth, Pag-IBIG, TIN): digits only, no letters/symbols

function isValidDigits($value, $minLen, $maxLen)
{

    return preg_match('/^\d{' . $minLen . ',' . $maxLen . '}$/', $value) === 1;

}



// Contact number validation now goes through validatePhoneNumber()

// in includes/phone_countries.php (country-aware, exact digit length).



// Minimum age: an employee must be 18 years old or older (minors not allowed)

define('MIN_EMPLOYEE_AGE', 18);



function isOfLegalAge($birthdate, $minAge = MIN_EMPLOYEE_AGE)
{

    try {

        $bd = new DateTime($birthdate);

        $now = new DateTime('today');

        if ($bd > $now) {

            return false; // future date, not valid

        }

        $age = $bd->diff($now)->y;

        return $age >= $minAge;

    } catch (Exception $e) {

        return false;

    }

}



// =====================================================

// HELPER: resolve dropdown value, using the "Other" text when selected

// =====================================================

function resolveDropdownValue($selectKey, $otherKey)
{

    $selected = trim($_POST[$selectKey] ?? '');

    if ($selected === '__other__') {

        return trim($_POST[$otherKey] ?? '');

    }

    return $selected;

}



// Fixed list of common positions (dropdown + "Other")

define('COMMON_POSITIONS', [

    'Cashier',

    'Inventory Staff',

    'Accountant',

    'HR Manager',

    'HR Staff',

]);



// (generateEmployeeCode() now lives in includes/applicant_actions.php.)



// =====================================================

// JOB APPLICANTS — handled by the shared handler, so the logic

// stays the same here and in applicant_review.php.

// =====================================================

// PHP gotcha: kapag lumagpas sa server's post_max_size ang KABUUANG laki
// ng na-submit na request (madaling mangyari dito dahil sinasadyang
// batch/sabay-sabay ang pag-upload ng ilang document nang sabay), tuluyang
// ginagawang WALANG-LAMAN ni PHP ang $_POST at $_FILES — walang action,
// walang CSRF token — kaya tahimik lang itong "walang nangyari" sa halip
// na mag-error nang malinaw. I-detect ito bago pa man tumakbo ang
// dispatcher sa baba. Ported from applicant_review.php now that the
// Requirements upload form lives in this page's Review modal too.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
) {
    $_SESSION['employees_flash_error'] = "The files you selected were too large to upload all together (the total exceeded this server's upload limit). Please select fewer files at a time — or smaller ones — then submit again.";
    redirect('employees.php?tab=applicants');
}

$__aa = processApplicantAction($pdo);

if ($__aa['handled']) {

    // Post/Redirect/Get — without this, refreshing after an applicant
    // action (Advance to Interview, Reject, Set Orientation Date, etc.)
    // would resubmit the same POST and repeat it. Redirect back to the
    // Applicants tab specifically, since that's the only tab these
    // actions come from.
    $_SESSION['employees_flash_success'] = $__aa['success'];
    $_SESSION['employees_flash_error'] = $__aa['error'];
    redirect('employees.php?tab=applicants');

}



// =====================================================

// HANDLE FORM SUBMISSIONS (employees-only actions)

// =====================================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && in_array($_POST['action'] ?? '', ['edit', 'offboard', 'suspend', 'lift_suspension'], true)) {



    requireCSRF();

    $action = $_POST['action'];



    // -------------------------------------------------

    // EDIT EMPLOYEE

    // -------------------------------------------------

    if ($action == 'edit') {



        $employee_id = $_POST['employee_id'];

        $first_name = trim($_POST['first_name']);

        $last_name = trim($_POST['last_name']);

        $middle_name = trim($_POST['middle_name'] ?? '');

        $birthdate = trim($_POST['birthdate'] ?? '');

        $address = trim($_POST['address'] ?? '');

        $contact_country = trim($_POST['contact_number_country'] ?? 'PH');

        $contact_number = trim($_POST['contact_number'] ?? '');

        $phone_error = validatePhoneNumber($contact_country, $contact_number);

        $contact_number = formatPhoneForStorage($contact_country, $contact_number);

        $email = trim($_POST['email'] ?? '');

        $sss_number = trim($_POST['sss_number'] ?? '');

        $philhealth_number = trim($_POST['philhealth_number'] ?? '');

        $pagibig_number = trim($_POST['pagibig_number'] ?? '');

        $tin_number = trim($_POST['tin_number'] ?? '');

        $department = resolveDropdownValue('department', 'department_other');

        $position = resolveDropdownValue('position', 'position_other');

        $employment_type = trim($_POST['employment_type']);

        $schedule_type = trim($_POST['schedule_type'] ?? 'full_time');

        $date_hired = trim($_POST['date_hired']);

        $basic_salary = trim($_POST['basic_salary']);

        $status = trim($_POST['status']);

        $bank_name = trim($_POST['bank_name'] ?? '');

        $bank_account_number = trim($_POST['bank_account_number'] ?? '');

        $bank_account_name = trim($_POST['bank_account_name'] ?? '');

        $payment_method = trim($_POST['payment_method'] ?? 'bank');



        // Get the employee's current status first, before validating.

        // This is needed to know if they're already offboarded (resigned/terminated),

        // because a Resigned/Terminated employee can no longer be revived back to

        // active/on-leave in the Edit form — they must re-apply to re-enter the system.

        $stmtCur = $pdo->prepare("SELECT status, offboarded_at, position, department FROM employees WHERE employee_id = ?");

        $stmtCur->execute([$employee_id]);

        $current = $stmtCur->fetch();

        $wasOffboarded = $current && in_array($current['status'], ['resigned', 'terminated']);

        $isOffboarded = in_array($status, ['resigned', 'terminated']);

        // Setting status to Resigned/Terminated is Admin-only — that's what
        // the dedicated "Offboard" action further below is for ("only admin
        // can offboard, HR can only add/edit"). The general Edit form is
        // NOT the place for that decision, so block it here too (kahit
        // direct POST gamit ang action=edit) — HR Staff/HR Manager can
        // still freely edit everything else, and can still toggle
        // active <-> on-leave, just not push someone straight to
        // resigned/terminated from this form.
        if ($isOffboarded && !$wasOffboarded && ($_SESSION['role'] ?? '') !== 'admin') {
            $error_message = "Only Admin can set an employee's status to Resigned or Terminated. Please use the dedicated \"Offboard\" action instead (Admin access required).";
        } elseif (empty($first_name) || empty($last_name)) {



            $error_message = "First name and last name are required.";

        } elseif (!isValidName($first_name)) {

            $error_message = "First name should contain letters only.";

        } elseif (!isValidName($last_name)) {

            $error_message = "Last name should contain letters only.";

        } elseif (!empty($middle_name) && !isValidName($middle_name)) {

            $error_message = "Middle name should contain letters only.";

        } elseif (!empty($birthdate) && !isOfLegalAge($birthdate)) {

            $error_message = "The employee must be " . MIN_EMPLOYEE_AGE . " years old or older. Setting a minor is not allowed.";

        } elseif ($phone_error !== null) {

            $error_message = $phone_error;

        } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $error_message = "Please enter a valid email address.";

        } elseif (!empty($sss_number) && !isValidDigits($sss_number, 10, 10)) {

            $error_message = "SSS number must be exactly 10 digits.";

        } elseif (!empty($philhealth_number) && !isValidDigits($philhealth_number, 12, 12)) {

            $error_message = "PhilHealth number must be exactly 12 digits.";

        } elseif (!empty($pagibig_number) && !isValidDigits($pagibig_number, 12, 12)) {

            $error_message = "Pag-IBIG number must be exactly 12 digits.";

        } elseif (!empty($tin_number) && !isValidDigits($tin_number, 9, 12)) {

            $error_message = "TIN number must be 9 to 12 digits.";

        } elseif (!in_array($employment_type, ['regular', 'probationary', 'contractual'])) {

            $error_message = "Please select a valid employment type.";

        } elseif (!in_array($schedule_type, ['full_time', 'part_time'])) {

            $error_message = "Please select a valid schedule type.";

        } elseif ($schedule_type === 'part_time' && !isPositionPartTimeEligible($position)) {

            $error_message = "Part-time is only allowed for " . implode(' or ', PART_TIME_ELIGIBLE_POSITIONS) . ". Set the position to one of these first, or choose Full-time.";

        } elseif (empty($date_hired) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_hired)) {

            $error_message = "Please provide a valid date hired.";

        } elseif (!is_numeric($basic_salary) || $basic_salary < 0) {

            $error_message = "Basic salary must be a valid positive number.";

        } elseif ((float) $basic_salary > MAX_SALARY) {

            $error_message = "Basic salary cannot exceed " . peso(MAX_SALARY) . ".";

        } elseif (!in_array($status, ['active', 'resigned', 'terminated', 'on-leave'])) {

            $error_message = "Please select a valid status.";

        } elseif (!in_array($payment_method, ['bank', 'cash'], true)) {

            $error_message = "Please select a valid salary payout method.";

        } elseif ($payment_method === 'bank' && !empty($bank_name) && $bank_name !== 'BDO') {

            $error_message = "BDO is currently the only supported bank for payroll deposit.";

        } elseif ($payment_method === 'bank' && !empty($bank_account_number) && !isValidDigits($bank_account_number, 16, 16)) {

            $error_message = "The BDO account/card number must be exactly 16 digits.";

        } elseif ($wasOffboarded && !$isOffboarded) {

            // A Resigned/Terminated employee can no longer be edited back to

            // Active/On-leave. They must re-apply to create a new record.

            $error_message = "A Resigned or Terminated employee can no longer be edited back to Active/On-leave. They must re-apply.";

        } else {



            // -------------------------------------------------

            // Validate the Weekly Shift Schedule against the

            // employee's schedule_type — a Part-time employee

            // should only be assignable to Part-time shifts, and a

            // Full-time employee only to Full-time shifts (or Day

            // Off, either way). Prevents mismatched shifts being

            // saved via a stale/tampered form submission.

            // -------------------------------------------------

            $weeklyShiftError = null;

            $weeklyShiftInput = [];

            for ($dow = 0; $dow <= 6; $dow++) {

                $shiftVal = trim($_POST['weekly_shift_' . $dow] ?? '');

                $shiftId = ($shiftVal !== '' && ctype_digit($shiftVal)) ? (int) $shiftVal : null;

                $weeklyShiftInput[$dow] = $shiftId;



                if ($shiftId !== null) {

                    $stmtShiftCheck = $pdo->prepare("SELECT shift_type FROM shifts WHERE shift_id = ?");

                    $stmtShiftCheck->execute([$shiftId]);

                    $shiftType = $stmtShiftCheck->fetchColumn();

                    if ($shiftType !== false && $shiftType !== $schedule_type) {

                        $weeklyShiftError = "One of the days in the Weekly Shift Schedule uses a "

                            . ($shiftType === 'part_time' ? 'Part-time' : 'Full-time')

                            . " shift, which doesn't match this employee's Schedule Type ("

                            . ($schedule_type === 'part_time' ? 'Part-time' : 'Full-time')

                            . "). Please update the Weekly Shift Schedule first.";

                        break;

                    }

                }

            }



            if ($weeklyShiftError) {

                $error_message = $weeklyShiftError;

            } else {



                try {

                    // The old status/offboard date was already fetched above (before

                    // validation) — just use it here to determine if it actually

                    // CHANGED to resigned/terminated (new offboard), or nothing

                    // changed (don't touch the offboard date in that case).

                    if ($isOffboarded && !$wasOffboarded) {



                        $offboarded_at = date('Y-m-d');       // newly offboarded now

                    } elseif (!$isOffboarded) {

                        $offboarded_at = null;                 // reverted back to active/on-leave

                    } else {

                        $offboarded_at = $current['offboarded_at']; // unchanged, keep as-is

                    }



                    $stmt = $pdo->prepare("

                    UPDATE employees SET

                        first_name = ?, last_name = ?, middle_name = ?, birthdate = ?, address = ?,

                        contact_number = ?, email = ?, sss_number = ?, philhealth_number = ?,

                        pagibig_number = ?, tin_number = ?, department = ?, position = ?,

                        employment_type = ?, schedule_type = ?, date_hired = ?, basic_salary = ?, status = ?, offboarded_at = ?,

                        bank_name = ?, bank_account_number = ?, bank_account_name = ?, payment_method = ?

                    WHERE employee_id = ?

                ");

                    $stmt->execute([

                        $first_name,

                        $last_name,

                        $middle_name ?: null,

                        $birthdate ?: null,

                        $address ?: null,

                        $contact_number ?: null,

                        $email ?: null,

                        $sss_number ?: null,

                        $philhealth_number ?: null,

                        $pagibig_number ?: null,

                        $tin_number ?: null,

                        $department ?: null,

                        $position ?: null,

                        $employment_type,

                        $schedule_type,

                        $date_hired,

                        $basic_salary,

                        $status,

                        $offboarded_at,

                        $bank_name ?: null,

                        $bank_account_number ?: null,

                        $bank_account_name ?: null,

                        $payment_method,

                        $employee_id

                    ]);



                    // Audit trail: record the position/department change (if any)
                    // so old sales/reports done under the previous position are
                    // never lost — this only ADDS a history row, it never touches
                    // the POS-side sales/stock_logs tables, which stay linked to
                    // the same permanent users.id account regardless of position.
                    logPositionChange(
                        $pdo,
                        $employee_id,
                        $current['position'] ?? null,
                        $position ?: null,
                        $current['department'] ?? null,
                        $department ?: null,
                        $_SESSION['user_id'] ?? null
                    );

                    // Audit trail: who changed this employee's status (and to
                    // what) via the Edit form — only logged when it actually
                    // changed, so routine edits that leave status untouched
                    // don't clutter the log.
                    if ($current && $current['status'] !== $status) {
                        logEmployeeStatusChange(
                            $pdo,
                            $employee_id,
                            'status_change',
                            $current['status'],
                            $status,
                            null,
                            $_SESSION['user_id'] ?? null
                        );
                    }



                    // -------------------------------------------------

                    // WEEKLY SHIFT SCHEDULE (Sun–Sat) — 7 dropdowns from the

                    // form, one per day of the week. "" (empty) means

                    // Day Off/no shift; otherwise it must be a valid

                    // shift_id from the `shifts` catalog (already

                    // type-checked against schedule_type above).

                    // -------------------------------------------------

                    $upsertShift = $pdo->prepare("

                    INSERT INTO employee_weekly_shifts (employee_id, day_of_week, shift_id)

                    VALUES (?, ?, ?)

                    ON DUPLICATE KEY UPDATE shift_id = VALUES(shift_id)

                ");

                    foreach ($weeklyShiftInput as $dow => $shiftId) {

                        $upsertShift->execute([$employee_id, $dow, $shiftId]);

                    }



                    $_SESSION['employees_flash_success'] = "Employee updated successfully.";

                    redirect('employees.php');

                } catch (PDOException $e) {

                    error_log("Edit employee error: " . $e->getMessage());

                    $error_message = "Could not update the employee. Please try again.";

                }

            } // end weeklyShiftError else

        }

    }



    // -------------------------------------------------

    // OFFBOARD EMPLOYEE (soft delete) — the record is not

    // deleted, only the status is changed to 'resigned' or

    // 'terminated' (with the date), so the record still shows

    // up in the list/reports.

    // -------------------------------------------------
    elseif ($action == 'offboard') {



        requireAdmin(); // only admin can offboard, HR can only add/edit



        $employee_id = $_POST['employee_id'];

        $reason = trim($_POST['reason'] ?? '');



        if (!in_array($reason, ['resigned', 'terminated'])) {

            $error_message = "Select a valid reason (Resigned or Terminated).";

        } else {

            try {

                $stmt = $pdo->prepare("SELECT employee_id, status FROM employees WHERE employee_id = ?");

                $stmt->execute([$employee_id]);

                $target = $stmt->fetch();



                if (!$target) {

                    $error_message = "Employee not found.";

                } else {

                    $pdo->prepare("UPDATE employees SET status = ?, offboarded_at = ? WHERE employee_id = ?")

                        ->execute([$reason, date('Y-m-d'), $employee_id]);

                    logEmployeeStatusChange($pdo, $employee_id, 'offboard', $target['status'], $reason, null, $_SESSION['user_id'] ?? null);

                    $success_message = "The employee has been marked as " . ($reason === 'resigned' ? 'Resigned' : 'Terminated') . ". Their record remains in the system for records/reports.";

                }



            } catch (PDOException $e) {

                error_log("Offboard employee error: " . $e->getMessage());

                $error_message = "Could not update this employee. Please try again.";

            }

        }

    } elseif ($action == 'suspend') {

        // HR tier (Staff/Manager/Admin) can suspend — same access level
        // as editing an employee. This is a reversible, temporary block,
        // unlike offboarding (Admin-only, permanent).

        $employee_id = $_POST['employee_id'];

        $suspension_reason = trim($_POST['suspension_reason'] ?? '');

        $suspension_days_raw = trim($_POST['suspension_days'] ?? '');

        // Strict digits-only check — no "-", "+", ".", "e", or letters.
        // (int) casting alone would silently accept junk like "5abc" or
        // "1e3" as 5/1, so the raw string is validated first.
        $suspension_days_valid = ($suspension_days_raw !== '' && preg_match('/^\d+$/', $suspension_days_raw));

        $suspension_days = $suspension_days_valid ? (int) $suspension_days_raw : 0;

        if (!$suspension_days_valid) {

            $error_message = "Number of Days must be a whole number (digits only — no letters or signs).";

        } elseif (empty($suspension_reason)) {

            $error_message = "Please provide a reason for the suspension.";

        } elseif ($suspension_days < 1 || $suspension_days > 365) {

            $error_message = "Suspension duration must be between 1 and 365 days.";

        } else {

            try {

                $stmt = $pdo->prepare("SELECT status FROM employees WHERE employee_id = ?");

                $stmt->execute([$employee_id]);

                $target = $stmt->fetch();

                if (!$target) {

                    $error_message = "Employee not found.";

                } elseif (in_array($target['status'], ['resigned', 'terminated'], true)) {

                    $error_message = "A Resigned or Terminated employee cannot be suspended.";

                } else {

                    $startDate = date('Y-m-d');

                    $endDate = date('Y-m-d', strtotime($startDate . ' +' . ($suspension_days - 1) . ' days'));

                    $pdo->prepare("
                        UPDATE employees
                        SET suspension_start_date = ?, suspension_end_date = ?, suspension_reason = ?
                        WHERE employee_id = ?
                    ")->execute([$startDate, $endDate, $suspension_reason, $employee_id]);

                    logEmployeeStatusChange(
                        $pdo,
                        $employee_id,
                        'suspend',
                        null,
                        null,
                        $suspension_days . ' day' . ($suspension_days == 1 ? '' : 's') . ' — ' . $suspension_reason,
                        $_SESSION['user_id'] ?? null
                    );

                    // Redirect back to the plain list (PRG pattern, same
                    // as the 'edit' action above) instead of just setting
                    // $success_message and falling through to the render
                    // below — the URL here still carries ?suspend=<id>
                    // (the Suspend form has no explicit action= and so
                    // posts back to itself), so without this redirect
                    // $suspend_employee gets re-fetched from that same
                    // $_GET param a few hundred lines down and the modal
                    // pops right back open even though the suspension
                    // already went through. Redirecting drops the query
                    // string, so the popup closes on its own.
                    $_SESSION['employees_flash_success'] = "The employee has been suspended for " . $suspension_days . " day" . ($suspension_days == 1 ? '' : 's') . " (until " . date('M d, Y', strtotime($endDate)) . "). Their account access is blocked for the duration.";
                    redirect('employees.php');

                }

            } catch (PDOException $e) {

                error_log("Suspend employee error: " . $e->getMessage());

                $error_message = "Could not suspend this employee. Please try again.";

            }

        }

    } elseif ($action == 'lift_suspension') {

        $employee_id = $_POST['employee_id'];

        try {

            $pdo->prepare("
                UPDATE employees
                SET suspension_start_date = NULL, suspension_end_date = NULL, suspension_reason = NULL
                WHERE employee_id = ?
            ")->execute([$employee_id]);

            logEmployeeStatusChange($pdo, $employee_id, 'lift_suspension', null, null, null, $_SESSION['user_id'] ?? null);

            $success_message = "The suspension has been lifted. The employee can log in again.";

        } catch (PDOException $e) {

            error_log("Lift suspension error: " . $e->getMessage());

            $error_message = "Could not lift the suspension. Please try again.";

        }

    }



}





// =====================================================

// GET EMPLOYEES LIST

// =====================================================

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : 'active';



$validFilters = ['active', 'all', 'resigned', 'terminated', 'on-leave'];

if (!in_array($status_filter, $validFilters, true)) {

    $status_filter = 'active';

}



$where = [];

$params = [];



if (!empty($search)) {

    $where[] = "(first_name LIKE ? OR last_name LIKE ? OR employee_code LIKE ? OR department LIKE ?)";

    $like = '%' . $search . '%';

    array_push($params, $like, $like, $like, $like);

}



if ($status_filter === 'active') {

    // Default view: hide resigned/terminated (offboarded) employees

    $where[] = "status NOT IN ('resigned', 'terminated')";

} elseif ($status_filter !== 'all') {

    $where[] = "status = ?";

    $params[] = $status_filter;

}

// 'all' = no status condition, show all including offboarded



$sql = "SELECT * FROM employees";

if (!empty($where)) {

    $sql .= " WHERE " . implode(' AND ', $where);

}

$sql .= " ORDER BY employee_id DESC";



$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$employees = $stmt->fetchAll();



// Distinct existing departments (for the Add/Edit Employee dropdown)

$existingDepartments = $pdo->query("

    SELECT DISTINCT department FROM employees

    WHERE department IS NOT NULL AND department <> ''

    ORDER BY department ASC

")->fetchAll(PDO::FETCH_COLUMN);



// Suspend / Offboard modals — same view-only-trigger pattern as
// History above (?suspend=ID / ?offboard=ID opens the modal; the
// actual POST actions are 'suspend' / 'offboard' handled earlier).
$suspend_employee = null;
if (isset($_GET['suspend'])) {
    $stmt = $pdo->prepare("SELECT employee_id, employee_code, first_name, last_name, status FROM employees WHERE employee_id = ?");
    $stmt->execute([dec_id($_GET['suspend'])]);
    $suspend_employee = $stmt->fetch();
}

$offboard_employee = null;
if (isset($_GET['offboard']) && $_SESSION['role'] === 'admin') {
    $stmt = $pdo->prepare("SELECT employee_id, employee_code, first_name, last_name, status FROM employees WHERE employee_id = ?");
    $stmt->execute([dec_id($_GET['offboard'])]);
    $offboard_employee = $stmt->fetch();
}

// Documents modal — reuses the existing employee_documents.php page as-is
// (upload/delete logic, file validation, etc. all stay there untouched)
// loaded inside an iframe, so it now opens as a popup instead of a full
// page navigation. employee_id is re-validated here just to avoid
// showing an empty modal for a bad/deleted id.
$documents_employee = null;
if (isset($_GET['documents'])) {
    $stmt = $pdo->prepare("SELECT employee_id, first_name, last_name FROM employees WHERE employee_id = ?");
    $stmt->execute([dec_id($_GET['documents'])]);
    $documents_employee = $stmt->fetch();
}



$edit_employee = null;

if (isset($_GET['edit'])) {

    $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");

    $stmt->execute([dec_id($_GET['edit'])]);

    $edit_employee = $stmt->fetch();

}



// Separate from Edit — the History modal is view-only and opens on its
// own (?history=ID), so the Edit form stays purely for editing fields.
$history_employee = null;

$history_position_history = [];

$history_status_logs = [];

if (isset($_GET['history'])) {

    $__history_id = dec_id($_GET['history']);

    $stmt = $pdo->prepare("SELECT employee_id, employee_code, first_name, last_name FROM employees WHERE employee_id = ?");

    $stmt->execute([$__history_id]);

    $history_employee = $stmt->fetch();

    if ($history_employee) {

        $stmtHist = $pdo->prepare("
            SELECT ph.old_position, ph.new_position, ph.old_department, ph.new_department,
                   ph.changed_at, u.full_name AS changed_by_name
            FROM position_history ph
            LEFT JOIN users u ON u.id = ph.changed_by
            WHERE ph.employee_id = ?
            ORDER BY ph.changed_at DESC
        ");

        $stmtHist->execute([$__history_id]);

        $history_position_history = $stmtHist->fetchAll();

        // Suspend/lift/offboard/status-change audit trail — who did it and when.
        $stmtStatusLog = $pdo->prepare("
            SELECT sl.action, sl.old_status, sl.new_status, sl.details, sl.logged_at, u.full_name AS performed_by_name
            FROM employee_status_logs sl
            LEFT JOIN users u ON u.id = sl.performed_by
            WHERE sl.employee_id = ?
            ORDER BY sl.logged_at DESC
        ");

        $stmtStatusLog->execute([$__history_id]);

        $history_status_logs = $stmtStatusLog->fetchAll();

    }

}



// Shift catalog (for the Weekly Shift Schedule dropdowns in the Edit

// Employee form) + the current weekly schedule of the employee being

// edited right now (if any). ALL active shifts (both Full-time and

// Part-time) are fetched here — each <option> is tagged with a

// data-type attribute in the HTML below, and JavaScript shows only

// the ones matching the employee's current Schedule Type (so a

// Part-time employee only sees 6-hour shifts, and a Full-time

// employee only sees 8-hour shifts). This keeps everything in one

// page load — no extra request needed when Schedule Type is switched.

$allShiftsActive = getActiveShifts($pdo);

$editWeeklySchedule = $edit_employee ? getEmployeeWeeklySchedule($pdo, $edit_employee['employee_id']) : [];

$DAY_NAMES = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];





// Status badge helper

function getStatusBadge($status)
{

    $map = [

        'active' => ['label' => 'Active', 'bg' => 'var(--green-pale)', 'color' => 'var(--text-accent)'],

        'on-leave' => ['label' => 'On Leave', 'bg' => 'var(--mustard-pale)', 'color' => 'var(--mustard-text2, #97661F)'],

        'resigned' => ['label' => 'Resigned', 'bg' => 'var(--gray-bg, #EFEFEF)', 'color' => 'var(--ink-soft)'],

        'terminated' => ['label' => 'Terminated', 'bg' => 'var(--red-pale)', 'color' => 'var(--red-alert)'],

    ];

    return $map[$status] ?? ['label' => ucfirst($status), 'bg' => 'var(--gray-bg, #EFEFEF)', 'color' => 'var(--ink-soft)'];

}



// =====================================================

// JOB APPLICANTS TAB — data + helper (relocated here)

// =====================================================

$active_tab = (isset($_GET['tab']) && $_GET['tab'] === 'applicants') ? 'applicants' : 'employees';



// The following queries (applicants list, recent decisions) are only

// actually needed when on the Job Applicants tab — they only run

// here so they don't pull extra data (and slow down the reload)

// when just the Employees tab is being viewed.

$active_applicants = [];

$recent_applicant_decisions = [];



// Just a quick count (for the tab-count badge in the TAB NAV) — this is

// the only one that runs even on the Employees tab, since it's just a

// COUNT(*) and doesn't need the full details of each applicant.

$active_applicants_count = (int) $pdo->query("

    SELECT COUNT(*) FROM job_applicants

    WHERE status IN ('for_resume', 'for_initial', 'for_final', 'for_requirements', 'for_admin_approval')

")->fetchColumn();



if ($active_tab === 'applicants') {

    // Auto-disqualify: the deadline has passed but requirements are still incomplete.

    sweepDisqualifiedApplicants($pdo);



    // Active applicants (working queue): every stage before hire/reject.

    // This is the "Applicants list" — stays here until hired or rejected.

    $stmt = $pdo->query("

        SELECT ja.*, ru.full_name AS reviewed_by_name

        FROM job_applicants ja

        LEFT JOIN users ru ON ja.reviewed_by = ru.id

        WHERE ja.status IN ('for_resume', 'for_initial', 'for_final', 'for_requirements', 'for_admin_approval')

        ORDER BY ja.applied_at ASC

    ");

    $active_applicants = $stmt->fetchAll();



    // Recent Decisions: hired/rejected TODAY ONLY. Tomorrow, if there's

    // no newly decided applicant, this list empties out again —

    // it's not meant to be a growing "history".

    $stmt = $pdo->query("

        SELECT ja.*, ru.full_name AS reviewed_by_name, du.full_name AS decided_by_name,

               (SELECT l.reason FROM job_applicant_logs l

                 WHERE l.applicant_id = ja.applicant_id

                   AND l.reason IS NOT NULL AND l.reason <> ''

                 ORDER BY l.log_id DESC LIMIT 1) AS last_reason

        FROM job_applicants ja

        LEFT JOIN users ru ON ja.reviewed_by = ru.id

        LEFT JOIN users du ON ja.decided_by = du.id

        WHERE ja.status IN ('hired', 'rejected', 'disqualified')

          AND DATE(ja.updated_at) = CURDATE()

        ORDER BY ja.updated_at DESC

    ");

    $recent_applicant_decisions = $stmt->fetchAll();

}

// HR Manager: view-only (oversight role) for the applicant pipeline —
// only HR Staff + Admin can advance/reject.
$canActApplicants = isAdminRole() || isHRStaffRole();

// -------------------------------------------------
// VIEW RESUME modal — opened via ?view_resume=<id> so a resume can be
// checked without leaving this page for a separate one. Works for any
// applicant (the "Resume" links throughout this tab all point here).
// -------------------------------------------------
$view_resume_applicant = null;
if (!empty($_GET['view_resume'])) {
    $stmt = $pdo->prepare("SELECT applicant_id, first_name, last_name, position_applied, status, resume_filename, resume_original_name FROM job_applicants WHERE applicant_id = ?");
    $stmt->execute([dec_id($_GET['view_resume'])]);
    $view_resume_applicant = $stmt->fetch();
}

// -------------------------------------------------
// REVIEW modal — opened via ?review=<id>. Ported from applicant_review.php
// so the full applicant profile (details, decision actions, requirements,
// audit trail) opens as a pop-up here instead of a separate page.
// -------------------------------------------------
$review_applicant = null;
$review_logs = [];
$review_extra = [];
$review_badge = null;
$review_isActive = false;
$review_showRequirements = false;
$review_uploadedDocs = [];
$review_missingDocs = [];
$review_canEditDocs = false;

if (!empty($_GET['review'])) {
    $stmt = $pdo->prepare("
        SELECT ja.*, ru.full_name AS reviewed_by_name, du.full_name AS decided_by_name
        FROM job_applicants ja
        LEFT JOIN users ru ON ja.reviewed_by = ru.id
        LEFT JOIN users du ON ja.decided_by  = du.id
        WHERE ja.applicant_id = ?
    ");
    $stmt->execute([dec_id($_GET['review'])]);
    $review_applicant = $stmt->fetch();

    if ($review_applicant) {
        try {
            $stmt = $pdo->prepare("
                SELECT l.*, u.full_name AS performed_by_name
                FROM job_applicant_logs l
                LEFT JOIN users u ON u.id = l.performed_by
                WHERE l.applicant_id = ?
                ORDER BY l.log_id DESC
            ");
            $stmt->execute([$review_applicant['applicant_id']]);
            $review_logs = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Applicant logs error: " . $e->getMessage());
        }

        $review_field_label_map = getFieldLabelMap($pdo);
        $review_extra = formatCustomResponses($review_applicant['custom_responses'] ?? null, $review_field_label_map);

        $review_badge = getApplicantStatusBadge($review_applicant['status']);
        $review_isActive = in_array($review_applicant['status'], ['for_resume', 'for_initial', 'for_final', 'for_requirements', 'for_admin_approval'], true);
        $review_showRequirements = in_array($review_applicant['status'], ['for_requirements', 'for_admin_approval', 'hired', 'disqualified'], true);
        $review_uploadedDocs = getApplicantDocuments($pdo, $review_applicant['applicant_id']);
        $review_missingDocs = applicantMissingDocuments($pdo, $review_applicant['applicant_id']);
        $review_canEditDocs = $canActApplicants && in_array($review_applicant['status'], ['for_requirements', 'for_admin_approval'], true);
        $review_hasSignedContract = $review_applicant['status'] === 'for_admin_approval'
            ? hasSignedApplicantContract($pdo, $review_applicant['applicant_id'])
            : false;
    }
}



function getApplicantStatusBadge($status)
{

    $map = [

        'for_resume' => ['label' => 'Resume Screening', 'bg' => 'var(--mustard-pale)', 'color' => 'var(--mustard-text2, #97661F)'],

        'for_initial' => ['label' => 'Initial Interview', 'bg' => 'var(--mustard-pale)', 'color' => 'var(--mustard-text2, #97661F)'],

        'for_final' => ['label' => 'Final Interview', 'bg' => 'var(--mustard-pale)', 'color' => 'var(--mustard-text2, #97661F)'],

        'for_requirements' => ['label' => 'For Requirements', 'bg' => 'var(--mustard-pale)', 'color' => 'var(--mustard-text2, #97661F)'],

        'for_admin_approval' => ['label' => 'For Final Approval', 'bg' => 'var(--green-pale)', 'color' => 'var(--text-accent)'],

        'hired' => ['label' => 'Hired', 'bg' => 'var(--green-pale)', 'color' => 'var(--text-accent)'],

        'rejected' => ['label' => 'Rejected', 'bg' => 'var(--red-pale)', 'color' => 'var(--red-alert)'],

        'disqualified' => ['label' => 'Disqualified', 'bg' => 'var(--red-pale)', 'color' => 'var(--red-alert)'],

    ];

    return $map[$status] ?? ['label' => ucfirst($status), 'bg' => 'var(--gray-bg, #EFEFEF)', 'color' => 'var(--ink-soft)'];

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Employees — HRMS &amp; Payroll</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        .document-status {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            margin-right: 6px;
        }

        .document-status--complete {
            color: #166534;
            background: #dcfce7;
        }

        .document-status--missing {
            color: #64748b;
            background: #f1f5f9;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }



        :root {

            --green-deep: #123C5C;
            --text-accent: var(--green-deep);

            --green-mid: #1978A8;
            --green-leaf: #4FC3D1;

            --green-pale: #E3F2F6;

            --mustard: #E0A23B;

            --mustard-pale: #FBEFD8;

            --red-alert: #C8472D;

            --red-pale: #FBE7E2;

            --cream: #F3F6F9;

            --white: #FFFFFF;

            --ink: #17222E;

            --ink-soft: #3D4C5C;

            --line: #DCE5EC;

            --font-display: 'Sora', sans-serif;

            --font-body: 'Inter', sans-serif;

        }



        html,
        body {

            min-height: 100vh;

            font-family: var(--font-body);

            color: var(--ink);

            background: var(--cream);

        }



        a {
            text-decoration: none;
            color: inherit;
        }

        /* ── HR sidebar (shared across HR Staff / HR Manager module pages) ── */
        /* ── Sidebar (same look/size as the POS "My Profile" sidebar) ── */
        .app-shell {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 288px;
            flex-shrink: 0;
            background: radial-gradient(420px 260px at 100% 0%, rgba(79, 195, 209, .16), transparent 60%), var(--green-deep);
            color: #FFFFFF;
            display: flex;
            flex-direction: column;
            padding: 30px 24px;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
            box-shadow: 2px 0 12px rgba(0, 0, 0, 0.18);
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 0 4px 26px;
            margin-bottom: 22px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.14);
        }

        .brand-mark {
            width: 46px;
            height: 46px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--white);
            border-radius: 13px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, .14);
        }

        .brand-mark .brand-icon,
        .brand-icon {
            width: 28px;
            height: 28px;
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.35;
        }

        .brand-name {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 17px;
        }

        .brand-tagline {
            font-size: 11px;
            color: var(--green-leaf);
            font-weight: 600;
            letter-spacing: .02em;
        }

        .nav-section-label {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: rgba(255, 255, 255, .42);
            padding: 0 14px;
            margin: 4px 0 10px;
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 13px 15px;
            border-radius: 12px;
            color: rgba(255, 255, 255, 0.82);
            font-size: 14.5px;
            font-weight: 600;
            transition: background 0.15s ease, color 0.15s ease;
        }

        .nav-icon-box {
            width: 30px;
            height: 30px;
            border-radius: 9px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, .08);
        }

        .nav-link.is-active .nav-icon-box {
            background: rgba(255, 255, 255, .35);
        }

        .nav-icon {
            width: 17px;
            height: 17px;
            flex-shrink: 0;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #FFFFFF;
        }

        .nav-link.is-active {
            background: var(--green-leaf);
            color: var(--green-deep);
            box-shadow: 0 4px 12px rgba(0, 0, 0, .16);
        }

        .nav-link--logout {
            margin-top: 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.14);
            padding-top: 18px;
            color: rgba(255, 255, 255, 0.65);
        }

        .nav-link--logout:hover {
            background: rgba(200, 71, 45, 0.18);
            color: var(--salmon-bg, #ffb3a3);
        }

        .sidebar-foot {
            margin-top: auto;
            padding: 14px;
            border-radius: 14px;
            background: rgba(255, 255, 255, .06);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-foot .foot-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            flex-shrink: 0;
            background: linear-gradient(150deg, var(--green-leaf), #2E9BAE);
            color: var(--green-deep);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 15px;
            overflow: hidden;
        }

        .sidebar-foot .foot-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .sidebar-foot .foot-text {
            display: flex;
            flex-direction: column;
            line-height: 1.3;
            min-width: 0;
        }

        .sidebar-foot .foot-name {
            font-size: 13.5px;
            font-weight: 700;
            color: #FFFFFF;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-foot .foot-role {
            font-size: 11.5px;
            color: rgba(255, 255, 255, 0.6);
            font-weight: 600;
        }

        .main-area {
            margin-left: 288px;
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            position: relative;
            z-index: 1;
        }

        @media (max-width: 900px) {
            .sidebar {
                width: 76px;
                padding: 24px 12px;
            }

            .brand-text,
            .nav-link span,
            .sidebar-foot .foot-text {
                display: none;
            }

            .sidebar-brand {
                justify-content: center;
                padding-bottom: 18px;
            }

            .nav-link {
                justify-content: center;
                padding: 12px;
                gap: 0;
            }

            .sidebar-foot {
                justify-content: center;
                padding: 10px;
            }

            .main-area {
                margin-left: 76px;
            }
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

        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--mustard);
            color: #FFFFFF;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
            overflow: hidden;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .topbar a.logout {

            color: var(--red-alert);

            font-weight: 600;

            font-size: 13.5px;

        }



        .container {

            padding: 30px 32px 64px;

        }



        .toolbar {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 12px;

            flex-wrap: wrap;

            margin-bottom: 20px;

        }



        .search-form {

            display: flex;

            gap: 8px;

        }



        .field-input,
        select.field-input {

            padding: 10px 14px;

            border: 1.5px solid var(--line);

            border-radius: 10px;

            font-family: var(--font-body);

            font-size: 14px;

            color: var(--ink);

            background: var(--white);

        }

        .field-input:focus {
            outline: none;
            border-color: var(--green-mid);
        }



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

        .btn-primary {
            background: linear-gradient(135deg, var(--green-mid), var(--green-deep));
            color: #FFFFFF;
            box-shadow: 0 2px 8px rgba(18, 60, 92, 0.22);
        }

        .btn-primary:hover {
            filter: brightness(1.08);
            box-shadow: 0 5px 16px rgba(18, 60, 92, 0.32);
            transform: translateY(-2px);
        }

        .btn-primary:active {
            transform: translateY(0);
            filter: brightness(0.97);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--ink);
            border: 1.5px solid var(--line);
        }

        .btn-secondary:hover {
            background: var(--cream);
            border-color: var(--green-leaf);
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(18, 60, 92, 0.08);
        }

        .btn-secondary:active {
            transform: translateY(0);
            box-shadow: none;
        }

        .btn-danger {
            background: var(--red-pale);
            color: var(--red-alert);
        }

        .btn-danger:hover {
            background: var(--salmon-bg, #f5d3ca);
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(200, 71, 45, 0.16);
        }

        .btn-danger:active {
            transform: translateY(0);
            box-shadow: none;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12.5px;
        }



        .alert {

            padding: 12px 16px;

            border-radius: 12px;

            font-size: 14px;

            font-weight: 500;

            margin-bottom: 20px;

        }

        .alert--success {
            background: var(--green-succ-bg, #e6f4ea);
            color: var(--green-succ-text, #1a7a3b);
        }

        .alert--error {
            background: var(--red-pale);
            color: var(--red-alert);
        }



        .table-card {

            background: var(--white);

            border: 1px solid var(--line);

            border-radius: 16px;

            overflow: hidden;

        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

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

        td {

            padding: 14px 16px;

            font-size: 14px;

            border-bottom: 1px solid var(--line);

        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: var(--gray-bg, #FAFAF7);
        }



        .badge {

            display: inline-block;

            padding: 4px 10px;

            border-radius: 999px;

            font-size: 12px;

            font-weight: 600;

        }



        .actions-cell {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }



        .empty-state {

            text-align: center;

            padding: 48px 24px;

            color: var(--ink-soft);

        }



        /* Tab navigation: Employees | Job Applicants */

        .tab-nav {

            display: flex;

            gap: 6px;

            margin-bottom: 20px;

            border-bottom: 1.5px solid var(--line);

        }

        .tab-link {

            padding: 10px 18px;

            font-size: 14px;

            font-weight: 600;

            color: var(--ink-soft);

            border-bottom: 2.5px solid transparent;

            margin-bottom: -1.5px;

            display: inline-flex;

            align-items: center;

            gap: 8px;

        }

        .tab-link:hover {
            color: var(--green-mid);
        }

        .tab-link.active {
            color: var(--text-accent);
            border-bottom-color: var(--text-accent);
        }

        .tab-count {

            background: var(--mustard);

            color: #FFFFFF;

            font-size: 11.5px;

            font-weight: 700;

            padding: 1px 8px;

            border-radius: 999px;

        }



        /* Section headers inside the Job Applicants tab */

        .section-head {
            margin-bottom: 14px;
        }

        .section-head h2 {

            font-family: var(--font-display);

            font-size: 18px;

            color: var(--text-accent);

        }

        .section-sub {

            font-size: 13px;

            color: var(--ink-soft);

            margin-top: 4px;

            max-width: 760px;

        }



        /* Modal */

        .modal-overlay {

            position: fixed;

            inset: 0;

            background: rgba(18, 60, 92, .25);

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 24px;

            z-index: 100;

        }

        .modal-card {

            background: var(--white);

            border-radius: 20px;

            padding: 32px;

            max-width: 640px;

            width: 100%;

            max-height: 88vh;

            overflow-y: auto;

        }

        .modal-card h2 {

            font-family: var(--font-display);

            color: var(--text-accent);

            font-size: 20px;

            margin-bottom: 20px;

        }

        .form-grid {

            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 14px;

        }

        .form-grid .full {
            grid-column: 1 / -1;
        }

        .weekly-shift-grid {

            display: grid;

            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));

            gap: 12px;

        }

        .field-group label {

            display: block;

            font-size: 12.5px;

            font-weight: 600;

            margin-bottom: 6px;

            color: var(--ink);

        }

        .field-group input,
        .field-group select {

            width: 100%;

            padding: 10px 12px;

            border: 1.5px solid var(--line);

            border-radius: 10px;

            font-family: var(--font-body);

            font-size: 13.5px;

        }

        .position-history {
            margin-top: 10px;
            background: var(--cream);
            border: 1.5px solid var(--line);
            border-radius: 12px;
            overflow: hidden;
        }

        .position-history-head {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            background: var(--green-pale);
            color: var(--text-accent);
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 12.5px;
            letter-spacing: .01em;
        }

        .ph-list {
            max-height: 160px;
            overflow-y: auto;
            padding: 10px 12px 4px;
        }

        .ph-item {
            position: relative;
            padding-left: 16px;
            padding-bottom: 12px;
            border-left: 2px solid var(--line);
            margin-left: 3px;
        }

        .ph-item:last-child {
            border-left-color: transparent;
            padding-bottom: 2px;
        }

        .ph-dot {
            position: absolute;
            left: -6px;
            top: 3px;
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--green-mid);
            box-shadow: 0 0 0 3px var(--cream);
        }

        .ph-change {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--ink);
        }

        .ph-arrow {
            color: var(--green-mid);
            margin: 0 4px;
        }

        .ph-dept-badge {
            display: inline-block;
            margin-top: 4px;
            padding: 2px 8px;
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 20px;
            font-size: 11px;
            color: var(--ink-soft);
        }

        .ph-meta {
            margin-top: 4px;
            font-size: 11px;
            color: var(--ink-soft);
        }

        .modal-footer {

            display: flex;

            justify-content: flex-end;

            gap: 10px;

            margin-top: 22px;

        }

        /* ---- Ported from applicant_review.php for the Review modal ---- */
        .review-card {
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 22px 24px;
            margin-bottom: 16px;
        }

        .review-card h3 {
            font-size: 15px;
            color: var(--text-accent);
            margin-bottom: 12px;
            font-family: var(--font-display);
        }

        .review-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 20px;
        }

        .review-item .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--ink-soft);
        }

        .review-item .value {
            font-size: 13.5px;
            font-weight: 600;
            margin-top: 2px;
        }

        .review-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            border-top: 1px solid var(--line);
            padding-top: 16px;
            margin-top: 2px;
        }

        .review-date-input {
            padding: 8px 10px;
            border: 1.5px solid var(--line);
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
        }

        .review-log {
            border-left: 3px solid var(--line);
            padding: 8px 0 8px 14px;
            margin-bottom: 10px;
        }

        .review-log .head {
            font-size: 13px;
            font-weight: 600;
        }

        .review-log .meta {
            font-size: 11.5px;
            color: var(--ink-soft);
        }

        .review-log .reason {
            font-size: 13px;
            margin-top: 4px;
        }



        @media (max-width: 640px) {

            .form-grid {
                grid-template-columns: 1fr;
            }

            .container {
                padding: 20px 16px;
            }

            .topbar {
                padding: 14px 16px;
                flex-wrap: wrap;
                gap: 8px;
            }

        }
    </style>

</head>

<body>

    <?php $__showHrSidebar = in_array($_SESSION['role'] ?? '', ['hr', 'hr_manager', 'admin'], true); ?>
    <?php if ($__showHrSidebar): ?>
        <div class="app-shell">
            <?php $activeNav = 'employees';
            include 'includes/hr_sidebar.php'; ?>
            <div class="main-area">
            <?php endif; ?>

            <div class="topbar">

                <h1>HRMS &amp; Payroll — Employees</h1>

                <div class="user-info">

                    <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img
                                src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>"
                                alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span>
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



                <!-- TAB NAV: Employees -->

                <div class="tab-nav">

                    <a href="employees.php"
                        class="tab-link <?= $active_tab === 'employees' ? 'active' : '' ?>">Employees</a>


                </div>



                <?php if ($active_tab === 'employees'): ?>

                    <div class="toolbar" style="justify-content:space-between;">

                        <form class="search-form" method="GET">

                            <input type="text" name="search" class="field-input"
                                placeholder="Search name, code, department..." value="<?= clean($search) ?>">

                            <select name="status_filter" class="field-input">

                                <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Active Employees
                                </option>

                                <option value="on-leave" <?= $status_filter == 'on-leave' ? 'selected' : '' ?>>On-leave
                                </option>

                                <option value="resigned" <?= $status_filter == 'resigned' ? 'selected' : '' ?>>Resigned
                                </option>

                                <option value="terminated" <?= $status_filter == 'terminated' ? 'selected' : '' ?>>Terminated
                                </option>

                                <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Employees</option>

                            </select>

                            <button type="submit" class="btn btn-secondary">Search</button>

                        </form>

                    </div>



                    <div class="table-card">

                        <table>

                            <thead>

                                <tr>

                                    <th>Code</th>

                                    <th>Name</th>

                                    <th>Position</th>

                                    <th>Type</th>

                                    <th>Schedule</th>

                                    <th style="white-space:nowrap;">Date Hired</th>

                                    <th>Salary</th>

                                    <th>Status</th>

                                    <th>Actions</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php if (empty($employees)): ?>

                                    <tr>
                                        <td colspan="9">
                                            <div class="empty-state">No employee records found.</div>
                                        </td>
                                    </tr>

                                <?php else: ?>

                                    <?php foreach ($employees as $emp):
                                        $badge = getStatusBadge($emp['status']);
                                        $rowSusp = getEmployeeSuspensionStatus($emp['suspension_start_date'], $emp['suspension_end_date']); ?>

                                        <tr>

                                            <td><?= clean($emp['employee_code']) ?></td>

                                            <td><?= clean($emp['last_name'] . ', ' . $emp['first_name']) ?></td>

                                            <td><?= clean($emp['position'] ?: '—') ?></td>

                                            <td><?= clean(ucfirst($emp['employment_type'])) ?></td>

                                            <td>

                                                <?php $isPartTime = ($emp['schedule_type'] ?? 'full_time') === 'part_time'; ?>

                                                <span class="badge"
                                                    style="background:<?= $isPartTime ? 'var(--gray-bg, #FDF1D6)' : 'var(--green-succ-bg, #E4F3E9)' ?>; color:<?= $isPartTime ? 'var(--mustard-text2, #8A5A00)' : 'var(--green-succ-text, #1F7A4D)' ?>;">

                                                    <?= $isPartTime ? 'Part-time · ' . PART_TIME_SHIFT_HOURS . 'h' : 'Full-time · ' . STANDARD_SHIFT_HOURS . 'h' ?>

                                                </span>

                                            </td>

                                            <td style="white-space:nowrap;">
                                                <?= clean(date('M d, Y', strtotime($emp['date_hired']))) ?>
                                            </td>

                                            <td><?= peso($emp['basic_salary']) ?></td>

                                            <td>
                                                <span class="badge"
                                                    style="background:<?= $badge['bg'] ?>; color:<?= $badge['color'] ?>;"><?= $badge['label'] ?></span>
                                                <?php if ($rowSusp['active']): ?>
                                                    <br>
                                                    <span class="badge"
                                                        style="background:var(--red-pale); color:var(--red-alert); margin-top:4px; display:inline-block;"
                                                        title="<?= clean($emp['suspension_reason']) ?>">
                                                        Suspended · <?= $rowSusp['days_remaining'] ?>
                                                        day<?= $rowSusp['days_remaining'] == 1 ? '' : 's' ?> left
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="actions-cell">

                                                <a href="?edit=<?= enc_id($emp['employee_id']) ?>"
                                                    class="btn btn-secondary btn-sm">Edit</a>

                                                <a href="?history=<?= enc_id($emp['employee_id']) ?>"
                                                    class="btn btn-secondary btn-sm">History</a>

                                                <a href="?documents=<?= enc_id($emp['employee_id']) ?>"
                                                    class="btn btn-secondary btn-sm">Documents</a>

                                                <?php if ($_SESSION['role'] == 'admin' && !in_array($emp['status'], ['resigned', 'terminated'])): ?>

                                                    <a href="?offboard=<?= enc_id($emp['employee_id']) ?>"
                                                        class="btn btn-danger btn-sm">Offboard</a>

                                                <?php endif; ?>

                                                <?php if (in_array($emp['status'], ['resigned', 'terminated']) && !empty($emp['offboarded_at'])): ?>

                                                    <span style="font-size:11.5px; color:var(--ink-soft);">Offboarded:
                                                        <?= clean(date('M d, Y', strtotime($emp['offboarded_at']))) ?></span>

                                                <?php elseif ($rowSusp['active']): ?>

                                                    <form method="POST"
                                                        data-confirm="Lift this employee's suspension now? They will be able to log in again immediately."
                                                        data-confirm-title="Lift Suspension" style="display:inline-flex;">

                                                        <?php csrfField(); ?>

                                                        <input type="hidden" name="action" value="lift_suspension">

                                                        <input type="hidden" name="employee_id" value="<?= $emp['employee_id'] ?>">

                                                        <button type="submit" class="btn btn-secondary btn-sm">Lift Suspension</button>

                                                    </form>

                                                <?php elseif (!in_array($emp['status'], ['resigned', 'terminated'])): ?>

                                                    <a href="?suspend=<?= enc_id($emp['employee_id']) ?>"
                                                        class="btn btn-danger btn-sm">Suspend</a>

                                                <?php endif; ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; // end Employees tab ?>



                <?php if ($active_tab === 'applicants'): ?>

                    <!-- ================= JOB APPLICANTS TAB ================= -->

                    <!-- Pipeline: Resume -> Initial -> Final -> (Admin) Hire + Orientation -->

                    <div class="section-head">

                        <h2>Active Applicants</h2>

                        <p class="section-sub">Pipeline: <strong>Resume Screening → Initial Interview → Final Interview →
                                (Admin) Hire</p>

                    </div>

                    <?php if ($canActApplicants): ?>
                        <!--
        Resume Screening batch toolbar. Review resumes first (View Resume
        opens a pop-up, no separate page needed), then filter out who
        moves on to the Initial Interview — check one box and submit for
        a single applicant, or check several for a batch, all sharing the
        one interview date below. The checkboxes live inside the table
        rows further down and are tied to this form via the form="..."
        attribute (same trick used for the requirements batch-upload form
        in applicant_review.php), so nesting isn't an issue.
    -->
                        <form method="POST" id="resume-batch-form"
                            style="background:var(--white); border:1px solid var(--line); border-radius:14px; padding:14px 18px; margin-bottom:14px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;"
                            data-confirm="Advance the selected applicant(s) from Resume Screening to the Initial Interview? The schedule will be emailed to each of them."
                            data-confirm-title="Advance to Initial Interview" data-confirm-icon="question"
                            data-confirm-ok="Yes, advance">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="advance_to_interview">
                            <span style="font-size:13px; font-weight:600; color:var(--text-accent);">Resume Screening:</span>
                            <span id="resumeSelectedCount" style="font-size:12.5px; color:var(--ink-soft);">0 selected</span>
                            <label style="font-size:13px; color:var(--ink-soft);">Initial Interview date:</label>
                            <input type="date" name="interview_date" class="date-input" required min="<?= date('Y-m-d') ?>"
                                style="padding:8px 10px; border:1.5px solid var(--line); border-radius:8px; font-size:13px; font-family:inherit;">
                            <button type="button" id="resumeBatchSubmit" class="btn btn-primary btn-sm" disabled>Advance
                                Selected to Initial Interview</button>
                        </form>
                        <script>
                            (function () {
                                // This button is type="button" (not "submit") on purpose: this
                                // form's checkboxes live outside it (associated via the table
                                // rows' form="resume-batch-form" attribute), and in practice the
                                // browser's implicit "submit on click" default action wasn't
                                // firing reliably for that setup. requestSubmit() does the same
                                // job explicitly — runs native validation, and if valid, fires
                                // the real 'submit' event (which includes.php's shared
                                // data-confirm handler picks up), exactly like a normal submit
                                // click would, minus the unreliable implicit part.
                                var form = document.getElementById('resume-batch-form');
                                var btn = document.getElementById('resumeBatchSubmit');
                                if (!form || !btn) return;
                                btn.addEventListener('click', function () {
                                    if (btn.disabled || form.dataset.submitting === '1') return;
                                    if (typeof form.requestSubmit === 'function') {
                                        // NOTE: do NOT set form.dataset.submitting = '1' here.
                                        // includes/sweetalert.php's shared document-level 'submit'
                                        // listener checks that exact flag first thing and bails
                                        // out (preventDefault + return) if it's already '1' — it
                                        // uses it to block accidental double-submits, not to be
                                        // pre-armed by the caller. Setting it before
                                        // requestSubmit() made sweetalert.php think this click
                                        // WAS the double-submit, so it silently swallowed every
                                        // click: no confirm popup, no submission, and the button
                                        // never got re-enabled. Just disable the button for
                                        // instant feedback; sweetalert.php's own lockForm()/
                                        // unlockForm() (and the MutationObserver below) own
                                        // data-submitting from here on.
                                        btn.disabled = true;
                                        form.requestSubmit();
                                    } else {
                                        // very old browser fallback
                                        if (form.reportValidity()) form.submit();
                                    }
                                });
                                // If native validation blocks the requestSubmit() above (e.g. an
                                // empty/invalid date), the shared 'invalid' handler in
                                // sweetalert.php re-enables type="submit" buttons only — this
                                // button is type="button", so re-enable it here too or it'd stay
                                // stuck disabled.
                                form.addEventListener('invalid', function () {
                                    form.dataset.submitting = '0';
                                    btn.disabled = false;
                                }, true);
                                // Also mirror the shared submitting flag in general (covers the
                                // "user cancelled the confirm dialog" path, where sweetalert.php's
                                // unlockForm() resets data-submitting but only re-enables
                                // type="submit" buttons directly).
                                new MutationObserver(function () {
                                    if (form.dataset.submitting !== '1' && btn.disabled) {
                                        var stillSelected = form.querySelectorAll('.resume-select-checkbox:checked').length > 0;
                                        btn.disabled = !stillSelected;
                                    }
                                }).observe(form, { attributes: true, attributeFilter: ['data-submitting'] });
                            })();
                        </script>
                    <?php endif; ?>

                    <div class="table-card">

                        <table>

                            <thead>

                                <tr>

                                    <?php if ($canActApplicants): ?>
                                        <th style="width:28px;"><input type="checkbox" id="selectAllApplicants"
                                                title="Select all (Resume Screening)"></th><?php endif; ?>

                                    <th>Applicant</th>

                                    <th>Position Applied</th>

                                    <th style="white-space:nowrap;">Applied</th>

                                    <th>Resume</th>

                                    <th>Status</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php if (empty($active_applicants)): ?>

                                    <tr>
                                        <td colspan="<?= $canActApplicants ? 6 : 5 ?>">
                                            <div class="empty-state">There are currently no active applicants.</div>
                                        </td>
                                    </tr>

                                <?php else: ?>

                                    <?php foreach ($active_applicants as $ap):
                                        $abadge = getApplicantStatusBadge($ap['status']); ?>

                                        <tr>

                                            <?php if ($canActApplicants): ?>
                                                <td>
                                                    <?php if ($ap['status'] === 'for_resume'): ?>
                                                        <input type="checkbox" name="applicant_ids[]"
                                                            value="<?= (int) $ap['applicant_id'] ?>" form="resume-batch-form"
                                                            class="resume-select-checkbox">
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>

                                            <td>

                                                <?= clean($ap['last_name'] . ', ' . $ap['first_name']) ?>

                                                <div style="font-size:12px; color:var(--ink-soft);"><?= clean($ap['email']) ?></div>

                                            </td>

                                            <td><?= clean($ap['position_applied'] ?: '—') ?></td>

                                            <td style="white-space:nowrap;">
                                                <?= clean(date('M d, Y', strtotime($ap['applied_at']))) ?>
                                            </td>

                                            <td>
                                                <a href="?tab=applicants&view_resume=<?= enc_id($ap['applicant_id']) ?>"
                                                    class="btn btn-secondary btn-sm">View Resume</a>
                                                <a href="?tab=applicants&review=<?= enc_id($ap['applicant_id']) ?>"
                                                    class="btn btn-secondary btn-sm">Review</a>
                                            </td>

                                            <td>

                                                <span class="badge"
                                                    style="background:<?= $abadge['bg'] ?>; color:<?= $abadge['color'] ?>;"><?= $abadge['label'] ?></span>

                                                <?php if ($ap['status'] === 'for_initial' && !empty($ap['initial_interview_date'])): ?>

                                                    <div style="font-size:11.5px; color:var(--ink-soft); margin-top:4px;">Initial:
                                                        <?= clean(date('M d, Y', strtotime($ap['initial_interview_date']))) ?>
                                                    </div>

                                                <?php elseif ($ap['status'] === 'for_final' && !empty($ap['final_interview_date'])): ?>

                                                    <div style="font-size:11.5px; color:var(--ink-soft); margin-top:4px;">Final:
                                                        <?= clean(date('M d, Y', strtotime($ap['final_interview_date']))) ?>
                                                    </div>

                                                <?php elseif ($ap['status'] === 'for_requirements' && !empty($ap['requirements_deadline'])): ?>

                                                    <div style="font-size:11.5px; color:var(--red-alert); margin-top:4px;">Deadline:
                                                        <?= clean(date('M d, Y', strtotime($ap['requirements_deadline']))) ?>
                                                    </div>

                                                <?php endif; ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>



                    <!-- Recent Decisions: hired/rejected. Hired -> orientation date can still be set. -->

                    <div class="section-head" style="margin-top:32px;">

                        <h2>Recent Decisions</h2>

                        <p class="section-sub">Processed Applicant Records </p>

                    </div>

                    <div class="table-card">

                        <table>

                            <thead>

                                <tr>

                                    <th>Applicant</th>

                                    <th>Position Applied</th>

                                    <th>Status</th>

                                    <th>Reason / Notes</th>

                                    <th>Orientation</th>

                                    <th>Resume</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php if (empty($recent_applicant_decisions)): ?>

                                    <tr>
                                        <td colspan="6">
                                            <div class="empty-state">No applicants have been processed yet.</div>
                                        </td>
                                    </tr>

                                <?php else: ?>

                                    <?php foreach ($recent_applicant_decisions as $ap):
                                        $abadge = getApplicantStatusBadge($ap['status']); ?>

                                        <tr>

                                            <td>

                                                <?= clean($ap['last_name'] . ', ' . $ap['first_name']) ?>

                                                <div style="font-size:12px; color:var(--ink-soft);"><?= clean($ap['email']) ?></div>

                                            </td>

                                            <td><?= clean($ap['position_applied'] ?: '—') ?></td>

                                            <td><span class="badge"
                                                    style="background:<?= $abadge['bg'] ?>; color:<?= $abadge['color'] ?>;"><?= $abadge['label'] ?></span>
                                            </td>

                                            <td style="max-width:260px;">

                                                <?php if (!empty($ap['last_reason'])): ?>

                                                    <span
                                                        style="font-size:12.5px; color:var(--ink-soft);"><?= nl2br(clean($ap['last_reason'])) ?></span>

                                                <?php else: ?>

                                                    <span style="color:var(--ink-soft);">—</span>

                                                <?php endif; ?>

                                            </td>

                                            <td>

                                                <?php if ($ap['status'] === 'hired'): ?>

                                                    <?php if (!empty($ap['orientation_date'])): ?>

                                                        <strong><?= clean(date('M d, Y', strtotime($ap['orientation_date']))) ?></strong>

                                                        <div style="font-size:11px; color:var(--ink-soft);">🔒 Already set (cannot be
                                                            changed)</div>

                                                    <?php elseif (isAdminRole()): ?>

                                                        <form method="POST"
                                                            style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;"
                                                            data-confirm="Set the orientation date? This cannot be changed afterward."
                                                            data-confirm-title="Set Orientation Date"
                                                            data-confirm-ok="Set Orientation Date">

                                                            <?php csrfField(); ?>

                                                            <input type="hidden" name="action" value="set_orientation_date">

                                                            <input type="hidden" name="applicant_id" value="<?= $ap['applicant_id'] ?>">

                                                            <input type="date" name="orientation_date" required
                                                                style="padding:6px 8px; border:1.5px solid var(--line); border-radius:8px; font-size:12.5px; font-family:var(--font-body);">

                                                            <button type="submit" class="btn btn-secondary btn-sm">Set</button>

                                                        </form>

                                                    <?php else: ?>

                                                        <span style="color:var(--ink-soft); font-size:12px;">Only Admin can set this</span>

                                                    <?php endif; ?>

                                                <?php else: ?>

                                                    <span style="color:var(--ink-soft);">—</span>

                                                <?php endif; ?>

                                            </td>

                                            <td><a href="?tab=applicants&view_resume=<?= enc_id($ap['applicant_id']) ?>"
                                                    class="btn btn-secondary btn-sm">View Resume</a></td>

                                        </tr>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; // end Job Applicants tab ?>

            </div>



            <?php if ($view_resume_applicant): ?>
                <div class="modal-overlay">
                    <div class="modal-card" style="max-width:720px;">
                        <h2>Resume —
                            <?= clean($view_resume_applicant['first_name'] . ' ' . $view_resume_applicant['last_name']) ?>
                        </h2>
                        <p style="font-size:13px; color:var(--ink-soft); margin-top:-12px; margin-bottom:16px;">
                            <?= clean($view_resume_applicant['position_applied'] ?: 'Position not specified') ?>
                            &middot; <?= clean($view_resume_applicant['resume_original_name']) ?>
                        </p>

                        <?php
                        $__ext = strtolower(pathinfo($view_resume_applicant['resume_filename'] ?? '', PATHINFO_EXTENSION));
                        $__resumeUrl = 'resume_download.php?id=' . enc_id($view_resume_applicant['applicant_id']);
                        ?>
                        <?php if ($__ext === 'pdf'): ?>
                            <iframe src="<?= $__resumeUrl ?>"
                                style="width:100%; height:60vh; border:1.5px solid var(--line); border-radius:12px;"></iframe>
                        <?php else: ?>
                            <div
                                style="background:var(--cream); border:1.5px solid var(--line); border-radius:12px; padding:24px; text-align:center; font-size:13.5px; color:var(--ink-soft);">
                                Preview isn't available for this file type (<?= clean(strtoupper($__ext ?: 'file')) ?>) — open
                                it instead.
                            </div>
                        <?php endif; ?>

                        <div style="margin-top:14px;">
                            <a href="<?= htmlspecialchars($__resumeUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank"
                                rel="noopener" class="btn btn-secondary btn-sm">Open in New Tab</a>
                        </div>

                        <?php if ($view_resume_applicant['status'] === 'for_resume' && $canActApplicants): ?>
                            <div
                                style="border-top:1px solid var(--line); margin-top:18px; padding-top:18px; display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                                <form method="POST" style="display:inline-flex; gap:8px; align-items:center; flex-wrap:wrap;"
                                    data-confirm="Advance this applicant to the Initial Interview? The schedule will be emailed to them."
                                    data-confirm-title="Advance to Initial Interview" data-confirm-icon="question"
                                    data-confirm-ok="Yes, advance">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="advance_to_interview">
                                    <input type="hidden" name="applicant_ids[]"
                                        value="<?= (int) $view_resume_applicant['applicant_id'] ?>">
                                    <label style="font-size:13px; color:var(--ink-soft);">Initial Interview:</label>
                                    <input type="date" name="interview_date" required min="<?= date('Y-m-d') ?>"
                                        style="padding:8px 10px; border:1.5px solid var(--line); border-radius:8px; font-size:13px; font-family:inherit;">
                                    <button type="submit" class="btn btn-primary btn-sm">Advance to Interview</button>
                                </form>

                                <form method="POST" style="display:inline;"
                                    data-confirm="Why is this applicant being rejected? The reason will be included in the email sent to them."
                                    data-confirm-title="Reject Applicant" data-confirm-danger="1" data-confirm-ok="Yes, reject"
                                    data-reason="required">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="reject_applicant">
                                    <input type="hidden" name="applicant_id"
                                        value="<?= (int) $view_resume_applicant['applicant_id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                                </form>
                            </div>
                        <?php elseif (!$canActApplicants): ?>
                            <p style="font-size:12.5px; color:var(--ink-soft); margin-top:16px;">View-only (HR Manager oversight
                                role).</p>
                        <?php endif; ?>

                        <div class="modal-footer">
                            <a href="?tab=applicants" class="btn btn-secondary">Close</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($review_applicant): ?>
                <div class="modal-overlay">
                    <div class="modal-card" style="max-width:760px;">
                        <h2><?= clean($review_applicant['first_name'] . ' ' . $review_applicant['last_name']) ?>
                            <span class="badge"
                                style="background:<?= $review_badge['bg'] ?>; color:<?= $review_badge['color'] ?>; margin-left:8px;"><?= $review_badge['label'] ?></span>
                        </h2>

                        <!-- ================= APPLICANT DETAILS ================= -->
                        <div class="review-card">
                            <h3>Applicant Details</h3>
                            <div class="review-grid">
                                <div class="review-item">
                                    <div class="label">Position Applied</div>
                                    <div class="value"><?= clean($review_applicant['position_applied'] ?: '—') ?></div>
                                </div>
                                <div class="review-item">
                                    <div class="label">Applied</div>
                                    <div class="value">
                                        <?= clean(date('M d, Y', strtotime($review_applicant['applied_at']))) ?>
                                    </div>
                                </div>
                                <div class="review-item">
                                    <div class="label">Email</div>
                                    <div class="value"><?= clean($review_applicant['email']) ?></div>
                                </div>
                                <div class="review-item">
                                    <div class="label">Contact Number</div>
                                    <div class="value"><?= clean($review_applicant['contact_number'] ?: '—') ?></div>
                                </div>
                                <div class="review-item">
                                    <div class="label">Initial Interview</div>
                                    <div class="value">
                                        <?= !empty($review_applicant['initial_interview_date']) ? clean(date('M d, Y', strtotime($review_applicant['initial_interview_date']))) : '—' ?>
                                    </div>
                                </div>
                                <div class="review-item">
                                    <div class="label">Final Interview</div>
                                    <div class="value">
                                        <?= !empty($review_applicant['final_interview_date']) ? clean(date('M d, Y', strtotime($review_applicant['final_interview_date']))) : '—' ?>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($review_applicant['cover_note'])): ?>
                                <div style="margin-top:16px;">
                                    <div class="review-item">
                                        <div class="label">Cover Note</div>
                                    </div>
                                    <div style="font-size:13.5px; margin-top:6px; white-space:pre-wrap;">
                                        <?= clean($review_applicant['cover_note']) ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($review_extra)): ?>
                                <div style="margin-top:16px;">
                                    <div class="review-item">
                                        <div class="label">Additional Info</div>
                                    </div>
                                    <div style="margin-top:6px; font-size:13.5px;">
                                        <?php foreach ($review_extra as $item): ?>
                                            <div><strong><?= clean($item['label']) ?>:</strong> <?= clean($item['value']) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div style="margin-top:16px;">
                                <a href="?tab=applicants&view_resume=<?= enc_id($review_applicant['applicant_id']) ?>"
                                    class="btn btn-secondary btn-sm">View Resume</a>
                            </div>
                        </div>

                        <!-- ================= DECISION ================= -->
                        <div class="review-card">
                            <h3>Decision</h3>

                            <?php if (!$review_isActive): ?>
                                <p style="font-size:13.5px; color:var(--ink-soft);">
                                    This applicant has already been processed (<strong><?= $review_badge['label'] ?></strong>).
                                    No further action can be taken here.
                                </p>
                            <?php elseif ($review_applicant['status'] === 'for_resume'): ?>
                                <p style="font-size:13.5px; color:var(--ink-soft);">
                                    Resume Screening is filtered from the applicant list itself — use <strong>View
                                        Resume</strong> above (or the checkboxes in the table) to advance this applicant to the
                                    Initial Interview, one at a time or by batch. Reject is still available below.
                                </p>
                            <?php elseif (!$canActApplicants): ?>
                                <p style="font-size:13.5px; color:var(--ink-soft);">
                                    View-only (HR Manager oversight role). HR Staff or Admin can Pass/Reject this applicant.
                                </p>
                            <?php else: ?>
                                <div class="review-actions">
                                    <?php if ($review_applicant['status'] === 'for_initial'): ?>
                                        <form method="POST" style="display:inline-flex; gap:8px; align-items:center;"
                                            data-confirm="Pass this applicant on the Initial Interview? The Final Interview date will be emailed to them."
                                            data-confirm-title="Pass Initial Interview" data-confirm-icon="question"
                                            data-confirm-ok="Yes, pass" data-reason="optional">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="pass_initial">
                                            <input type="hidden" name="applicant_id"
                                                value="<?= (int) $review_applicant['applicant_id'] ?>">
                                            <label style="font-size:13px; color:var(--ink-soft);">Final Interview:</label>
                                            <input type="date" name="interview_date" class="review-date-input" required
                                                min="<?= date('Y-m-d') ?>">
                                            <button type="submit" class="btn btn-primary btn-sm">Schedule Final Interview</button>
                                        </form>
                                        <span style="font-size:12.5px; color:var(--ink-soft);">— or Reject below if they didn't pass
                                            the Initial Interview.</span>

                                    <?php elseif ($review_applicant['status'] === 'for_final'): ?>
                                        <form method="POST"
                                            style="display:inline-flex; gap:8px; align-items:center; flex-wrap:wrap;"
                                            data-confirm="Pass this applicant on the Final Interview? The list of requirements will be emailed to them."
                                            data-confirm-title="Pass Final Interview" data-confirm-icon="question"
                                            data-confirm-ok="Yes, pass" data-reason="optional">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="pass_final">
                                            <input type="hidden" name="applicant_id"
                                                value="<?= (int) $review_applicant['applicant_id'] ?>">
                                            <label style="font-size:13px; color:var(--ink-soft);">Requirements deadline:</label>
                                            <input type="date" name="requirements_deadline" class="review-date-input" required
                                                min="<?= date('Y-m-d') ?>">
                                            <button type="submit" class="btn btn-primary btn-sm">Pass Final Interview</button>
                                        </form>

                                    <?php elseif ($review_applicant['status'] === 'for_requirements'): ?>
                                        <span style="font-size:13px; color:var(--ink-soft);">
                                            <strong><?= count($review_missingDocs) ?></strong> requirement(s) still missing.
                                            Complete them below before hiring.
                                        </span>
                                        <form method="POST" style="display:inline-flex; margin-left:10px;"
                                            data-confirm="Resend the requirements email (with the submission link) to this applicant?"
                                            data-confirm-title="Resend Requirements Email" data-confirm-icon="question"
                                            data-confirm-ok="Yes, resend">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="resend_requirements_email">
                                            <input type="hidden" name="applicant_id"
                                                value="<?= (int) $review_applicant['applicant_id'] ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">Resend Requirements
                                                Email</button>
                                        </form>

                                    <?php elseif ($review_applicant['status'] === 'for_admin_approval'): ?>
                                        <span style="font-size:13px; color:var(--ink-soft);">
                                            <?= $review_hasSignedContract ? 'Signed contract on file.' : 'A signed contract is required before hiring.' ?>
                                        </span>
                                        <?php if (!$review_hasSignedContract && $canActApplicants): ?>
                                            <a class="btn btn-secondary btn-sm"
                                                href="hr_letter_edit.php?applicant_id=<?= enc_id($review_applicant['applicant_id']) ?>&amp;letter_type=contract">Create
                                                contract</a>
                                        <?php endif; ?>
                                        <?php if (isAdminRole()): ?>
                                            <?php if ($review_hasSignedContract): ?>
                                                <form method="POST" style="display:inline;"
                                                    data-confirm="Confirm this applicant as hired? This will create an employee record."
                                                    data-confirm-title="Confirm Hire" data-confirm-icon="question"
                                                    data-confirm-ok="Yes, hire" data-reason="optional">
                                                    <?php csrfField(); ?>
                                                    <input type="hidden" name="action" value="confirm_hire">
                                                    <input type="hidden" name="applicant_id"
                                                        value="<?= (int) $review_applicant['applicant_id'] ?>">
                                                    <button type="submit" class="btn btn-primary btn-sm">Hire</button>
                                                </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="font-size:13px; color:var(--ink-soft);">Waiting for Admin hire
                                                confirmation.</span>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <form method="POST" style="display:inline;"
                                        data-confirm="Why is this applicant being rejected? The reason will be included in the email sent to them."
                                        data-confirm-title="Reject Applicant" data-confirm-danger="1"
                                        data-confirm-ok="Yes, reject" data-reason="required">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="reject_applicant">
                                        <input type="hidden" name="applicant_id"
                                            value="<?= (int) $review_applicant['applicant_id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- ================= REQUIREMENTS ================= -->
                        <?php if ($review_showRequirements): ?>
                            <div class="review-card">
                                <h3>Requirements (Pre-Employment Documents)</h3>
                                <p style="font-size:13.5px; color:var(--ink-soft); margin-bottom:14px;">
                                    <?php if (!empty($review_applicant['requirements_deadline'])): ?>
                                        Deadline: <strong
                                            style="color:<?= (strtotime($review_applicant['requirements_deadline']) < strtotime('today')) ? 'var(--red-alert)' : 'var(--ink)' ?>;">
                                            <?= clean(date('M d, Y', strtotime($review_applicant['requirements_deadline']))) ?>
                                        </strong> ·
                                    <?php endif; ?>
                                    <?php if (empty($review_missingDocs)): ?>
                                        <strong style="color:var(--text-accent);">Complete ✔</strong>
                                    <?php else: ?>
                                        Missing: <strong style="color:var(--red-alert);"><?= count($review_missingDocs) ?></strong>
                                        of <?= count(APPLICANT_REQUIRED_DOCUMENTS) ?>
                                    <?php endif; ?>
                                </p>

                                <?php
                                // Kapag may kahit isang missing requirement pa at pwede mag-edit,
                                // isang form na lang ang gagamitin para sa lahat ng upload —
                                // hindi ito pwedeng i-nest sa loob ng bawat row (may sariling
                                // Delete <form> ang bawat isa), kaya ang bawat file input at
                                // ang Submit button ay tinali dito sa pamamagitan ng
                                // form="requirements-upload-form" attribute.
                                $review_hasMissingEditable = $review_canEditDocs && !empty($review_missingDocs);
                                ?>

                                <?php if ($review_hasMissingEditable): ?>
                                    <form method="POST" enctype="multipart/form-data" id="requirements-upload-form">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="upload_applicant_document">
                                        <input type="hidden" name="applicant_id"
                                            value="<?= (int) $review_applicant['applicant_id'] ?>">
                                    </form>
                                <?php endif; ?>

                                <?php foreach (APPLICANT_REQUIRED_DOCUMENTS as $key => $label): ?>
                                    <?php $doc = $review_uploadedDocs[$key] ?? null; ?>
                                    <div
                                        style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; padding:10px 0; border-bottom:1px solid var(--line);">
                                        <div style="flex:1 1 220px; font-size:13.5px;">
                                            <span
                                                class="document-status <?= $doc ? 'document-status--complete' : 'document-status--missing' ?>"><?= $doc ? 'Complete' : 'Not submitted' ?></span>
                                            <strong><?= clean($label) ?></strong>
                                            <?php if ($doc): ?>
                                                <div style="font-size:11.5px; color:var(--ink-soft);">
                                                    <?= clean($doc['original_name']) ?> ·
                                                    <?= clean(date('M d, Y', strtotime($doc['uploaded_at']))) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($doc): ?>
                                            <button type="button" class="btn btn-secondary btn-sm js-view-doc" title="View"
                                                aria-label="View"
                                                data-url="applicant_document_download.php?id=<?= enc_id($doc['document_id']) ?>"
                                                data-label="<?= clean($label) ?>"><i class="fa-regular fa-eye"></i></button>
                                            <?php if ($review_canEditDocs): ?>
                                                <form method="POST" style="display:inline;"
                                                    data-confirm="Reject the <?= clean($label) ?>? The applicant will be emailed and asked to resend just this requirement."
                                                    data-confirm-title="Reject Document" data-confirm-danger="1"
                                                    data-confirm-ok="Yes, reject" data-reason="required">
                                                    <?php csrfField(); ?>
                                                    <input type="hidden" name="action" value="delete_applicant_document">
                                                    <input type="hidden" name="document_id" value="<?= (int) $doc['document_id'] ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm"
                                                        title="Reject — unreadable or wrong document" aria-label="Reject"><i
                                                            class="fa-regular fa-circle-xmark"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        <?php elseif ($review_canEditDocs): ?>
                                            <input type="file" name="document[<?= clean($key) ?>]"
                                                accept="<?= clean(applicantDocAcceptAttr($key)) ?>"
                                                style="font-size:12px; max-width:220px;" form="requirements-upload-form">
                                        <?php else: ?>
                                            <span style="font-size:12.5px; color:var(--ink-soft);">Not submitted</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>

                                <?php if ($review_hasMissingEditable): ?>
                                    <div style="margin-top:14px;">
                                        <button type="submit" id="submitDocsBtn" class="btn btn-primary btn-sm"
                                            form="requirements-upload-form">Submit Selected Documents</button>
                                    </div>
                                    <script>
                                        (function () {
                                            var POST_MAX_BYTES = <?= (int) iniSizeToBytes(ini_get('post_max_size')) ?>;
                                            var SAFETY_MARGIN = 0.9;

                                            var form = document.getElementById('requirements-upload-form');
                                            if (!form) return;

                                            form.addEventListener('submit', function (e) {
                                                var fileInputs = form.querySelectorAll('input[type="file"]');
                                                var total = 0;
                                                var anySelected = false;
                                                fileInputs.forEach(function (input) {
                                                    if (input.files && input.files.length > 0) {
                                                        anySelected = true;
                                                        total += input.files[0].size;
                                                    }
                                                });

                                                if (!anySelected) return;

                                                if (POST_MAX_BYTES > 0 && total > POST_MAX_BYTES * SAFETY_MARGIN) {
                                                    e.preventDefault();
                                                    e.stopImmediatePropagation();
                                                    var totalMB = (total / (1024 * 1024)).toFixed(1);
                                                    var limitMB = (POST_MAX_BYTES / (1024 * 1024)).toFixed(1);
                                                    var msg = 'The files you selected add up to about ' + totalMB + ' MB, which is over this server\'s ' + limitMB + ' MB upload limit. Please submit fewer files at a time (e.g. 2-3 documents per batch instead of all at once).';
                                                    if (typeof Swal !== 'undefined') {
                                                        Swal.fire({ icon: 'warning', title: 'Files too large to submit together', text: msg, confirmButtonColor: 'var(--green-mid)' });
                                                    } else {
                                                        Swal.fire({
                                                            icon: 'error',
                                                            title: 'Action could not be completed',
                                                            text: msg,
                                                            confirmButtonText: 'Okay'
                                                        });
                                                    }
                                                    form.dataset.submitting = '0';
                                                    var btns = form.querySelectorAll('button[type="submit"], input[type="submit"]');
                                                    for (var i = 0; i < btns.length; i++) { btns[i].disabled = false; }
                                                }
                                            }, true);
                                        })();
                                    </script>
                                <?php endif; ?>

                                <?php if ($review_canEditDocs): ?>
                                    <p style="font-size:11.5px; color:var(--ink-soft); margin-top:12px;">
                                        Accepted: PDF for documents and JPEG for Valid Government ID / 2x2 ID Picture (max 5 MB per
                                        file). You can choose files for several requirements at once, then submit them together —
                                        but if you select many large files, submit in smaller batches (e.g. 2-3 at a time) so the
                                        total stays under this server's upload limit. HR uploads these on the applicant's behalf.
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- ================= AUDIT TRAIL ================= -->
                        <div class="review-card">
                            <h3>Decision History</h3>
                            <?php if (empty($review_logs)): ?>
                                <p style="font-size:13.5px; color:var(--ink-soft);">No decisions recorded yet.</p>
                            <?php else: ?>
                                <?php foreach ($review_logs as $l): ?>
                                    <div class="review-log">
                                        <div class="head"><?= clean(ucwords(str_replace('_', ' ', $l['action']))) ?></div>
                                        <div class="meta">
                                            <?= clean(date('M d, Y h:i A', strtotime($l['logged_at']))) ?>
                                            <?= !empty($l['performed_by_name']) ? ' · ' . clean($l['performed_by_name']) : '' ?>
                                        </div>
                                        <?php if (!empty($l['reason'])): ?>
                                            <div class="reason"><?= nl2br(clean($l['reason'])) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="modal-footer">
                            <a href="?tab=applicants" class="btn btn-secondary">Close</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($edit_employee): ?>

                <div class="modal-overlay">

                    <div class="modal-card">

                        <h2>Edit Employee — <?= clean($edit_employee['employee_code']) ?></h2>

                        <form method="POST">

                            <?php csrfField(); ?>

                            <input type="hidden" name="action" value="edit">

                            <input type="hidden" name="employee_id" value="<?= $edit_employee['employee_id'] ?>">

                            <div class="form-grid">

                                <div class="field-group"><label>First Name *</label><input type="text" name="first_name"
                                        class="input-name" pattern="[\p{L}\s.'-]+" title="Letters only"
                                        value="<?= clean($edit_employee['first_name']) ?>" required></div>

                                <div class="field-group"><label>Last Name *</label><input type="text" name="last_name"
                                        class="input-name" pattern="[\p{L}\s.'-]+" title="Letters only"
                                        value="<?= clean($edit_employee['last_name']) ?>" required></div>

                                <div class="field-group"><label>Middle Name</label><input type="text" name="middle_name"
                                        class="input-name" pattern="[\p{L}\s.'-]*" title="Letters only"
                                        value="<?= clean($edit_employee['middle_name'] ?? '') ?>"></div>

                                <div class="field-group"><label>Birthdate</label><input type="date" name="birthdate"
                                        max="<?= date('Y-m-d', strtotime('-' . MIN_EMPLOYEE_AGE . ' years')) ?>"
                                        title="Must be <?= MIN_EMPLOYEE_AGE ?> years old or older"
                                        value="<?= clean($edit_employee['birthdate'] ?? '') ?>"></div>

                                <div class="field-group full"><label>Address</label><input type="text" name="address"
                                        value="<?= clean($edit_employee['address'] ?? '') ?>"></div>

                                <div class="field-group"><label>Contact
                                        Number</label><?php renderPhoneFieldInputs('contact_number', $edit_employee['contact_number'] ?? null, false, 'input-digits'); ?>
                                </div>

                                <div class="field-group"><label>Email</label><input type="email" name="email"
                                        value="<?= clean($edit_employee['email'] ?? '') ?>"></div>

                                <div class="field-group"><label>SSS Number</label><input type="text" name="sss_number"
                                        class="input-digits" inputmode="numeric" maxlength="10" pattern="\d{10}"
                                        title="10 digits" value="<?= clean($edit_employee['sss_number'] ?? '') ?>"></div>

                                <div class="field-group"><label>PhilHealth Number</label><input type="text"
                                        name="philhealth_number" class="input-digits" inputmode="numeric" maxlength="12"
                                        pattern="\d{12}" title="12 digits"
                                        value="<?= clean($edit_employee['philhealth_number'] ?? '') ?>"></div>

                                <div class="field-group"><label>Pag-IBIG Number</label><input type="text"
                                        name="pagibig_number" class="input-digits" inputmode="numeric" maxlength="12"
                                        pattern="\d{12}" title="12 digits"
                                        value="<?= clean($edit_employee['pagibig_number'] ?? '') ?>"></div>

                                <div class="field-group"><label>TIN Number</label><input type="text" name="tin_number"
                                        class="input-digits" inputmode="numeric" maxlength="12" pattern="\d{9,12}"
                                        title="9 to 12 digits" value="<?= clean($edit_employee['tin_number'] ?? '') ?>">
                                </div>

                                <?php

                                $curDept = $edit_employee['department'] ?? '';

                                $curPos = $edit_employee['position'] ?? '';

                                $deptIsOther = $curDept !== '' && !in_array($curDept, $existingDepartments, true);

                                $posIsOther = $curPos !== '' && !in_array($curPos, COMMON_POSITIONS, true);

                                ?>

                                <div class="field-group">

                                    <label>Department</label>

                                    <select name="department" class="dept-select"
                                        onchange="toggleOther(this, 'edit-department-other')">

                                        <option value="">— Select Department —</option>

                                        <?php foreach ($existingDepartments as $dept): ?>

                                            <option value="<?= clean($dept) ?>" <?= $curDept === $dept ? 'selected' : '' ?>>
                                                <?= clean($dept) ?>
                                            </option>

                                        <?php endforeach; ?>

                                        <option value="__other__" <?= $deptIsOther ? 'selected' : '' ?>>Other (specify)...
                                        </option>

                                    </select>

                                    <input type="text" name="department_other" id="edit-department-other"
                                        placeholder="Enter department name"
                                        value="<?= $deptIsOther ? clean($curDept) : '' ?>"
                                        style="<?= $deptIsOther ? '' : 'display:none;' ?> margin-top:6px;">

                                </div>

                                <div class="field-group">

                                    <label>Position</label>

                                    <select name="position" class="pos-select"
                                        onchange="toggleOther(this, 'edit-position-other'); updateScheduleTypeOptions(this.value);">

                                        <option value="">— Select Position —</option>

                                        <?php foreach (COMMON_POSITIONS as $pos): ?>

                                            <option value="<?= clean($pos) ?>" <?= $curPos === $pos ? 'selected' : '' ?>>
                                                <?= clean($pos) ?>
                                            </option>

                                        <?php endforeach; ?>

                                        <option value="__other__" <?= $posIsOther ? 'selected' : '' ?>>Other (specify)...
                                        </option>

                                    </select>

                                    <input type="text" name="position_other" id="edit-position-other"
                                        placeholder="Enter position title" value="<?= $posIsOther ? clean($curPos) : '' ?>"
                                        style="<?= $posIsOther ? '' : 'display:none;' ?> margin-top:6px;">



                                </div>

                                <div class="field-group">

                                    <label>Employment Type *</label>

                                    <select name="employment_type" required>

                                        <?php foreach (['probationary', 'regular', 'contractual'] as $type): ?>

                                            <option value="<?= $type ?>" <?= $edit_employee['employment_type'] == $type ? 'selected' : '' ?>><?= ucfirst($type) ?></option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <?php

                                $curSchedule = $edit_employee['schedule_type'] ?? 'full_time';

                                $curPosEligible = isPositionPartTimeEligible($curPos);

                                ?>

                                <div class="field-group">

                                    <label>Schedule Type *</label>

                                    <select name="schedule_type" id="edit-schedule-type" required
                                        onchange="updateWeeklyShiftOptions(this.value);">

                                        <option value="full_time" <?= $curSchedule === 'full_time' ? 'selected' : '' ?>>
                                            Full-time (<?= STANDARD_SHIFT_HOURS ?> hrs/shift)</option>

                                        <option value="part_time" <?= $curSchedule === 'part_time' ? 'selected' : '' ?>
                                            <?= $curPosEligible ? '' : 'disabled' ?>>Part-time (<?= PART_TIME_SHIFT_HOURS ?>
                                            hrs/shift)</option>

                                    </select>

                                    <small id="schedule-type-hint"
                                        style="display:<?= $curPosEligible ? 'none' : '' ?>; margin-top:6px; color:var(--ink-soft);">

                                        Part-time is only allowed for:
                                        <?= clean(implode(', ', PART_TIME_ELIGIBLE_POSITIONS)) ?>.

                                    </small>

                                </div>

                                <div class="field-group"><label>Date Hired *</label><input type="date" name="date_hired"
                                        value="<?= clean($edit_employee['date_hired']) ?>" required></div>

                                <div class="field-group"><label>Basic Salary *</label><input type="number" step="0.01"
                                        min="0" name="basic_salary" value="<?= clean($edit_employee['basic_salary']) ?>"
                                        required></div>

                                <div class="field-group full"
                                    style="border-top:1px solid var(--line); padding-top:12px; margin-top:4px;">

                                    <label style="color:var(--text-accent);">Weekly Shift Schedule</label>

                                    <div class="form-hint" style="margin:2px 0 10px;">Not all employees need to work the
                                        same hours — pick the shift for each day of the week (or "— Day Off —" if they don't
                                        work that day). Only shifts matching this employee's <strong>Schedule Type</strong>
                                        above are shown (Full-time = <?= STANDARD_SHIFT_HOURS ?>h shifts, Part-time =
                                        <?= PART_TIME_SHIFT_HOURS ?>h shifts). This is the basis for "Late", OT, and
                                        Undertime in Attendance/Payroll.
                                    </div>

                                    <div class="weekly-shift-grid">

                                        <?php foreach ($DAY_NAMES as $dow => $dayLabel):
                                            $curDayShift = $editWeeklySchedule[$dow] ?? null; ?>

                                            <div class="field-group">

                                                <label><?= $dayLabel ?></label>

                                                <select name="weekly_shift_<?= $dow ?>" class="weekly-shift-select">

                                                    <option value="">— Day Off —</option>

                                                    <?php foreach ($allShiftsActive as $sh): ?>

                                                        <option value="<?= (int) $sh['shift_id'] ?>"
                                                            data-type="<?= clean($sh['shift_type']) ?>" <?= ($curDayShift && (int) $curDayShift['shift_id'] === (int) $sh['shift_id']) ? 'selected' : '' ?>>

                                                            <?= clean($sh['shift_name']) ?>

                                                        </option>

                                                    <?php endforeach; ?>

                                                    <?php if ($curDayShift && !in_array($curDayShift['shift_id'], array_column($allShiftsActive, 'shift_id'))): ?>

                                                        <option value="<?= (int) $curDayShift['shift_id'] ?>" selected>

                                                            <?= clean($curDayShift['shift_name']) ?> (inactive)

                                                        </option>

                                                    <?php endif; ?>

                                                </select>

                                            </div>

                                        <?php endforeach; ?>

                                    </div>

                                </div>

                                <?php $curPaymentMethod = $edit_employee['payment_method'] ?? 'bank'; ?>
                                <div class="field-group full"
                                    style="border-top:1px solid var(--line); padding-top:12px; margin-top:4px;"><label
                                        style="color:var(--text-accent);">Salary Payout Method</label></div>
                                <div class="field-group full">
                                    <select name="payment_method" id="paymentMethodSelect"
                                        onchange="toggleBankFieldsByPaymentMethod()">
                                        <option value="bank" <?= $curPaymentMethod === 'bank' ? 'selected' : '' ?>>Bank Deposit
                                        </option>
                                        <option value="cash" <?= $curPaymentMethod === 'cash' ? 'selected' : '' ?>>Cash
                                            (Walk-in) — no bank account yet</option>
                                    </select>
                                    <div style="font-size:12px; color:var(--ink-soft); margin-top:4px;">New hires default to
                                        Cash — switch to Bank Deposit once the employee has opened an account.</div>
                                </div>

                                <div id="bankDetailsSection">
                                    <div class="field-group full"
                                        style="border-top:1px solid var(--line); padding-top:12px; margin-top:4px;"><label
                                            style="color:var(--text-accent);">Bank / Card Details (for payroll
                                            deposit)</label></div>

                                    <div class="field-group"><label>Bank Name</label>

                                        <select name="bank_name">

                                            <option value="" <?= empty($edit_employee['bank_name']) ? 'selected' : '' ?>>—
                                                Select —</option>

                                            <option value="BDO" <?= (($edit_employee['bank_name'] ?? '') === 'BDO') ? 'selected' : '' ?>>BDO</option>

                                        </select>

                                    </div>

                                    <div class="field-group"><label>Card Number</label><input type="text"
                                            name="bank_account_number" inputmode="numeric" maxlength="16" pattern="\d{16}"
                                            title="Exactly 16 digits (BDO card/account number)"
                                            placeholder="e.g. 1234567890123456"
                                            value="<?= clean($edit_employee['bank_account_number'] ?? '') ?>"
                                            oninput="this.value = this.value.replace(/\D/g, '').slice(0, 16);"></div>

                                    <div class="field-group full"><label>Account Holder Name</label><input type="text"
                                            name="bank_account_name" maxlength="100" placeholder="Name on the account"
                                            value="<?= clean($edit_employee['bank_account_name'] ?? '') ?>"></div>
                                </div>

                                <?php $editIsOffboarded = in_array($edit_employee['status'], ['resigned', 'terminated']); ?>
                                <?php $editCanOffboard = ($_SESSION['role'] ?? '') === 'admin'; ?>

                                <div class="field-group">

                                    <label>Status *</label>

                                    <select name="status" required <?= $editIsOffboarded ? 'disabled' : '' ?>>

                                        <?php
                                        // Resigned/Terminated are only offered here to Admin — HR
                                        // Staff/HR Manager use the dedicated "Offboard" action for
                                        // that instead (see the Offboard button on the employee
                                        // list). Server-side enforcement is the authoritative
                                        // check (see the 'edit' POST handler above); this just
                                        // keeps the dropdown itself from offering an option that
                                        // would get rejected anyway.
                                        $statusOptions = $editIsOffboarded
                                            ? ['resigned', 'terminated']
                                            : ($editCanOffboard ? ['active', 'on-leave', 'resigned', 'terminated'] : ['active', 'on-leave']);

                                        foreach ($statusOptions as $st):

                                            ?>

                                            <option value="<?= $st ?>" <?= $edit_employee['status'] == $st ? 'selected' : '' ?>>
                                                <?= ucfirst(str_replace('-', ' ', $st)) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                    <?php if ($editIsOffboarded): ?>

                                        <input type="hidden" name="status" value="<?= clean($edit_employee['status']) ?>">

                                        <small style="display:block; margin-top:6px; color:var(--ink-soft);">

                                            This employee has already completed the offboarding
                                            process.(<?= ucfirst($edit_employee['status']) ?>). This employee can no longer be
                                            reinstated to Active/On-leave status through this process; they will need to submit
                                            a new application.

                                        </small>

                                    <?php elseif (!$editCanOffboard): ?>

                                        <small style="display:block; margin-top:6px; color:var(--ink-soft);">
                                            To mark this employee as Resigned or Terminated, use the "Offboard" action on the
                                            employee list (Admin access required).
                                        </small>

                                    <?php endif; ?>

                                </div>

                            </div>

                            <div class="modal-footer">

                                <a href="employees.php" class="btn btn-secondary">Cancel</a>

                                <button type="submit" class="btn btn-primary">Update Employee</button>

                            </div>

                        </form>

                    </div>

                </div>

            <?php endif; ?>

            <?php if ($history_employee): ?>
                <div class="modal-overlay">
                    <div class="modal-card" style="max-width:520px;">

                        <h2>Position History —
                            <?= clean($history_employee['first_name'] . ' ' . $history_employee['last_name']) ?>
                        </h2>

                        <?php if (empty($history_position_history)): ?>
                            <p style="color:var(--ink-soft); font-size:13.5px;">No position or department changes recorded yet.
                            </p>
                        <?php else: ?>
                            <div class="position-history" style="margin-top:0;">
                                <div class="ph-list" style="max-height:220px;">
                                    <?php foreach ($history_position_history as $h): ?>
                                        <div class="ph-item">
                                            <span class="ph-dot"></span>
                                            <div class="ph-change">
                                                <?= clean($h['old_position'] ?: '—') ?><span
                                                    class="ph-arrow">→</span><?= clean($h['new_position'] ?: '—') ?>
                                            </div>
                                            <?php if ($h['old_department'] !== $h['new_department']): ?>
                                                <div class="ph-dept-badge">Dept: <?= clean($h['old_department'] ?: '—') ?> →
                                                    <?= clean($h['new_department'] ?: '—') ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="ph-meta">
                                                <?= date('M d, Y g:i A', strtotime($h['changed_at'])) ?>
                                                <?= $h['changed_by_name'] ? ' · by ' . clean($h['changed_by_name']) : '' ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <h2 style="margin-top:20px;">Status &amp; Suspension Log</h2>

                        <?php if (empty($history_status_logs)): ?>
                            <p style="color:var(--ink-soft); font-size:13.5px;">No status changes, suspensions, or offboarding
                                recorded yet.</p>
                        <?php else: ?>
                            <div class="position-history" style="margin-top:0;">
                                <div class="ph-list" style="max-height:220px;">
                                    <?php foreach ($history_status_logs as $sl):
                                        switch ($sl['action']) {
                                            case 'suspend':
                                                $slLabel = 'Suspended — ' . clean($sl['details']);
                                                break;
                                            case 'lift_suspension':
                                                $slLabel = 'Suspension lifted';
                                                break;
                                            case 'offboard':
                                                $slLabel = 'Offboarded — marked as ' . clean(ucfirst($sl['new_status'] ?? ''));
                                                break;
                                            default:
                                                $slLabel = 'Status changed: ' . clean(ucfirst($sl['old_status'] ?? '—')) . ' → ' . clean(ucfirst($sl['new_status'] ?? '—'));
                                        }
                                        ?>
                                        <div class="ph-item">
                                            <span class="ph-dot"></span>
                                            <div class="ph-change"><?= $slLabel ?></div>
                                            <div class="ph-meta">
                                                <?= date('M d, Y g:i A', strtotime($sl['logged_at'])) ?>
                                                <?= $sl['performed_by_name'] ? ' · by ' . clean($sl['performed_by_name']) : ' · by system' ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="modal-footer">
                            <a href="employees.php" class="btn btn-secondary">Close</a>
                        </div>

                    </div>
                </div>
            <?php endif; ?>

            <?php if ($suspend_employee): ?>
                <div class="modal-overlay">
                    <div class="modal-card" style="max-width:420px;">

                        <h2>Suspend — <?= clean($suspend_employee['first_name'] . ' ' . $suspend_employee['last_name']) ?>
                        </h2>

                        <form method="POST">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="suspend">
                            <input type="hidden" name="employee_id" value="<?= $suspend_employee['employee_id'] ?>">

                            <div class="form-grid">
                                <div class="field-group">
                                    <label>Number of Days</label>
                                    <input type="text" name="suspension_days" inputmode="numeric" pattern="[0-9]{1,3}"
                                        autocomplete="off" maxlength="3" required placeholder="e.g. 5"
                                        oninput="this.value = this.value.replace(/[^0-9]/g, '');"
                                        onkeydown="if (['e','E','+','-','.',','].includes(event.key)) event.preventDefault();"
                                        onpaste="setTimeout(() => { this.value = this.value.replace(/[^0-9]/g, ''); }, 0);">
                                </div>
                                <div class="field-group full">
                                    <label>Reason</label>
                                    <input type="text" name="suspension_reason" required
                                        placeholder="e.g. Repeated tardiness">
                                </div>
                            </div>

                            <p style="color:var(--ink-soft); font-size:12.5px; margin-top:14px;">
                                The employee will not be able to log in for the duration of the suspension. This starts
                                today.
                            </p>

                            <div class="modal-footer">
                                <a href="employees.php" class="btn btn-secondary">Cancel</a>
                                <button type="submit" class="btn btn-danger">Confirm Suspension</button>
                            </div>
                        </form>

                    </div>
                </div>
            <?php endif; ?>

            <?php if ($offboard_employee): ?>
                <div class="modal-overlay">
                    <div class="modal-card" style="max-width:420px;">

                        <h2>Offboard —
                            <?= clean($offboard_employee['first_name'] . ' ' . $offboard_employee['last_name']) ?>
                        </h2>

                        <form method="POST">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="offboard">
                            <input type="hidden" name="employee_id" value="<?= $offboard_employee['employee_id'] ?>">

                            <div class="field-group">
                                <label>Reason</label>
                                <select name="reason" required>
                                    <option value="">Select reason...</option>
                                    <option value="resigned">Resigned</option>
                                    <option value="terminated">Terminated</option>
                                </select>
                            </div>

                            <p style="color:var(--ink-soft); font-size:12.5px; margin-top:14px;">
                                This is permanent — the employee's record stays in the system for reports, but they can no
                                longer be reinstated through Edit. They would need to re-apply.
                            </p>

                            <div class="modal-footer">
                                <a href="employees.php" class="btn btn-secondary">Cancel</a>
                                <button type="submit" class="btn btn-danger">Confirm Offboard</button>
                            </div>
                        </form>

                    </div>
                </div>
            <?php endif; ?>

            <?php if ($documents_employee): ?>
                <div class="modal-overlay">
                    <div class="modal-card" style="max-width:960px; padding:0; overflow:hidden;">

                        <div
                            style="display:flex; align-items:center; justify-content:space-between; padding:18px 24px; border-bottom:1.5px solid var(--line);">
                            <h2 style="margin:0;">Documents —
                                <?= clean($documents_employee['first_name'] . ' ' . $documents_employee['last_name']) ?>
                            </h2>
                            <a href="employees.php" class="btn btn-secondary btn-sm">Close</a>
                        </div>

                        <iframe
                            src="employee_documents.php?employee_id=<?= enc_id($documents_employee['employee_id']) ?>&embed=1"
                            style="width:100%; height:74vh; border:0; display:block;"></iframe>

                    </div>
                </div>
            <?php endif; ?>

            <?php if ($__showHrSidebar): ?>
            </div>
        </div>
    <?php endif; ?>

    <script>

        // Real-time input restriction: invalid characters are blocked immediately while typing

        document.querySelectorAll('.input-digits').forEach(function (el) {

            el.addEventListener('input', function () {

                this.value = this.value.replace(/[^0-9+]/g, '');

            });

        });

    </script>
    <?php renderPhoneFieldScript(); ?>

    <script>

        document.querySelectorAll('.input-name').forEach(function (el) {

            el.addEventListener('input', function () {

                this.value = this.value.replace(/[^\p{L}\s.'-]/gu, '');

            });

        });



        // Gray out (visually only — fields stay enabled so nothing already
        // typed gets silently dropped from the submission) the Bank/Card
        // Details section when "Cash (Walk-in)" is selected, since those
        // fields aren't relevant yet for an employee being paid in cash.
        function toggleBankFieldsByPaymentMethod() {

            var select = document.getElementById('paymentMethodSelect');

            var section = document.getElementById('bankDetailsSection');

            if (!select || !section) return;

            section.style.opacity = (select.value === 'cash') ? '0.5' : '1';

        }

        toggleBankFieldsByPaymentMethod();

        // Show the "Other" text input when selected in the Department/Position dropdown

        function toggleOther(selectEl, otherInputId) {

            var otherInput = document.getElementById(otherInputId);

            if (!otherInput) return;

            if (selectEl.value === '__other__') {

                otherInput.style.display = '';

                otherInput.focus();

            } else {

                otherInput.style.display = 'none';

                otherInput.value = '';

            }

        }



        // Positions that can be Part-time (from PART_TIME_ELIGIBLE_POSITIONS

        // in includes/config.php). When the Position dropdown changes, this checks

        // whether the "Part-time" option in Schedule Type should be disabled — this

        // mirrors the server-side validation, so the UI doesn't mismatch what's actually saved.

        var PART_TIME_ELIGIBLE_POSITIONS_JS = <?= json_encode(PART_TIME_ELIGIBLE_POSITIONS) ?>;



        function updateScheduleTypeOptions(positionValue) {

            var scheduleSelect = document.getElementById('edit-schedule-type');

            if (!scheduleSelect) return;

            var hint = document.getElementById('schedule-type-hint');

            var partTimeOpt = scheduleSelect.querySelector('option[value="part_time"]');

            if (!partTimeOpt) return;



            var eligible = PART_TIME_ELIGIBLE_POSITIONS_JS.indexOf(positionValue) !== -1;

            partTimeOpt.disabled = !eligible;

            if (hint) hint.style.display = eligible ? 'none' : '';

            if (!eligible && scheduleSelect.value === 'part_time') {

                scheduleSelect.value = 'full_time';

            }

            // Position change can force Schedule Type back to Full-time (above) —

            // keep the Weekly Shift Schedule dropdowns in sync either way.

            updateWeeklyShiftOptions(scheduleSelect.value);

        }



        // -------------------------------------------------------------

        // WEEKLY SHIFT SCHEDULE — show only the shifts (per day dropdown)

        // that match the employee's current Schedule Type (Full-time =

        // 8-hour shifts, Part-time = 6-hour shifts). Each <option> in the

        // 7 day dropdowns is tagged with data-type="full_time|part_time" by

        // PHP (see the Weekly Shift Schedule block above) — options that

        // don't match the given scheduleType are hidden + disabled so they

        // can't be picked. The "— Day Off —" option (no data-type) and any

        // "(inactive)" fallback option (also no data-type, since it may be

        // an old/retired shift no longer in the active catalog) are left

        // untouched so they're always selectable regardless of type.

        // -------------------------------------------------------------

        function updateWeeklyShiftOptions(scheduleType) {

            document.querySelectorAll('.weekly-shift-select').forEach(function (sel) {

                var changedSelectedOut = false;

                sel.querySelectorAll('option[data-type]').forEach(function (opt) {

                    var matches = (opt.dataset.type === scheduleType);

                    opt.hidden = !matches;

                    opt.disabled = !matches;

                    if (opt.selected && !matches) {

                        changedSelectedOut = true;

                    }

                });

                // If the day's previously-selected shift no longer matches the

                // new Schedule Type, reset that day back to "Day Off" instead

                // of silently keeping a now-hidden/disabled option selected.

                if (changedSelectedOut) {

                    sel.value = '';

                }

            });

        }



        // Initialize the Weekly Shift Schedule dropdowns to match the

        // employee's current Schedule Type as soon as the Edit modal loads

        // (PHP already renders all shifts; this just hides the non-matching

        // ones on first paint).

        (function () {

            var scheduleSelect = document.getElementById('edit-schedule-type');

            if (scheduleSelect) {

                updateWeeklyShiftOptions(scheduleSelect.value);

            }

        })();

    </script>



    <script>
        (function () {
            // Resume Screening batch toolbar: keep the "X selected" label and the
            // submit button's disabled state in sync with the checkboxes (which
            // live in the table, tied to #resume-batch-form via form="..."), and
            // let the header checkbox select/deselect all of them at once.
            var boxes = document.querySelectorAll('.resume-select-checkbox');
            var countLabel = document.getElementById('resumeSelectedCount');
            var submitBtn = document.getElementById('resumeBatchSubmit');
            var selectAll = document.getElementById('selectAllApplicants');
            if (!boxes.length || !countLabel || !submitBtn) return;

            function refresh() {
                var n = 0;
                boxes.forEach(function (b) { if (b.checked) n++; });
                countLabel.textContent = n + (n === 1 ? ' selected' : ' selected');
                submitBtn.disabled = (n === 0);
                if (selectAll) {
                    selectAll.checked = (n === boxes.length);
                    selectAll.indeterminate = (n > 0 && n < boxes.length);
                }
            }
            boxes.forEach(function (b) { b.addEventListener('change', refresh); });
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    boxes.forEach(function (b) { b.checked = selectAll.checked; });
                    refresh();
                });
            }
            refresh();
        })();
    </script>

    <!-- ================= DOCUMENT VIEWER MODAL (Requirements "eye" button) ================= -->
    <div class="modal-overlay" id="docViewModal" style="display:none;">
        <div class="modal-card">
            <h2 id="docViewTitle">Document</h2>
            <p style="font-size:12px; color:var(--ink-soft); margin-bottom:12px;">If the preview below looks blank, use
                "Open in New Tab" instead (some file types cannot preview inline).</p>
            <iframe id="docViewFrame" src=""
                style="width:100%; height:65vh; border:1.5px solid var(--line); border-radius:12px; background:var(--cream);"></iframe>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:16px;">
                <a id="docViewOpenNewTab" href="#" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">Open
                    in New Tab</a>
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeDocModal()">Close</button>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var modal = document.getElementById('docViewModal');
            var frame = document.getElementById('docViewFrame');
            var title = document.getElementById('docViewTitle');
            var newTab = document.getElementById('docViewOpenNewTab');
            if (!modal || !frame) return;

            function openDocModal(url, label) {
                title.textContent = label || 'Document';
                frame.src = url;
                newTab.href = url;
                modal.style.display = 'flex';
                modal.classList.add('open');
            }
            window.closeDocModal = function () {
                modal.style.display = 'none';
                modal.classList.remove('open');
                frame.src = ''; // stop the preview from loading/playing in the background
            };

            // Delegated listener: the Requirements list re-renders whenever the
            // Review modal is opened via ?review=<id>, so binding on document
            // instead of the individual buttons avoids re-wiring on every open.
            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.js-view-doc');
                if (btn) openDocModal(btn.dataset.url, btn.dataset.label);
            });

            modal.addEventListener('click', function (e) {
                if (e.target === modal) window.closeDocModal();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal.classList.contains('open')) window.closeDocModal();
            });
        })();
    </script>

    <?php include 'includes/sweetalert.php'; ?>



</body>

</html>