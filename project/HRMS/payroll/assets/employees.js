// ============================================================
// employees.js — Employees tab: table render, filters, add/edit/
// remove employee, and the LOA / Inactive status modals.
// Requires app.js to be loaded first.
// ============================================================

// ---------- EMPLOYEES ----------
let empSearchTerm = '';
let empFilterStatusVal = '';
let empFilterDeptVal = '';
let empSortBy = 'name-asc';

function initEmpFilterControls() {
  const deptSel = document.getElementById('empFilterDept');
  deptSel.innerHTML = '<option value="">All departments</option>' +
    DEPARTMENT_OPTIONS.map(d => `<option value="${d}">${d}</option>`).join('');

  document.getElementById('empSearch').addEventListener('input', (e) => {
    empSearchTerm = e.target.value;
    renderEmployees();
  });
  deptSel.addEventListener('change', (e) => {
    empFilterDeptVal = e.target.value;
    renderEmployees();
  });
  document.getElementById('empFilterStatus').addEventListener('change', (e) => {
    empFilterStatusVal = e.target.value;
    renderEmployees();
  });
  document.getElementById('empSort').addEventListener('change', (e) => {
    empSortBy = e.target.value;
    renderEmployees();
  });
  document.getElementById('empClearFilters').addEventListener('click', () => {
    empSearchTerm = '';
    empFilterStatusVal = '';
    empFilterDeptVal = '';
    empSortBy = 'name-asc';
    document.getElementById('empSearch').value = '';
    document.getElementById('empFilterStatus').value = '';
    document.getElementById('empFilterDept').value = '';
    document.getElementById('empSort').value = 'name-asc';
    renderEmployees();
  });
}

function getDisplayedEmployees() {
  const term = normalizeName(empSearchTerm);
  let list = state.employees.filter(emp => {
    if (term && !normalizeName(emp.name).includes(term)) return false;
    if (empFilterStatusVal && emp.status !== empFilterStatusVal) return false;
    if (empFilterDeptVal && emp.dept !== empFilterDeptVal) return false;
    return true;
  });

  const statusOrder = { Active: 0, LOA: 1, Inactive: 2 };
  list.sort((a, b) => {
    switch (empSortBy) {
      case 'name-desc': return (b.name || '').localeCompare(a.name || '');
      case 'basic-desc': return (b.basic || 0) - (a.basic || 0);
      case 'basic-asc': return (a.basic || 0) - (b.basic || 0);
      case 'status': return (statusOrder[a.status] ?? 9) - (statusOrder[b.status] ?? 9) || (a.name || '').localeCompare(b.name || '');
      case 'dept': return (a.dept || '').localeCompare(b.dept || '') || (a.name || '').localeCompare(b.name || '');
      case 'name-asc':
      default: return (a.name || '').localeCompare(b.name || '');
    }
  });
  return list;
}

