<?php
// ============================================================
// payslip.php — A printable payslip (Print / Save as PDF)
// ------------------------------------------------------------
// Access:
//   - Employee      : only their own payslip
//   - Admin/HR/HRM  : anyone's payslip
// Called via: payslip.php?id=<payroll_id>
// ============================================================
require_once 'includes/config.php';
require_once __DIR__ . '/payroll/workflow.php';
requireLogin();

$payroll_id = dec_id($_GET['id'] ?? '');

$stmt = $pdo->prepare("
    SELECT pr.payroll_id, pr.employee_id, pr.pay_period_start, pr.pay_period_end, pr.payout_date,
           pr.basic_pay, pr.overtime_pay, pr.allowances, pr.gross_pay,
           pr.sss_deduction, pr.philhealth_deduction, pr.pagibig_deduction,
           pr.tax_deduction, pr.other_deductions, pr.net_pay, pr.generated_at, pr.approval_status, pr.payment_status,
           pr.cash_ack_file, pr.cash_ack_original_name, pr.cash_ack_note, pr.cash_ack_uploaded_at,
           ack_u.full_name AS cash_ack_uploaded_by_name,
           e.employee_code, e.first_name, e.last_name, e.department, e.position,
           e.bank_name, e.bank_account_number, e.bank_account_name, e.payment_method,
           gu.full_name AS generated_by_name
    FROM payroll_records pr
    JOIN employees e ON e.employee_id = pr.employee_id
    LEFT JOIN users gu ON gu.id = pr.generated_by
    LEFT JOIN users ack_u ON ack_u.id = pr.cash_ack_uploaded_by
    WHERE pr.payroll_id = ?
");
$stmt->execute([$payroll_id]);
$p = $stmt->fetch();

if (!$p) {
    http_response_code(404);
    die("<p style='font-family:sans-serif;padding:24px;'>Payslip not found.</p>");
}

// -------- Authorization --------
$role    = $_SESSION['role'] ?? '';
$myEmpId = $_SESSION['employee_id'] ?? null;
$isHRTier = in_array($role, ['admin', 'hr', 'hr_manager'], true);

if (!$isHRTier && (int) $p['employee_id'] !== (int) $myEmpId) {
    http_response_code(403);
    die("<p style='font-family:sans-serif;padding:24px;'>You do not have access to this payslip.</p>");
}

// Pending/rejected submissions remain hidden from employees until an
// authorized Finance/Admin decision has been recorded.
if (in_array($p['approval_status'], ['draft', 'pending', 'rejected'], true) && $role !== 'admin') {
    http_response_code(403);
    die("<p style='font-family:sans-serif;padding:24px;'>This payroll is still awaiting Finance/Admin approval and cannot be viewed yet.</p>");
}

$isOwnPayslip = $myEmpId && (int) $p['employee_id'] === (int) $myEmpId;
$isPreview = $isOwnPayslip
    && $p['approval_status'] === 'approved'
    && $p['payment_status'] === 'unpaid'
    && !empty($p['payout_date'])
    && strtotime($p['payout_date']) > strtotime('today');

// Employees may print an approved upcoming payout as a clearly marked
// preview, but only a Finance-released payroll is an official payslip.
if ((!$isHRTier || ($isOwnPayslip && $role !== 'admin'))
    && !payrollIsReleased($p['approval_status'], $p['payment_status'])
    && !$isPreview) {
    http_response_code(403);
    die("<p style='font-family:sans-serif;padding:24px;'>This payslip will be available after Finance releases your salary.</p>");
}

// Mask the account number — only the last 4 characters are visible.
$acctDigits = preg_replace('/\s+/', '', (string) ($p['bank_account_number'] ?? ''));
$acctMasked = $acctDigits !== ''
    ? ((strlen($acctDigits) > 4 ? str_repeat('•', strlen($acctDigits) - 4) : '') . substr($acctDigits, -4))
    : '';

$totalDed = (float) $p['sss_deduction'] + (float) $p['philhealth_deduction'] + (float) $p['pagibig_deduction']
          + (float) $p['tax_deduction'] + (float) $p['other_deductions'];

$periodLabel = date('M d', strtotime($p['pay_period_start'])) . ' – ' . date('M d, Y', strtotime($p['pay_period_end']));

$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payslip — <?= clean($p['employee_code']) ?> — <?= clean($periodLabel) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --green-deep: #123C5C; --green-mid: #1978A8; --green-leaf: #4FC3D1;
    --text-accent: var(--green-deep); --green-pale: #E3F2F6; --cream: #F3F6F9;
    --white: #FFFFFF; --ink: #17222E; --ink-soft: #3D4C5C; --line: #DCE5EC;
}
body { font-family: 'Inter', Arial, sans-serif; background: var(--cream); color: var(--ink); padding: 28px 16px; }

