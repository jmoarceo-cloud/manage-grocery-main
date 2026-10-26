<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
?>
</div><!-- /.wrap (opened in includes/header.php) -->
<?php if ($__showHrSidebar): ?>
</div><!-- /.main-area -->
</div><!-- /.app-shell -->
<?php endif; ?>

<!-- Generic confirm modal — used by openConfirm()/closeConfirmModal() in
     app.js (e.g. "Archive this period?"). This markup is shared across
     every payroll page since app.js wires up its click handlers
     unconditionally on load. -->
<div class="modal-overlay" id="confirmModal">
  <div class="modal-box">
    <h3 class="modal-title" id="confirmTitle">Are you sure?</h3>
    <p class="modal-msg" id="confirmMsg"></p>
    <div class="modal-actions">
      <button type="button" class="modal-btn-cancel" onclick="closeConfirmModal()">Cancel</button>
      <button type="button" class="confirm" id="confirmActionBtn">Yes</button>
    </div>
  </div>
</div>

<!-- Record detail modal (used by history.js: viewRecord()/closeRecordModal()) -->
<div class="modal-overlay" id="recordModal">
  <div class="modal-box wide">
    <h3 class="modal-title" id="recordModalTitle">Payroll Record</h3>
    <div class="modal-msg" id="recordModalSummary"></div>
    <div class="table-scroll" style="margin-bottom:16px; max-height:320px; overflow-y:auto;">
      <table style="min-width:560px;">
        <thead>
          <tr>
            <th style="text-align:left;">Employee</th>
            <th>Basic Pay</th>
            <th>OT Pay</th>
            <th>Allowance</th>
            <th class="computed">Gross</th>
            <th>Deductions</th>
            <th class="computed">Net</th>
          </tr>
        </thead>
        <tbody id="recordDetailBody"></tbody>
      </table>
    </div>
    <div class="modal-actions spread">
      <button type="button" class="danger" onclick="requestDeleteRecord()" id="recordModalDeleteBtn">Delete record</button>
      <button type="button" class="modal-btn-cancel" onclick="closeRecordModal()">Close</button>
    </div>
  </div>
</div>

<!-- Archive list modal (used by history.js: openArchiveModal()/closeArchiveModal()) -->
<div class="modal-overlay" id="archiveModal">
  <div class="modal-box wide">
    <h3 class="modal-title">Archived Records</h3>
    <div class="modal-msg">Deleted payroll records are kept here instead of being erased. You can restore a record back into History at any time.</div>
    <div id="archiveList" style="max-height:360px; overflow-y:auto;"></div>
    <div class="empty-note" id="archiveEmptyNote" style="display:none;">No archived records.</div>
    <div class="modal-actions">
      <button type="button" class="modal-btn-cancel" onclick="closeArchiveModal()">Close</button>
    </div>
  </div>
</div>

<?php
  // app.js defines the shared `state` object, loadState()/saveState(),
  // and other utilities that EVERY page-specific script below depends
  // on (see the "Requires app.js to be loaded first" comment at the
  // top of each one) — it must load before $pageScript.
  $__appJsPath = __DIR__ . '/../assets/app.js';
  // gov_contributions.js defines computeGovDeductionsForCutoff() /
  // computeWithholdingTaxSemiMonthly(), used by payroll.js — also a
  // shared, standalone script, so it's safe to load on every page.
  $__govJsPath = __DIR__ . '/../assets/gov_contributions.js';
?>
  <script src="assets/app.js?v=<?php echo file_exists($__appJsPath) ? filemtime($__appJsPath) : time(); ?>"></script>
  <script src="assets/gov_contributions.js?v=<?php echo file_exists($__govJsPath) ? filemtime($__govJsPath) : time(); ?>"></script>
<?php if (!empty($pageScript)): ?>
  <script src="assets/<?php echo htmlspecialchars($pageScript); ?>?v=<?php
      $__scriptPath = __DIR__ . '/../assets/' . $pageScript;
      echo file_exists($__scriptPath) ? filemtime($__scriptPath) : time();
  ?>"></script>
<?php endif; ?>

<?php
  // Loads SweetAlert2 + the shared a[data-confirm] click handler, so the
  // Logout link that hr_sidebar.php prints (included above via
  // includes/header.php) actually shows "Are you sure you want to
  // logout?" — without this, the payroll module had no listener at all
  // for data-confirm links/forms, so clicking Logout here just navigated
  // straight through with no confirmation popup.
  include __DIR__ . '/../../includes/sweetalert.php';
?>
</body>
</html>