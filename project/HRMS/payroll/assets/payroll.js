// ============================================================
// payroll.js — Payroll Run tab: pay period selection, assumptions,
// per-employee computation, CSV export/print, and saving a period
// to History.
// Requires app.js to be loaded first.
// ============================================================

// approvalStatuses: employeeId -> 'draft'|'pending'|'approved'|'rejected'|'n/a',
// for the CURRENT period, loaded from window.SERVER_APPROVAL_STATUSES and
// kept in sync after every autosave (save_record.php) and submission
// (submit_for_approval.php). Per-employee (not one shared batch status)
// so each row can be submitted for approval independently.
let approvalStatuses = {};
// selectedForApproval: Set of employeeIds currently ticked in the
// per-row checkbox column, ready to be sent via "Submit Selected for
// Approval". Only ever contains employees whose status is 'draft'.
let selectedForApproval = new Set();

// ---------- GOVERNMENT CONTRIBUTIONS / TAX (auto-compute) ----------
// SSS, PhilHealth, Pag-IBIG, and Withholding Tax are auto-filled based on
// the employee's monthly basic salary (see gov_contributions.js for the
// actual 2026 tables). HR can still type over any of these fields — once
// edited, that field switches to "Manual" and stops auto-updating until
// HR clicks "Auto" again to reset it.
function defaultPayrollRow() {
  return {
    days: 0, ot: 0, ut: 0, allow: 0, sss: 0, phic: 0, pagibig: 0, tax: 0, other: 0,
    govAuto: { sss: true, phic: true, pagibig: true, tax: true }
  };
}

function ensureGovAuto(row) {
  if (!row.govAuto) row.govAuto = { sss: true, phic: true, pagibig: true, tax: true };
}

// Recomputes any auto-flagged sss/phic/pagibig/tax fields on the row IN
// PLACE. `gross` is that employee's gross pay for THIS cutoff (basic +
// OT + allowance) and is needed to compute the withholding tax base.
function syncAutoGovFields(emp, row, gross) {
  ensureGovAuto(row);
  if (row.govAuto.sss || row.govAuto.phic || row.govAuto.pagibig) {
    const auto = computeGovDeductionsForCutoff(emp.basic || 0, state.periodStart);
    if (row.govAuto.sss) row.sss = auto.sss;
    if (row.govAuto.phic) row.phic = auto.phic;
    if (row.govAuto.pagibig) row.pagibig = auto.pagibig;
  }
  if (row.govAuto.tax) {
    const taxable = gross - (row.sss || 0) - (row.phic || 0) - (row.pagibig || 0);
    row.tax = computeWithholdingTaxSemiMonthly(taxable);
  }
}

// Called when HR types directly into an SSS/PhilHealth/Pag-IBIG/Tax
// field — that field becomes Manual (no longer auto-recomputed).
function setGovManual(row, field) {
  ensureGovAuto(row);
  row.govAuto[field] = false;
}

// Called from the "Auto" reset link — flips the field back to
// auto-computed and immediately refreshes the numbers.
function resetGovAuto(empId, field) {
  const row = state.payrollRows[empId];
  if (!row) return;
  ensureGovAuto(row);
  row.govAuto[field] = true;
  recalcPayroll();
  saveState();
}

// ---------- HIRE-DATE SCOPING ----------
// An employee should never show up (or get paid) in a pay period that
// ended before their actual Date Hired -- payroll/payslips only start
// once they've been hired and actually started reporting for work.
// `emp.dateHired` comes straight from HRMS -> Employees -> "Date Hired"
// (see getPayrollScopedEmployees() in HRMS/includes/config.php). If
// it's missing (older/legacy record), we don't exclude -- no date on
// file just means we can't say either way.
function isEmployedForPeriod(emp, periodEnd) {
  periodEnd = periodEnd || state.periodEnd;
  if (!emp.dateHired || !periodEnd) return true;
  return emp.dateHired <= periodEnd;
}

function getActiveEmployeesForPeriod(periodEnd) {
  return state.employees.filter(e => e.status === 'Active' && isEmployedForPeriod(e, periodEnd));
}

// ---------- ARCHIVE PERIOD ----------
// Formerly "Clear this period" — just reset values back to 0.
// Now, since payroll is automatically recorded (see scheduleAutoSaveRecord),
// "Archive this period" instead: (1) moves the saved record for this
// period into the Archive (same mechanism as History's
// "Delete & Archive" — restorable anytime), then (2) clears the
// working values here so you can start fresh.
function requestArchivePeriod() {
  const activeCount = getActiveEmployeesForPeriod().length;
  if (activeCount === 0) return;

  const existing = (state.history || []).find(
    r => r.periodStart === state.periodStart && r.periodEnd === state.periodEnd
  );

  const msg = existing
    ? `This will move the saved payroll record for ${existing.periodLabel} to the Archive (restorable later from History → Archive), and reset Days Worked, OT Hours, Undertime Hours, Allowance, and all Deductions to 0 for ${activeCount} active employee${activeCount === 1 ? '' : 's'}.`
    : `This period has no saved record yet, so there is nothing to archive — this will just reset Days Worked, OT Hours, Undertime Hours, Allowance, and all Deductions to 0 for ${activeCount} active employee${activeCount === 1 ? '' : 's'}.`;

  openConfirm(
    'Archive this period?',
    msg,
    () => {
      if (existing) {
        const idx = state.history.findIndex(r => r.id === existing.id);
        if (idx !== -1) {
          const [removed] = state.history.splice(idx, 1);
          if (!Array.isArray(state.archivedHistory)) state.archivedHistory = [];
          removed.archivedAt = new Date().toISOString();
          state.archivedHistory.unshift(removed);
        }
      }
      getActiveEmployeesForPeriod().forEach(emp => {
        state.payrollRows[emp.id] = defaultPayrollRow();
      });
      renderPayroll();
      saveState();
      if (typeof renderHistory === 'function') renderHistory();
    },
    'danger',
    'Archive'
  );
}

// ---------- ATTENDANCE-BASED DEFAULTS ----------
// Pre-fills "Days Worked", "OT Hours", and "Undertime Hours" from the real
// HRMS Attendance module's Time In / Time Out records for the active period
// (window.SERVER_ATTENDANCE, set in payroll.php from the attendance table —
// OT = hours beyond the standard shift, Undertime = hours short of it).
// Only fills rows that don't already have a value (e.g. restored from a
// saved History record, or already edited) so we never clobber something
// HR already entered.
function applyAttendanceDefaults() {
  const attendance = window.SERVER_ATTENDANCE || {};
  getActiveEmployeesForPeriod().forEach(emp => {
    if (!state.payrollRows[emp.id]) {
      state.payrollRows[emp.id] = defaultPayrollRow();
    }
    const row = state.payrollRows[emp.id];
    const summary = attendance[emp.id];
    if (!summary) return;
    if (!row.days) row.days = summary.presentDays || 0;
    if (!row.ot) row.ot = summary.otHours || 0;
    if (!row.ut) row.ut = summary.undertimeHours || 0;
  });
}

// ---------- PAY PERIOD ----------
// Period changes now navigate to a fresh copy of this page (with
// ?period_start=&period_end= in the URL) so the server can recompute the
// HRMS Attendance summary for the new range — see includes/header.php and
// the top of payroll.php.
function navigateToPeriod(start, end) {
  const params = new URLSearchParams({ period_start: start, period_end: end });
  window.location.href = 'payroll.php?' + params.toString();
}

