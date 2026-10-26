<?php
require_once __DIR__ . '/../includes/config.php';
requireHR();
$activeTab = 'thirteenth';
$pageTitle = '13th Month · HRMS Payroll';
$pageScript = 'thirteenth.js';
include __DIR__ . '/includes/header.php';
?>
<script>
  // Real data from the `payroll_records` table (not just browser
  // localStorage) — needed here so Admin can see the 13th month pay
  // for ALL employees (not just HR Staff/Manager), including payroll
  // saved by HR Staff on a different browser or device. (Same data
  // source as the History tab.)
  window.SERVER_HISTORY_RECORDS = <?php echo json_encode(getPayrollHistoryRecords($pdo, $__scopeOverride)); ?>;
</script>
<div class="panel active" id="panel-thirteenth">
    <div class="period-row">
      <div class="field">
        <label class="label">Calendar year</label>
        <div style="display:flex; align-items:center; gap:8px;">
          <button type="button" class="year-nav-btn" id="yearPrevBtn" title="Previous year">&#8249;</button>
          <input class="period-input" id="thirteenthYear" type="number" step="1" style="width:100px; text-align:center;" />
          <button type="button" class="year-nav-btn" id="yearNextBtn" title="Next year">&#8250;</button>
        </div>
      </div>
    </div>

    <div class="table-scroll">
      <table>
        <thead>
          <tr>
            <th style="text-align:left;">Employee</th>
            <th>Total Basic Pay (Year)</th>
            <th class="computed">13th Month Pay</th>
          </tr>
        </thead>
        <tbody id="thirteenthRows"></tbody>
        <tfoot>
          <tr>
            <td>TOTAL</td>
            <td id="thirteenthTotalBasic">0.00</td>
            <td id="thirteenthTotalPay">0.00</td>
          </tr>
        </tfoot>
      </table>
    </div>
    <div class="empty-note" id="thirteenthEmptyNote" style="display:none;">No saved payroll records for the selected year yet. Go to the Payroll Run tab and click "Save Record" first to see data here.</div>

    <div class="stamp-wrap">
      <div class="stamp">
        <div>
          <div class="stamp-label">Total 13th Month Pay</div>
          <div class="stamp-amount" id="thirteenthStamp">₱0.00</div>
        </div>
        <div class="stamp-badge" id="thirteenthYearBadge">Year</div>
      </div>
    </div>
<?php include __DIR__ . '/includes/footer.php'; ?>