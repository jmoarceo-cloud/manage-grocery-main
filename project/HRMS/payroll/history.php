<?php
require_once __DIR__ . '/../includes/config.php';
requireHR();
$activeTab = 'history';
$pageTitle = 'History · HRMS Payroll';
$pageScript = 'history.js';
include __DIR__ . '/includes/header.php';
?>
<script>
  // Real data from the `payroll_records` table (not just browser
  // localStorage) — this is where HR Manager sees the payroll saved
  // by HR Staff, and where Admin sees the records from HR Staff/HR Manager.
  window.SERVER_HISTORY_RECORDS = <?php echo json_encode(getPayrollHistoryRecords($pdo, $__scopeOverride)); ?>;
  // HR Manager: view-only — no edit/archive/delete/restore.
  window.HISTORY_READ_ONLY = <?php echo isHRManagerRole() ? 'true' : 'false'; ?>;
</script>

<div class="panel active" id="panel-history">
    <div class="emp-toolbar">
      <div class="emp-search-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <input type="text" class="emp-search" id="histSearch" placeholder="Search by period or employee name…" />
      </div>
      <select class="emp-sort-select" id="histSort">
        <option value="date-desc">Date Saved (Newest)</option>
        <option value="date-asc">Date Saved (Oldest)</option>
        <option value="net-desc">Net Total (High–Low)</option>
        <option value="net-asc">Net Total (Low–High)</option>
        <option value="period-desc">Period (Newest first)</option>
        <option value="period-asc">Period (Oldest first)</option>
      </select>
      <button type="button" class="emp-clear-btn" id="histClearFilters">Clear</button>
      <button type="button" class="emp-clear-btn" id="histArchiveBtn" onclick="openArchiveModal()">Archive (<span id="archiveCount">0</span>)</button>
    </div>
    <div id="historyList"></div>
    <div class="emp-no-results" id="histNoResults" style="display:none;">No records match your search.</div>
    <div class="empty-note" id="historyEmptyNote" style="display:none;">No saved payroll records yet. Go to the Payroll Run tab and click "Save Record" to archive one.</div>
  </div>
<?php include __DIR__ . '/includes/footer.php'; ?>