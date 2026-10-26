<?php
require_once __DIR__ . '/../includes/config.php';
requireHR();
$activeTab = 'payroll';
$pageTitle = 'Payroll Run · HRMS Payroll';
$pageScript = 'payroll.js';

// Feedback from capital_transaction.php (Add Capital / Owner
// Transaction) when redirected back here.
$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Business Cash Position — POS Gross Profit + Capital In/Out −
// Payroll Released. Ito ang koneksyon papunta sa POS Admin Reports
// profit figure (see getBusinessCashPosition() sa HRMS/includes/config.php),
// so we know whether the grocery has earned enough for the next
// payroll batch, or whether an "Add Capital" from the Owner is
// needed. Admin-only — the capital ledger is an Owner/Admin
// decision, not something ordinary HR Staff should see.
$businessCash = isAdminRole() ? getBusinessCashPosition($posPdo, $pdo) : null;

// ---- Resolve the pay period. Defaults to the current half-month, or
//      whatever was passed in the URL (set when HR changes the period
//      or clicks 1st/2nd half — see payroll.js: navigateToPeriod()). ----
$today = new DateTime();
$autoStart = ($today->format('j') <= 15) ? $today->format('Y-m-01') : $today->format('Y-m-16');
$autoEnd   = ($today->format('j') <= 15) ? $today->format('Y-m-15') : $today->format('Y-m-t');

$periodStart = $_GET['period_start'] ?? $autoStart;
$periodEnd   = $_GET['period_end'] ?? $autoEnd;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart)) { $periodStart = $autoStart; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd))   { $periodEnd   = $autoEnd; }
if ($periodEnd < $periodStart) { $periodEnd = $periodStart; }

// ---- Attendance summary for that period, straight from the real HRMS
//      Attendance module — this is what fills in "Days Worked" below so
//      Payroll always matches what was actually encoded in Attendance.
//      SHARED with save_record.php (computePayrollAttendanceSummary() in
//      HRMS/includes/config.php) so the display here and the figures
//      the server actually recomputes/validates on save can never
//      drift apart. ----
$__payrollAttendance = computePayrollAttendanceSummary($pdo, $periodStart, $periodEnd);

include __DIR__ . '/includes/header.php';

