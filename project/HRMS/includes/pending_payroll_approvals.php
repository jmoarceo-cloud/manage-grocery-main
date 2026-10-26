<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// ============================================================
// includes/pending_payroll_approvals.php
// Shared "Pending Payroll Approvals" panel for the Accountant payroll
// surface. This is deliberately separate from the Finance release step.
//
// Shows submitted batches with approval_status = 'pending'. Approval
// moves them to Finance's release queue; it does not release funds or
// make a payslip visible to an employee.
//
// REQUIRED before including:
//   - $__pendingApprovalsActionUrl : relative path to
//     payroll/approve_record.php from the page currently doing
//     the include (e.g. 'approve_record.php' if inside /payroll/
//     itself like history.php, or 'payroll/approve_record.php'
//     if at the app root like admin_dashboard.php).
// ============================================================
if (($_SESSION['role'] ?? '') !== 'accountant') {
    return;
}
if (!isset($__pendingApprovalsActionUrl) || trim((string) $__pendingApprovalsActionUrl) === '') {
    $__pendingApprovalsActionUrl = 'payroll/approve_record.php';
}
$__pendingApprovals = getPendingPayrollApprovals($pdo);
?>
<script>
  // Make sure CSRF_TOKEN exists regardless of which page this is
  // included on (e.g. admin_dashboard.php doesn't have it by default,
  // unlike pages inside /payroll/ that have their own header.php
  // that sets it). Safe to set again even if it already exists —
  // it's still the same session token.
  window.CSRF_TOKEN = <?php echo json_encode(generateCSRFToken()); ?>;
</script>
<!-- ============================================= -->
<!-- PENDING PAYROLL APPROVALS (Accountant only)     -->
<!-- ============================================= -->
<div class="card-box" style="margin-bottom:20px; border:1.5px solid var(--mustard, #E0A23B);">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom: <?= empty($__pendingApprovals) ? '0' : '14px' ?>;">
    <h3 style="margin:0; font-family:var(--font-display, 'Sora', sans-serif); color:var(--text-accent, #123C5C);">
      ⏳ Pending Payroll Approvals
      <span style="font-size:13px; font-weight:600; color:#7A5A1E; background:var(--mustard-pale,#FBEFD8); padding:2px 10px; border-radius:999px; margin-left:6px;"><?= count($__pendingApprovals) ?></span>
    </h3>
  </div>
  <?php if (empty($__pendingApprovals)): ?>
    <div class="empty-note" style="margin:0;">No payroll requests are waiting for approval right now.</div>
  <?php else: foreach ($__pendingApprovals as $batch): ?>
    <div class="pending-approval-batch" data-period-start="<?= clean($batch['periodStart']) ?>" data-period-end="<?= clean($batch['periodEnd']) ?>" data-generated-by="<?= (int) $batch['generatedBy'] ?>"
         style="border:1px solid var(--line, #DCE5EC); border-radius:12px; padding:14px 16px; margin-bottom:12px; background:#FFFDF7;">
      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:10px;">
        <div>
          <div style="font-weight:700; color:var(--text-accent,#123C5C);"><?= clean($batch['periodLabel']) ?></div>
          <div style="font-size:12.5px; color:var(--ink-soft,#3D4C5C);">
            Submitted by <strong><?= clean($batch['submittedBy'] ?: '—') ?></strong>
            &middot; <?= count($batch['rows']) ?> employee(s)
            &middot; Payout: <?= !empty($batch['payoutDate']) ? clean(date('M d, Y', strtotime($batch['payoutDate']))) : '—' ?>
            &middot; Submitted: <?= !empty($batch['submittedAt']) ? clean(date('M d, Y g:i A', strtotime($batch['submittedAt']))) : '—' ?>
          </div>
        </div>
        <div style="text-align:right;">
          <div style="font-size:12px; color:var(--ink-soft,#3D4C5C);">Total Net Pay</div>
          <div style="font-weight:800; font-size:18px; color:var(--text-accent,#123C5C);"><?= peso($batch['totals']['net']) ?></div>
        </div>
      </div>
      <?php
        $__blockedInBatch = array_filter($batch['rows'], fn($row) => !empty($row['blockedFromRelease']));
      ?>
      <?php if (!empty($__blockedInBatch)): ?>
      <div style="background:#FDEDEC; border:1px solid #F1B0AC; border-radius:8px; padding:8px 12px; margin-bottom:10px; font-size:12.5px; color:#8A2E27;">
        ⚠ <?= count($__blockedInBatch) ?> employee(s) in this batch are no longer active/on-leave. Finance will not be able to release their salary until their status is reviewed.
      </div>
      <?php endif; ?>
      <div style="overflow-x:auto; margin-bottom:12px;">
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
          <thead>
            <tr style="text-align:left; color:var(--ink-soft,#3D4C5C); border-bottom:1px solid var(--line,#DCE5EC);">
              <th style="padding:6px 8px;">Employee</th>
              <th style="padding:6px 8px;">Department</th>
              <th style="padding:6px 8px; text-align:right;">Gross Pay</th>
              <th style="padding:6px 8px; text-align:right;">Net Pay</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($batch['rows'] as $row): ?>
            <tr style="border-bottom:1px solid var(--line,#DCE5EC); <?= !empty($row['blockedFromRelease']) ? 'background:#FFF7F6;' : '' ?>">
              <td style="padding:6px 8px;">
                <?= clean($row['name']) ?>
                <?php if (!empty($row['blockedFromRelease'])): ?>
                  <div style="font-size:11px; color:#B3261E; font-weight:700; margin-top:2px;">⚠ Marked "<?= clean($row['empStatus']) ?>" — release requires review</div>
                <?php endif; ?>
              </td>
              <td style="padding:6px 8px;"><?= clean($row['dept'] ?: '—') ?></td>
              <td style="padding:6px 8px; text-align:right;"><?= peso($row['gross']) ?></td>
              <td style="padding:6px 8px; text-align:right;">
                <?= peso($row['net']) ?>
                <?php if ((float) $row['net'] <= 0): ?>
                    <div style="font-size:11px; color:var(--ink-soft,#3D4C5C); font-weight:500;">Zero net pay — Finance release is not needed</div>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="btn-group">
        <button type="button" class="add-btn" onclick="decidePendingPayroll(this, 'approve')">
          ✔ Approve for Finance Release
        </button>
        <button type="button" class="add-btn danger" onclick="decidePendingPayroll(this, 'reject')">✘ Reject</button>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>
