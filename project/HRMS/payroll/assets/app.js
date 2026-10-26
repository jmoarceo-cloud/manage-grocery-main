// ============================================================
// app.js — shared state, utilities, and generic confirm modal.
// Loaded on every page. Page-specific rendering logic lives in
// payroll.js / history.js / thirteenth.js
// ============================================================

const STORAGE_KEY = 'hrms-payroll-data';
let state = {
  employees: [],
  periodStart: '',
  periodEnd: '',
  assumptions: { workDays: 22, workHours: 8, otMult: 1.25 },
  payrollRows: {},   // employeeId -> { days, ot, allow, sss, phic, pagibig, tax, other }
  history: [],       // saved payroll records
  archivedHistory: [], // soft-deleted payroll records
  attendance: {},    // dateISO -> { employeeId -> { status, timeIn, timeOut, hours, notes } }
  attendanceSelectedDate: '' // last-viewed date in the Attendance tab
};
let empIdSeed = 1;
let saveTimer = null;
let confirmCallback = null;
const MIN_ABSENT_DAYS_TO_REMOVE = 5;

const POSITION_OPTIONS = [
  "Store Manager", "Department Supervisor",
  "HR Officer", "Accountant", "Payroll Staff",
  "Cashier", "Inventory Staff", "Produce Staff",
  "Warehouse Staff", "Receiving Clerk", "Inventory Controller",
  "Security Guard", "Janitor", "Maintenance Technician", "Electrician"
];
const DEPARTMENT_OPTIONS = [
  "Management", "Administration", "Store Operations", "Warehouse & Logistics", "Security & Facilities"
];

// Which positions are valid for each department. A department not listed
// here (or no department chosen yet) falls back to the full position list.
const DEPARTMENT_POSITIONS = {
  "Management": ["Store Manager", "Department Supervisor"],
  "Administration": ["HR Officer", "Accountant", "Payroll Staff"],
  "Store Operations": ["Cashier", "Inventory Staff", "Produce Staff"],
  "Warehouse & Logistics": ["Warehouse Staff", "Receiving Clerk", "Inventory Controller"],
  "Security & Facilities": ["Security Guard", "Janitor", "Maintenance Technician", "Electrician"]
};

function getPositionsForDept(dept) {
  // No department chosen yet → no valid positions to offer. Positions are
  // only shown once they belong to the selected department.
  if (!dept) return [];
  return DEPARTMENT_POSITIONS[dept] || [];
}

function fmt(n) {
  if (!isFinite(n)) n = 0;
  return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function pad2(n) { return String(n).padStart(2, '0'); }
function toISO(d) { return `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`; }
function fromISO(s) {
  const [y, m, d] = s.split('-').map(Number);
  return new Date(y, m - 1, d);
}
function lastDayOfMonth(year, monthIndex) { return new Date(year, monthIndex + 1, 0).getDate(); }

function defaultPeriodFor(date, half) {
  const year = date.getFullYear(), month = date.getMonth();
  if (half === 1) {
    return { start: toISO(new Date(year, month, 1)), end: toISO(new Date(year, month, 15)) };
  }
  return { start: toISO(new Date(year, month, 16)), end: toISO(new Date(year, month, lastDayOfMonth(year, month))) };
}

function autoDetectPeriod() {
  const now = new Date();
  return defaultPeriodFor(now, now.getDate() <= 15 ? 1 : 2);
}

function formatPeriodDisplay(startISO, endISO) {
  if (!startISO || !endISO) return '—';
  const start = fromISO(startISO), end = fromISO(endISO);
  const opts = { month: 'short', day: 'numeric' };
  const startStr = start.toLocaleDateString('en-US', opts);
  const sameMonth = start.getMonth() === end.getMonth() && start.getFullYear() === end.getFullYear();
  const endStr = sameMonth
    ? `${end.getDate()}, ${end.getFullYear()}`
    : `${end.toLocaleDateString('en-US', opts)}, ${end.getFullYear()}`;
  return sameMonth ? `${startStr}–${endStr}` : `${startStr}, ${start.getFullYear()} – ${endStr}`;
}

function formatDateDisplay(iso) {
  if (!iso) return '—';
  const d = fromISO(iso);
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function buildOptionsHTML(options, currentValue, placeholder) {
  const val = (currentValue || '').trim();
  let extra = '';
  if (val && !options.includes(val)) {
    extra = `<option value="${escapeHtml(val)}" selected>${escapeHtml(val)}</option>`;
  }
  const opts = options.map(o => `<option value="${o}" ${o === val ? 'selected' : ''}>${o}</option>`).join('');
  const placeholderText = placeholder || (options.length === 0 ? '— Select department first —' : '— Optional —');
  return `<option value="" ${val ? '' : 'selected'}>${placeholderText}</option>${extra}${opts}`;
}

// Employees no longer live in this module's own store — the roster is
// fetched live from the real HRMS `employees` table on every page load
// (see includes/header.php, which sets window.SERVER_EMPLOYEES). This
// keeps names, departments, and basic pay always in sync with HRMS.
function syncEmployeesFromServer() {
  if (Array.isArray(window.SERVER_EMPLOYEES)) {
    state.employees = window.SERVER_EMPLOYEES.map(e => ({ ...e }));
    empIdSeed = (state.employees.reduce((m, e) => Math.max(m, e.id), 0) || 0) + 1;
  }
}

function seedDefault() {
  state.employees = [];
  empIdSeed = 1;
  const p = autoDetectPeriod();
  state.periodStart = p.start;
  state.periodEnd = p.end;
  state.payrollRows = {};
  state.history = [];
  state.archivedHistory = [];
  state.attendance = {};
  state.attendanceSelectedDate = toISO(new Date());
}

// ---------- STATE PERSISTENCE (browser localStorage) ----------
// Every page loads the full state object from localStorage, so data
// entered on one tab (e.g. Employees) is immediately available on
// every other tab/page (Attendance, Payroll, History, 13th Month).
function loadState() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw) {
      const loaded = JSON.parse(raw);
      state = Object.assign(state, loaded);
      if (!Array.isArray(state.history)) state.history = [];
      if (!Array.isArray(state.archivedHistory)) state.archivedHistory = [];
      if (!state.attendance || typeof state.attendance !== 'object') state.attendance = {};
      if (!state.attendanceSelectedDate) state.attendanceSelectedDate = toISO(new Date());
      if (!state.periodStart || !state.periodEnd) {
        const p = autoDetectPeriod();
        state.periodStart = state.periodStart || p.start;
        state.periodEnd = state.periodEnd || p.end;
      }
    } else {
      seedDefault();
    }
  } catch (e) {
    seedDefault();
  }
  // Employee roster always comes fresh from the real HRMS database,
  // regardless of whatever was cached in localStorage before.
  syncEmployeesFromServer();
  saveState();
}

