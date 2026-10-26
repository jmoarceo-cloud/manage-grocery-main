// ============================================================
// attendance.js — Attendance tab: daily attendance table, filters,
// monthly summary, and CSV export.
// Requires app.js to be loaded first.
// ============================================================

// ---------- ATTENDANCE ----------
const ATTENDANCE_STATUSES = ['Present', 'Late', 'Half Day', 'On Leave', 'Absent'];
const STANDARD_TIME_IN = '09:00';
const STANDARD_TIME_OUT = '17:00';
let attSearchTerm = '';
let attFilterDeptVal = '';
let attFilterStatusVal = '';
let attSortBy = 'name-asc';

function attClass(status) {
  // Turns "Half Day" -> "HalfDay", "On Leave" -> "OnLeave", so status
  // names can be used as CSS class suffixes without embedded spaces.
  return (status || '').replace(/\s+/g, '');
}

function initAttFilterControls() {
  const deptSel = document.getElementById('attFilterDept');
  deptSel.innerHTML = '<option value="">All departments</option>' +
    DEPARTMENT_OPTIONS.map(d => `<option value="${d}">${d}</option>`).join('');

  document.getElementById('attSearch').addEventListener('input', (e) => {
    attSearchTerm = e.target.value;
    renderAttendance();
  });
  deptSel.addEventListener('change', (e) => {
    attFilterDeptVal = e.target.value;
    renderAttendance();
  });
  document.getElementById('attFilterStatus').addEventListener('change', (e) => {
    attFilterStatusVal = e.target.value;
    renderAttendance();
  });
  document.getElementById('attSort').addEventListener('change', (e) => {
    attSortBy = e.target.value;
    renderAttendance();
  });
  document.getElementById('attClearFilters').addEventListener('click', () => {
    attSearchTerm = '';
    attFilterDeptVal = '';
    attFilterStatusVal = '';
    attSortBy = 'name-asc';
    document.getElementById('attSearch').value = '';
    document.getElementById('attFilterDept').value = '';
    document.getElementById('attFilterStatus').value = '';
    document.getElementById('attSort').value = 'name-asc';
    renderAttendance();
  });
  document.getElementById('attDate').addEventListener('change', (e) => {
    if (!e.target.value) return;
    state.attendanceSelectedDate = e.target.value;
    renderAttendance();
    saveState();
  });
  document.getElementById('attPrevBtn').addEventListener('click', () => shiftAttendanceDate(-1));
  document.getElementById('attNextBtn').addEventListener('click', () => shiftAttendanceDate(1));
}

function shiftAttendanceDate(days) {
  const cur = fromISO(state.attendanceSelectedDate || toISO(new Date()));
  cur.setDate(cur.getDate() + days);
  state.attendanceSelectedDate = toISO(cur);
  renderAttendance();
  saveState();
}

function setAttendanceToday() {
  state.attendanceSelectedDate = toISO(new Date());
  renderAttendance();
  saveState();
}

function ensureAttendanceDate(dateISO) {
  if (!state.attendance[dateISO]) state.attendance[dateISO] = {};
  return state.attendance[dateISO];
}

function getAttendanceRecord(dateISO, emp) {
  const dayData = state.attendance[dateISO] || {};
  if (dayData[emp.id]) return dayData[emp.id];
  // A helpful default: employees currently on LOA default to "On Leave"
  // for a day being tracked for the first time. Present/Late default to
  // the standard shift hours so Time In/Out don't need to be typed by hand.
  const defaultStatus = emp.status === 'LOA' ? 'On Leave' : 'Present';
  if (defaultStatus === 'Present' || defaultStatus === 'Late') {
    return {
      status: defaultStatus,
      timeIn: STANDARD_TIME_IN,
      timeOut: STANDARD_TIME_OUT,
      hours: computeHoursFromTimes(STANDARD_TIME_IN, STANDARD_TIME_OUT) || 0,
      notes: ''
    };
  }
  return { status: defaultStatus, timeIn: '', timeOut: '', hours: 0, notes: '' };
}