function loadPayrollRowsForPeriod() {
  // Payroll entries (days, OT, allowance, deductions) belong to a specific
  // period. Whenever the period changes, start from a clean slate — but if
  // this exact period already has a saved History record, restore its
  // entries (and the assumptions used when it was saved) so going back to
  // that day still shows the record instead of blank fields, and so it can
  // continue to be edited.
  state.payrollRows = {};
  const existing = (state.history || []).find(
    r => r.periodStart === state.periodStart && r.periodEnd === state.periodEnd
  );
  if (!existing) {
    state.payoutDate = state.periodEnd || '';
    return;
  }
  state.payoutDate = existing.payoutDate || state.periodEnd || '';

  if (existing.assumptions) {
    state.assumptions = { ...state.assumptions, ...existing.assumptions };
  }
  existing.rows.forEach(r => {
    if (r.empId === undefined || r.empId === null) return;
    state.payrollRows[r.empId] = {
      days: r.days || 0,
      ot: r.ot || 0,
      ut: r.ut || 0,
      allow: r.allow || 0,
      sss: r.sss || 0,
      phic: r.phic || 0,
      pagibig: r.pagibig || 0,
      tax: r.tax || 0,
      other: r.other || 0
    };
  });
}

function setQuickPeriod(half) {
  const now = new Date();
  const p = defaultPeriodFor(now, half);
  if (state.periodStart === p.start && state.periodEnd === p.end) return;
  navigateToPeriod(p.start, p.end);
}

function renderPeriodFields() {
  document.getElementById('periodStart').value = state.periodStart || '';
  document.getElementById('periodEnd').value = state.periodEnd || '';
  document.getElementById('periodDisplay').textContent = formatPeriodDisplay(state.periodStart, state.periodEnd);
  document.getElementById('payoutDate').value = state.payoutDate || state.periodEnd || '';

  const now = new Date();
  const h1 = defaultPeriodFor(now, 1), h2 = defaultPeriodFor(now, 2);
  document.getElementById('quick1').classList.toggle('active-quick', state.periodStart === h1.start && state.periodEnd === h1.end);
  document.getElementById('quick2').classList.toggle('active-quick', state.periodStart === h2.start && state.periodEnd === h2.end);
}

document.getElementById('periodStart').addEventListener('change', e => {
  let start = e.target.value;
  let end = document.getElementById('periodEnd').value || start;
  if (end < start) end = start;
  if (start === state.periodStart && end === state.periodEnd) return;
  navigateToPeriod(start, end);
});
document.getElementById('periodEnd').addEventListener('change', e => {
  let end = e.target.value;
  let start = document.getElementById('periodStart').value || end;
  if (end < start) start = end;
  if (start === state.periodStart && end === state.periodEnd) return;
  navigateToPeriod(start, end);
});
document.getElementById('payoutDate').addEventListener('change', e => {
  state.payoutDate = e.target.value || '';
  saveState();
  scheduleAutoSaveRecord();
});

// ---------- PAYROLL SEARCH / FILTER / SORT ----------
let paySearchTerm = '';
let payFilterDeptVal = '';
let paySortBy = 'name-asc';

function initPayFilterControls() {
  const deptSel = document.getElementById('payFilterDept');
  // Build the department list from the real employees on this page
  // (synced from HRMS via window.SERVER_EMPLOYEES) instead of a fixed
  // hardcoded list — so it always matches whatever departments this
  // business actually has on file, and never shows options with zero
  // matching employees (or is missing ones that exist).
  const realDepts = Array.from(new Set(
    (state.employees || []).map(emp => (emp.dept || '').trim()).filter(Boolean)
  )).sort((a, b) => a.localeCompare(b));
  deptSel.innerHTML = '<option value="">All departments</option>' +
    realDepts.map(d => `<option value="${d}">${d}</option>`).join('');

  document.getElementById('paySearch').addEventListener('input', (e) => {
    paySearchTerm = e.target.value;
    renderPayroll();
  });
  deptSel.addEventListener('change', (e) => {
    payFilterDeptVal = e.target.value;
    renderPayroll();
  });
  document.getElementById('paySort').addEventListener('change', (e) => {
    paySortBy = e.target.value;
    renderPayroll();
  });
  document.getElementById('payClearFilters').addEventListener('click', () => {
    paySearchTerm = '';
    payFilterDeptVal = '';
    paySortBy = 'name-asc';
    document.getElementById('paySearch').value = '';
    document.getElementById('payFilterDept').value = '';
    document.getElementById('paySort').value = 'name-asc';
    renderPayroll();
  });
}

function getDisplayedActiveEmployees() {
  const term = normalizeName(paySearchTerm);
  let list = state.employees.filter(emp => {
    if (emp.status !== 'Active') return false;
    if (!isEmployedForPeriod(emp)) return false;
    if (term && !normalizeName(emp.name).includes(term)) return false;
    if (payFilterDeptVal && emp.dept !== payFilterDeptVal) return false;
    return true;
  });

  list.sort((a, b) => {
    switch (paySortBy) {
      case 'name-desc': return (b.name || '').localeCompare(a.name || '');
      case 'net-desc': return computeRowNet(b) - computeRowNet(a);
      case 'net-asc': return computeRowNet(a) - computeRowNet(b);
      case 'dept': return (a.dept || '').localeCompare(b.dept || '') || (a.name || '').localeCompare(b.name || '');
      case 'name-asc':
      default: return (a.name || '').localeCompare(b.name || '');
    }
  });
  return list;
}

function computeRowNet(emp) {
  const workDays = parseFloat(document.getElementById('workDays').value) || 1;
  const workHours = parseFloat(document.getElementById('workHours').value) || 1;
  const otMult = parseFloat(document.getElementById('otMult').value) || 0;
  const row = state.payrollRows[emp.id] || defaultPayrollRow();
  const daily = emp.basic / workDays;
  const basicPay = daily * (row.days || 0);
  const otRate = (daily / workHours) * otMult;
  const otPay = (row.ot || 0) * otRate;
  const utRate = daily / workHours;
  const utPay = (row.ut || 0) * utRate;
  const gross = basicPay + otPay - utPay + (row.allow || 0);
  syncAutoGovFields(emp, row, gross);
  const totalDed = (row.sss||0)+(row.phic||0)+(row.pagibig||0)+(row.tax||0)+(row.other||0);
  return gross - totalDed;
}

