// ============================================================
// history.js — History tab: saved payroll records, record detail
// modal, delete-to-archive, and the Archive modal.
// Requires app.js to be loaded first.
// ============================================================

// ---------- HISTORY SEARCH / FILTER / SORT ----------
let histSearchTerm = '';
let histSortBy = 'date-desc';

function initHistFilterControls() {
  document.getElementById('histSearch').addEventListener('input', (e) => {
    histSearchTerm = e.target.value;
    renderHistory();
  });
  document.getElementById('histSort').addEventListener('change', (e) => {
    histSortBy = e.target.value;
    renderHistory();
  });
  document.getElementById('histClearFilters').addEventListener('click', () => {
    histSearchTerm = '';
    histSortBy = 'date-desc';
    document.getElementById('histSearch').value = '';
    document.getElementById('histSort').value = 'date-desc';
    renderHistory();
  });
}

function recordMatchesSearch(rec, term) {
  if (!term) return true;
  const normTerm = normalizeName(term);
  if (normalizeName(rec.periodLabel || '').includes(normTerm)) return true;
  return (rec.rows || []).some(r => normalizeName(r.name || '').includes(normTerm));
}

function getDisplayedHistory() {
  const term = histSearchTerm;
  let list = (state.history || []).filter(rec => recordMatchesSearch(rec, term));

  list.sort((a, b) => {
    switch (histSortBy) {
      case 'date-asc': return new Date(a.savedAt) - new Date(b.savedAt);
      case 'net-desc': return (b.totals?.net || 0) - (a.totals?.net || 0);
      case 'net-asc': return (a.totals?.net || 0) - (b.totals?.net || 0);
      case 'period-desc': return (b.periodStart || '').localeCompare(a.periodStart || '');
      case 'period-asc': return (a.periodStart || '').localeCompare(b.periodStart || '');
      case 'date-desc':
      default: return new Date(b.savedAt) - new Date(a.savedAt);
    }
  });
  return list;
}

function renderHistory() {
  const list = document.getElementById('historyList');
  const empty = document.getElementById('historyEmptyNote');
  const noResults = document.getElementById('histNoResults');
  const hist = state.history || [];
  const displayed = getDisplayedHistory();
  list.innerHTML = '';
  empty.style.display = hist.length ? 'none' : 'block';
  noResults.style.display = (hist.length && !displayed.length) ? 'block' : 'none';
  displayed.forEach(rec => {
    const div = document.createElement('div');
    div.className = 'history-card';
    const savedDate = new Date(rec.savedAt);
    const savedStr = savedDate.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    div.innerHTML = `
      <div class="hc-left">
        <div class="hc-period">${rec.periodLabel}</div>
        <div class="hc-meta">Saved ${savedStr} · ${rec.rows.length} employee${rec.rows.length === 1 ? '' : 's'}${rec.payoutDate ? ' · Payout: ' + formatDateDisplay(rec.payoutDate) : ''}</div>
      </div>
      <div class="hc-right">
        <div class="hc-net">₱${fmt(rec.totals.net)}</div>
        <button class="hc-view-btn" onclick="viewRecord('${rec.id}')">View</button>
      </div>
    `;
    list.appendChild(div);
  });
  const archiveCountEl = document.getElementById('archiveCount');
  if (archiveCountEl) archiveCountEl.textContent = (state.archivedHistory || []).length;
}

