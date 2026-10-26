<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();
if (($_SESSION['role'] ?? '') !== 'accountant') {
    redirect(appBaseUrl() . '/login.php');
}

$activeTab = 'payroll';
$pageTitle = 'Payroll · HRMS';
include __DIR__ . '/includes/header.php';
?>
<div class="card-box" style="margin-bottom:20px;">
  <h3 style="margin-top:0;">Submitted Payroll Requests</h3>
  <p style="margin-bottom:0;">This Accountant view is read-only. Finance/Admin approval is required before Finance can release payroll. Only after Finance records payment can employees view payslips.</p>
</div>
<?php
$__accountantPending = getPendingPayrollApprovals($pdo);
if (empty($__accountantPending)):
?>
  <div class="empty-note">No payroll requests are currently awaiting Finance/Admin approval.</div>
<?php else: foreach ($__accountantPending as $__batch): ?>
  <div class="card-box" style="margin-bottom:14px;">
    <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
      <strong><?= clean($__batch['periodLabel']) ?></strong>
      <span>Submitted by <?= clean($__batch['submittedBy'] ?: '—') ?> · <?= count($__batch['rows']) ?> employee(s)</span>
      <strong>Net total <?= peso($__batch['totals']['net']) ?></strong>
    </div>
    <div style="overflow-x:auto; margin-top:12px;">
      <table style="width:100%; border-collapse:collapse;">
        <thead><tr><th style="text-align:left;">Employee</th><th style="text-align:left;">Department</th><th style="text-align:right;">Gross</th><th style="text-align:right;">Net</th></tr></thead>
        <tbody>
        <?php foreach ($__batch['rows'] as $__row): ?>
          <tr>
            <td><?= clean($__row['name']) ?></td>
            <td><?= clean($__row['dept'] ?: '—') ?></td>
            <td style="text-align:right;"><?= peso($__row['gross']) ?></td>
            <td style="text-align:right;"><?= peso($__row['net']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
