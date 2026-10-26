<?php
// ============================================================
// payroll/save_record.php
// AJAX endpoint called by the "Save Record" button on Payroll
// Run (payroll.js: saveOrUpdateRecord()). This is where payroll
// is actually saved to the real `payroll_records` / `payroll_logs`
// tables, not just browser localStorage — which is why it shows
// up in the Admin/HR Dashboard totals and in the employee's
// "My Payslips".
//
// SECURITY NOTE (highest priority — actual money is on the line
// here, more so than any POS UI/UX gap): everything the browser
// sends (basicPay, otPay, gross, sss, phic, pagibig, tax, net...)
// is RECOMPUTED here from the real attendance records, the
// employee's actual rate in the database, and the official
// government-contribution tables — see computePayrollAttendanceSummary()
// and computeSSS/PhilHealth/PagIbig/WithholdingTax*() in
// HRMS/includes/config.php. The client-submitted numbers are only
// used for (a) the SSS/PhilHealth/Pag-IBIG/Tax fields HR has
// switched to "Manual" (validated against a reasonable range — see
// validateGovManualOverride() below), and (b) the free-form
// Allowance / Other Deductions fields (also range-checked). Every
// other figure is the server's own computation — the client's
// version of it is never trusted, only compared against for the
// audit note in payroll_logs.
// ============================================================

require_once __DIR__ . '/../includes/config.php';

// ---- Auth guard (JSON version — no redirect, since this is AJAX) ----
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'hr', 'hr_manager'], true)) {
    jsonResponse(false, 'Not logged in or no access. Please log in as Admin or HR.');
}
$_SESSION['last_activity'] = time();

requireCSRFJson();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonResponse(false, 'Invalid request payload.');
}

$periodStart = $input['periodStart'] ?? '';
$periodEnd   = $input['periodEnd'] ?? '';
$payoutDate  = $input['payoutDate'] ?? '';
$rows        = $input['rows'] ?? [];

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) {
    jsonResponse(false, 'Invalid pay period dates.');
}
if ($periodEnd < $periodStart) {
    jsonResponse(false, 'Period end cannot be earlier than period start.');
}
if ($payoutDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payoutDate)) {
    jsonResponse(false, 'Invalid payout date.');
}
if ($payoutDate === '') {
    $payoutDate = $periodEnd; // sensible default if not set in the UI
}
if (!is_array($rows) || count($rows) === 0) {
    jsonResponse(false, 'No active employees to save for this period.');
}

// -----------------------------------------------------------------
// PAYROLL ASSUMPTIONS ("Working days / month", "Working hours / day",
// "OT multiplier") — these drive the daily-rate / OT-rate math below.
// They're company-policy knobs HR can see/edit on screen, so we still
// accept them from the client, but only within a REASONABLE RANGE —
// outside of this, it can only be a mistake or an attempt to inflate
// pay (e.g. a huge OT multiplier), so the whole batch is rejected
// rather than silently clamped.
// -----------------------------------------------------------------
$assumptionsIn = $input['assumptions'] ?? [];
$workDays = (float) ($assumptionsIn['workDays'] ?? 0);
$workHours = (float) ($assumptionsIn['workHours'] ?? 0);
$otMult = (float) ($assumptionsIn['otMult'] ?? 0);

if ($workDays < 1 || $workDays > 31) {
    jsonResponse(false, 'Invalid "Working days / month" value — must be between 1 and 31.');
}
if ($workHours < 1 || $workHours > 24) {
    jsonResponse(false, 'Invalid "Working hours / day" value — must be between 1 and 24.');
}
if ($otMult < 1 || $otMult > 3) {
    jsonResponse(false, 'Invalid "OT multiplier" value — must be between 1.0 and 3.0.');
}

// -----------------------------------------------------------------
// Fetch the account-role + contact/bank info + ACTUAL basic_salary
// (rate) of each employee in rows, straight from the employees
// table — never from whatever the client happens to send. Used for
// (a) server-side scope enforcement, (b) recomputing pay below, and
// (c) the salary-released email notification after saving.
// -----------------------------------------------------------------
$empIds = [];
foreach ($rows as $row) {
    $eid = (int) ($row['empId'] ?? 0);
    if ($eid > 0) { $empIds[$eid] = true; }
}
$empIds = array_keys($empIds);