.toolbar { max-width: 720px; margin: 0 auto 16px; display: flex; justify-content: space-between; gap: 12px; }
.btn {
    padding: 10px 18px; border: none; border-radius: 10px; font-size: 14px;
    font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block;
    transition: filter .15s ease, box-shadow .15s ease, transform .15s ease, border-color .15s ease;
}
.btn-primary { background: linear-gradient(135deg, var(--green-mid), var(--green-deep)); color: #FFFFFF; box-shadow: 0 2px 8px rgba(18,60,92,0.22); }
.btn-primary:hover { filter: brightness(1.08); box-shadow: 0 5px 16px rgba(18,60,92,0.32); transform: translateY(-2px); }
.btn-secondary { background: var(--white); color: var(--ink); border: 1.5px solid var(--line); }
.btn-secondary:hover { background: var(--cream); border-color: var(--green-leaf); transform: translateY(-2px); box-shadow: 0 3px 10px rgba(18,60,92,0.08); }

.slip {
    max-width: 720px; margin: 0 auto; background: var(--white);
    border: 1px solid var(--line); border-radius: 16px; overflow: hidden;
}
.slip-head { background: var(--green-deep); color: #FFFFFF; padding: 22px 28px; }
.slip-head h1 { font-size: 19px; margin-bottom: 2px; }
.slip-head .sub { font-size: 13px; opacity: .85; }

.slip-body { padding: 24px 28px; }

.meta { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 24px; margin-bottom: 22px; }
.meta .item .label { font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-soft); }
.meta .item .value { font-size: 14px; font-weight: 600; margin-top: 2px; }

h2.sec {
    font-size: 12.5px; text-transform: uppercase; letter-spacing: .05em;
    color: var(--text-accent); border-bottom: 1.5px solid var(--line);
    padding-bottom: 6px; margin: 18px 0 10px;
}
table { width: 100%; border-collapse: collapse; }
td { padding: 7px 0; font-size: 14px; }
td.amt {
    text-align: right;
    font-family: var(--font-body, "Inter", "Segoe UI", Arial, sans-serif);
    font-feature-settings: "tnum" 1;
    font-variant-numeric: tabular-nums;
}
tr.total td { border-top: 1px solid var(--line); font-weight: 700; padding-top: 10px; }

.net {
    margin-top: 20px; background: var(--green-pale); border-radius: 12px;
    padding: 16px 20px; display: flex; justify-content: space-between; align-items: center;
}
.net .label { font-size: 13px; font-weight: 600; color: var(--text-accent); }
.net .value {
    color: var(--text-accent);
    font-family: var(--font-body, "Inter", "Segoe UI", Arial, sans-serif);
    font-size: clamp(1.25rem, 1.8vw, 1.5rem);
    font-feature-settings: "tnum" 1;
    font-variant-numeric: tabular-nums;
    font-weight: 700;
    letter-spacing: -.025em;
    line-height: 1.3;
}

.foot { margin-top: 20px; font-size: 11.5px; color: var(--ink-soft); line-height: 1.6; }
.preview-notice { margin: 0 0 18px; padding: 10px 12px; border: 1px solid #E5B94C; border-radius: 8px; background: #FFF7DF; color: #755300; font-size: 12px; font-weight: 700; text-align: center; }

.alert { max-width: 720px; margin: 0 auto 14px; padding: 10px 16px; border-radius: 10px; font-size: 13.5px; font-weight: 500; }
.alert--success { background: #e6f4ea; color: #1a7a3b; }
.alert--error   { background: #FBE7E2; color: #C8472D; }

@media print {
    body { background: #fff; padding: 0; }
    .toolbar { display: none; }
    .alert { display: none; }
    .slip { border: none; border-radius: 0; max-width: none; }
}
@media (max-width: 560px) { .meta { grid-template-columns: 1fr; } }
</style>
</head>
<body>

<?php if ($flash_success): ?><div class="alert alert--success"><?= clean($flash_success) ?></div><?php endif; ?>
<?php if ($flash_error): ?><div class="alert alert--error"><?= clean($flash_error) ?></div><?php endif; ?>

<div class="toolbar">
    <a href="<?= $isHRTier ? clean(dashboardUrlForRole($role)) : 'employee_dashboard.php' ?>" class="btn btn-secondary">&larr; Back</a>
    <button type="button" class="btn btn-primary" onclick="window.print();">Print / Save as PDF</button>
</div>

<div class="slip">
    <div class="slip-head">
        <h1>HR Department — Payslip</h1>
        <div class="sub">Pay Period: <?= clean($periodLabel) ?></div>
    </div>

    <div class="slip-body">
        <?php if ($isPreview): ?><div class="preview-notice">PREVIEW — NOT RELEASED. This is not a final payslip.</div><?php endif; ?>
        <div class="meta">
            <div class="item"><div class="label">Employee</div><div class="value"><?= clean($p['first_name'] . ' ' . $p['last_name']) ?></div></div>
            <div class="item"><div class="label">Employee Code</div><div class="value"><?= clean($p['employee_code']) ?></div></div>
            <div class="item"><div class="label">Position</div><div class="value"><?= clean($p['position'] ?: '—') ?></div></div>
            <div class="item"><div class="label">Department</div><div class="value"><?= clean($p['department'] ?: '—') ?></div></div>
            <div class="item"><div class="label">Payout Date</div><div class="value"><?= !empty($p['payout_date']) ? clean(date('M d, Y', strtotime($p['payout_date']))) : '—' ?></div></div>
            <div class="item">
                <div class="label"><?= $p['payment_method'] === 'cash' ? 'Payout Method' : 'Deposited To' ?></div>
                <div class="value">
                    <?php if ($p['payment_method'] === 'cash'): ?>
                        Cash (Walk-in)
                    <?php elseif (!empty($p['bank_name']) || $acctMasked !== ''): ?>
                        <?= clean($p['bank_name'] ?: '—') ?><?= $acctMasked !== '' ? ' · ' . clean($acctMasked) : '' ?>
                    <?php else: ?>
                        <span style="color:var(--ink-soft); font-weight:500;">No bank details set</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($p['payment_method'] === 'cash'): ?>
        <div class="cash-ack-box" style="margin:14px 0 4px; padding:12px 16px; background:#F3F6F9; border:1px dashed var(--line); border-radius:10px;">
            <div style="font-weight:700; color:var(--text-accent); font-size:13.5px; margin-bottom:6px;">Cash Release Acknowledgment</div>
            <?php if (!empty($p['cash_ack_file'])): ?>
                <div style="font-size:13px;">
                    Signed receipt on file: <a href="payroll/cash_ack_download.php?id=<?= enc_id($p['payroll_id']) ?>" target="_blank"><?= clean($p['cash_ack_original_name'] ?: 'download') ?></a>
                    <?php if (!empty($p['cash_ack_uploaded_at'])): ?>
                        <div style="color:var(--ink-soft); margin-top:2px;">Uploaded <?= clean(date('M d, Y h:i A', strtotime($p['cash_ack_uploaded_at']))) ?><?= !empty($p['cash_ack_uploaded_by_name']) ? ' by ' . clean($p['cash_ack_uploaded_by_name']) : '' ?><?= !empty($p['cash_ack_note']) ? ' · ' . clean($p['cash_ack_note']) : '' ?></div>
                    <?php endif; ?>
                </div>
            <?php elseif ($isHRTier): ?>
                <form action="payroll/upload_cash_ack.php" method="POST" enctype="multipart/form-data" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                    <?php csrfField(); ?>
                    <input type="hidden" name="payroll_id" value="<?= (int) $p['payroll_id'] ?>">
                    <input type="hidden" name="return_to" value="../payslip.php?id=<?= enc_id($p['payroll_id']) ?>">
                    <input type="file" name="ack_file" accept=".pdf,.jpg,.jpeg,.png" required style="font-size:12.5px;">
                    <input type="text" name="note" maxlength="255" placeholder="Optional note (e.g. signed by employee)" style="font-size:12.5px; padding:6px 8px; border:1px solid var(--line); border-radius:6px; flex:1; min-width:160px;">
                    <button type="submit" class="btn btn-secondary" style="font-size:12.5px; padding:6px 12px;">Upload signed receipt</button>
                </form>
                <div style="font-size:11.5px; color:var(--ink-soft); margin-top:4px;">No proof of release yet — upload a scanned signed receipt/acknowledgment once the employee has been paid in cash.</div>
            <?php else: ?>
                <div style="font-size:13px; color:var(--ink-soft);">No proof of release uploaded yet.</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <h2 class="sec">Earnings</h2>
        <table>
            <tr><td>Basic Pay</td><td class="amt"><?= peso($p['basic_pay']) ?></td></tr>
            <tr><td>Overtime Pay</td><td class="amt"><?= peso($p['overtime_pay']) ?></td></tr>
            <tr><td>Allowances</td><td class="amt"><?= peso($p['allowances']) ?></td></tr>
            <tr class="total"><td>Gross Pay</td><td class="amt"><?= peso($p['gross_pay']) ?></td></tr>
        </table>

        <h2 class="sec">Deductions</h2>
        <table>
            <tr><td>SSS</td><td class="amt"><?= peso($p['sss_deduction']) ?></td></tr>
            <tr><td>PhilHealth</td><td class="amt"><?= peso($p['philhealth_deduction']) ?></td></tr>
            <tr><td>Pag-IBIG</td><td class="amt"><?= peso($p['pagibig_deduction']) ?></td></tr>
            <tr><td>Withholding Tax</td><td class="amt"><?= peso($p['tax_deduction']) ?></td></tr>
            <tr><td>Other Deductions</td><td class="amt"><?= peso($p['other_deductions']) ?></td></tr>
            <tr class="total"><td>Total Deductions</td><td class="amt"><?= peso($totalDed) ?></td></tr>
        </table>

        <div class="net">
            <div class="label">NET PAY</div>
            <div class="value"><?= peso($p['net_pay']) ?></div>
        </div>

        <div class="foot">
            Generated: <?= clean(date('M d, Y h:i A', strtotime($p['generated_at']))) ?>
            <?= !empty($p['generated_by_name']) ? ' · by ' . clean($p['generated_by_name']) : '' ?><br>
            This is a system-generated payslip from the HR Department. If you have any questions, please contact HR.
        </div>
    </div>
</div>

</body>
</html>