function renderEmployees() {
  const tbody = document.getElementById('empRows');
  tbody.innerHTML = '';
  const displayed = getDisplayedEmployees();
  document.getElementById('empNoResults').style.display = displayed.length ? 'none' : 'block';
  displayed.forEach(emp => {
    const tr = document.createElement('tr');
    if (emp.status === 'Active') tr.classList.add('active-row');
    if (emp.status === 'Inactive') tr.classList.add('inactive-row');
    if (emp.status === 'LOA') tr.classList.add('loa-row');
    tr.dataset.empId = emp.id;
    const allowedPos = getPositionsForDept(emp.dept);
    tr.innerHTML = `
      <td class="text-cell">
        <div class="name-cell">
          <span class="emp-name-text">${escapeHtml(emp.name) || '(unnamed)'}</span>
          <button type="button" class="edit-name-btn" onclick="requestEditName(${emp.id})" title="Edit name">✎</button>
        </div>
      </td>
      <td class="text-cell"><select data-f="pos" ${allowedPos.length === 0 ? 'disabled' : ''}>${buildOptionsHTML(allowedPos, emp.pos)}</select></td>
      <td class="text-cell"><select data-f="dept">${buildOptionsHTML(DEPARTMENT_OPTIONS, emp.dept)}</select></td>
      <td class="text-cell">
        <div class="status-cell">
          <span class="status-dot ${emp.status}"></span>
          <select class="status-select ${emp.status}" data-f="status">
            <option value="Active" ${emp.status === 'Active' ? 'selected' : ''}>Active</option>
            <option value="LOA" ${emp.status === 'LOA' ? 'selected' : ''}>LOA</option>
            <option value="Inactive" ${emp.status === 'Inactive' ? 'selected' : ''}>Inactive</option>
          </select>
          ${(emp.status === 'LOA' || emp.status === 'Inactive') ? `<button type="button" class="info-btn ${emp.status}" title="View details" onclick="viewStatusDetails(${emp.id})">i</button>` : ''}
        </div>
      </td>
      <td><input type="number" value="${emp.basic}" data-f="basic" min="0" /></td>
      <td><button class="remove-btn" onclick="requestRemoveEmployee(${emp.id})" title="Remove employee">✕</button></td>
    `;
    tr.querySelectorAll('[data-f]').forEach(inp => {
      // Status is handled separately below: switching TO Inactive/LOA must
      // be gated behind their respective modals, so it's excluded from the
      // generic commit-on-input/change wiring used by the other fields.
      // Department is handled separately: changing it must re-filter the
      // position <select> in the same row to only the positions valid for
      // the new department, and reset the position if it's no longer valid.
      if (inp.dataset.f === 'dept') {
        inp.addEventListener('change', () => {
          emp.dept = inp.value;
          const posSel = tr.querySelector('[data-f="pos"]');
          const allowed = getPositionsForDept(emp.dept);
          if (posSel && !allowed.includes(emp.pos)) {
            emp.pos = allowed.length === 1 ? allowed[0] : '';
          }
          if (posSel) {
            posSel.innerHTML = buildOptionsHTML(allowed, emp.pos);
            posSel.disabled = allowed.length === 0;
          }
          updateEmpCount();
          saveState();
        });
        return;
      }

      if (inp.dataset.f === 'status') {
        inp.dataset.prevValue = emp.status;
        inp.addEventListener('change', () => {
          const prevVal = inp.dataset.prevValue !== undefined ? inp.dataset.prevValue : emp.status;
          const newVal = inp.value;
          if (newVal === prevVal) return;
          if (newVal === 'Inactive') {
            // Don't commit yet — wait for the modal to confirm the gate.
            requestInactiveStatus(emp.id, inp, prevVal);
            return;
          }
          if (newVal === 'LOA') {
            // Don't commit yet — wait for the reason modal to confirm.
            requestLoaStatus(emp.id, inp, prevVal);
            return;
          }
          // Active: allowed immediately, no gate needed.
          emp.status = newVal;
          delete emp.inactiveReason;
          delete emp.inactiveAbsentDays;
          delete emp.loaReason;
          delete emp.loaStart;
          delete emp.loaEnd;
          inp.dataset.prevValue = newVal;
          applyStatusToRow(inp, newVal);
          updateEmpCount();
          saveState();
        });
        return;
      }

      const commit = () => {
        const f = inp.dataset.f;
        let val = inp.value;
        if (f === 'basic') val = clampNonNegative(inp);
        emp[f] = val;
        updateEmpCount();
        saveState();
      };
      inp.addEventListener('input', commit);
      inp.addEventListener('change', () => {
        if (inp.dataset.f === 'basic') finalizeNumberField(inp);
        commit();
      });
    });
    tbody.appendChild(tr);
  });
  updateEmpCount();
}

function updateEmpCount() {
  const total = state.employees.length;
  const active = state.employees.filter(e => e.status === 'Active').length;
  const loa = state.employees.filter(e => e.status === 'LOA').length;
  const inactive = state.employees.filter(e => e.status === 'Inactive').length;
  const shown = getDisplayedEmployees().length;
  const filtersActive = empSearchTerm || empFilterStatusVal || empFilterDeptVal;
  document.getElementById('empCount').textContent = filtersActive
    ? `Showing ${shown} of ${total} · ${active} active · ${loa} on LOA · ${inactive} inactive`
    : `${active} active · ${loa} on LOA · ${inactive} inactive · ${total} total`;
}