// Inactive employees are excluded — they're no longer part of the active
// roster being tracked day to day. Active and LOA employees both show up
// (LOA staff still need their leave days logged).
function getAttendanceEmployees() {
  return state.employees.filter(e => e.status !== 'Inactive');
}

function computeHoursFromTimes(timeIn, timeOut) {
  if (!timeIn || !timeOut) return null;
  const [ih, im] = timeIn.split(':').map(Number);
  const [oh, om] = timeOut.split(':').map(Number);
  if ([ih, im, oh, om].some(n => isNaN(n))) return null;
  let mins = (oh * 60 + om) - (ih * 60 + im);
  if (mins < 0) mins += 24 * 60; // handles an overnight shift
  return Math.round((mins / 60) * 100) / 100;
}

function getDisplayedAttendance() {
  const term = normalizeName(attSearchTerm);
  const dateISO = state.attendanceSelectedDate;
  const dayData = state.attendance[dateISO] || {};
  let list = getAttendanceEmployees().filter(emp => {
    if (term && !normalizeName(emp.name).includes(term)) return false;
    if (attFilterDeptVal && emp.dept !== attFilterDeptVal) return false;
    if (attFilterStatusVal) {
      const rec = dayData[emp.id] || getAttendanceRecord(dateISO, emp);
      if (rec.status !== attFilterStatusVal) return false;
    }
    return true;
  });

  const statusOrder = { Present: 0, Late: 1, 'Half Day': 2, 'On Leave': 3, Absent: 4 };
  list.sort((a, b) => {
    switch (attSortBy) {
      case 'name-desc': return (b.name || '').localeCompare(a.name || '');
      case 'status': {
        const ra = dayData[a.id] || getAttendanceRecord(dateISO, a);
        const rb = dayData[b.id] || getAttendanceRecord(dateISO, b);
        return (statusOrder[ra.status] ?? 9) - (statusOrder[rb.status] ?? 9) || (a.name || '').localeCompare(b.name || '');
      }
      case 'dept': return (a.dept || '').localeCompare(b.dept || '') || (a.name || '').localeCompare(b.name || '');
      case 'name-asc':
      default: return (a.name || '').localeCompare(b.name || '');
    }
  });
  return list;
}