<script>
// Accountant: Approve / Reject a submitted batch. Approval only queues
// the batch for a separate Finance release.
if (typeof window.decidePendingPayroll !== 'function') {
window.decidePendingPayroll = async function decidePendingPayroll(btn, action) {
  const card = btn.closest('.pending-approval-batch');
  if (!card) return;
  const label = action === 'approve' ? 'approve for Finance release' : 'reject';
  Swal.fire({
    title: action === 'approve' ? 'Approve payroll batch?' : 'Reject payroll batch?',
    text: 'Are you sure you want to ' + label + ' this payroll batch?',
    icon: action === 'approve' ? 'question' : 'warning',
    showCancelButton: true,
    confirmButtonText: action === 'approve' ? 'Yes, approve' : 'Yes, reject',
    cancelButtonText: 'Cancel',
    confirmButtonColor: action === 'approve' ? '#123C5C' : '#C8472D'
  }).then(async function (result) {
    if (!result.isConfirmed) return;
    const group = btn.parentElement;
    const originalLabels = Array.from(group.querySelectorAll('button')).map(b => b.textContent);
    Array.from(group.querySelectorAll('button')).forEach(b => b.disabled = true);
    btn.textContent = 'Processing…';

  try {
    const res = await fetch(<?= json_encode($__pendingApprovalsActionUrl) ?>, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': window.CSRF_TOKEN || ''
      },
      body: JSON.stringify({
        action: action,
        periodStart: card.dataset.periodStart,
        periodEnd: card.dataset.periodEnd,
        generatedBy: card.dataset.generatedBy
      })
    });
    const data = await res.json();
    Swal.fire({
      icon: data.success ? 'success' : 'error',
      title: data.success ? 'Completed' : 'Action failed',
      text: data.message || (data.success ? 'Done.' : 'An error occurred.'),
      confirmButtonText: 'Okay'
    });
    if (data.success) {
      window.location.reload();
    } else {
      Array.from(group.querySelectorAll('button')).forEach((b, i) => { b.disabled = false; b.textContent = originalLabels[i]; });
    }
  } catch (err) {
    Swal.fire({
      icon: 'error',
      title: 'Connection error',
      text: 'Could not reach the server. Check your connection and try again.',
      confirmButtonText: 'Okay'
    });
    Array.from(group.querySelectorAll('button')).forEach((b, i) => { b.disabled = false; b.textContent = originalLabels[i]; });
  }
  });
};
}
</script>