$empInfo = [];
if (!empty($empIds)) {
    $ph = implode(',', array_fill(0, count($empIds), '?'));
    $infoStmt = $pdo->prepare("
        SELECT e.employee_id, COALESCE(u.role, 'employee') AS acct_role,
               e.email, e.first_name, e.last_name, e.bank_name, e.bank_account_number,
               e.payment_method, e.basic_salary, e.status, e.date_hired
        FROM employees e
        LEFT JOIN users u ON u.employee_id = e.employee_id
        WHERE e.employee_id IN ($ph)
    ");
    $infoStmt->execute($empIds);
    foreach ($infoStmt->fetchAll() as $r) {
        $empInfo[(int) $r['employee_id']] = $r;
    }
}

// SCOPE ENFORCEMENT (server-side, defense-in-depth):
// HR Staff can only process rank-and-file Employees. The payroll of
// fellow HR Staff / HR Manager can now be processed by HR Manager
// (previously Admin-only), BUT it still requires Admin's approval
// before it can be released — see $__requiresApproval below. Admin's
// own payroll can still only be processed by Admin themself.
// (This is already scoped in the UI via getPayrollScopedEmployees(),
//  but we enforce it here too so it can't be bypassed via a direct POST.)
$actorRole = $_SESSION['role'] ?? '';
if ($actorRole === 'hr') {
    foreach ($empIds as $eid) {
        if (isset($empInfo[$eid]) && $empInfo[$eid]['acct_role'] !== 'employee') {
            jsonResponse(false, 'You are not allowed to do this: only Admin can process the payroll of HR Staff / HR Manager / Admin.');
        }
    }
}
if ($actorRole === 'hr_manager') {
    foreach ($empIds as $eid) {
        if (isset($empInfo[$eid]) && $empInfo[$eid]['acct_role'] === 'admin') {
            jsonResponse(false, 'You are not allowed to do this: only Admin themself can process Admin\'s payroll.');
        }
    }
}

// -----------------------------------------------------------------
// SERVER-SIDE RECOMPUTE
// -----------------------------------------------------------------
// Actual attendance summary (presentDays / otHours / undertimeHours)
// straight from the HRMS Attendance module, for THIS exact period —
// same function that powers the Payroll Run page display, so what HR
// sees on screen and what gets validated here can never disagree.
$attendanceSummary = computePayrollAttendanceSummary($pdo, $periodStart, $periodEnd);

// Reasonable ceiling for the free-form Allowance / Other Deductions
// fields — these have no formula (they're HR's own discretionary
// entries), so they can't be "recomputed", only sanity-bounded. A
// single allowance/deduction line bigger than 5x the employee's own
// monthly basic salary (or ₱50,000, whichever is bigger, to still
// allow reasonable amounts for very low-salary employees) can only be
// a typo or an attempt to siphon money through the payroll.
function __payrollReasonableDiscretionaryCap($basicSalary) {
    return max(((float) $basicSalary) * 5, 50000);
}

// Validates a manually-overridden gov-contribution field against the
// statutory maximum that field could ever legally be. Returns true if
// OK, or a string error message if out of range.
function __validateGovManual($label, $value, $max) {
    if ($value < 0) {
        return "$label cannot be negative.";
    }
    if ($value > $max) {
        return sprintf('%s (₱%s manual entry) is outside the reasonable range (max ₱%s per the current table). Please double-check, or switch it back to "Auto".', $label, number_format($value, 2), number_format($max, 2));
    }
    return true;
}

$computedRows = [];   // employeeId => authoritative recomputed figures
$rowErrors = [];       // employeeId => error message, if any
$discrepancyNotes = []; // employeeId => note, if client and server disagreed

foreach ($rows as $row) {
    $employeeId = (int) ($row['empId'] ?? 0);
    if ($employeeId <= 0) {
        continue; // malformed row — just skip it, same as before
    }

    $info = $empInfo[$employeeId] ?? null;
    if (!$info) {
        $rowErrors[$employeeId] = "Employee #$employeeId was not found — it may have been removed. Please refresh the page.";
        continue;
    }

    // Payroll/payslips only start once the employee has actually been
    // hired and started reporting for work — reject (not just trim)
    // any row for a pay period that ended before their Date Hired.
    // (Already hidden client-side in payroll.js — this is the
    // authoritative server-side enforcement so it can't be bypassed
    // via a direct POST.)
    if (!empty($info['date_hired']) && $info['date_hired'] > $periodEnd) {
        $rowErrors[$employeeId] = "{$info['first_name']} {$info['last_name']} was hired on " . date('M d, Y', strtotime($info['date_hired'])) . ", which is after this pay period ({$periodEnd}) — payroll for them can only start once they've actually been hired/started.";
        continue;
    }

    // Payroll can only be generated for employees who are still with
    // the company — 'resigned'/'terminated' employees are done being
    // paid as of their offboarding, so no new payroll record should
    // ever be created for them, no matter who's running payroll.
    // (Already hidden client-side — getPayrollScopedEmployees() only
    // lists 'active' employees to begin with — this is the
    // authoritative server-side enforcement so it can't be bypassed
    // via a direct POST using the ID of an offboarded employee.)
    if (!in_array($info['status'], ['active', 'on-leave'], true)) {
        $rowErrors[$employeeId] = "{$info['first_name']} {$info['last_name']} is marked as \"{$info['status']}\" and is no longer eligible for payroll processing.";
        continue;
    }

    $basicSalary = (float) $info['basic_salary'];
    $att = $attendanceSummary[$employeeId] ?? ['presentDays' => 0, 'otHours' => 0, 'undertimeHours' => 0];
    $days = (float) $att['presentDays'];
    $otHours = (float) $att['otHours'];
    $utHours = (float) $att['undertimeHours'];

    // ---- Basic / OT / Undertime pay — 100% server-computed from the
    //      real attendance summary + the employee's actual rate. The
    //      client's days/OT/undertime inputs are DISPLAY-ONLY as far
    //      as this endpoint is concerned; if attendance needs a
    //      correction, that belongs in the Attendance module itself,
    //      not a payroll override. ----
    $daily = $workDays > 0 ? $basicSalary / $workDays : 0;
    $basicPay = round($daily * $days, 2);
    $otRate = $workHours > 0 ? ($daily / $workHours) * $otMult : 0;
    $otPay = round($otHours * $otRate, 2);
    $utRate = $workHours > 0 ? $daily / $workHours : 0;
    $utPay = round($utHours * $utRate, 2);

    // ---- Allowance / Other Deductions — HR's discretionary entries,
    //      trusted but sanity-bounded. ----
    $discretionaryCap = __payrollReasonableDiscretionaryCap($basicSalary);
    $allow = round(max(0, (float) ($row['allow'] ?? 0)), 2);
    if ($allow > $discretionaryCap) {
        $rowErrors[$employeeId] = "Allowance for {$info['first_name']} {$info['last_name']} (₱" . number_format($allow, 2) . ") looks too high for this employee's salary. Please double-check.";
        continue;
    }
    $other = round(max(0, (float) ($row['other'] ?? 0)), 2);
    if ($other > $discretionaryCap) {
        $rowErrors[$employeeId] = "Other Deductions for {$info['first_name']} {$info['last_name']} (₱" . number_format($other, 2) . ") looks too high for this employee's salary. Please double-check.";
        continue;
    }

    $gross = round($basicPay + $otPay - $utPay + $allow, 2);

    // ---- SSS / PhilHealth / Pag-IBIG / Tax — auto-computed from the
    //      official tables UNLESS HR has switched that specific field
    //      to "Manual" (govAuto.<field> === false), in which case the
    //      client-entered value is accepted but validated against the
    //      statutory maximum for that contribution. ----
    $govAuto = is_array($row['govAuto'] ?? null) ? $row['govAuto'] : [];
    $autoGov = computeGovDeductionsForCutoff($basicSalary, $periodStart);

    $sssIsAuto = $govAuto['sss'] ?? true;
    if ($sssIsAuto) {
        $sss = $autoGov['sss'];
    } else {
        $sss = round((float) ($row['sss'] ?? 0), 2);
        $err = __validateGovManual('SSS', $sss, GOV_SSS_MAX_SHARE);
        if ($err !== true) { $rowErrors[$employeeId] = "{$info['first_name']} {$info['last_name']}: $err"; continue; }
    }

    $phicIsAuto = $govAuto['phic'] ?? true;
    if ($phicIsAuto) {
        $phic = $autoGov['phic'];
    } else {
        $phic = round((float) ($row['phic'] ?? 0), 2);
        $err = __validateGovManual('PhilHealth', $phic, GOV_PHIC_MAX_SHARE);
        if ($err !== true) { $rowErrors[$employeeId] = "{$info['first_name']} {$info['last_name']}: $err"; continue; }
    }

    $pagibigIsAuto = $govAuto['pagibig'] ?? true;
    if ($pagibigIsAuto) {
        $pagibig = $autoGov['pagibig'];
    } else {
        $pagibig = round((float) ($row['pagibig'] ?? 0), 2);
        $err = __validateGovManual('Pag-IBIG', $pagibig, GOV_PAGIBIG_MAX_SHARE);
        if ($err !== true) { $rowErrors[$employeeId] = "{$info['first_name']} {$info['last_name']}: $err"; continue; }
    }

    $taxIsAuto = $govAuto['tax'] ?? true;
    if ($taxIsAuto) {
        $taxableBase = $gross - $sss - $phic - $pagibig;
        $tax = computeWithholdingTaxSemiMonthly($taxableBase);
    } else {
        $tax = round((float) ($row['tax'] ?? 0), 2);
        // Tax obviously can't exceed the gross pay it's deducted from;
        // beyond that, cap it at a generous multiple of the flat 12%
        // policy rate (~3x) so a legitimate manual adjustment still
        // goes through, but a wildly-inflated number doesn't.
        $taxMax = min($gross, max($gross * 0.36, 1));
        $err = __validateGovManual('Tax', $tax, $taxMax);
        if ($err !== true) { $rowErrors[$employeeId] = "{$info['first_name']} {$info['last_name']}: $err"; continue; }
    }

    $totalDed = round($sss + $phic + $pagibig + $tax + $other, 2);
    $net = round(max(0, $gross - $totalDed), 2);

    // ---- Audit trail: note (don't block) if what the client showed
    //      on screen ended up different from what the server just
    //      computed — e.g. attendance was edited after the page
    //      loaded. The server's numbers below are what actually gets
    //      saved either way. ----
    $clientGross = round((float) ($row['gross'] ?? 0), 2);
    $clientNet   = round((float) ($row['net'] ?? 0), 2);
    if (abs($clientGross - $gross) > 1.00 || abs($clientNet - $net) > 1.00) {
        $discrepancyNotes[$employeeId] = sprintf(
            'Server recomputed this record from actual attendance/rate/gov tables — gross ₱%s (browser showed ₱%s), net ₱%s (browser showed ₱%s).',
            number_format($gross, 2), number_format($clientGross, 2),
            number_format($net, 2), number_format($clientNet, 2)
        );
    }

    $computedRows[$employeeId] = [
        'basicPay' => $basicPay, 'otPay' => $otPay, 'allow' => $allow, 'gross' => $gross,
        'sss' => $sss, 'phic' => $phic, 'pagibig' => $pagibig, 'tax' => $tax,
        'other' => $other, 'net' => $net,
    ];
}

// Reject the WHOLE batch if any row failed validation — a payroll
// save should be all-or-nothing, not a silent partial save.
if (!empty($rowErrors)) {
    jsonResponse(false, implode(' ', array_values($rowErrors)));
}
if (empty($computedRows)) {
    jsonResponse(false, 'No valid employee rows to save for this period.');
}

// -----------------------------------------------------------------
// STATUS TO COMPUTE FOR THIS AUTO-SAVE:
//   - Made by Admin          -> 'approved' (Admin approval; Finance must
//                                still record the release)
//   - HR Staff/Manager       -> 'draft' (auto-save only; not yet visible
//                                to Finance — HR must still click
//                                "Submit for Approval" on Payroll Run
//                                for it to become 'pending' — see
//                                payroll/submit_for_approval.php)
// A record that was previously 'approved'/'rejected'/'pending' but
// edited again by HR Staff/Manager STAYS 'draft' (not 'pending') —
// they must deliberately Submit for Approval again; it doesn't just
// automatically return to Finance's queue on every keystroke.
$__requiresApproval = ($actorRole !== 'admin');
$__approvalStatus = $__requiresApproval ? 'draft' : 'approved';

$generatedBy = (int) $_SESSION['user_id'];
$created = 0;
$updated = 0;

try {
    $pdo->beginTransaction();

    $findStmt = $pdo->prepare("
        SELECT payroll_id, basic_pay, overtime_pay, allowances, gross_pay,
               sss_deduction, philhealth_deduction, pagibig_deduction, tax_deduction,
               other_deductions, net_pay, payout_date, approval_status
        FROM payroll_records
        WHERE employee_id = ? AND pay_period_start = ? AND pay_period_end = ?
    ");
    $insertStmt = $pdo->prepare("
        INSERT INTO payroll_records
            (employee_id, pay_period_start, pay_period_end, payout_date, basic_pay, overtime_pay, allowances,
             gross_pay, sss_deduction, philhealth_deduction, pagibig_deduction, tax_deduction,
             other_deductions, net_pay, generated_by, approval_status, approved_by, approved_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    // NOTE: approval_status is computed fresh on every save UNLESS this
    // particular employee's row genuinely didn't change (see $unchanged
    // below) — autosave always re-sends EVERY active employee's row
    // together (not just the one HR actually edited), so without this
    // check, submitting Employee A for approval and then merely editing
    // Employee B's field would silently reset A back to 'draft' too on
    // the very next autosave. Only a row whose own numbers actually
    // differ from what's stored gets bumped back to 'draft' for HR or
    // remains 'approved' for Admin — untouched rows keep their approval
    // metadata and status.
    $updateStmtReset = $pdo->prepare("
        UPDATE payroll_records SET
            payout_date = ?, basic_pay = ?, overtime_pay = ?, allowances = ?, gross_pay = ?,
            sss_deduction = ?, philhealth_deduction = ?, pagibig_deduction = ?,
            tax_deduction = ?, other_deductions = ?, net_pay = ?, generated_by = ?,
            generated_at = CURRENT_TIMESTAMP, approval_status = ?, approved_by = ?, approved_at = ?
        WHERE payroll_id = ?
    ");
    $updateStmtKeep = $pdo->prepare("
        UPDATE payroll_records SET
            payout_date = ?, basic_pay = ?, overtime_pay = ?, allowances = ?, gross_pay = ?,
            sss_deduction = ?, philhealth_deduction = ?, pagibig_deduction = ?,
            tax_deduction = ?, other_deductions = ?, net_pay = ?, generated_by = ?,
            generated_at = CURRENT_TIMESTAMP
        WHERE payroll_id = ?
    ");
    $logStmt = $pdo->prepare("
        INSERT INTO payroll_logs (payroll_id, action, performed_by, notes)
        VALUES (?, ?, ?, ?)
    ");

    $periodLabel = "$periodStart to $periodEnd";
    $__rowStatuses = []; // employeeId => resulting approval_status, returned to the client

    foreach ($computedRows as $employeeId => $c) {
        $basicPay = $c['basicPay']; $otPay = $c['otPay']; $allow = $c['allow']; $gross = $c['gross'];
        $sss = $c['sss']; $phic = $c['phic']; $pagibig = $c['pagibig']; $tax = $c['tax'];
        $other = $c['other']; $net = $c['net'];

        $findStmt->execute([$employeeId, $periodStart, $periodEnd]);
        $existing = $findStmt->fetch();

        $note = $discrepancyNotes[$employeeId] ?? null;

        if ($existing) {
            $payrollId = (int) $existing['payroll_id'];

            $valuesChanged =
                abs((float) $existing['basic_pay'] - $basicPay) >= 0.01
                || abs((float) $existing['overtime_pay'] - $otPay) >= 0.01
                || abs((float) $existing['allowances'] - $allow) >= 0.01
                || abs((float) $existing['gross_pay'] - $gross) >= 0.01
                || abs((float) $existing['sss_deduction'] - $sss) >= 0.01
                || abs((float) $existing['philhealth_deduction'] - $phic) >= 0.01
                || abs((float) $existing['pagibig_deduction'] - $pagibig) >= 0.01
                || abs((float) $existing['tax_deduction'] - $tax) >= 0.01
                || abs((float) $existing['other_deductions'] - $other) >= 0.01
                || abs((float) $existing['net_pay'] - $net) >= 0.01
                || (string) $existing['payout_date'] !== (string) $payoutDate;

            if ($valuesChanged && in_array($existing['approval_status'], ['approved', 'n/a'], true)) {
                throw new RuntimeException('Released payroll cannot be edited. Create a documented payroll adjustment or reversal instead.');
            }

            $unchanged = !$valuesChanged && (
                ($__requiresApproval && in_array($existing['approval_status'], ['pending', 'approved', 'rejected'], true))
                || (!$__requiresApproval && in_array($existing['approval_status'], ['approved', 'n/a'], true))
            );

            if ($unchanged) {
                $updateStmtKeep->execute([
                    $payoutDate, $basicPay, $otPay, $allow, $gross,
                    $sss, $phic, $pagibig, $tax, $other, $net, $generatedBy,
                    $payrollId
                ]);
                $__rowStatuses[$employeeId] = $existing['approval_status'];
            } else {
                $updateStmt = $updateStmtReset;
                $updateStmt->execute([
                    $payoutDate, $basicPay, $otPay, $allow, $gross,
                    $sss, $phic, $pagibig, $tax, $other, $net, $generatedBy,
                    $__approvalStatus,
                    $__requiresApproval ? null : $generatedBy,
                    $__requiresApproval ? null : date('Y-m-d H:i:s'),
                    $payrollId
                ]);
                $__rowStatuses[$employeeId] = $__approvalStatus;
                $logStmt->execute([$payrollId, 'Payroll updated', $generatedBy, "Payroll record updated for period $periodLabel."
                    . ($__requiresApproval ? ' (Draft — "Submit for Approval" still needs to be clicked for an Accountant to see this.)' : ' (Approved by Admin; awaiting Finance release.)')
                    . ($note ? " $note" : '')]);
            }
            $updated++;
        } else {
            $insertStmt->execute([
                $employeeId, $periodStart, $periodEnd, $payoutDate, $basicPay, $otPay, $allow,
                $gross, $sss, $phic, $pagibig, $tax, $other, $net, $generatedBy, $__approvalStatus,
                $__requiresApproval ? null : $generatedBy,
                $__requiresApproval ? null : date('Y-m-d H:i:s')
            ]);
            $payrollId = (int) $pdo->lastInsertId();
            $__rowStatuses[$employeeId] = $__approvalStatus;
            $logStmt->execute([$payrollId, $__requiresApproval ? 'Payroll draft saved' : 'Payroll generated', $generatedBy,
                ($__requiresApproval
                    ? "New payroll record drafted (auto-save) for period $periodLabel — not yet submitted for Finance approval."
                    : "New payroll record approved by Admin for period $periodLabel; awaiting Finance release.")
                . ($note ? " $note" : '')]);
            $created++;
            // If net pay is ₱0 (or nothing) — e.g. the employee had no
            // time in / nothing to pay out for this period — no
            // "Salary Released" email should be sent since there's
            // really nothing to pay out.
            if (!$__requiresApproval) {
                $logStmt->execute([
                    $payrollId,
                    'Payroll approved',
                    $generatedBy,
                    "Payroll approved by Admin for period $periodLabel; awaiting separate Finance release."
                ]);
            }
        }
    }

    $pdo->commit();

    $__msg = $__requiresApproval
        ? "Saved as DRAFT: $created new record(s), $updated updated. Click \"Submit for Approval\" when you're done to send this to an Accountant."
        : "Saved as approved: $created new record(s), $updated updated. Finance must record the release before employees can view their payslips.";

    jsonResponse(true, $__msg, [
        'created'           => $created,
        'updated'           => $updated,
        'notified'          => 0,
        'requiresApproval'  => $__requiresApproval,
        'approvalStatus'    => $__approvalStatus,
        'approvalStatuses'  => $__rowStatuses,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('payroll/save_record.php error: ' . $e->getMessage());
    $message = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'An error occurred while saving to the database. Please try again.';
    jsonResponse(false, $message);
}