function renderAttendance() {
  if (!state.attendanceSelectedDate) state.attendanceSelectedDate = toISO(new Date());
  const dateISO = state.attendanceSelectedDate;
  document.getElementById('attDate').value = dateISO;
  document.getElementById('attDateDisplay').textContent = fromISO(dateISO).toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

  ensureAttendanceDate(dateISO);
  const dayData = state.attendance[dateISO];

  const allEmps = getAttendanceEmployees();
  const displayed = getDisplayedAttendance();
  document.getElementById('attEmptyNote').style.display = allEmps.length ? 'none' : 'block';
  document.getElementById('attTable').style.display = allEmps.length ? '' : 'none';
  document.getElementById('attNoResults').style.display = (allEmps.length && !displayed.length) ? 'block' : 'none';

  const tbody = document.getElementById('attRows');
  tbody.innerHTML = '';

  displayed.forEach(emp => {
    if (!dayData[emp.id]) {
      dayData[emp.id] = getAttendanceRecord(dateISO, emp);
    }
    const rec = dayData[emp.id];
    const tr = document.createElement('tr');
    tr.className = `att-row-${attClass(rec.status)}`;
    tr.dataset.empId = emp.id;
    const disabledTimes = (rec.status === 'Absent' || rec.status === 'On Leave');
    tr.innerHTML = `
      <td class="text-cell">${escapeHtml(emp.name) || '(unnamed)'}</td>
      <td class="text-cell">${escapeHtml(emp.dept || '—')}</td>
      <td class="text-cell">
        <select class="status-select att-${attClass(rec.status)}" data-f="status">
          ${ATTENDANCE_STATUSES.map(s => `<option value="${s}" ${s === rec.status ? 'selected' : ''}>${s}</option>`).join('')}
        </select>
      </td>
      <td><input type="time" data-f="timeIn" value="${rec.timeIn || ''}" ${disabledTimes ? 'disabled' : ''} /></td>
      <td><input type="time" data-f="timeOut" value="${rec.timeOut || ''}" ${disabledTimes ? 'disabled' : ''} /></td>
      <td><input type="text" inputmode="decimal" data-f="hours" value="${rec.hours || 0}" /></td>
      <td class="text-cell"><input type="text" data-f="notes" value="${escapeHtml(rec.notes || '')}" placeholder="Optional note" /></td>
    `;

    const statusSel = tr.querySelector('[data-f="status"]');
    const timeInEl = tr.querySelector('[data-f="timeIn"]');
    const timeOutEl = tr.querySelector('[data-f="timeOut"]');
    const hoursEl = tr.querySelector('[data-f="hours"]');
    const notesEl = tr.querySelector('[data-f="notes"]');

    statusSel.addEventListener('change', () => {
      rec.status = statusSel.value;
      statusSel.className = `status-select att-${attClass(rec.status)}`;
      tr.className = `att-row-${attClass(rec.status)}`;
      if (rec.status === 'Absent' || rec.status === 'On Leave') {
        rec.timeIn = ''; rec.timeOut = ''; rec.hours = 0;
        timeInEl.value = ''; timeOutEl.value = ''; hoursEl.value = 0;
        timeInEl.disabled = true; timeOutEl.disabled = true;
      } else {
        timeInEl.disabled = false; timeOutEl.disabled = false;
        // Auto-fill the standard shift for Present/Late whenever the
        // field is still empty — never overwrites a time already entered.
        if (rec.status === 'Present' || rec.status === 'Late') {
          if (!rec.timeIn) { rec.timeIn = STANDARD_TIME_IN; timeInEl.value = STANDARD_TIME_IN; }
          if (!rec.timeOut) { rec.timeOut = STANDARD_TIME_OUT; timeOutEl.value = STANDARD_TIME_OUT; }
          const h = computeHoursFromTimes(rec.timeIn, rec.timeOut);
          if (h !== null) { rec.hours = h; hoursEl.value = h; }
        }
      }
      updateAttCount();
      renderAttendanceSummary(dateISO);
      saveState();
    });

    function recalcHoursFromTimes() {
      const h = computeHoursFromTimes(timeInEl.value, timeOutEl.value);
      if (h !== null) {
        rec.hours = h;
        hoursEl.value = h;
      }
    }
    timeInEl.addEventListener('change', () => { rec.timeIn = timeInEl.value; recalcHoursFromTimes(); renderAttendanceSummary(dateISO); saveState(); });
    timeOutEl.addEventListener('change', () => { rec.timeOut = timeOutEl.value; recalcHoursFromTimes(); renderAttendanceSummary(dateISO); saveState(); });
    hoursEl.addEventListener('keydown', (e) => blockNonNumericKeys(e, true));
    hoursEl.addEventListener('input', () => { rec.hours = clampNonNegative(hoursEl); });
    hoursEl.addEventListener('change', () => { finalizeNumberField(hoursEl); rec.hours = clampNonNegative(hoursEl); renderAttendanceSummary(dateISO); saveState(); });
    notesEl.addEventListener('input', () => { rec.notes = notesEl.value; });
    notesEl.addEventListener('change', () => { saveState(); });

    tbody.appendChild(tr);
  });

  updateAttCount();
  renderAttendanceSummary(dateISO);
}

function updateAttCount() {
  const dateISO = state.attendanceSelectedDate;
  const dayData = state.attendance[dateISO] || {};
  const all = getAttendanceEmployees();
  let present = 0, absent = 0, leave = 0, late = 0, half = 0;
  all.forEach(emp => {
    const rec = dayData[emp.id] || getAttendanceRecord(dateISO, emp);
    if (rec.status === 'Present') present++;
    else if (rec.status === 'Absent') absent++;
    else if (rec.status === 'On Leave') leave++;
    else if (rec.status === 'Late') late++;
    else if (rec.status === 'Half Day') half++;
  });
  document.getElementById('attCount').textContent =
    `${present} present · ${late} late · ${half} half day · ${leave} on leave · ${absent} absent · ${all.length} total`;
}