// ---------- PAYROLL ----------
function renderPayroll() {
  renderPeriodFields();
  document.getElementById('workDays').value = state.assumptions.workDays;
  document.getElementById('workHours').value = state.assumptions.workHours;
  document.getElementById('otMult').value = state.assumptions.otMult;

  const tbody = document.getElementById('payRows');
  tbody.innerHTML = '';
  const active = getActiveEmployeesForPeriod();
  const displayed = getDisplayedActiveEmployees();
  document.getElementById('payEmptyNote').style.display = active.length ? 'none' : 'block';
  document.getElementById('payTable').style.display = active.length ? '' : 'none';
  document.getElementById('payNoResults').style.display = (active.length && !displayed.length) ? 'block' : 'none';
  document.getElementById('clearBtn').disabled = active.length === 0;
  document.getElementById('exportBtn').disabled = active.length === 0;
  document.getElementById('printBtn').disabled = active.length === 0;

  const hasExistingRecord = (state.history || []).some(
    r => r.periodStart === state.periodStart && r.periodEnd === state.periodEnd
  );
  document.getElementById('periodSavedNote').style.display = active.length ? 'block' : 'none';
  updateAutoSaveStatus(hasExistingRecord ? 'saved' : 'idle');

  displayed.forEach(emp => {
    if (!state.payrollRows[emp.id]) {
      state.payrollRows[emp.id] = defaultPayrollRow();
    }
    const row = state.payrollRows[emp.id];
    const empStatus = approvalStatuses[emp.id];
    const canSelect = empStatus === 'draft';
    if (!canSelect) selectedForApproval.delete(emp.id);
    const tr = document.createElement('tr');
    tr.dataset.empId = emp.id;
    tr.innerHTML = `
      <td class="pay-select-col">
        <input type="checkbox" class="pay-row-select" data-emp-id="${emp.id}" ${canSelect ? '' : 'disabled'} ${selectedForApproval.has(emp.id) ? 'checked' : ''}>
      </td>
      <td class="payslip-col">
        <button type="button" class="payslip-print-btn" title="Print this employee's payslip" onclick="printEmployeePayslip(${emp.id})">
          <svg viewBox="0 0 20 20" fill="none" width="15" height="15"><path d="M5.5 7.5V3h9v4.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><rect x="3.5" y="7.5" width="13" height="6" rx="1" stroke="currentColor" stroke-width="1.4"/><path d="M5.5 12.5h9V17h-9v-4.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
          <span>Payslip</span>
        </button>
      </td>
      <td class="text-cell">${emp.name ? escapeHtml(emp.name) : '(unnamed)'}${renderApprovalBadge(empStatus)}</td>
      <td class="text-cell">${emp.paymentMethod === 'cash'
          ? '<span style="color:#1F5C3F; font-weight:600;" title="Salary released in cash/personally">Cash (Walk-in)</span>'
          : (emp.hasBank
              ? escapeHtml(emp.bank || '—') + (emp.bankAcct ? ' · ' + escapeHtml(emp.bankAcct) : '')
              : '<span style="color:#C8472D; font-weight:600;" title="No bank/card details — set this in the Employees module">No bank details</span>')}</td>
      <td>${fmt(emp.basic)}</td>
      <td class="computed" data-c="daily">0.00</td>
      <td>
        <div class="days-cell-wrap">
          <input type="text" inputmode="decimal" data-f="days" value="${row.days}" />
          <span class="days-net-hint" data-c="daysNetHint" style="display:none;"></span>
        </div>
      </td>
      <td class="computed" data-c="basicPay">0.00</td>
      <td><input type="text" inputmode="decimal" data-f="ot" value="${row.ot}" /></td>
      <td class="computed" data-c="otRate">0.00</td>
      <td class="computed pay-add" data-c="otPay">0.00</td>
      <td><input type="text" inputmode="decimal" data-f="ut" value="${row.ut || 0}" /></td>
      <td class="computed pay-ded" data-c="utPay">0.00</td>
      <td><input type="text" inputmode="decimal" data-f="allow" value="${row.allow}" /></td>
      <td class="computed" data-c="gross">0.00</td>
      <td class="gov-cell"><input type="text" inputmode="decimal" data-f="sss" value="${row.sss}" /><a href="#" class="gov-auto-tag" data-reset="sss" title="Auto-computed from monthly salary per SSS table. Click to reset if manually edited.">auto</a></td>
      <td class="gov-cell"><input type="text" inputmode="decimal" data-f="phic" value="${row.phic}" /><a href="#" class="gov-auto-tag" data-reset="phic" title="Auto-computed from monthly salary per PhilHealth table. Click to reset if manually edited.">auto</a></td>
      <td class="gov-cell"><input type="text" inputmode="decimal" data-f="pagibig" value="${row.pagibig}" /><a href="#" class="gov-auto-tag" data-reset="pagibig" title="Auto-computed from monthly salary per Pag-IBIG table. Click to reset if manually edited.">auto</a></td>
      <td class="gov-cell"><input type="text" inputmode="decimal" data-f="tax" value="${row.tax}" /><a href="#" class="gov-auto-tag" data-reset="tax" title="Auto-computed from BIR withholding tax table. Click to reset if manually edited.">auto</a></td>
      <td><input type="text" inputmode="decimal" data-f="other" value="${row.other}" /></td>
      <td class="computed" data-c="totalDed">0.00</td>
      <td class="computed net" data-c="net">0.00</td>
    `;
    tr.querySelectorAll('[data-f]').forEach(inp => {
      inp.addEventListener('keydown', (e) => blockNonNumericKeys(e, true));
      inp.addEventListener('input', () => {
        row[inp.dataset.f] = clampNonNegative(inp);
        if (row.govAuto && inp.dataset.f in row.govAuto) setGovManual(row, inp.dataset.f);
        recalcPayroll();
        saveState();
      });
      inp.addEventListener('change', () => {
        finalizeNumberField(inp);
        row[inp.dataset.f] = clampNonNegative(inp);
        if (row.govAuto && inp.dataset.f in row.govAuto) setGovManual(row, inp.dataset.f);
        recalcPayroll();
        saveState();
        scheduleAutoSaveRecord();
      });
    });
    tr.querySelectorAll('[data-reset]').forEach(link => {
      link.addEventListener('click', (e) => {
        e.preventDefault();
        resetGovAuto(emp.id, link.dataset.reset);
        scheduleAutoSaveRecord();
      });
    });
    const selectCb = tr.querySelector('.pay-row-select');
    if (selectCb) {
      selectCb.addEventListener('change', () => {
        const eid = parseInt(selectCb.dataset.empId, 10);
        if (selectCb.checked) selectedForApproval.add(eid); else selectedForApproval.delete(eid);
        updateSubmitApprovalBox();
      });
    }
    tbody.appendChild(tr);
  });

  initPaySelectAll();
  recalcPayroll();
  updateSubmitApprovalBox();
}

// Renders the small colored status pill shown beside an employee's name
// (Draft / Pending / Approved / Rejected). Admin-generated records are
// pre-approved by Admin and are not submitted through this HR-only box.
function renderApprovalBadge(status) {
  if (!status || status === 'n/a' || window.SESSION_ROLE === 'admin') return '';
  const styles = {
    draft:    ['Draft',    '#7A5A1E', '#FBEFD8'],
    pending:  ['Pending',  '#2A5C87', '#EAF2FA'],
    approved: ['Approved', '#1F5C3F', '#EAF5EE'],
    rejected: ['Rejected', '#C8472D', '#FBEAE6'],
  };
  const [label, color, bg] = styles[status] || [status, '#3D4C5C', '#F0F0F0'];
  return ` <span style="display:inline-block; padding:1px 8px; border-radius:999px; font-size:10.5px; font-weight:700; color:${color}; background:${bg};">${label}</span>`;
}

// "Select all" checkbox in the table header — only ever selects/
// deselects employees currently eligible (status === 'draft'). Bound
// once (idempotent via dataset.bound) since the header isn't re-rendered
// on every renderPayroll() call, only the tbody is.
function initPaySelectAll() {
  const cb = document.getElementById('paySelectAll');
  if (!cb || cb.dataset.bound) return;
  cb.dataset.bound = '1';
  cb.addEventListener('change', () => {
    document.querySelectorAll('#payRows .pay-row-select:not(:disabled)').forEach(input => {
      input.checked = cb.checked;
      const eid = parseInt(input.dataset.empId, 10);
      if (cb.checked) selectedForApproval.add(eid); else selectedForApproval.delete(eid);
    });
    updateSubmitApprovalBox();
  });
}

