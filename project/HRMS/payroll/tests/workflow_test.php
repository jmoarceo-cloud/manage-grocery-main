<?php
require_once __DIR__ . '/../workflow.php';

$systemRoot = dirname(__DIR__, 3);
$approvalEndpoint = file_get_contents(__DIR__ . '/../approve_record.php');
$submissionEndpoint = file_get_contents(__DIR__ . '/../submit_for_approval.php');
$saveEndpoint = file_get_contents(__DIR__ . '/../save_record.php');
$releaseHelper = file_get_contents($systemRoot . '/includes/payroll_release.php');
$employeePayslip = file_get_contents($systemRoot . '/payslip_view.php');
$hrmsPayslip = file_get_contents($systemRoot . '/HRMS/payslip.php');
$employeeProfile = file_get_contents($systemRoot . '/HRMS/my_profile.php');
$employeeDashboard = file_get_contents($systemRoot . '/HRMS/employee_dashboard.php');
$posProfile = file_get_contents($systemRoot . '/my_profile.php');
$cashierProfile = file_get_contents($systemRoot . '/my_profile.php');
foreach ([$approvalEndpoint, $submissionEndpoint, $saveEndpoint, $releaseHelper, $employeePayslip, $hrmsPayslip, $employeeProfile, $employeeDashboard, $posProfile, $cashierProfile] as $source) {
    if ($source === false) {
        throw new RuntimeException('Could not load payroll workflow source for access-contract tests.');
    }
}

$assertSource = static function ($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assertSource(
    strpos($submissionEndpoint, "['hr', 'hr_manager']") !== false
        && strpos($submissionEndpoint, "SET approval_status = ?") !== false
        && strpos($saveEndpoint, "\$__approvalStatus = \$__requiresApproval ? 'draft' : 'approved'") !== false,
    'HR must save drafts and explicitly submit them before Finance approval.'
);
$assertSource(
    strpos($approvalEndpoint, "['finance', 'admin']") !== false
        && strpos($releaseHelper, "['finance', 'admin']") !== false
        && strpos($approvalEndpoint, "['finance', 'admin', 'accountant']") === false
        && strpos($releaseHelper, "['finance', 'admin', 'accountant']") === false,
    'Payroll approval and release must be restricted to Finance/Admin, not Accountant.'
);
$assertSource(
    strpos($releaseHelper, "AND pr.approval_status = 'approved' AND pr.payment_status = 'unpaid'") !== false
        && strpos($releaseHelper, "SET payment_status = 'paid'") !== false,
    'Release must only mark Finance-approved unpaid payroll as paid.'
);
$assertSource(
    strpos($employeePayslip, 'payrollIsReleased($p[\'approval_status\'], $p[\'payment_status\'])') !== false
        && strpos($employeePayslip, "HRMS/payroll/workflow.php") !== false
        && strpos($hrmsPayslip, 'payrollIsReleased($p[\'approval_status\'], $p[\'payment_status\'])') !== false
        && strpos($employeePayslip, 'PREVIEW — NOT RELEASED') !== false
        && strpos($hrmsPayslip, 'PREVIEW — NOT RELEASED') !== false
        && strpos($employeePayslip, '&& !$isPreview') !== false
        && strpos($hrmsPayslip, '&& !$isPreview') !== false
        && strpos($employeeProfile, "approval_status IN ('approved', 'n/a')") !== false
        && strpos($employeeProfile, "payment_status = 'paid'") !== false,
    'Employees may view released payslips and only their own approved upcoming payroll as a marked preview.'
);
$assertSource(
    strpos($employeeProfile, "approval_status = 'approved' AND payment_status = 'unpaid' AND payout_date > CURDATE()") !== false
        && strpos($employeeDashboard, "approval_status = 'approved' AND payment_status = 'unpaid' AND payout_date > CURDATE()") !== false
        && strpos($employeeProfile, 'elseif ($pay[\'payment_status\'] === \'paid\')') !== false
        && strpos($employeeDashboard, 'elseif ($pay[\'payment_status\'] === \'paid\')') !== false
        && strpos($employeeProfile, '>View Payslip</a>') !== false
        && strpos($employeeProfile, 'payslip.php?id=<?= enc_id($cp[\'payroll_id\']) ?>') !== false
        && strpos($employeeDashboard, '>View Payslip</a>') !== false,
    'Employees must see upcoming approved previews, and released salary history must retain its View Payslip action.'
);
$assertSource(
    strpos($posProfile, "approval_status = 'approved' AND payment_status = 'unpaid' AND payout_date > CURDATE()") !== false
        && strpos($posProfile, 'payslip_view.php?id=<?= enc_id($cp[\'payroll_id\']) ?>') !== false
        && strpos($posProfile, '>View Payslip</a>') !== false
        && strpos($posProfile, 'View / Print') === false
        && strpos($posProfile, 'onclick="return openPayslipModal(this.href);"') !== false,
    'POS My Profile must show the View Payslip modal for approved upcoming payroll.'
);
$assertSource(
    strpos($cashierProfile, "approval_status = 'approved'") !== false
        && strpos($cashierProfile, "payment_status = 'paid'") !== false,
    'Cashier payroll listings must require Finance approval and salary release.'
);

$cases = [
    ['pending', 'approve', 'finance', 'approved'],
    ['pending', 'reject', 'finance', 'rejected'],
    ['pending', 'approve', 'admin', 'approved'],
    ['pending', 'approve', 'accountant', null],
    ['pending', 'approve', 'hr', null],
    ['draft', 'approve', 'finance', null],
    ['approved', 'approve', 'finance', null],
];

foreach ([
    ['draft', 'hr', 'pending'],
    ['draft', 'hr_manager', 'pending'],
    ['draft', 'accountant', null],
    ['approved', 'hr', null],
] as [$current, $role, $expected]) {
    if (payrollSubmissionTransition($current, $role) !== $expected) {
        throw new RuntimeException("Unexpected submission transition for $role from $current.");
    }
}

foreach ($cases as [$current, $action, $role, $expected]) {
    if (payrollApprovalTransition($current, $action, $role) !== $expected) {
        throw new RuntimeException("Unexpected $role transition $current -> $action.");
    }
}

$releaseCases = [
    ['pending', 'unpaid', 'finance', false],
    ['pending', 'unpaid', 'accountant', false],
    ['approved', 'unpaid', 'accountant', false],
    ['approved', 'unpaid', 'hr', false],
    ['approved', 'unpaid', 'finance', true],
    ['approved', 'unpaid', 'admin', true],
    ['approved', 'paid', 'finance', false],
];
foreach ($releaseCases as [$approval, $payment, $role, $expected]) {
    if (payrollCanRelease($approval, $payment, $role) !== $expected) {
        throw new RuntimeException("Unexpected release permission for $role $approval/$payment.");
    }
}

$visibilityCases = [
    ['approved', 'paid', true],
    ['n/a', 'paid', true],
    ['approved', 'unpaid', false],
    ['pending', 'paid', false],
    ['draft', 'paid', false],
    ['rejected', 'paid', false],
];

foreach ($visibilityCases as [$approval, $payment, $expected]) {
    if (payrollIsReleased($approval, $payment) !== $expected) {
        throw new RuntimeException("Unexpected payslip visibility for $approval/$payment.");
    }
}

echo "Payroll workflow tests passed.\n";
