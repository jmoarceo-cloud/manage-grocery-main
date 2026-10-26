<?php
// ============================================================
// payroll/submit_for_approval.php
// AJAX endpoint called by the "Submit Selected for Approval" button on
// Payroll Run (payroll.js: submitPeriodForApproval()). Used
// only by HR Staff/HR Manager — this is where they deliberately
// send specific employees' DRAFT payroll records for Finance/Admin approval
// decision (approval_status: 'draft' -> 'pending'). HR ticks which
// employees to include (checkbox column) — this is NOT all-or-nothing
// for the whole period; the rest stay as Draft until submitted later.
//
// Before this is clicked, only auto-save happens while HR is
// typing (approval_status = 'draft') — it cannot be reviewed yet.
// It only shows up in the "Pending Payroll Approvals"
// panel once submitted (see getPendingPayrollApprovals() in config.php).
// ============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/workflow.php';

// ---- Auth guard: HR Staff/HR Manager only (JSON version) ----
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['hr', 'hr_manager'], true)) {
    jsonResponse(false, 'Not logged in as HR Staff/HR Manager, or you do not have access to this.');
}
$_SESSION['last_activity'] = time();

requireCSRFJson();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonResponse(false, 'Invalid request payload.');
}

$periodStart = $input['periodStart'] ?? '';
$periodEnd   = $input['periodEnd'] ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) {
    jsonResponse(false, 'Invalid pay period dates.');
}

// employeeIds: which employees HR actually ticked to submit. Required —
// no more "submit the whole period at once" fallback, since that's
// exactly the all-or-nothing behavior this per-employee selection
// replaces.
$employeeIds = array_values(array_unique(array_map('intval', $input['employeeIds'] ?? [])));
$employeeIds = array_filter($employeeIds, fn($id) => $id > 0);
if (empty($employeeIds)) {
    jsonResponse(false, 'Select at least one employee to submit.');
}

$actorId = (int) $_SESSION['user_id'];
$nextStatus = payrollSubmissionTransition('draft', $_SESSION['role']);
if ($nextStatus === null) {
    jsonResponse(false, 'This role cannot submit payroll for approval.');
}

try {
    $pdo->beginTransaction();

    // Only the currently logged-in HR user's own draft records, for
    // this period, AND only for the employee(s) they actually ticked —
    // it must still be 'draft' (if it's already 'pending'/'approved',
    // nothing happens to it, silently skipped).
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    $findStmt = $pdo->prepare("
        SELECT payroll_id, employee_id FROM payroll_records
        WHERE pay_period_start = ? AND pay_period_end = ?
          AND generated_by = ? AND approval_status = 'draft'
          AND employee_id IN ($placeholders)
        FOR UPDATE
    ");
    $findStmt->execute(array_merge([$periodStart, $periodEnd, $actorId], $employeeIds));
    $rows = $findStmt->fetchAll();

    if (empty($rows)) {
        $pdo->rollBack();
        jsonResponse(false, 'None of the selected employees have a draft payroll for this period anymore (it may already be submitted, or the data has been refreshed) — please refresh and try again.');
    }

    $updateStmt = $pdo->prepare("
        UPDATE payroll_records SET approval_status = ?
        WHERE payroll_id = ?
    ");
    $logStmt = $pdo->prepare("
        INSERT INTO payroll_logs (payroll_id, action, performed_by, notes)
        VALUES (?, ?, ?, ?)
    ");

    $periodLabel = "$periodStart to $periodEnd";
    $submittedIds = [];
    foreach ($rows as $r) {
        $updateStmt->execute([$nextStatus, (int) $r['payroll_id']]);
        $logStmt->execute([
            (int) $r['payroll_id'],
            'Payroll submitted for approval',
            $actorId,
            "Payroll record submitted for Finance/Admin approval for period $periodLabel."
        ]);
        $submittedIds[] = (int) $r['employee_id'];
    }

    $pdo->commit();

    $skipped = count($employeeIds) - count($rows);
    $msg = 'Sent for Finance/Admin approval: ' . count($rows) . ' employee(s).'
        . ($skipped > 0 ? " ($skipped selected employee(s) were skipped — no longer draft.)" : '')
        . ' You will be notified (via History) once this is approved or rejected.';

    jsonResponse(true, $msg, [
        'submitted'    => count($rows),
        'submittedIds' => $submittedIds,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('payroll/submit_for_approval.php error: ' . $e->getMessage());
    jsonResponse(false, 'An error occurred while submitting. Please try again.');
}