function recalcPayroll() {
  const workDays = parseFloat(document.getElementById('workDays').value) || 1;
  const workHours = parseFloat(document.getElementById('workHours').value) || 1;
  const otMult = parseFloat(document.getElementById('otMult').value) || 0;

  // Totals always reflect ALL active employees for the period, regardless
  // of what the search/filter is currently narrowing the visible rows to.
  let totGross = 0, totDed = 0, totNet = 0;
  getActiveEmployeesForPeriod().forEach(emp => {
    const row = state.payrollRows[emp.id] || defaultPayrollRow();
    const daily = emp.basic / workDays;
    const basicPay = daily * (row.days || 0);
    const otRate = (daily / workHours) * otMult;
    const otPay = (row.ot || 0) * otRate;
    const utRate = daily / workHours;
    const utPay = (row.ut || 0) * utRate;
    const gross = basicPay + otPay - utPay + (row.allow || 0);
    syncAutoGovFields(emp, row, gross);
    const totalDed = (row.sss||0) + (row.phic||0) + (row.pagibig||0) + (row.tax||0) + (row.other||0);
    const net = Math.max(0, gross - totalDed);
    totGross += gross; totDed += totalDed; totNet += net;
  });

  document.querySelectorAll('#payRows tr').forEach(tr => {
    const empId = parseInt(tr.dataset.empId);
    const emp = state.employees.find(e => e.id === empId);
    const row = state.payrollRows[empId];
    if (!emp || !row) return;

    const daily = emp.basic / workDays;
    const basicPay = daily * (row.days || 0);
    const otRate = (daily / workHours) * otMult;
    const otPay = (row.ot || 0) * otRate;
    const utRate = daily / workHours;
    const utPay = (row.ut || 0) * utRate;
    const gross = basicPay + otPay - utPay + (row.allow || 0);
    const totalDed = (row.sss||0) + (row.phic||0) + (row.pagibig||0) + (row.tax||0) + (row.other||0);
    const net = gross - totalDed;

    tr.querySelector('[data-c="daily"]').textContent = fmt(daily);
    tr.querySelector('[data-c="basicPay"]').textContent = fmt(basicPay);
    tr.querySelector('[data-c="otRate"]').textContent = fmt(otRate);
    tr.querySelector('[data-c="otPay"]').textContent = (otPay > 0 ? '+' : '') + fmt(otPay);
    tr.querySelector('[data-c="utPay"]').textContent = (utPay > 0 ? '−' : '') + fmt(utPay);
    tr.querySelector('[data-c="gross"]').textContent = fmt(gross);
    tr.querySelector('[data-c="totalDed"]').textContent = fmt(totalDed);
    tr.querySelector('[data-c="net"]').textContent = fmt(Math.max(0, net));

    // "Net days" hint under Days Worked — makes it obvious that an
    // undertime day isn't secretly paid in full; it shows the effective
    // number of days actually being paid for (days worked minus the
    // fraction lost to undertime hours), even though the peso deduction
    // itself is applied via the Undertime Ded. column.
    const hintEl = tr.querySelector('[data-c="daysNetHint"]');
    if (hintEl) {
      const netDays = (row.days || 0) - ((row.ut || 0) / workHours);
      if ((row.ut || 0) > 0) {
        hintEl.textContent = `→ ${netDays.toFixed(2)} net days`;
        hintEl.style.display = '';
      } else {
        hintEl.style.display = 'none';
      }
    }

    // Refresh auto-computed field values + their "auto/manual" tags without
    // clobbering fields HR has manually overridden.
    ['sss', 'phic', 'pagibig', 'tax'].forEach(f => {
      const inp = tr.querySelector(`[data-f="${f}"]`);
      const tag = tr.querySelector(`[data-reset="${f}"]`);
      if (row.govAuto && row.govAuto[f]) {
        if (document.activeElement !== inp) inp.value = row[f];
        if (tag) { tag.textContent = 'auto'; tag.classList.remove('is-manual'); }
      } else if (tag) {
        tag.textContent = 'reset';
        tag.classList.add('is-manual');
      }
    });
  });

  document.getElementById('totGross').textContent = fmt(totGross);
  document.getElementById('totDed').textContent = fmt(totDed);
  document.getElementById('totNet').textContent = fmt(totNet);
  document.getElementById('stampAmount').textContent = '₱' + fmt(totNet);

  updatePayrollShortfallBanner(totNet);
}

// Ikinukumpara ang Net Payroll Total ng period na ito sa natitirang
// Available Cash ng negosyo (POS profit + capital, minus payroll na
// na-release na — see window.SERVER_BUSINESS_CASH, set sa payroll.php).
// Kapag lalampas ang total sa available cash, ipinapakita ang
// shortfall banner (kasama ang link para mag-"Add Capital"). Admin-only
// na feature — window.SERVER_BUSINESS_CASH ay null kung hindi Admin
// o hindi ma-connect sa POS, kaya wala lang mangyayari kung ganoon.
function updatePayrollShortfallBanner(totNet) {
  const banner = document.getElementById('payrollShortfallBanner');
  if (!banner) return;
  const cash = window.SERVER_BUSINESS_CASH;
  if (!cash || totNet <= 0) { banner.style.display = 'none'; return; }

  const shortfall = totNet - cash.available_cash;
  if (shortfall > 0) {
    document.getElementById('shortfallNet').textContent = '₱' + fmt(totNet);
    document.getElementById('shortfallAmount').textContent = '₱' + fmt(shortfall);
    banner.style.display = 'block';
  } else {
    banner.style.display = 'none';
  }
}

['workDays','workHours','otMult'].forEach(id => {
  const inp = document.getElementById(id);
  inp.addEventListener('keydown', (e) => blockNonNumericKeys(e, true));
  inp.addEventListener('input', () => {
    state.assumptions[id] = clampNonNegative(inp);
    recalcPayroll();
    saveState();
  });
  inp.addEventListener('change', () => {
    finalizeNumberField(inp);
    state.assumptions[id] = clampNonNegative(inp);
    recalcPayroll();
    saveState();
    scheduleAutoSaveRecord();
  });
});