const NEW_EMP_DEFAULT_ERROR = 'A name is required before the employee can be added.';

function addEmployee() {
  document.getElementById('newEmpName').value = '';
  document.getElementById('newEmpDept').innerHTML = buildOptionsHTML(DEPARTMENT_OPTIONS, '');
  const posSel = document.getElementById('newEmpPos');
  posSel.innerHTML = buildOptionsHTML(getPositionsForDept(''), '');
  posSel.disabled = true;
  const errEl = document.getElementById('newEmpError');
  errEl.textContent = NEW_EMP_DEFAULT_ERROR;
  errEl.style.display = 'none';
  document.getElementById('addEmpModal').classList.add('open');
  setTimeout(() => document.getElementById('newEmpName').focus(), 50);
}

function closeAddEmpModal() {
  document.getElementById('addEmpModal').classList.remove('open');
}

function confirmAddEmployee() {
  const nameInp = document.getElementById('newEmpName');
  const posInp = document.getElementById('newEmpPos');
  const deptInp = document.getElementById('newEmpDept');
  const name = nameInp.value.trim();
  const pos = posInp.value;
  const dept = deptInp.value;
  const errEl = document.getElementById('newEmpError');

  if (!name) {
    errEl.textContent = NEW_EMP_DEFAULT_ERROR;
    errEl.style.display = 'block';
    nameInp.focus();
    return;
  }

  if (/[0-9]/.test(name)) {
    errEl.textContent = 'Employee names can only contain letters, not numbers.';
    errEl.style.display = 'block';
    nameInp.focus();
    return;
  }

  if (isDuplicateName(name, null)) {
    errEl.textContent = `An employee named "${name}" already exists. Use a unique name (e.g. add a middle initial or suffix).`;
    errEl.style.display = 'block';
    nameInp.focus();
    return;
  }

  state.employees.push({
    id: empIdSeed++,
    name, pos, dept,
    status: "Active",
    basic: 0
  });
  closeAddEmpModal();
  renderEmployees();
  saveState();
}

document.getElementById('addEmpModal').addEventListener('click', (e) => {
  if (e.target.id === 'addEmpModal') closeAddEmpModal();
});
document.getElementById('newEmpDept').addEventListener('change', () => {
  const deptSel = document.getElementById('newEmpDept');
  const posSel = document.getElementById('newEmpPos');
  const allowed = getPositionsForDept(deptSel.value);
  const currentPos = posSel.value;
  posSel.innerHTML = buildOptionsHTML(allowed, allowed.includes(currentPos) ? currentPos : (allowed.length === 1 ? allowed[0] : ''));
  posSel.disabled = allowed.length === 0;
});
['newEmpName'].forEach(id => {
  document.getElementById(id).addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); confirmAddEmployee(); }
  });
  document.getElementById(id).addEventListener('input', (e) => {
    sanitizeNameField(e.target);
    document.getElementById('newEmpError').style.display = 'none';
  });
});

// ---------- EDIT EMPLOYEE NAME ----------
let pendingEditNameId = null;

function requestEditName(id) {
  const emp = state.employees.find(e => e.id === id);
  if (!emp) return;
  pendingEditNameId = id;
  const inp = document.getElementById('editNameInput');
  inp.value = emp.name || '';
  const errEl = document.getElementById('editNameError');
  errEl.style.display = 'none';
  document.getElementById('editNameModal').classList.add('open');
  setTimeout(() => { inp.focus(); inp.select(); }, 50);
}

function closeEditNameModal() {
  document.getElementById('editNameModal').classList.remove('open');
  pendingEditNameId = null;
}

