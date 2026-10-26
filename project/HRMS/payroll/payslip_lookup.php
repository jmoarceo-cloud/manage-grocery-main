<?php
// ============================================================
// payslip_lookup.php — "Payslip View" tab. Lets HR/HR Manager/Admin
// pick ONE employee from a dropdown, then pick which pay period, and
// see just that employee's payslip in the exact same professional
// "resibo" format as ../payslip.php (it's loaded in an iframe so we
// never fork the payslip design) — ready to Print / Save as PDF, or
// open in its own tab.
// ============================================================
require_once __DIR__ . '/../includes/config.php';
requireHR();
$activeTab = 'payslip';
$pageTitle = 'Payslip View · HRMS Payroll';
$pageScript = 'payslip_lookup.js';
include __DIR__ . '/includes/header.php';
?>
<div class="panel active" id="panel-payslip">
  <div class="card-box">
    <div class="period-row" style="align-items:flex-end;">
      <div class="field" style="min-width:260px; flex:1;">
        <label class="label">Employee</label>
        <select class="period-input" id="pslipEmployee">
          <option value="">Select an employee…</option>
        </select>
      </div>
      <div class="field" style="min-width:260px; flex:1;">
        <label class="label">Pay period</label>
        <select class="period-input" id="pslipPeriod" disabled>
          <option value="">Select an employee first…</option>
        </select>
      </div>
      <div class="field" style="flex:0 0 auto;">
        <a class="add-btn" id="pslipOpenNewTab" href="#" target="_blank" rel="noopener"
           style="display:none; text-decoration:none;">Open in new tab</a>
      </div>
    </div>
    <div class="form-hint" style="margin-top:6px;">
      Only payslips this account is allowed to view are listed here. Submitted payroll is hidden until Finance/Admin approval,
      and employees can view payslips only after Finance records the release.
    </div>

    <div class="empty-note" id="pslipEmptyNote">Pick an employee, then a pay period, to view their payslip here.</div>
    <div class="form-hint" id="pslipNoRecords" style="display:none;">This employee has no payslip available to view yet.</div>

    <div id="pslipFrameWrap" style="display:none; margin-top:16px;">
      <iframe id="pslipFrame" title="Payslip"
              style="width:100%; min-height:720px; border:1px solid var(--line, #DCE5EC); border-radius:12px; background:#fff;"></iframe>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