// ---------- EXPORT / PRINT ----------
function csvEscape(val) {
  const s = String(val ?? '');
  if (/[",\r\n]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
  return s;
}

function exportPayrollCSV() {
  const rows = buildActiveSnapshotRows();
  if (!rows.length) return;

  const headers = [
    'Employee', 'Department', 'Monthly Basic', 'Days Worked', 'Basic Pay',
    'OT Hours', 'OT Pay', 'Undertime Hours', 'Undertime Ded.', 'Allowance', 'Gross Pay',
    'SSS', 'PhilHealth', 'Pag-IBIG', 'Tax', 'Other Ded.', 'Total Ded.', 'Net Pay'
  ];
  const periodLine = `Period,${csvEscape(formatPeriodDisplay(state.periodStart, state.periodEnd))},Payout Date,${csvEscape(state.payoutDate ? formatDateDisplay(state.payoutDate) : '')}`;
  const csvLines = [periodLine, headers.map(csvEscape).join(',')];

  rows.forEach(r => {
    csvLines.push([
      csvEscape(r.name), csvEscape(r.dept || ''), r.basic.toFixed(2), r.days || 0, r.basicPay.toFixed(2),
      r.ot || 0, r.otPay.toFixed(2), r.ut || 0, (r.utPay || 0).toFixed(2), (r.allow || 0).toFixed(2), r.gross.toFixed(2),
      (r.sss || 0).toFixed(2), (r.phic || 0).toFixed(2), (r.pagibig || 0).toFixed(2),
      (r.tax || 0).toFixed(2), (r.other || 0).toFixed(2), r.totalDed.toFixed(2), r.net.toFixed(2)
    ].join(','));
  });

  const totals = rows.reduce((acc, r) => { acc.gross += r.gross; acc.ded += r.totalDed; acc.net += Math.max(0, r.net); return acc; }, { gross: 0, ded: 0, net: 0 });
  csvLines.push([
    'TOTAL', '', '', '', '', '', '', '', '', '', totals.gross.toFixed(2), '', '', '', '', '', totals.ded.toFixed(2), totals.net.toFixed(2)
  ].join(','));

  const csvContent = '\uFEFF' + csvLines.join('\r\n');
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  const periodSlug = (state.periodStart && state.periodEnd) ? `${state.periodStart}_to_${state.periodEnd}` : 'payroll';
  link.href = url;
  link.download = `payroll_${periodSlug}.csv`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

// Printing used to just call window.print() on the whole Payroll Run
// page -- with 19 columns, that regularly spilled over onto a 2nd (or
// 3rd) printed page and mixed in the on-screen search/filter chrome.
// It then moved to a separate popup window/tab -- better, but still
// left the page and could be blocked by the browser's pop-up blocker.
// Now it's an in-page modal (same pattern as the single-employee
// Payslip modal below): a compact, landscape, single-purpose payroll
// register -- fewer/condensed columns, small print-safe font, and a
// proper header/footer -- sized to fit on one page for a normal-size
// roster, shown right over the Payroll Run page with its own
// Close/Print controls. "Print" only prints the register card itself.
function money(n) {
  return (Number(n) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function ensureRegisterPrintStyles() {
  if (document.getElementById('registerPrintStyles')) return;
  const style = document.createElement('style');
  style.id = 'registerPrintStyles';
  style.textContent = `
@media print {
  body > *:not(#registerModalOverlay) { display: none !important; }
  #registerModalOverlay {
    position: static !important; background: none !important; padding: 0 !important;
    display: block !important;
  }
  #registerModalOverlay .register-modal-toolbar { display: none !important; }
  #registerModalOverlay .register-modal-card {
    box-shadow: none !important; max-height: none !important; overflow: visible !important;
    border: none !important; border-radius: 0 !important; margin: 0 !important; max-width: none !important;
  }
  #registerModalOverlay * {
    -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important;
  }
  @page { size: A4 landscape; margin: 10mm 9mm; }
}`;
  document.head.appendChild(style);
}

function closeRegisterModal() {
  const el = document.getElementById('registerModalOverlay');
  if (el) el.remove();
  document.removeEventListener('keydown', registerModalEscHandler);
}
function registerModalEscHandler(e) {
  if (e.key === 'Escape') closeRegisterModal();
}

function printPayroll() {
  const rows = buildActiveSnapshotRows();
  if (!rows.length) return;

  ensureRegisterPrintStyles();

  const periodLabel = formatPeriodDisplay(state.periodStart, state.periodEnd);
  const payoutLabel = state.payoutDate ? formatDateDisplay(state.payoutDate) : '—';
  const generatedLabel = new Date().toLocaleString('en-US', {
    month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'
  });
  const preparedBy = (window.CURRENT_USER_NAME || '').trim();

  const totals = rows.reduce((acc, r) => {
    acc.basic += r.basicPay; acc.ot += r.otPay; acc.ut += r.utPay; acc.allow += r.allow;
    acc.gross += r.gross; acc.ded += r.totalDed; acc.net += Math.max(0, r.net);
    return acc;
  }, { basic: 0, ot: 0, ut: 0, allow: 0, gross: 0, ded: 0, net: 0 });

  // Sort alphabetically by name so the printed register is easy to
  // scan/file, regardless of whatever sort HR currently has on screen.
  const sortedRows = [...rows].sort((a, b) => (a.name || '').localeCompare(b.name || ''));

  const bodyRows = sortedRows.map((r, i) => `
    <tr>
      <td class="c-name">${r.code ? `<div class="c-id-row"><span class="c-id">${escapeHtml(r.code)}</span></div>` : ''}<div class="c-name-text">${escapeHtml(r.name).replace(/,\s+/, ',&nbsp;')}</div>${r.dept ? `<div class="c-dept">${escapeHtml(r.dept)}</div>` : ''}</td>
      <td class="c-num">${r.days || 0}</td>
      <td class="c-amt">${money(r.basicPay)}</td>
      <td class="c-amt">${money(r.otPay)}</td>
      <td class="c-amt">${r.utPay ? '-' + money(r.utPay) : '0.00'}</td>
      <td class="c-amt">${money(r.allow)}</td>
      <td class="c-amt c-strong">${money(r.gross)}</td>
      <td class="c-amt">${money(r.sss)}</td>
      <td class="c-amt">${money(r.phic)}</td>
      <td class="c-amt">${money(r.pagibig)}</td>
      <td class="c-amt">${money(r.tax)}</td>
      <td class="c-amt">${money(r.other)}</td>
      <td class="c-amt c-strong">${money(r.totalDed)}</td>
      <td class="c-amt c-net">${money(Math.max(0, r.net))}</td>
    </tr>`).join('');

  // Shrink the font a touch once the roster gets long, so a bigger
  // headcount still has a fair shot at fitting on one printed page.
  const density = rows.length > 40 ? 'register-dense' : rows.length > 22 ? 'register-cozy' : 'register-normal';

  // Remove any existing instance before opening a fresh one.
  const existing = document.getElementById('registerModalOverlay');
  if (existing) existing.remove();

  const overlay = document.createElement('div');
  overlay.id = 'registerModalOverlay';
  overlay.className = 'register-modal-overlay';
  overlay.innerHTML = `
    <div class="register-modal-card">
      <div class="register-modal-toolbar no-print">
        <div class="register-modal-title">Payroll Register</div>
        <div class="register-modal-actions">
          <button type="button" class="secondary" data-action="close">Close</button>
          <button type="button" data-action="print">Print</button>
        </div>
      </div>
      <div class="register-sheet ${density}">
        <div class="register-head">
          <div>
            <h1>HCI GroceryOne — Payroll Register</h1>
            <div class="sub">Period: ${escapeHtml(periodLabel)}</div>
          </div>
          <div class="meta">
            <div>Payout Date: <b>${escapeHtml(payoutLabel)}</b></div>
            <div>Employees: <b>${rows.length}</b></div>
            <div>Generated: <b>${escapeHtml(generatedLabel)}</b></div>
          </div>
        </div>

        <table>
          <colgroup>
            <col style="width:17%">
            <col style="width:3.5%">
            <col style="width:7.5%">
            <col style="width:7%">
            <col style="width:6%">
            <col style="width:6%">
            <col style="width:8%">
            <col style="width:6%">
            <col style="width:6.5%">
            <col style="width:6%">
            <col style="width:6%">
            <col style="width:6%">
            <col style="width:7%">
            <col style="width:7.5%">
          </colgroup>
          <thead>
            <tr>
              <th>Employee</th>
              <th>Days</th>
              <th>Basic Pay</th>
              <th>OT Pay</th>
              <th>UT Ded.</th>
              <th>Allow.</th>
              <th>Gross Pay</th>
              <th>SSS</th>
              <th>PhilHealth</th>
              <th>Pag-IBIG</th>
              <th>Tax</th>
              <th>Other Ded.</th>
              <th>Total Ded.</th>
              <th>Net Pay</th>
            </tr>
          </thead>
          <tbody>${bodyRows}</tbody>
          <tfoot>
            <tr>
              <td colspan="2">TOTAL</td>
              <td class="c-amt">${money(totals.basic)}</td>
              <td class="c-amt">${money(totals.ot)}</td>
              <td class="c-amt">${money(totals.ut)}</td>
              <td class="c-amt">${money(totals.allow)}</td>
              <td class="c-amt">${money(totals.gross)}</td>
              <td colspan="5"></td>
              <td class="c-amt">${money(totals.ded)}</td>
              <td class="c-amt">${money(totals.net)}</td>
            </tr>
          </tfoot>
        </table>

        <div class="register-foot">
          <div class="sig"><div class="line">Prepared by${preparedBy ? ' — ' + escapeHtml(preparedBy) : ''}</div></div>
          <div class="sig"><div class="line">Approved by</div></div>
          <div class="sig"><div class="line">Received by</div></div>
        </div>
        <div class="register-gen-note">Generated from HCI GroceryOne HRMS Payroll Run. Figures are computed server-side from actual Attendance records and each employee's current rate on file.</div>
      </div>
    </div>`;

  document.body.appendChild(overlay);

  overlay.querySelector('[data-action="close"]').addEventListener('click', closeRegisterModal);
  overlay.querySelector('[data-action="print"]').addEventListener('click', () => window.print());
  overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) closeRegisterModal(); });
  document.addEventListener('keydown', registerModalEscHandler);
}

// Ensures the print-only stylesheet for the payslip modal exists in the
// page (added once). This is what makes "Print" only output the
// payslip card itself when the browser print dialog is triggered,
// instead of the whole Payroll Run page behind it.
function ensurePayslipPrintStyles() {
  if (document.getElementById('payslipPrintStyles')) return;
  const style = document.createElement('style');
  style.id = 'payslipPrintStyles';
  style.textContent = `
@media print {
  body > *:not(#payslipModalOverlay) { display: none !important; }
  #payslipModalOverlay {
    position: static !important; background: none !important; padding: 0 !important;
    display: block !important;
  }
  #payslipModalOverlay .payslip-modal-toolbar { display: none !important; }
  #payslipModalOverlay .payslip-modal-card {
    box-shadow: none !important; max-height: none !important; overflow: visible !important;
    border: none !important; border-radius: 0 !important; margin: 0 !important; max-width: none !important;
  }
  @page { size: A4 portrait; margin: 14mm 16mm; }
}`;
  document.head.appendChild(style);
}

function closePayslipModal() {
  const el = document.getElementById('payslipModalOverlay');
  if (el) el.remove();
  document.removeEventListener('keydown', payslipModalEscHandler);
}
function payslipModalEscHandler(e) {
  if (e.key === 'Escape') closePayslipModal();
}

// Shows ONE employee's payslip as an in-page popup/modal -- a proper
// portrait payslip document (header, employee details, itemized
// earnings and deductions, big Net Pay total, signature lines) --
// instead of the full multi-employee register, and instead of opening
// a separate browser tab/window. "Print" here only prints the payslip
// card itself (see ensurePayslipPrintStyles()), so it never spills the
// rest of the Payroll Run page onto the printout or onto an extra page.
// Uses the exact same figures already on screen for this pay period
// (buildActiveSnapshotRows()) -- no need to save the record first.
function printEmployeePayslip(empId) {
  const rows = buildActiveSnapshotRows();
  const r = rows.find(x => x.empId === empId);
  const emp = state.employees.find(e => e.id === empId);
  if (!r || !emp) return;

  ensurePayslipPrintStyles();

  const periodLabel = formatPeriodDisplay(state.periodStart, state.periodEnd);
  const payoutLabel = state.payoutDate ? formatDateDisplay(state.payoutDate) : '—';
  const generatedLabel = new Date().toLocaleString('en-US', {
    month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'
  });
  const preparedBy = (window.CURRENT_USER_NAME || '').trim();

  const payoutMethod = emp.paymentMethod === 'cash'
    ? 'Cash (Walk-in)'
    : (emp.hasBank
        ? [emp.bank || '—', emp.bankAcct].filter(Boolean).join(' · ')
        : 'No bank details on file');

  // Remove any existing instance before opening a fresh one.
  const existing = document.getElementById('payslipModalOverlay');
  if (existing) existing.remove();

  const overlay = document.createElement('div');
  overlay.id = 'payslipModalOverlay';
  overlay.className = 'payslip-modal-overlay';
  overlay.innerHTML = `
    <div class="payslip-modal-card">
      <div class="payslip-modal-toolbar no-print">
        <div class="payslip-modal-title">Employee Payslip</div>
        <div class="payslip-modal-actions">
          <button type="button" class="secondary" data-action="close">Close</button>
          <button type="button" data-action="print">Print</button>
        </div>
      </div>
      <div class="payslip-sheet">
        <div class="payslip-head">
          <div class="co">HCI GroceryOne — HRMS</div>
          <h1>Employee Payslip</h1>
          <div class="sub">Pay Period: ${escapeHtml(periodLabel)}</div>
        </div>

        <div class="payslip-body">
          <div class="payslip-meta">
            <div class="item"><div class="label">Employee</div><div class="value">${escapeHtml(r.name)}</div></div>
            <div class="item"><div class="label">Department</div><div class="value">${escapeHtml(r.dept || '—')}</div></div>
            <div class="item"><div class="label">Payout Date</div><div class="value">${escapeHtml(payoutLabel)}</div></div>
            <div class="item"><div class="label">${emp.paymentMethod === 'cash' ? 'Payout Method' : 'Deposited To'}</div><div class="value">${escapeHtml(payoutMethod)}</div></div>
            <div class="item"><div class="label">Days Worked</div><div class="value">${r.days || 0} day(s)</div></div>
            <div class="item"><div class="label">OT / Undertime Hours</div><div class="value">${r.ot || 0} hr OT &nbsp;/&nbsp; ${r.ut || 0} hr UT</div></div>
          </div>

          <h2 class="sec">Earnings</h2>
          <table>
            <tr><td>Basic Pay (${r.days || 0} day${(r.days || 0) === 1 ? '' : 's'} &times; ${money(r.daily)}/day)</td><td class="amt">${money(r.basicPay)}</td></tr>
            <tr><td>Overtime Pay (${r.ot || 0} hr${(r.ot || 0) === 1 ? '' : 's'} &times; ${money(r.otRate)}/hr)</td><td class="amt">${money(r.otPay)}</td></tr>
            <tr><td>Allowance</td><td class="amt">${money(r.allow)}</td></tr>
            ${r.utPay ? `<tr><td>Undertime Deduction (${r.ut || 0} hr${(r.ut || 0) === 1 ? '' : 's'} &times; ${money(r.utRate)}/hr)</td><td class="amt neg">−${money(r.utPay)}</td></tr>` : ''}
            <tr class="total"><td>Gross Pay</td><td class="amt">${money(r.gross)}</td></tr>
          </table>

          <h2 class="sec">Deductions</h2>
          <table>
            <tr><td>SSS</td><td class="amt">${money(r.sss)}</td></tr>
            <tr><td>PhilHealth</td><td class="amt">${money(r.phic)}</td></tr>
            <tr><td>Pag-IBIG</td><td class="amt">${money(r.pagibig)}</td></tr>
            <tr><td>Withholding Tax</td><td class="amt">${money(r.tax)}</td></tr>
            <tr><td>Other Deductions</td><td class="amt">${money(r.other)}</td></tr>
            <tr class="total"><td>Total Deductions</td><td class="amt">${money(r.totalDed)}</td></tr>
          </table>

          <div class="payslip-net">
            <div class="label">NET PAY</div>
            <div class="value">₱${money(Math.max(0, r.net))}</div>
          </div>

          <div class="payslip-foot-sign">
            <div class="sig"><div class="line">Prepared by${preparedBy ? ' — ' + escapeHtml(preparedBy) : ''}</div></div>
            <div class="sig"><div class="line">Received by (Employee Signature)</div></div>
          </div>

          <div class="payslip-gen-note">
            This is a system-generated payslip from the HCI GroceryOne HRMS Payroll Run for the pay period stated above. Generated ${escapeHtml(generatedLabel)}. Figures are computed from actual Attendance records and the employee's current rate on file for this period; if this period has not yet been saved/approved, treat this as a draft copy. For questions about this payslip, please contact HR.
          </div>
        </div>
      </div>
    </div>`;

  document.body.appendChild(overlay);

  overlay.querySelector('[data-action="close"]').addEventListener('click', closePayslipModal);
  overlay.querySelector('[data-action="print"]').addEventListener('click', () => window.print());
  overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) closePayslipModal(); });
  document.addEventListener('keydown', payslipModalEscHandler);
}