function confirmEditName() {
  const inp = document.getElementById('editNameInput');
  const errEl = document.getElementById('editNameError');
  const name = inp.value.trim();
  const id = pendingEditNameId;

  if (!name) {
    errEl.textContent = 'A name is required.';
    errEl.style.display = 'block';
    inp.focus();
    return;
  }

  if (/[0-9]/.test(name)) {
    errEl.textContent = 'Employee names can only contain letters, not numbers.';
    errEl.style.display = 'block';
    inp.focus();
    return;
  }

  if (isDuplicateName(name, id)) {
    errEl.textContent = `An employee named "${name}" already exists. Use a unique name (e.g. add a middle initial or suffix).`;
    errEl.style.display = 'block';
    inp.focus();
    return;
  }

  const emp = state.employees.find(e => e.id === id);
  if (!emp) {
    closeEditNameModal();
    return;
  }
  emp.name = name;
  closeEditNameModal();
  renderEmployees();
  // Payroll page isn't loaded here — refresh it too if it happens to be open.
  if (typeof renderPayroll === 'function') renderPayroll();
  saveState();
}

document.getElementById('editNameModal').addEventListener('click', (e) => {
  if (e.target.id === 'editNameModal') closeEditNameModal();
});
document.getElementById('editNameInput').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); confirmEditName(); }
});
document.getElementById('editNameInput').addEventListener('input', (e) => {
  sanitizeNameField(e.target);
  document.getElementById('editNameError').style.display = 'none';
});

// ---------- SHARED: absent-days + reason validation ----------
// Used both when marking an employee Inactive and when removing one —
// both actions require at least MIN_ABSENT_DAYS_TO_REMOVE consecutive
// days absent, plus a non-empty reason.
function validateAbsentAndReason(daysInp, reasonInp, errEl) {
  const days = parseInt(daysInp.value, 10);

  if (daysInp.value === '' || isNaN(days) || days < 0) {
    errEl.textContent = 'Enter the number of consecutive days absent.';
    errEl.style.display = 'block';
    daysInp.focus();
    return null;
  }

  if (days < MIN_ABSENT_DAYS_TO_REMOVE) {
    errEl.textContent = `This employee has only ${days} consecutive day${days === 1 ? '' : 's'} absent. At least ${MIN_ABSENT_DAYS_TO_REMOVE} consecutive days absent are required.`;
    errEl.style.display = 'block';
    daysInp.focus();
    return null;
  }

  const reason = reasonInp.value.trim();
  if (!reason) {
    errEl.textContent = 'A reason is required.';
    errEl.style.display = 'block';
    reasonInp.focus();
    return null;
  }

  return { days, reason };
}

// ---------- REMOVE EMPLOYEE (requires 5+ consecutive days absent + reason) ----------
let pendingRemoveEmpId = null;

function requestRemoveEmployee(id) {
  const emp = state.employees.find(e => e.id === id);
  if (!emp) return;
  pendingRemoveEmpId = id;
  document.getElementById('removeEmpMsg').textContent =
    `This will permanently remove "${emp.name || '(unnamed)'}" along with their entries for the current payroll period. This cannot be undone. (Saved History records will not be affected.)`;
  document.getElementById('removeEmpAbsentDays').value = '';
  document.getElementById('removeEmpReason').value = '';
  const errEl = document.getElementById('removeEmpError');
  errEl.style.display = 'none';
  document.getElementById('removeEmpModal').classList.add('open');
  setTimeout(() => document.getElementById('removeEmpAbsentDays').focus(), 50);
}

function closeRemoveEmpModal() {
  document.getElementById('removeEmpModal').classList.remove('open');
  pendingRemoveEmpId = null;
}

function confirmRemoveEmployee() {
  const daysInp = document.getElementById('removeEmpAbsentDays');
  const reasonInp = document.getElementById('removeEmpReason');
  const errEl = document.getElementById('removeEmpError');

  const result = validateAbsentAndReason(daysInp, reasonInp, errEl);
  if (!result) return;

  const id = pendingRemoveEmpId;
  closeRemoveEmpModal();
  removeEmployee(id);
}