// ---- Current approval_status of the logged-in HR Staff/Manager's
//      records for this period, PER EMPLOYEE (draft/pending/approved/
//      rejected) — used by payroll.js to show each row's own status
//      badge/checkbox on first load, so employees can be submitted for
//      approval individually instead of as one all-or-nothing batch.
//      Only Admin doesn't have this — they are 'n/a' immediately. ----
$__myApprovalStatuses = [];
if (in_array($_SESSION['role'] ?? '', ['hr', 'hr_manager'], true)) {
    $__stApp = $pdo->prepare("
        SELECT employee_id, approval_status FROM payroll_records
        WHERE pay_period_start = ? AND pay_period_end = ? AND generated_by = ?
    ");
    $__stApp->execute([$periodStart, $periodEnd, (int) $_SESSION['user_id']]);
    foreach ($__stApp->fetchAll() as $__r) {
        $__myApprovalStatuses[(int) $__r['employee_id']] = $__r['approval_status'];
    }
}
?>
<script>
  // Real Attendance data for the period below (from the HRMS Attendance
  // module) — payroll.js uses this to auto-fill "Days Worked".
  window.SERVER_PERIOD = {
    start: <?php echo json_encode($periodStart); ?>,
    end: <?php echo json_encode($periodEnd); ?>
  };
  window.SERVER_ATTENDANCE = <?php echo json_encode($__payrollAttendance); ?>;
  // Current approval_status (draft/pending/approved/rejected), PER
  // EMPLOYEE (keyed by employee_id), of the logged-in HR user's
  // payroll records for this period.
  window.SERVER_APPROVAL_STATUSES = <?php echo json_encode($__myApprovalStatuses); ?>;
  // Business Cash Position (POS profit + capital, all-time) — used
  // to warn if this period's computed Net Payroll Total would exceed
  // what the business actually has on hand. Admin-only; null kung
  // hindi Admin o hindi ma-connect sa POS.
  window.SERVER_BUSINESS_CASH = <?php echo json_encode($businessCash); ?>;
</script>
<?php
// Warning if there are employees on "Bank Deposit" with no bank/card
// details — there's no way to know where to deposit their salary.
// Employees on "Cash (Walk-in)" are excluded — that's an intentional
// choice (e.g. a new hire without a bank account yet), not a gap.
$__noBank = array_values(array_filter($__payrollDbEmployees, function ($e) {
    return ($e['paymentMethod'] ?? 'bank') === 'bank' && empty($e['hasBank']);
}));
?>
<?php if (!empty($__noBank)): ?>
<div style="background:var(--red-pale, #FBE7E2); color:var(--red-alert, #C8472D); border:1px solid var(--red-pale, #f3cfc6); border-radius:12px; padding:12px 16px; margin-bottom:16px; font-size:13.5px; line-height:1.5;">
    ⚠️ <strong><?= count($__noBank) ?></strong> employee(s) have no <strong>bank/card details</strong>:
    <?= clean(implode(', ', array_map(function ($e) { return $e['name']; }, array_slice($__noBank, 0, 8)))) ?><?= count($__noBank) > 8 ? ', and more…' : '' ?>.
    Set this in <strong>Employees &rarr; Edit &rarr; Bank / Card Details</strong>.
</div>
<?php endif; ?>
<?php if ($flash_success): ?><div class="form-hint" style="background:var(--green-succ-bg, #E7F4EC); color:var(--green-succ-text, #1F6B41); border:1px solid var(--green-pale, #cdeada); border-radius:12px; padding:12px 16px; margin-bottom:16px;"><?= clean($flash_success) ?></div><?php endif; ?>
<?php if ($flash_error): ?><div class="form-hint save-error" style="display:block; background:var(--red-pale, #FBE7E2); border:1px solid var(--red-pale, #f3cfc6); border-radius:12px; padding:12px 16px; margin-bottom:16px;"><?= clean($flash_error) ?></div><?php endif; ?>
<?php if ($businessCash !== null): ?>
<div class="panel" style="margin-bottom:16px;">
    <div class="panel-header" style="padding:14px 18px 0;">
        <h3 style="margin:0; font-size:15px;">Business Cash Position <span style="font-weight:400; font-size:12.5px; color:var(--ink-soft, #7a7566);">(POS profit + capital, all-time)</span></h3>
    </div>
    <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:14px; padding:14px 18px 18px;">
        <div>
            <div style="font-size:12px; color:var(--ink-soft, #7a7566); font-weight:600;">Available Cash</div>
            <div id="bizAvailableCash" style="font-size:19px; font-weight:800; <?= $businessCash['available_cash'] < 0 ? 'color:var(--red-alert, #C8472D);' : '' ?>"><?= peso($businessCash['available_cash']) ?></div>
        </div>
        <div>
            <div style="font-size:12px; color:var(--ink-soft, #7a7566); font-weight:600;">Gross Profit (POS, all-time)</div>
            <div style="font-size:19px; font-weight:800;"><?= peso($businessCash['gross_profit']) ?></div>
        </div>
        <div>
            <div style="font-size:12px; color:var(--ink-soft, #7a7566); font-weight:600;">Restock Cost (POS, all-time)</div>
            <div style="font-size:19px; font-weight:800;"><?= peso($businessCash['restock_cost']) ?></div>
        </div>
        <div>
            <div style="font-size:12px; color:var(--ink-soft, #7a7566); font-weight:600;">Payroll Released (all-time)</div>
            <div style="font-size:19px; font-weight:800;"><?= peso($businessCash['payroll_released']) ?></div>
        </div>
    </div>
    <div id="payrollShortfallBanner" style="display:none; margin:0 18px 16px; background:var(--red-pale, #FBE7E2); color:var(--red-alert, #C8472D); border:1px solid var(--red-pale, #f3cfc6); border-radius:12px; padding:12px 16px; font-size:13.5px; line-height:1.5;">
        ⚠️ The Net Payroll Total for this period (<span id="shortfallNet">₱0.00</span>) will exceed the remaining Available Cash
        (short by <strong><span id="shortfallAmount">₱0.00</span></strong>). An <strong>"Add Capital"</strong> entry from the Owner is needed before releasing this payroll.
    </div>
    <div style="padding:0 18px 18px;">
        <button type="button" class="add-btn" onclick="document.getElementById('addCapitalForm').style.display = (document.getElementById('addCapitalForm').style.display === 'none' ? 'grid' : 'none');">+ Add Capital / Owner Transaction</button>
        <form id="addCapitalForm" method="POST" action="capital_transaction.php" style="display:none; margin-top:12px; max-width:420px; gap:12px; background:var(--cream, #FAF7F0); border-radius:12px; padding:16px;">
            <?php csrfField(); ?>
            <input type="hidden" name="period_start" value="<?= clean($periodStart) ?>">
            <input type="hidden" name="period_end" value="<?= clean($periodEnd) ?>">
            <div class="field" style="margin-bottom:10px;">
                <label class="label">Type</label>
                <select name="capital_type" required style="width:100%; padding:9px 12px; border-radius:8px; border:1.5px solid var(--line, #e3e1d6);">
                    <option value="capital_in">Add Capital (Owner cash → business)</option>
                    <option value="capital_out">Owner Withdrawal</option>
                </select>
            </div>
            <div class="field" style="margin-bottom:10px;">
                <label class="label">Amount</label>
                <input type="number" name="amount" min="0.01" step="0.01" required placeholder="0.00" style="width:100%; padding:9px 12px; border-radius:8px; border:1.5px solid var(--line, #e3e1d6);">
            </div>
            <div class="field" style="margin-bottom:10px;">
                <label class="label">Reason</label>
                <input type="text" name="reason" required maxlength="255" placeholder="hal. Shortfall sa payroll — <?= clean($periodStart) ?> to <?= clean($periodEnd) ?>" style="width:100%; padding:9px 12px; border-radius:8px; border:1.5px solid var(--line, #e3e1d6);">
            </div>
            <button type="submit" class="add-btn">Save Transaction</button>
        </form>
        <div class="form-hint" style="margin-top:8px;">Makikita rin ito, at ang buong ledger, sa POS Admin &rarr; Sales Report &rarr; Profit &amp; Business Capital.</div>
    </div>
</div>
<?php endif; ?>
<div class="panel active" id="panel-payroll">
    <div class="period-row">
      <div class="field">
        <label class="label">Period start</label>
        <input class="period-input" id="periodStart" type="date" />
      </div>
      <div class="field">
        <label class="label">Period end</label>
        <input class="period-input" id="periodEnd" type="date" />
      </div>
      <div class="field">
        <label class="label">This period</label>
        <div class="period-display" id="periodDisplay">—</div>
      </div>
      <div class="field">
        <label class="label">Payout date</label>
        <input class="period-input" id="payoutDate" type="date" />
      </div>
      <div class="period-quick">
        <button type="button" id="quick1" onclick="setQuickPeriod(1)">1st half</button>
        <button type="button" id="quick2" onclick="setQuickPeriod(2)">2nd half</button>
      </div>
    </div>

    <div class="assumptions">
      <div class="field"><label class="label">Working days / month</label><input id="workDays" type="text" inputmode="decimal" value="22" /></div>
      <div class="field"><label class="label">Working hours / day</label><input id="workHours" type="text" inputmode="decimal" value="8" /></div>
      <div class="field"><label class="label">OT multiplier</label><input id="otMult" type="text" inputmode="decimal" value="1.25" /></div>
      <div class="tag">Only Active employees appear below</div>
    </div>

    <div class="emp-toolbar">
      <div class="emp-search-wrap">
        <input type="text" class="emp-search" id="paySearch" placeholder="Search by name…" style="padding-left:12px;" />
      </div>
      <select class="emp-filter-select" id="payFilterDept">
        <option value="">All departments</option>
      </select>
      <select class="emp-sort-select" id="paySort">
        <option value="name-asc">Name (A–Z)</option>
        <option value="name-desc">Name (Z–A)</option>
        <option value="net-desc">Net Pay (High–Low)</option>
        <option value="net-asc">Net Pay (Low–High)</option>
        <option value="dept">Department</option>
      </select>
      <button type="button" class="emp-clear-btn" id="payClearFilters">Clear</button>
    </div>

    <div class="table-scroll">
      <table id="payTable">
        <thead>
          <tr>
            <th class="pay-select-col"><input type="checkbox" id="paySelectAll" title="Select all submittable employees"></th>
            <th class="payslip-col">Payslip</th>
            <th>Employee</th>
            <th>Bank / Card</th>
            <th>Monthly Basic</th>
            <th class="computed">Daily Rate</th>
            <th>Days Worked</th>
            <th class="computed">Basic Pay</th>
            <th>OT Hours</th>
            <th class="computed">OT Rate</th>
            <th class="computed">OT Pay</th>
            <th>Undertime Hours</th>
            <th class="computed">Undertime Ded.</th>
            <th>Allowance</th>
            <th class="computed">Gross Pay</th>
            <th>SSS</th>
            <th>PhilHealth</th>
            <th>Pag-IBIG</th>
            <th>Tax</th>
            <th>Other Ded.</th>
            <th class="computed">Total Ded.</th>
            <th class="computed">Net Pay</th>
          </tr>
        </thead>
        <tbody id="payRows"></tbody>
        <tfoot>
          <tr>
            <td colspan="14">TOTAL</td>
            <td id="totGross">0.00</td>
            <td colspan="5"></td>
            <td id="totDed">0.00</td>
            <td id="totNet">0.00</td>
          </tr>
        </tfoot>
      </table>
      <div class="emp-no-results" id="payNoResults" style="display:none;">No employees match your search or filters.</div>
    </div>
    <div class="empty-note" id="payEmptyNote" style="display:none;">No active employees yet — add one in HRMS &rarr; Employees.</div>
    <div class="form-hint" id="periodSavedNote" style="display:none; margin-top:10px;">Payroll for this period is recorded automatically as you enter values — <span id="autoSaveStatus">Saved ✓</span>.</div>
    <div id="submitApprovalBox" style="display:none; margin-top:12px; padding:14px 16px; border-radius:12px; border:1.5px solid var(--mustard, #E0A23B); background:var(--mustard-pale, #FBEFD8);">
      <div id="submitApprovalText" style="color:var(--mustard-text2, #7A5A1E); font-size:13.5px; line-height:1.5; margin-bottom:10px;"></div>
      <button type="button" class="add-btn" id="submitApprovalBtn" onclick="submitPeriodForApproval()">Submit Selected for Approval →</button>
    </div>
    <div class="form-hint" style="margin-top:6px;">Tick the checkbox beside an employee (leftmost column) to include them, then <strong>Submit Selected for Approval</strong> — only the employees you check are sent to an Accountant; the rest stay as Draft until you're ready for them too.</div>
    <div class="form-hint" style="margin-top:6px;">"Days Worked", "OT Hours", and "Undertime Hours" are all pre-filled automatically from HRMS Attendance's real Time In / Time Out records for this period (Undertime = hours short of that employee's actual assigned shift for that day — see their Weekly Shift Schedule in Employees — OT = hours beyond it) — you can still adjust any of them manually per employee if needed. A day only counts toward "Days Worked" once a Time Out is actually recorded for it.</div>
    <div class="form-hint" style="margin-top:6px;">Undertime pay is deducted, not just tracked: the red "Undertime Ded." amount is subtracted from that day's pay, and a small "→ X.XX net days" note appears under Days Worked so it's clear the day wasn't paid in full. OT Pay (green) is added on top the same way.</div>
    <div class="form-hint" style="margin-top:6px;">Payout date defaults to the period end date — adjust it to your actual release date (e.g. a few days after cutoff) before saving.</div>
    <div class="form-hint" style="margin-top:6px;">SSS, PhilHealth, and Pag-IBIG are auto-computed from each employee's Monthly Basic (2026 tables) and deducted only once per month — on the <strong>1st cutoff</strong> (1st–15th) only, not again on the 2nd cutoff — and Tax is auto-computed via the BIR semi-monthly graduated withholding table (RR 11-2018 Annex E, TRAIN Law) based on this period's taxable pay. Edit any of these fields directly to override — a <strong>reset</strong> link will appear so you can revert it back to auto anytime.</div>

    <div class="toolbar">
      <div class="btn-group">
        <button class="add-btn" id="exportBtn" onclick="exportPayrollCSV()">Export CSV</button>
        <button class="add-btn" id="printBtn" onclick="printPayroll()">Print</button>
        <button class="add-btn danger" id="clearBtn" onclick="requestArchivePeriod()">Archive this period</button>
      </div>
      <div class="legend"><div>Payroll is recorded automatically — Archive moves this period's saved record out of History (restorable anytime) and clears the working values</div></div>
    </div>
    <div class="form-hint save-error" id="saveErrorMsg" style="display:none;"></div>

    <div class="stamp-wrap">
      <div class="stamp">
        <div>
          <div class="stamp-label">Net Payroll Total</div>
          <div class="stamp-amount" id="stampAmount">₱0.00</div>
        </div>
        <div class="stamp-badge">This Period</div>
      </div>
    </div>
  </div>
<?php include __DIR__ . '/includes/footer.php'; ?>