// ---------- SAVE RECORD / HISTORY ----------
function buildActiveSnapshotRows() {
  const workDays = parseFloat(document.getElementById('workDays').value) || 1;
  const workHours = parseFloat(document.getElementById('workHours').value) || 1;
  const otMult = parseFloat(document.getElementById('otMult').value) || 0;
  const active = getActiveEmployeesForPeriod();
  return active.map(emp => {
    const row = state.payrollRows[emp.id] || defaultPayrollRow();
    const daily = emp.basic / workDays;
    const basicPay = daily * (row.days || 0);
    const otRate = (daily / workHours) * otMult;
    const otPay = (row.ot || 0) * otRate;
    const utRate = daily / workHours;
    const utPay = (row.ut || 0) * utRate;
    const gross = basicPay + otPay - utPay + (row.allow || 0);
    syncAutoGovFields(emp, row, gross);
    const totalDed = (row.sss||0)+(row.phic||0)+(row.pagibig||0)+(row.tax||0)+(row.other||0);
    const net = Math.max(0, gross - totalDed);
    return {
      empId: emp.id, code: emp.code || '', name: emp.name || '(unnamed)', dept: emp.dept, basic: emp.basic,
      days: row.days, ot: row.ot, ut: row.ut || 0, allow: row.allow,
      daily, basicPay, otRate, otPay, utRate, utPay, gross,
      sss: row.sss, phic: row.phic, pagibig: row.pagibig, tax: row.tax, other: row.other,
      totalDed, net
    };
  });
}