document.getElementById('removeEmpModal').addEventListener('click', (e) => {
  if (e.target.id === 'removeEmpModal') closeRemoveEmpModal();
});
document.getElementById('removeEmpAbsentDays').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); document.getElementById('removeEmpReason').focus(); }
});
document.getElementById('removeEmpAbsentDays').addEventListener('input', (e) => {
  clampNonNegativeInteger(e.target);
  document.getElementById('removeEmpError').style.display = 'none';
});
document.getElementById('removeEmpReason').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); confirmRemoveEmployee(); }
});
document.getElementById('removeEmpReason').addEventListener('input', () => {
  document.getElementById('removeEmpError').style.display = 'none';
});

function removeEmployee(id) {
  state.employees = state.employees.filter(e => e.id !== id);
  delete state.payrollRows[id];
  renderEmployees();
  saveState();
}

// ---------- MARK EMPLOYEE INACTIVE (requires 5+ consecutive days absent + reason) ----------
let pendingInactiveEmpId = null;
let pendingInactiveSelectEl = null;
let pendingInactivePrevValue = 'Active';

function requestInactiveStatus(id, selectEl, prevValue) {
  const emp = state.employees.find(e => e.id === id);
  if (!emp) return;
  pendingInactiveEmpId = id;
  pendingInactiveSelectEl = selectEl;
  pendingInactivePrevValue = prevValue;
  document.getElementById('inactiveEmpMsg').textContent =
    `This will set "${emp.name || '(unnamed)'}"'s status to Inactive.`;
  document.getElementById('inactiveEmpAbsentDays').value = '';
  document.getElementById('inactiveEmpReason').value = '';
  const errEl = document.getElementById('inactiveEmpError');
  errEl.style.display = 'none';
  document.getElementById('inactiveEmpModal').classList.add('open');
  setTimeout(() => document.getElementById('inactiveEmpAbsentDays').focus(), 50);
}

function closeInactiveEmpModal() {
  document.getElementById('inactiveEmpModal').classList.remove('open');
  // Cancelling should leave the dropdown showing the employee's actual status
  if (pendingInactiveSelectEl) pendingInactiveSelectEl.value = pendingInactivePrevValue;
  pendingInactiveEmpId = null;
  pendingInactiveSelectEl = null;
}

function confirmInactiveEmployee() {
  const daysInp = document.getElementById('inactiveEmpAbsentDays');
  const reasonInp = document.getElementById('inactiveEmpReason');
  const errEl = document.getElementById('inactiveEmpError');

  const result = validateAbsentAndReason(daysInp, reasonInp, errEl);
  if (!result) return;

  const id = pendingInactiveEmpId;
  const selectEl = pendingInactiveSelectEl;
  const emp = state.employees.find(e => e.id === id);
  document.getElementById('inactiveEmpModal').classList.remove('open');
  pendingInactiveEmpId = null;
  pendingInactiveSelectEl = null;

  if (!emp) return;
  emp.status = 'Inactive';
  emp.inactiveAbsentDays = result.days;
  emp.inactiveReason = result.reason;
  applyStatusToRow(selectEl, 'Inactive');
  updateEmpCount();
  saveState();
}

document.getElementById('inactiveEmpModal').addEventListener('click', (e) => {
  if (e.target.id === 'inactiveEmpModal') closeInactiveEmpModal();
});
document.getElementById('inactiveEmpAbsentDays').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); document.getElementById('inactiveEmpReason').focus(); }
});
document.getElementById('inactiveEmpAbsentDays').addEventListener('input', (e) => {
  clampNonNegativeInteger(e.target);
  document.getElementById('inactiveEmpError').style.display = 'none';
});
document.getElementById('inactiveEmpReason').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); confirmInactiveEmployee(); }
});
document.getElementById('inactiveEmpReason').addEventListener('input', () => {
  document.getElementById('inactiveEmpError').style.display = 'none';
});

// ---------- MARK EMPLOYEE LOA (requires a reason) ----------
let pendingLoaEmpId = null;
let pendingLoaSelectEl = null;
let pendingLoaPrevValue = 'Active';