function saveState() {
  clearTimeout(saveTimer);
  saveTimer = setTimeout(() => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch (e) {
      // Silent — data stays in memory for this session even if persistence fails.
    }
  }, 350);
}

function clampNonNegative(inp) {
  // Called while typing: strips out any character that isn't a digit or a
  // single decimal point. This blocks +, -, e/E and any other symbol no
  // matter where in the value it's typed — not just at the start/end —
  // while still allowing the field to be temporarily empty so the user
  // can freely clear and retype a value.
  let sanitized = inp.value.replace(/[^0-9.]/g, '');
  const firstDot = sanitized.indexOf('.');
  if (firstDot !== -1) {
    sanitized = sanitized.slice(0, firstDot + 1) + sanitized.slice(firstDot + 1).replace(/\./g, '');
  }
  if (sanitized !== inp.value) inp.value = sanitized;
  let v = parseFloat(sanitized);
  if (isNaN(v) || v < 0) v = 0;
  return v;
}

function clampNonNegativeInteger(inp) {
  // Same idea as clampNonNegative but for whole-number-only fields (years,
  // day counts): strips every character that isn't a digit, wherever it
  // appears in the value.
  let sanitized = inp.value.replace(/[^0-9]/g, '');
  if (sanitized !== inp.value) inp.value = sanitized;
  let v = parseInt(sanitized, 10);
  if (isNaN(v) || v < 0) v = 0;
  return v;
}

function finalizeNumberField(inp) {
  // Called on blur/change: if the field was left empty, show 0 so it
  // doesn't look broken — but only once the user is done editing.
  if (inp.value === '' || isNaN(parseFloat(inp.value))) {
    inp.value = 0;
  }
}

// Blocks disallowed keystrokes at the keyboard level (in addition to the
// input-event sanitization in clampNonNegative), so letters and symbols
// like e, E, +, -, and spaces never even appear in the field — not even
// momentarily. Navigation, editing, and copy/paste shortcut keys are
// always allowed through.
function blockNonNumericKeys(e, allowDecimal) {
  const allowedControlKeys = [
    'Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
    'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'
  ];
  if (allowedControlKeys.includes(e.key)) return;
  if (e.ctrlKey || e.metaKey) return; // allow copy/paste/select-all etc.
  if (/^[0-9]$/.test(e.key)) return;
  if (allowDecimal && e.key === '.' && !e.target.value.includes('.')) return;
  e.preventDefault();
}

function normalizeName(s) {
  return (s || '').trim().replace(/\s+/g, ' ').toLowerCase();
}

function sanitizeNameField(inp) {
  // Strips any digit characters as the user types (or pastes), so employee
  // names can only contain letters and the usual name punctuation/spacing.
  const sanitized = inp.value.replace(/[0-9]/g, '');
  if (sanitized !== inp.value) inp.value = sanitized;
  return sanitized;
}

function isDuplicateName(name, excludeId) {
  const norm = normalizeName(name);
  if (!norm) return false;
  return state.employees.some(e => e.id !== excludeId && normalizeName(e.name) === norm);
}

function escapeHtml(str) {
  // Prevents stored XSS when user-entered text (names, etc.) is inserted
  // into innerHTML — always escape before interpolating into markup.
  return String(str ?? '').replace(/[&<>"']/g, ch => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[ch]));
}


// ---------- GENERIC CONFIRM MODAL ----------
function openConfirm(title, msg, onConfirm, actionClass, actionLabel) {
  document.getElementById('confirmTitle').textContent = title;
  document.getElementById('confirmMsg').textContent = msg;
  confirmCallback = onConfirm;
  const btn = document.getElementById('confirmActionBtn');
  btn.className = actionClass || 'confirm';
  btn.textContent = actionLabel || 'Yes';
  document.getElementById('confirmModal').classList.add('open');
}
function closeConfirmModal() {
  document.getElementById('confirmModal').classList.remove('open');
  confirmCallback = null;
}
document.getElementById('confirmActionBtn').addEventListener('click', () => {
  if (confirmCallback) confirmCallback();
  closeConfirmModal();
});
document.getElementById('confirmModal').addEventListener('click', (e) => {
  if (e.target.id === 'confirmModal') closeConfirmModal();
});

// Every modal-close function below is defined in a page-specific JS file
// (history.js) that isn't loaded on every page, so each call is guarded
// with a typeof check before Escape closes it.
document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return;
  closeConfirmModal();
  if (typeof closeRecordModal === 'function') closeRecordModal();
  if (typeof closeArchiveModal === 'function') closeArchiveModal();
});