// ---------- AUTO-SAVE ----------
// Payroll used to require HR to click "Save Record" / "Update Record"
// manually. Now, it's automatically recorded to the database as HR
// types/edits values — nothing to click. Debounced (waits a few
// seconds after the last change) so requests aren't spammed while typing.
let autoSaveTimer = null;
const AUTO_SAVE_DEBOUNCE_MS = 1200;

function updateAutoSaveStatus(mode, pending) {
  const el = document.getElementById('autoSaveStatus');
  if (!el) return;
  if (mode === 'saving') el.textContent = 'Saving…';
  else if (mode === 'saved') el.textContent = pending ? 'Saved as Draft (not yet submitted for Finance approval)' : 'Saved ✓';
  else if (mode === 'error') el.textContent = 'Not saved — check connection';
  else el.textContent = 'Not yet recorded — enter values above to record this period';
}

// ---------- SUBMIT FOR APPROVAL (HR Staff / HR Manager only) ----------
// This box is only shown to HR users, and only
// when there's at least one of this HR user's employee records for the
// period in draft/pending/approved/rejected. Draft employees can be
// individually ticked (checkbox column) and submitted for Finance approval — the rest
// stay untouched as Draft.
function updateSubmitApprovalBox() {
  const box = document.getElementById('submitApprovalBox');
  const text = document.getElementById('submitApprovalText');
  const btn = document.getElementById('submitApprovalBtn');
  const selectAllCb = document.getElementById('paySelectAll');
  if (!box || !text || !btn) return;

  if (window.SESSION_ROLE === 'admin') {
    box.style.display = 'none';
    return;
  }

  const active = getActiveEmployeesForPeriod();
  const draftEmps    = active.filter(e => approvalStatuses[e.id] === 'draft');
  const pendingCount  = active.filter(e => approvalStatuses[e.id] === 'pending').length;
  const approvedCount = active.filter(e => approvalStatuses[e.id] === 'approved').length;
  const rejectedCount = active.filter(e => approvalStatuses[e.id] === 'rejected').length;

  if (draftEmps.length === 0 && pendingCount === 0 && approvedCount === 0 && rejectedCount === 0) {
    box.style.display = 'none';
    return;
  }

  // Keep the selection limited to employees still actually in draft
  // (e.g. one may have just been submitted, or edited back to draft).
  Array.from(selectedForApproval).forEach(id => {
    if (approvalStatuses[id] !== 'draft') selectedForApproval.delete(id);
  });

  box.style.display = 'block';
  box.style.borderColor = 'var(--mustard, #E0A23B)';
  box.style.background = 'var(--mustard-pale, #FBEFD8)';
  text.style.color = '#7A5A1E';

  let msg = `📝 <strong>${draftEmps.length}</strong> employee(s) in Draft — Finance cannot review these yet.`;
  const extras = [];
  if (pendingCount)  extras.push(`⏳ ${pendingCount} submitted, awaiting Finance approval`);
  if (approvedCount) extras.push(`✅ ${approvedCount} Finance-approved, awaiting release`);
  if (rejectedCount) extras.push(`❌ ${rejectedCount} rejected — edit their values to draft them again`);
  if (extras.length) msg += ' ' + extras.join(' · ') + '.';
  msg += draftEmps.length
    ? ' Tick the employees (leftmost column) you want to send, then submit.'
    : '';
  text.innerHTML = msg;

  if (selectAllCb) {
    selectAllCb.disabled = draftEmps.length === 0;
    selectAllCb.checked = draftEmps.length > 0 && draftEmps.every(e => selectedForApproval.has(e.id));
  }

  const selectedCount = selectedForApproval.size;
  btn.style.display = draftEmps.length > 0 ? 'inline-block' : 'none';
  btn.disabled = selectedCount === 0;
  btn.textContent = selectedCount > 0
    ? `Submit Selected (${selectedCount}) for Approval →`
    : 'Submit Selected for Approval →';
}

