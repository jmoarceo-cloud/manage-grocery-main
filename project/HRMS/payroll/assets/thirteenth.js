// ============================================================
// thirteenth.js — 13th Month Pay tab: totals basic pay earned per
// employee for a selected calendar year, from saved History records.
// Requires app.js to be loaded first.
// ============================================================

// ---------- 13TH MONTH PAY ----------
// The year field is a free, open-ended input (no min/max) so any calendar
// year can be selected — not just years that already have saved records.
let thirteenthSelectedYear = null;

function renderThirteenth() {
  const inp = document.getElementById('thirteenthYear');
  if (thirteenthSelectedYear === null) {
    thirteenthSelectedYear = new Date().getFullYear();
  }
  inp.value = thirteenthSelectedYear;
  computeAndRenderThirteenth(thirteenthSelectedYear);
}

function setThirteenthYear(year) {
  if (isNaN(year)) return;
  thirteenthSelectedYear = year;
  document.getElementById('thirteenthYear').value = year;
  computeAndRenderThirteenth(year);
}

function computeAndRenderThirteenth(year) {
  // Group by employee ID (not name) so a rename doesn't split someone's
  // total across two rows, and two different employees who happen to
  // share a name don't get merged into one. Older records saved before
  // empId was tracked fall back to grouping by name.
  const totals = {}; // key -> { empId, name, totalBasic }
  (state.history || []).forEach(rec => {
    const d = rec.periodEnd ? fromISO(rec.periodEnd) : new Date(rec.savedAt);
    if (d.getFullYear() !== year) return;
    rec.rows.forEach(r => {
      const key = (r.empId !== undefined && r.empId !== null) ? ('id:' + r.empId) : ('name:' + r.name);
      if (!totals[key]) totals[key] = { empId: r.empId, name: r.name, totalBasic: 0 };
      totals[key].totalBasic += (r.basicPay || 0);
    });
  });

  // Prefer the employee's current name (in case they were renamed since saving)
  Object.values(totals).forEach(t => {
    if (t.empId !== undefined && t.empId !== null) {
      const emp = state.employees.find(e => e.id === t.empId);
      if (emp && emp.name) t.name = emp.name;
    }
  });

  const entries = Object.values(totals).sort((a, b) => a.name.localeCompare(b.name));
  const tbody = document.getElementById('thirteenthRows');
  tbody.innerHTML = '';
  let totalBasicAll = 0, totalPayAll = 0;

  entries.forEach(({ name, totalBasic }) => {
    const pay = totalBasic / 12;
    totalBasicAll += totalBasic;
    totalPayAll += pay;
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="text-cell">${escapeHtml(name)}</td>
      <td>${fmt(totalBasic)}</td>
      <td class="computed">${fmt(pay)}</td>
    `;
    tbody.appendChild(tr);
  });

  document.getElementById('thirteenthEmptyNote').style.display = entries.length ? 'none' : 'block';
  document.getElementById('thirteenthTotalBasic').textContent = fmt(totalBasicAll);
  document.getElementById('thirteenthTotalPay').textContent = fmt(totalPayAll);
  document.getElementById('thirteenthStamp').textContent = '₱' + fmt(totalPayAll);
  document.getElementById('thirteenthYearBadge').textContent = year;
}

document.getElementById('thirteenthYear').addEventListener('input', e => {
  const v = clampNonNegativeInteger(e.target);
  if (v) {
    thirteenthSelectedYear = v;
    computeAndRenderThirteenth(v);
  }
});
document.getElementById('thirteenthYear').addEventListener('change', e => {
  let v = clampNonNegativeInteger(e.target);
  if (!v) v = new Date().getFullYear();
  setThirteenthYear(v);
});
document.getElementById('yearPrevBtn').addEventListener('click', () => {
  setThirteenthYear((thirteenthSelectedYear ?? new Date().getFullYear()) - 1);
});
document.getElementById('yearNextBtn').addEventListener('click', () => {
  setThirteenthYear((thirteenthSelectedYear ?? new Date().getFullYear()) + 1);
});


// Isama ang mga payroll record galing mismo sa database
// (window.SERVER_HISTORY_RECORDS, see thirteenth.php) — hindi lang yung
// laman ng browser localStorage ng kasalukuyang user. Kailangan ito para
// makita rin dito ang mga payroll na na-save ni HR Staff (mga Employees),
// hindi lang yung sa Admin/HR mismo. Parehong logic ng history.js.
function mergeServerHistoryRecordsForThirteenth() {
  const serverRecords = Array.isArray(window.SERVER_HISTORY_RECORDS) ? window.SERVER_HISTORY_RECORDS : [];
  if (!Array.isArray(state.history)) state.history = [];

  // Alisin muna sa naka-cache na state.history (localStorage) ang mga DB
  // record na wala (o hindi na kasama) sa kasalukuyang "Showing:" scope —
  // kung hindi, patuloy silang lalabas kahit lumipat na ang Admin mula
  // "Lahat" papuntang "HR Staff & HR Manager" (o kabaliktaran), dahil
  // naiwan pa rin sila sa browser mula sa dating (mas malawak na) scope.
  // Hindi kasama dito ang mga bagong-save na record (walang 'db_' prefix
  // pa) — 'yon ay hindi pa naka-sync sa DB kaya huwag alisin.
  const serverKeys = new Set(serverRecords.map(r => r.periodStart + '_' + r.periodEnd));
  state.history = state.history.filter(r => {
    if (typeof r.id === 'string' && r.id.indexOf('db_') === 0) {
      return serverKeys.has(r.periodStart + '_' + r.periodEnd);
    }
    return true;
  });

  if (!serverRecords.length) { saveState(); return; }

  serverRecords.forEach(serverRec => {
    const idx = state.history.findIndex(r => r.periodStart === serverRec.periodStart && r.periodEnd === serverRec.periodEnd);
    if (idx === -1) {
      state.history.push(serverRec);
    } else {
      state.history[idx] = { ...state.history[idx], ...serverRec };
    }
  });
  saveState();
}

// ---------- PAGE INIT ----------
loadState();
mergeServerHistoryRecordsForThirteenth();
renderThirteenth();