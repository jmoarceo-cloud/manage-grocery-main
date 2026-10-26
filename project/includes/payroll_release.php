<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// ============================================================
// includes/payroll_release.php (POS side)
// Finance's "Payroll Awaiting Release" — the LAST step of the payroll flow:
//   HR Staff prepares -> HR Manager approves -> FINANCE RELEASES (this file).
//
// There is no "finance" role in HRMS. Finance accounts only exist in the POS
// database, so everything here reads/writes hrms.payroll_records through
// $hrmsPdo. HRMS stores approved_by / performed_by as HRMS users.id, which is
// NOT the same number as the POS users.id, so we look it up by employee_id.
//
// State machine (no schema change):
//   approved + unpaid  = "awaiting release"   (listed here)
//   approved + paid    = released by Finance  (paid_at, payment_processed_by,
//                                              payment_processed_at, reference)
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../HRMS/includes/mailer.php';
require_once __DIR__ . '/../HRMS/payroll/workflow.php';

// Build links that remain valid when the POS is installed in a subdirectory.
// Keep this local to the payroll helper because older wala config.php versions
// do not yet expose the source application's posFullBaseUrl() helper.
if (!function_exists('posFullBaseUrl')) {
    function posFullBaseUrl() {
        if (defined('APP_PUBLIC_BASE_URL') && trim(APP_PUBLIC_BASE_URL) !== '') {
            return rtrim(APP_PUBLIC_BASE_URL, '/');
        }

        $configuredUrl = trim((string) env('APP_URL', ''));
        if ($configuredUrl !== '' && filter_var($configuredUrl, FILTER_VALIDATE_URL)) {
            return rtrim($configuredUrl, '/');
        }
        $scheme = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443)
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        ) ? 'https' : 'http';
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        // Apache/XAMPP can expose a local virtual-host label such as
        // "wala". That is not resolvable by an external supplier, so use
        // the browser-accessible local host instead of generating a broken
        // email link.
        if ($host === '' || ($host !== 'localhost' && !filter_var($host, FILTER_VALIDATE_IP) && strpos($host, '.') === false)) {
            $host = 'localhost';
        }
        $base = $scheme . '://' . $host;
        return filter_var($base, FILTER_VALIDATE_URL) ? $base : 'http://localhost';
    }
}

if (!function_exists('posApplicationBaseUrl')) {
    function posApplicationBaseUrl() {
        $configured = '';
        if (defined('APP_PUBLIC_BASE_URL') && trim(APP_PUBLIC_BASE_URL) !== '') {
            $configured = trim(APP_PUBLIC_BASE_URL);
        } else {
            $configured = trim((string) env('APP_URL', ''));
        }
        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_URL)) {
            $parts = parse_url($configured);
            $path = rtrim((string) ($parts['path'] ?? ''), '/');
            if ($path !== '') {
                return rtrim($configured, '/');
            }
            return rtrim($configured, '/') . systemBaseUrl();
        }
        return rtrim(posFullBaseUrl(), '/') . systemBaseUrl();
    }
}

// HRMS users.id of the logged-in Finance user, matched by employee_id.
// Returns 0 if the Finance account has no HRMS user (cannot be audited).
function financeHrmsUserId($hrmsPdo, $employeeId) {
    $employeeId = (int) $employeeId;
    if (!$hrmsPdo || $employeeId <= 0) return 0;
    try {
        $stmt = $hrmsPdo->prepare("SELECT id FROM users WHERE employee_id = ? LIMIT 1");
        $stmt->execute([$employeeId]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('financeHrmsUserId error: ' . $e->getMessage());
        return 0;
    }
}

function payrollFinanceActorHrmsId($hrmsPdo, $financeEmployeeId) {
    if (($_SESSION['role'] ?? '') === 'finance') {
        return financeHrmsUserId($hrmsPdo, $financeEmployeeId);
    }
    if (($_SESSION['role'] ?? '') !== 'admin' || !$hrmsPdo) {
        return 0;
    }
    try {
        $stmt = $hrmsPdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$_SESSION['username'] ?? '']);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('payrollFinanceActorHrmsId error: ' . $e->getMessage());
        return 0;
    }
}