async function submitPeriodForApproval() {
  const btn = document.getElementById('submitApprovalBtn');
  if (!btn) return;
  const employeeIds = Array.from(selectedForApproval);
  if (employeeIds.length === 0) return;

  const names = getActiveEmployeesForPeriod()
    .filter(e => selectedForApproval.has(e.id))
    .map(e => e.name)
    .join(', ');
  Swal.fire({
    title: 'Submit payroll for approval?',
    text: `Submit ${employeeIds.length} employee(s) for Finance/Admin approval? ${names} You will not be able to edit their values until a decision is made.`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Yes, submit',
    cancelButtonText: 'Cancel'
  }).then(async function (result) {
    if (!result.isConfirmed) return;
    btn.disabled = true;
    btn.textContent = 'Submitting…';

  try {
    const res = await fetch('submit_for_approval.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': window.CSRF_TOKEN || ''
      },
      body: JSON.stringify({ periodStart: state.periodStart, periodEnd: state.periodEnd, employeeIds })
    });
    const data = await res.json();
    Swal.fire({
      icon: data.success ? 'success' : 'error',
      title: data.success ? 'Submitted' : 'Submission failed',
      text: data.message || (data.success ? 'Submitted.' : 'An error occurred.'),
      confirmButtonText: 'Okay'
    });
    if (data.success) {
      const submittedIds = (data.data && data.data.submittedIds) || employeeIds;
      submittedIds.forEach(id => { approvalStatuses[id] = 'pending'; });
      selectedForApproval.clear();
      renderPayroll();
    } else {
      btn.disabled = false;
      btn.textContent = 'Submit Selected for Approval →';
    }
  } catch (err) {
    Swal.fire({
      icon: 'error',
      title: 'Connection error',
      text: 'Could not reach the server. Check your connection and try again.',
      confirmButtonText: 'Okay'
    });
    btn.disabled = false;
    btn.textContent = 'Submit Selected for Approval →';
  }
  });
}

function scheduleAutoSaveRecord() {
  const active = getActiveEmployeesForPeriod();
  if (active.length === 0) return;
  updateAutoSaveStatus('saving');
  clearTimeout(autoSaveTimer);
  autoSaveTimer = setTimeout(() => {
    const existing = (state.history || []).find(
      r => r.periodStart === state.periodStart && r.periodEnd === state.periodEnd
    );
    saveOrUpdateRecord(existing ? existing.id : null);
  }, AUTO_SAVE_DEBOUNCE_MS);
}

function showSaveError(msg) {
  const el = document.getElementById('saveErrorMsg');
  if (!el) return;
  el.textContent = msg;
  el.style.display = 'block';
}
function hideSaveError() {
  const el = document.getElementById('saveErrorMsg');
  if (!el) return;
  el.style.display = 'none';
  el.textContent = '';
}

// Saves the current period's payroll straight to the real HRMS database
// (payroll_records / payroll_logs — see payroll/save_record.php), then
// mirrors it into the local history[] so the History tab (search, sort,
// archive) still has its own working copy to page through. Called
// automatically by scheduleAutoSaveRecord() — no manual button needed.
async function saveOrUpdateRecord(existingId) {
  const rows = buildActiveSnapshotRows();
  const totals = rows.reduce((acc, r) => { acc.gross += r.gross; acc.ded += r.totalDed; acc.net += r.net; return acc; }, { gross: 0, ded: 0, net: 0 });
  if (!Array.isArray(state.history)) state.history = [];

  hideSaveError();

  try {
    const payload = {
      periodStart: state.periodStart,
      periodEnd: state.periodEnd,
      payoutDate: state.payoutDate || state.periodEnd || '',
      // Sent so the server can recompute basic/OT/undertime pay using
      // the SAME assumptions HR is looking at — the server still
      // validates these against a reasonable range before trusting them
      // (see save_record.php); it never trusts them blindly.
      assumptions: {
        workDays: parseFloat(document.getElementById('workDays').value) || 0,
        workHours: parseFloat(document.getElementById('workHours').value) || 0,
        otMult: parseFloat(document.getElementById('otMult').value) || 0
      },
      rows: rows.map(r => {
        const row = state.payrollRows[r.empId] || {};
        ensureGovAuto(row);
        return {
          empId: r.empId,
          // basic_pay in the DB doesn't have a separate column for
          // undertime yet, so Undertime Ded. (r.utPay) is subtracted here
          // first — gross_pay/net_pay still match what's shown in the UI,
          // only "basic_pay" ends up net-of-undertime.
          basicPay: r.basicPay - (r.utPay || 0),
          otPay: r.otPay,
          allow: r.allow,
          gross: r.gross,
          sss: r.sss,
          phic: r.phic,
          pagibig: r.pagibig,
          tax: r.tax,
          other: r.other,
          net: r.net,
          // Which of sss/phic/pagibig/tax are still on "auto" (server
          // recomputes these from the table, ignoring whatever number
          // is shown) vs. manually typed over by HR (server accepts the
          // typed value but checks it's within a reasonable range).
          govAuto: { ...row.govAuto }
        };
      })
    };

    const res = await fetch('save_record.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': window.CSRF_TOKEN || ''
      },
      body: JSON.stringify(payload)
    });
    const data = await res.json();

    if (!data.success) {
      showSaveError(data.message || 'Not saved to database. Please try again.');
      updateAutoSaveStatus('error');
      return;
    }
    var __requiresApproval = !!(data.data && data.data.requiresApproval);
    var __rowStatuses = (data.data && data.data.approvalStatuses) || {};
  } catch (err) {
    showSaveError('Could not reach the server. Check your connection and try again.');
    updateAutoSaveStatus('error');
    return;
  }

  Object.keys(__rowStatuses).forEach(id => { approvalStatuses[id] = __rowStatuses[id]; });
  Array.from(selectedForApproval).forEach(id => {
    if (approvalStatuses[id] !== 'draft') selectedForApproval.delete(id);
  });
  // Avoid a full row re-render while HR is actively typing in another
  // field of the same table (would drop focus/cursor mid-edit) — just
  // refresh the submit box; the badges/checkboxes catch up on the next
  // natural render (period change, next field blur, etc).
  const typingInTable = document.activeElement && document.activeElement.closest && document.activeElement.closest('#payRows');
  if (typingInTable) {
    updateSubmitApprovalBox();
  } else {
    renderPayroll();
  }

  // DB save succeeded — mirror into local history for the History tab.
  if (existingId) {
    const idx = state.history.findIndex(r => r.id === existingId);
    if (idx !== -1) {
      state.history[idx] = {
        ...state.history[idx],
        savedAt: new Date().toISOString(),
        payoutDate: state.payoutDate || state.periodEnd || '',
        assumptions: { ...state.assumptions },
        rows,
        totals
      };
    }
  } else {
    const record = {
      id: 'rec_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7),
      savedAt: new Date().toISOString(),
      periodStart: state.periodStart,
      periodEnd: state.periodEnd,
      periodLabel: formatPeriodDisplay(state.periodStart, state.periodEnd),
      payoutDate: state.payoutDate || state.periodEnd || '',
      assumptions: { ...state.assumptions },
      rows,
      totals
    };
    state.history.unshift(record);
  }

  saveState();
  // History page isn't loaded here — refresh it too if it happens to be open.
  if (typeof renderHistory === 'function') renderHistory();
  updateAutoSaveStatus('saved', __requiresApproval);
}


// ---------- PAGE INIT ----------
loadState();
if (window.SERVER_PERIOD) {
  state.periodStart = window.SERVER_PERIOD.start;
  state.periodEnd = window.SERVER_PERIOD.end;
}
loadPayrollRowsForPeriod();
applyAttendanceDefaults();
initPayFilterControls();
approvalStatuses = Object.assign({}, window.SERVER_APPROVAL_STATUSES || {});
renderPayroll();
saveState();
scheduleAutoSaveRecord();