function viewRecord(id) {
  const rec = (state.history || []).find(r => r.id === id);
  if (!rec) return;
  document.getElementById('recordModalTitle').textContent = rec.periodLabel;
  const savedDate = new Date(rec.savedAt);
  const savedStr = savedDate.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
  document.getElementById('recordModalSummary').textContent =
    `Saved ${savedStr}${rec.payoutDate ? ' · Payout: ' + formatDateDisplay(rec.payoutDate) : ''} · Gross ₱${fmt(rec.totals.gross)} · Deductions ₱${fmt(rec.totals.ded)} · Net ₱${fmt(rec.totals.net)}`;
  const tbody = document.getElementById('recordDetailBody');
  tbody.innerHTML = '';
  rec.rows.forEach(r => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="text-cell">${escapeHtml(r.name)}</td>
      <td>${fmt(r.basicPay)}</td>
      <td>${fmt(r.otPay)}</td>
      <td>${fmt(r.allow)}</td>
      <td class="computed">${fmt(r.gross)}</td>
      <td>${fmt(r.totalDed)}</td>
      <td class="computed net">${fmt(r.net)}</td>
    `;
    tbody.appendChild(tr);
  });
  document.getElementById('recordModal').dataset.recordId = id;
  document.getElementById('recordModal').classList.add('open');
}

function closeRecordModal() {
  document.getElementById('recordModal').classList.remove('open');
}

document.getElementById('recordModal').addEventListener('click', (e) => {
  if (e.target.id === 'recordModal') closeRecordModal();
});

function requestDeleteRecord() {
  const id = document.getElementById('recordModal').dataset.recordId;
  if (!id) return;
  const rec = (state.history || []).find(r => r.id === id);
  closeRecordModal();
  openConfirm(
    'Delete this record?',
    `This will move the saved payroll record for ${rec ? rec.periodLabel : 'this period'} to the Archive. It will be removed from History, but you can restore it later from the Archive if needed.`,
    () => {
      const idx = (state.history || []).findIndex(r => r.id === id);
      if (idx === -1) return;
      const [removed] = state.history.splice(idx, 1);
      if (!Array.isArray(state.archivedHistory)) state.archivedHistory = [];
      removed.archivedAt = new Date().toISOString();
      state.archivedHistory.unshift(removed);
      saveState();
      renderHistory();
      // Payroll page isn't loaded here — refresh it too if it happens to be open.
      if (typeof renderPayroll === 'function') renderPayroll();
    },
    'confirm',
    'Delete & Archive'
  );
}

// ---------- ARCHIVE (soft-deleted history records) ----------
function openArchiveModal() {
  renderArchiveList();
  document.getElementById('archiveModal').classList.add('open');
}

function closeArchiveModal() {
  document.getElementById('archiveModal').classList.remove('open');
}

document.getElementById('archiveModal').addEventListener('click', (e) => {
  if (e.target.id === 'archiveModal') closeArchiveModal();
});

function renderArchiveList() {
  const list = document.getElementById('archiveList');
  const empty = document.getElementById('archiveEmptyNote');
  const archived = state.archivedHistory || [];
  list.innerHTML = '';
  empty.style.display = archived.length ? 'none' : 'block';
  archived.forEach(rec => {
    const archivedStr = rec.archivedAt
      ? new Date(rec.archivedAt).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
      : '—';
    const div = document.createElement('div');
    div.className = 'history-card';
    div.innerHTML = `
      <div class="hc-left">
        <div class="hc-period">${rec.periodLabel}</div>
        <div class="hc-meta">Archived ${archivedStr} · ${rec.rows.length} employee${rec.rows.length === 1 ? '' : 's'}</div>
      </div>
      <div class="hc-right">
        <div class="hc-net">₱${fmt(rec.totals.net)}</div>
        <button class="hc-view-btn" onclick="restoreArchivedRecord('${rec.id}')">Restore</button>
      </div>
    `;
    list.appendChild(div);
  });
}

function restoreArchivedRecord(id) {
  const idx = (state.archivedHistory || []).findIndex(r => r.id === id);
  if (idx === -1) return;
  const [rec] = state.archivedHistory.splice(idx, 1);

  const conflict = (state.history || []).find(
    r => r.periodStart === rec.periodStart && r.periodEnd === rec.periodEnd
  );
  if (conflict) {
    openConfirm(
      'Overwrite current record?',
      `${rec.periodLabel} already has a different saved record in History. Restoring this archived one will replace it. Continue?`,
      () => {
        state.history = state.history.filter(r => r.id !== conflict.id);
        delete rec.archivedAt;
        state.history.unshift(rec);
        saveState();
        renderHistory();
        renderArchiveList();
        if (typeof renderPayroll === 'function') renderPayroll();
      },
      'primary',
      'Overwrite'
    );
    // Put it back until confirmed, so cancelling leaves it in the archive.
    state.archivedHistory.splice(idx, 0, rec);
    return;
  }

  delete rec.archivedAt;
  state.history.unshift(rec);
  saveState();
  renderHistory();
  renderArchiveList();
  if (typeof renderPayroll === 'function') renderPayroll();
}


// ---------- PAGE INIT ----------
loadState();
mergeServerHistoryRecords();
applyHistoryReadOnlyMode();
initHistFilterControls();
renderHistory();

// Ang totoong pinagmumulan ng payroll history ay ang `payroll_records`
// table (window.SERVER_HISTORY_RECORDS, see history.php) — hindi lang
// yung nasa browser localStorage ng kasalukuyang user. Dito, isinasama
// natin ang mga DB record na wala pa sa local state.history (matched
// by period), para totoo at shared ang nakikita ng bawat role
// (lalo na ni HR Manager, na dapat nakikita niya ang na-save ni HR
// Staff kahit ibang browser/device pa ang ginamit nito).
function mergeServerHistoryRecords() {
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

  if (!serverRecords.length) { state.history.sort((a, b) => new Date(b.savedAt) - new Date(a.savedAt)); saveState(); return; }

  serverRecords.forEach(serverRec => {
    const idx = state.history.findIndex(r => r.periodStart === serverRec.periodStart && r.periodEnd === serverRec.periodEnd);
    if (idx === -1) {
      state.history.push(serverRec);
    } else {
      // Panatilihin ang DB bersyon bilang authoritative kung mismatch —
      // pero huwag galawin kung parehas lang naman.
      state.history[idx] = { ...state.history[idx], ...serverRec };
    }
  });
  state.history.sort((a, b) => new Date(b.savedAt) - new Date(a.savedAt));
  saveState();
}

// HR Manager: view-only sa Payroll History — walang Delete/Archive.
function applyHistoryReadOnlyMode() {
  if (!window.HISTORY_READ_ONLY) return;
  const archiveBtn = document.getElementById('histArchiveBtn');
  if (archiveBtn) archiveBtn.style.display = 'none';
  const deleteBtn = document.getElementById('recordModalDeleteBtn');
  if (deleteBtn) deleteBtn.style.display = 'none';
}