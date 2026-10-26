<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/workflow.php';

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['finance', 'admin'], true)) {
    jsonResponse(false, 'Only Finance or Admin can approve payroll.');
}
$_SESSION['last_activity'] = time();
requireCSRFJson();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonResponse(false, 'Invalid request payload.');
}
$action = $input['action'] ?? '';
$periodStart = $input['periodStart'] ?? '';
$periodEnd = $input['periodEnd'] ?? '';
$generatedBy = (int) ($input['generatedBy'] ?? 0);
if (!in_array($action, ['approve', 'reject'], true)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)
    || $periodEnd < $periodStart
    || $generatedBy <= 0) {
    jsonResponse(false, 'Invalid payroll approval request.');
}

$actorId = (int) ($_SESSION['user_id'] ?? 0);
if ($role === 'finance') {
    $actorStmt = $pdo->prepare('SELECT id FROM users WHERE employee_id = ? LIMIT 1');
    $actorStmt->execute([(int) ($_SESSION['employee_id'] ?? 0)]);
    $actorId = (int) $actorStmt->fetchColumn();
}
if ($actorId <= 0) {
    jsonResponse(false, 'Your Finance/Admin account is not linked to an HRMS user, so the decision cannot be audited.');
}

try {
    $pdo->beginTransaction();
    $find = $pdo->prepare("
        SELECT payroll_id
        FROM payroll_records
        WHERE pay_period_start = ? AND pay_period_end = ?
          AND generated_by = ? AND approval_status = 'pending'
          AND payment_status = 'unpaid'
        FOR UPDATE
    ");
    $find->execute([$periodStart, $periodEnd, $generatedBy]);
    $payrollIds = $find->fetchAll(PDO::FETCH_COLUMN);
    if (!$payrollIds) {
        $pdo->rollBack();
        jsonResponse(false, 'There is no pending payroll approval for this batch.');
    }
    $nextStatus = payrollApprovalTransition('pending', $action, $role);
    if ($nextStatus === null) {
        $pdo->rollBack();
        jsonResponse(false, 'This role cannot approve payroll.');
    }

    $update = $pdo->prepare("
        UPDATE payroll_records SET approval_status = ?, approved_by = ?, approved_at = NOW()
        WHERE payroll_id = ? AND approval_status = 'pending' AND payment_status = 'unpaid'
    ");
    $log = $pdo->prepare("
        INSERT INTO payroll_logs (payroll_id, action, performed_by, notes)
        VALUES (?, ?, ?, ?)
    ");
    $periodLabel = "$periodStart to $periodEnd";
    $processed = 0;
    foreach ($payrollIds as $payrollId) {
        $update->execute([$nextStatus, $actorId, (int) $payrollId]);
        if ($update->rowCount() !== 1) continue;
        $log->execute([
            (int) $payrollId,
            $action === 'approve' ? 'Payroll approved by Finance' : 'Payroll rejected by Finance',
            $actorId,
            $action === 'approve'
                ? "Payroll approved by " . ucfirst($role) . " for period $periodLabel; awaiting separate Finance release."
                : "Payroll rejected by " . ucfirst($role) . " for period $periodLabel."
        ]);
        $processed++;
    }
    if (!$processed) {
        $pdo->rollBack();
        jsonResponse(false, 'The payroll batch was already processed.');
    }
    $pdo->commit();
    jsonResponse(true, $action === 'approve'
        ? "Finance/Admin approved $processed payroll record(s). The batch is now eligible for Finance release."
        : "Finance/Admin rejected $processed payroll record(s).", ['rows' => $processed, 'status' => $nextStatus]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('payroll/approve_record.php error: ' . $e->getMessage());
    jsonResponse(false, 'Could not save the Finance payroll decision. Please try again.');
}
