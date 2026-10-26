<?php
// ============================================================
// get_employee_payslips.php — AJAX endpoint used by the new
// "Payslip View" tab (payslip_lookup.php). Given ?employee_id=,
// returns the list of that employee's payroll_records that are
// allowed to be viewed/printed as a payslip (payslip.php applies
// the exact same rule when the record is actually opened, so the
// dropdown here can never offer something payslip.php would then
// block).
// ============================================================
require_once __DIR__ . '/../includes/config.php';
requireHR();
header('Content-Type: application/json');

$employeeId = (int) ($_GET['employee_id'] ?? 0);
if ($employeeId <= 0) {
    echo json_encode([]);
    exit;
}

$role = $_SESSION['role'] ?? '';

// HR lookup excludes draft/pending/rejected records. The actual payslip
// route separately enforces release before an employee can open it.
$sql = "
    SELECT payroll_id, pay_period_start, pay_period_end, payout_date,
           net_pay, approval_status, generated_at
    FROM payroll_records
    WHERE employee_id = ?
";
if ($role !== 'admin') {
    $sql .= " AND approval_status IN ('n/a', 'approved')";
}
$sql .= " ORDER BY pay_period_end DESC, generated_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$employeeId]);

$statusLabels = [
    'draft'    => 'Draft',
    'pending'  => 'Pending Finance Approval',
    'rejected' => 'Rejected',
    'approved' => 'Approved',
    'n/a'      => '',
];

$out = [];
foreach ($stmt->fetchAll() as $r) {
    $out[] = [
        'id'      => enc_id((int) $r['payroll_id']),
        'label'   => date('M d', strtotime($r['pay_period_start'])) . ' – ' . date('M d, Y', strtotime($r['pay_period_end'])),
        'status'  => $r['approval_status'],
        'statusLabel' => $statusLabels[$r['approval_status']] ?? '',
        'net'     => (float) $r['net_pay'],
        'payout'  => $r['payout_date'] ? date('M d, Y', strtotime($r['payout_date'])) : null,
    ];
}
echo json_encode($out);