function requestLoaStatus(id, selectEl, prevValue) {
  const emp = state.employees.find(e => e.id === id);
  if (!emp) return;
  pendingLoaEmpId = id;
  pendingLoaSelectEl = selectEl;
  pendingLoaPrevValue = prevValue;
  document.getElementById('loaEmpMsg').textContent =
    `This will set "${emp.name || '(unnamed)'}"'s status to LOA (Leave of Absence).`;
  document.getElementById('loaEmpStart').value = emp.loaStart || toISO(new Date());
  document.getElementById('loaEmpEnd').value = emp.loaEnd || '';
  document.getElementById('loaEmpReason').value = emp.loaReason || '';
  const errEl = document.getElementById('loaEmpError');
  errEl.style.display = 'none';
  document.getElementById('loaEmpModal').classList.add('open');
  setTimeout(() => document.getElementById('loaEmpStart').focus(), 50);
}

function closeLoaEmpModal() {
  document.getElementById('loaEmpModal').classList.remove('open');
  // Cancelling should leave the dropdown showing the employee's actual status
  if (pendingLoaSelectEl) pendingLoaSelectEl.value = pendingLoaPrevValue;
  pendingLoaEmpId = null;
  pendingLoaSelectEl = null;
}

function confirmLoaEmployee() {
  const startInp = document.getElementById('loaEmpStart');
  const endInp = document.getElementById('loaEmpEnd');
  const reasonInp = document.getElementById('loaEmpReason');
  const errEl = document.getElementById('loaEmpError');
  const start = startInp.value;
  const end = endInp.value;
  const reason = reasonInp.value.trim();

  if (!start) {
    errEl.textContent = 'A start date is required.';
    errEl.style.display = 'block';
    startInp.focus();
    return;
  }

  if (end && end < start) {
    errEl.textContent = 'End date cannot be earlier than the start date.';
    errEl.style.display = 'block';
    endInp.focus();
    return;
  }

  if (!reason) {
    errEl.textContent = 'A reason is required.';
    errEl.style.display = 'block';
    reasonInp.focus();
    return;
  }

  const id = pendingLoaEmpId;
  const selectEl = pendingLoaSelectEl;
  const emp = state.employees.find(e => e.id === id);
  document.getElementById('loaEmpModal').classList.remove('open');
  pendingLoaEmpId = null;
  pendingLoaSelectEl = null;

  if (!emp) return;
  emp.status = 'LOA';
  emp.loaStart = start;
  emp.loaEnd = end || '';
  emp.loaReason = reason;
  delete emp.inactiveReason;
  delete emp.inactiveAbsentDays;
  applyStatusToRow(selectEl, 'LOA');
  updateEmpCount();
  saveState();
}

document.getElementById('loaEmpModal').addEventListener('click', (e) => {
  if (e.target.id === 'loaEmpModal') closeLoaEmpModal();
});
document.getElementById('loaEmpStart').addEventListener('input', () => {
  document.getElementById('loaEmpError').style.display = 'none';
});
document.getElementById('loaEmpEnd').addEventListener('input', () => {
  document.getElementById('loaEmpError').style.display = 'none';
});
document.getElementById('loaEmpReason').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); confirmLoaEmployee(); }
});
document.getElementById('loaEmpReason').addEventListener('input', () => {
  document.getElementById('loaEmpError').style.display = 'none';
});