function markAllPresent() {
  const dateISO = state.attendanceSelectedDate;
  const dayData = ensureAttendanceDate(dateISO);
  getAttendanceEmployees().forEach(emp => {
    const prior = dayData[emp.id];
    const timeIn = (prior && prior.timeIn) || STANDARD_TIME_IN;
    const timeOut = (prior && prior.timeOut) || STANDARD_TIME_OUT;
    dayData[emp.id] = {
      status: 'Present',
      timeIn, timeOut,
      hours: (prior && prior.timeIn && prior.timeOut) ? prior.hours || 0 : (computeHoursFromTimes(timeIn, timeOut) || 0),
      notes: prior ? prior.notes || '' : ''
    };
  });
  renderAttendance();
  saveState();
}

function renderAttendanceSummary(dateISO) {
  const d = fromISO(dateISO);
  const year = d.getFullYear(), month = d.getMonth();
  document.getElementById('attMonthLabel').textContent = d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

  const totals = {}; // empId -> { Present, Late, 'Half Day', 'On Leave', Absent, hours }
  Object.keys(state.attendance).forEach(iso => {
    const dd = fromISO(iso);
    if (dd.getFullYear() !== year || dd.getMonth() !== month) return;
    const dayData = state.attendance[iso];
    Object.keys(dayData).forEach(empIdStr => {
      const empId = parseInt(empIdStr, 10);
      const rec = dayData[empIdStr];
      if (!totals[empId]) totals[empId] = { Present: 0, Late: 0, 'Half Day': 0, 'On Leave': 0, Absent: 0, hours: 0 };
      if (totals[empId][rec.status] !== undefined) totals[empId][rec.status]++;
      totals[empId].hours += (rec.hours || 0);
    });
  });

  const rows = Object.keys(totals).map(idStr => {
    const id = parseInt(idStr, 10);
    const emp = state.employees.find(e => e.id === id);
    return { id, name: emp ? emp.name : '(removed employee)', ...totals[idStr] };
  }).sort((a, b) => (a.name || '').localeCompare(b.name || ''));

  const tbody = document.getElementById('attSummaryRows');
  tbody.innerHTML = '';
  document.getElementById('attSummaryEmptyNote').style.display = rows.length ? 'none' : 'block';
  rows.forEach(r => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="text-cell">${escapeHtml(r.name) || '(unnamed)'}</td>
      <td>${r.Present}</td>
      <td>${r.Late}</td>
      <td>${r['Half Day']}</td>
      <td>${r['On Leave']}</td>
      <td>${r.Absent}</td>
      <td class="computed">${fmt(r.hours)}</td>
    `;
    tbody.appendChild(tr);
  });
}

function exportAttendanceCSV() {
  const dateISO = state.attendanceSelectedDate;
  const dayData = state.attendance[dateISO] || {};
  const emps = getAttendanceEmployees();
  if (!emps.length) return;

  const headers = ['Employee', 'Department', 'Status', 'Time In', 'Time Out', 'Hours', 'Notes'];
  const lines = [headers.map(csvEscape).join(',')];
  emps.forEach(emp => {
    const rec = dayData[emp.id] || getAttendanceRecord(dateISO, emp);
    lines.push([
      csvEscape(emp.name), csvEscape(emp.dept || ''), csvEscape(rec.status),
      csvEscape(rec.timeIn || ''), csvEscape(rec.timeOut || ''), (rec.hours || 0), csvEscape(rec.notes || '')
    ].join(','));
  });

  const csvContent = '\uFEFF' + lines.join('\r\n');
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `attendance_${dateISO}.csv`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}


// ---------- PAGE INIT ----------
loadState();
initAttFilterControls();
renderAttendance();