function getPayrollAwaitingFinanceApproval($hrmsPdo) {
    if (!$hrmsPdo) return [];
    try {
        $stmt = $hrmsPdo->query("
            SELECT pr.payroll_id, pr.employee_id, pr.pay_period_start, pr.pay_period_end,
                   pr.payout_date, pr.net_pay, pr.generated_by,
                   e.first_name, e.last_name, e.department, e.status AS emp_status,
                   gu.full_name AS generated_by_name
            FROM payroll_records pr
            JOIN employees e ON e.employee_id = pr.employee_id
            LEFT JOIN users gu ON gu.id = pr.generated_by
            WHERE pr.approval_status = 'pending' AND pr.payment_status = 'unpaid'
            ORDER BY pr.pay_period_end DESC, pr.pay_period_start DESC, e.last_name, e.first_name
        ");
    } catch (PDOException $e) {
        error_log('getPayrollAwaitingFinanceApproval error: ' . $e->getMessage());
        return [];
    }

    $batches = [];
    foreach ($stmt->fetchAll() as $r) {
        $key = $r['pay_period_start'] . '_' . $r['pay_period_end'] . '_' . $r['generated_by'];
        if (!isset($batches[$key])) {
            $batches[$key] = [
                'periodStart' => $r['pay_period_start'],
                'periodEnd' => $r['pay_period_end'],
                'periodLabel' => date('M d', strtotime($r['pay_period_start'])) . '–' . date('M d, Y', strtotime($r['pay_period_end'])),
                'payoutDate' => $r['payout_date'],
                'generatedBy' => (int) $r['generated_by'],
                'submittedBy' => $r['generated_by_name'],
                'rows' => [],
                'total' => 0.0,
            ];
        }
        $net = (float) $r['net_pay'];
        $batches[$key]['rows'][] = [
            'payrollId' => (int) $r['payroll_id'],
            'name' => $r['last_name'] . ', ' . $r['first_name'],
            'department' => $r['department'],
            'gross' => 0.0,
            'net' => $net,
            'status' => $r['emp_status'],
        ];
        $batches[$key]['total'] += $net;
    }
    return array_values($batches);
}

function decidePayrollBatchByFinance($hrmsPdo, $periodStart, $periodEnd, $generatedBy, $action, $financeEmployeeId = 0) {
    if (!in_array($_SESSION['role'] ?? '', ['finance', 'admin'], true)) {
        return ['ok' => false, 'message' => 'Only Finance or Admin can approve payroll.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $periodStart)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $periodEnd)
        || (int) $generatedBy <= 0
        || !in_array($action, ['approve', 'reject'], true)) {
        return ['ok' => false, 'message' => 'Invalid payroll approval request.'];
    }
    $actorId = payrollFinanceActorHrmsId($hrmsPdo, $financeEmployeeId);
    if ($actorId <= 0) {
        return ['ok' => false, 'message' => 'Your Finance/Admin account is not linked to an HRMS user, so the decision cannot be audited.'];
    }
    try {
        $hrmsPdo->beginTransaction();
        $find = $hrmsPdo->prepare("
            SELECT payroll_id FROM payroll_records
            WHERE pay_period_start = ? AND pay_period_end = ?
              AND generated_by = ? AND approval_status = 'pending'
              AND payment_status = 'unpaid'
            FOR UPDATE
        ");
        $find->execute([$periodStart, $periodEnd, (int) $generatedBy]);
        $rows = $find->fetchAll(PDO::FETCH_COLUMN);
        if (!$rows) {
            $hrmsPdo->rollBack();
            return ['ok' => false, 'message' => 'There is no pending payroll approval for this batch.'];
        }
        $nextStatus = payrollApprovalTransition('pending', $action, $_SESSION['role']);
        if ($nextStatus === null) {
            $hrmsPdo->rollBack();
            return ['ok' => false, 'message' => 'This role cannot approve or reject payroll.'];
        }
        $update = $hrmsPdo->prepare("
            UPDATE payroll_records SET approval_status = ?, approved_by = ?, approved_at = NOW()
            WHERE payroll_id = ? AND approval_status = 'pending' AND payment_status = 'unpaid'
        ");
        $log = $hrmsPdo->prepare("
            INSERT INTO payroll_logs (payroll_id, action, performed_by, notes)
            VALUES (?, ?, ?, ?)
        ");
        $periodLabel = "$periodStart to $periodEnd";
        $processed = 0;
        foreach ($rows as $payrollId) {
            $update->execute([$nextStatus, $actorId, (int) $payrollId]);
            if ($update->rowCount() !== 1) continue;
            $log->execute([
                (int) $payrollId,
                $action === 'approve' ? 'Payroll approved by Finance' : 'Payroll rejected by Finance',
                $actorId,
                $action === 'approve'
                    ? "Payroll approved by " . ucfirst($_SESSION['role']) . " for period $periodLabel; awaiting separate Finance release."
                    : "Payroll rejected by " . ucfirst($_SESSION['role']) . " for period $periodLabel."
            ]);
            $processed++;
        }
        if ($processed === 0) {
            $hrmsPdo->rollBack();
            return ['ok' => false, 'message' => 'The payroll batch was already processed.'];
        }
        $hrmsPdo->commit();
        return [
            'ok' => true,
            'message' => $action === 'approve'
                ? "Approved $processed employee payroll record(s). Finance can now release the approved batch."
                : "Rejected $processed employee payroll record(s).",
        ];
    } catch (PDOException $e) {
        if ($hrmsPdo->inTransaction()) $hrmsPdo->rollBack();
        error_log('decidePayrollBatchByFinance error: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not save the Finance payroll decision. Please try again.'];
    }
}

// All Finance-approved payroll that Finance has not released yet, grouped into
// batches (pay period + who prepared it). Rows with PHP 0.00 net pay are
// hidden: there is nothing to pay, so they should not add noise.
// $financeEmployeeId is only used to flag the viewer's own salary row.
function getPayrollAwaitingRelease($hrmsPdo, $financeEmployeeId = 0) {
    if (!$hrmsPdo) return [];
    $financeEmployeeId = (int) $financeEmployeeId;

    try {
        $stmt = $hrmsPdo->query("
            SELECT pr.payroll_id, pr.employee_id, pr.pay_period_start, pr.pay_period_end,
                   pr.payout_date, pr.net_pay, pr.approved_at, pr.generated_by,
                   e.first_name, e.last_name, e.department, e.status AS emp_status, e.payment_method,
                   au.full_name AS approved_by_name,
                   gu.full_name AS generated_by_name
            FROM payroll_records pr
            JOIN employees e ON e.employee_id = pr.employee_id
            LEFT JOIN users au ON au.id = pr.approved_by
            LEFT JOIN users gu ON gu.id = pr.generated_by
            WHERE pr.approval_status = 'approved'
              AND pr.payment_status = 'unpaid'
              AND pr.net_pay > 0
            ORDER BY pr.pay_period_end DESC, pr.pay_period_start DESC, e.last_name, e.first_name
        ");
    } catch (PDOException $e) {
        error_log('getPayrollAwaitingRelease error: ' . $e->getMessage());
        return [];
    }

    $batches = [];
    foreach ($stmt->fetchAll() as $r) {
        $key = $r['pay_period_start'] . '_' . $r['pay_period_end'] . '_' . $r['generated_by'];
        if (!isset($batches[$key])) {
            $batches[$key] = [
                'periodStart'  => $r['pay_period_start'],
                'periodEnd'    => $r['pay_period_end'],
                'periodLabel'  => date('M d', strtotime($r['pay_period_start'])) . '–' . date('M d, Y', strtotime($r['pay_period_end'])),
                'payoutDate'   => $r['payout_date'],
                'generatedBy'  => (int) $r['generated_by'],
                'preparedBy'   => $r['generated_by_name'],
                'rows'         => [],
                'total'        => 0.0,   // everything in the batch
                'releasable'   => 0.0,   // what a release click would actually pay
                'blockedCount' => 0,
            ];
        }

        $blocked = '';
        if (!in_array($r['emp_status'], ['active', 'on-leave'], true)) {
            $blocked = 'Marked "' . $r['emp_status'] . '" — will be skipped';
        } elseif ($financeEmployeeId > 0 && (int) $r['employee_id'] === $financeEmployeeId) {
            $blocked = 'Your own salary — another Finance user must release it';
        }

        $net = (float) $r['net_pay'];
        $batches[$key]['rows'][] = [
            'payrollId'  => (int) $r['payroll_id'],
            'empId'      => (int) $r['employee_id'],
            'name'       => $r['last_name'] . ', ' . $r['first_name'],
            'dept'       => $r['department'],
            'method'     => ($r['payment_method'] ?? 'bank') === 'cash' ? 'Cash (Walk-in)' : 'Bank / Card',
            'net'        => $net,
            'approvedBy' => $r['approved_by_name'],
            'approvedAt' => $r['approved_at'],
            'blocked'    => $blocked,
        ];
        $batches[$key]['total'] += $net;
        if ($blocked === '') {
            $batches[$key]['releasable'] += $net;
        } else {
            $batches[$key]['blockedCount']++;
        }
    }
    return array_values($batches);
}

// Release one batch. Returns ['ok' => bool, 'message' => string].
//   - one transaction on HRMS; rows are locked FOR UPDATE and only rows that are
//     STILL approved + unpaid are paid, so a double click can never double pay
//   - blocked: employee no longer active/on-leave, or the Finance user's own row
//   - cash check: refuses if the batch is bigger than Available Cash
//   - emails are sent AFTER the commit (net pay > 0 only)
function releasePayrollBatch($hrmsPdo, $pdo, $periodStart, $periodEnd, $generatedBy, $financeEmployeeId, $reference = '') {
    if (!in_array($_SESSION['role'] ?? '', ['finance', 'admin'], true)) {
        return ['ok' => false, 'message' => 'Only Finance or Admin can release payroll.'];
    }
    if (!$hrmsPdo) {
        return ['ok' => false, 'message' => 'Cannot reach the HRMS database right now, so nothing was released.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $periodStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $periodEnd) || (int) $generatedBy <= 0) {
        return ['ok' => false, 'message' => 'Invalid payroll batch.'];
    }

    $financeHrmsId = payrollFinanceActorHrmsId($hrmsPdo, $financeEmployeeId);
    if ($financeHrmsId <= 0) {
        return ['ok' => false, 'message' => 'Your Finance account is not linked to an HRMS employee/user, so the release cannot be recorded. Please ask HR to link it.'];
    }

    $reference = trim((string) $reference);
    $reference = $reference === '' ? null : mb_substr($reference, 0, 100);
    $financeName = $_SESSION['full_name'] ?? 'Finance';

    try {
        $hrmsPdo->beginTransaction();

        $find = $hrmsPdo->prepare("
            SELECT pr.payroll_id, pr.employee_id, pr.net_pay, pr.payout_date,
                   e.email, e.first_name, e.last_name, e.bank_name, e.bank_account_number,
                   e.payment_method, e.status AS emp_status
            FROM payroll_records pr
            JOIN employees e ON e.employee_id = pr.employee_id
            WHERE pr.pay_period_start = ? AND pr.pay_period_end = ? AND pr.generated_by = ?
              AND pr.approval_status = 'approved' AND pr.payment_status = 'unpaid'
            FOR UPDATE
        ");
        $find->execute([$periodStart, $periodEnd, (int) $generatedBy]);
        $rows = $find->fetchAll();

        if (empty($rows)) {
            $hrmsPdo->rollBack();
            return ['ok' => false, 'message' => 'There is no approved payroll waiting for release here anymore (it may already have been released).'];
        }

        $toPay = []; $blocked = [];
        foreach ($rows as $r) {
            $isOwn    = (int) $financeEmployeeId > 0 && (int) $r['employee_id'] === (int) $financeEmployeeId;
            $inactive = !in_array($r['emp_status'], ['active', 'on-leave'], true);
            if ($isOwn || $inactive) {
                $blocked[] = trim($r['first_name'] . ' ' . $r['last_name']) . ($isOwn ? ' (your own salary)' : " ({$r['emp_status']})");
            } else {
                $toPay[] = $r;
            }
        }
        if (empty($toPay)) {
            $hrmsPdo->rollBack();
            return ['ok' => false, 'message' => 'Nothing can be released in this batch: ' . implode(', ', $blocked) . '.'];
        }

        // Cash check: Available Cash already subtracts everything PAID, so the
        // batch we are about to pay must fit inside it.
        $total = 0.0;
        foreach ($toPay as $r) { $total += (float) $r['net_pay']; }
        $cash = getBusinessCashPosition($pdo, $hrmsPdo);
        if (empty($cash['payroll_data_available'])) {
            $hrmsPdo->rollBack();
            return ['ok' => false, 'message' => 'Available cash could not be verified against payroll records. No salary was released. Please check the HRMS connection and try again.'];
        }
        if ($total > (float) $cash['available_cash'] + 0.005) {
            $hrmsPdo->rollBack();
            $short = $total - (float) $cash['available_cash'];
            return ['ok' => false, 'message' => 'Not enough Available Cash to release this batch: it needs ' . peso($total)
                . ' but only ' . peso($cash['available_cash']) . ' is available (short by ' . peso($short)
                . '). Add capital first (Profit & Business Capital, below), then release again.'];
        }

        $upd = $hrmsPdo->prepare("
            UPDATE payroll_records
            SET payment_status = 'paid', paid_at = NOW(),
                payment_processed_by = ?, payment_processed_at = NOW(),
                payment_reference_number = ?
            WHERE payroll_id = ? AND approval_status = 'approved' AND payment_status = 'unpaid'
        ");
        $log = $hrmsPdo->prepare("
            INSERT INTO payroll_logs (payroll_id, action, performed_by, notes)
            VALUES (?, 'Payroll released', ?, ?)
        ");

        $periodLabel = "$periodStart to $periodEnd";
        $released = [];
        foreach ($toPay as $r) {
            if (!payrollCanRelease('approved', 'unpaid', $_SESSION['role'])) { continue; }
            $upd->execute([$financeHrmsId, $reference, (int) $r['payroll_id']]);
            if ($upd->rowCount() !== 1) { continue; }
            $log->execute([
                (int) $r['payroll_id'], $financeHrmsId,
                "Salary released by Finance ($financeName) for period $periodLabel."
                . ($reference !== null ? " Reference: $reference." : '')
            ]);
            $released[] = $r;
        }
        $hrmsPdo->commit();
    } catch (PDOException $e) {
        if ($hrmsPdo->inTransaction()) { $hrmsPdo->rollBack(); }
        error_log('releasePayrollBatch error: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'An error occurred while releasing the payroll. Nothing was changed. Please try again.'];
    }

    // "Salary Released" emails — only after the commit, only for net pay > 0.
    $notified = 0;
    $periodHuman = date('M j', strtotime($periodStart)) . ' – ' . date('M j, Y', strtotime($periodEnd));
    $appUrl = posFullBaseUrl() . str_replace(' ', '%20', rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\'));
    foreach ($released as $r) {
        if ((float) $r['net_pay'] <= 0 || empty($r['email'])) continue;
        $payslipUrl = $appUrl . '/HRMS/payslip.php?id=' . enc_id($r['payroll_id']);
        if (sendPayrollReleasedEmail(
            $r['email'], trim($r['first_name'] . ' ' . $r['last_name']), $periodHuman,
            (float) $r['net_pay'], $r['payout_date'], $r['bank_name'] ?? '', $r['bank_account_number'] ?? '',
            $payslipUrl, $r['payment_method'] ?? 'bank'
        )) { $notified++; }
    }

    // PHP 0.00 rows are settled silently along with the batch; do not count them.
    $paidCount = count(array_filter($released, fn($r) => (float) $r['net_pay'] > 0));
    $msg = 'Released ' . peso($total) . ' to ' . $paidCount . ' employee(s) — marked as paid and deducted from Available Cash (' . $notified . ' email(s) sent).';
    if (!empty($blocked)) {
        $msg .= ' NOT released (still waiting): ' . implode(', ', $blocked) . '.';
    }
    return ['ok' => true, 'message' => $msg];
}