function applyStatusToRow(selectEl, newVal) {
  if (!selectEl) return;
  const tr = selectEl.closest('tr');
  selectEl.value = newVal;
  selectEl.dataset.prevValue = newVal;
  selectEl.className = `status-select ${newVal}`;
  if (tr) {
    tr.classList.toggle('active-row', newVal === 'Active');
    tr.classList.toggle('inactive-row', newVal === 'Inactive');
    tr.classList.toggle('loa-row', newVal === 'LOA');
    const dot = tr.querySelector('.status-dot');
    if (dot) dot.className = `status-dot ${newVal}`;

    // The info ("i") button only belongs on LOA/Inactive rows — add or
    // remove it here so it never lingers after switching back to Active.
    const statusCell = selectEl.closest('.status-cell');
    let infoBtn = statusCell ? statusCell.querySelector('.info-btn') : null;
    const empId = parseInt(tr.dataset.empId);
    if (newVal === 'LOA' || newVal === 'Inactive') {
      if (!infoBtn) {
        infoBtn = document.createElement('button');
        infoBtn.type = 'button';
        infoBtn.title = 'View details';
        infoBtn.textContent = 'i';
        infoBtn.onclick = () => viewStatusDetails(empId);
        statusCell.appendChild(infoBtn);
      }
      infoBtn.className = `info-btn ${newVal}`;
    } else if (infoBtn) {
      infoBtn.remove();
    }
  }
}

// ---------- VIEW STATUS DETAILS (LOA / Inactive) ----------
function getStatusSelectEl(empId) {
  return document.querySelector(`#empRows tr[data-emp-id="${empId}"] [data-f="status"]`);
}

function formatDateNice(iso) {
  if (!iso) return '';
  return fromISO(iso).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
}

function viewStatusDetails(empId) {
  const emp = state.employees.find(e => e.id === empId);
  if (!emp) return;
  const body = document.getElementById('statusDetailBody');
  const title = document.getElementById('statusDetailTitle');
  const editBtn = document.getElementById('statusDetailEditBtn');
  body.innerHTML = '';

  if (emp.status === 'LOA') {
    title.textContent = `${emp.name || '(unnamed)'} — LOA details`;
    title.style.color = 'var(--mustard)';
    const rangeText = emp.loaStart
      ? `${formatDateNice(emp.loaStart)} – ${emp.loaEnd ? formatDateNice(emp.loaEnd) : 'Ongoing'}`
      : 'Not recorded';
    body.innerHTML = `
      <div class="detail-row"><span class="dl-label">LOA period</span><span class="dl-value">${escapeHtml(rangeText)}</span></div>
      <div class="detail-row"><span class="dl-label">Reason</span><span class="dl-value">${escapeHtml(emp.loaReason || '—')}</span></div>
    `;
  } else if (emp.status === 'Inactive') {
    title.textContent = `${emp.name || '(unnamed)'} — Inactive details`;
    title.style.color = 'var(--red-alert)';
    body.innerHTML = `
      <div class="detail-row"><span class="dl-label">Consecutive days absent</span><span class="dl-value">${emp.inactiveAbsentDays !== undefined ? emp.inactiveAbsentDays : '—'}</span></div>
      <div class="detail-row"><span class="dl-label">Reason</span><span class="dl-value">${escapeHtml(emp.inactiveReason || '—')}</span></div>
    `;
  } else {
    return;
  }

  editBtn.dataset.empId = empId;
  editBtn.style.display = '';
  document.getElementById('statusDetailModal').classList.add('open');
}

function closeStatusDetailModal() {
  document.getElementById('statusDetailModal').classList.remove('open');
}

function editStatusDetails() {
  const empId = parseInt(document.getElementById('statusDetailEditBtn').dataset.empId);
  const emp = state.employees.find(e => e.id === empId);
  if (!emp) return;
  closeStatusDetailModal();
  const selectEl = getStatusSelectEl(empId);
  if (emp.status === 'LOA') {
    requestLoaStatus(empId, selectEl, 'LOA');
  } else if (emp.status === 'Inactive') {
    requestInactiveStatus(empId, selectEl, 'Inactive');
    // Pre-fill the existing absent days when editing (request function clears these by default).
    document.getElementById('inactiveEmpAbsentDays').value = emp.inactiveAbsentDays ?? '';
    document.getElementById('inactiveEmpReason').value = emp.inactiveReason ?? '';
  }
}

document.getElementById('statusDetailModal').addEventListener('click', (e) => {
  if (e.target.id === 'statusDetailModal') closeStatusDetailModal();
});


// ---------- PAGE INIT ----------
loadState();
initEmpFilterControls();
renderEmployees();
