// payslip_lookup.js — Requires app.js to be loaded first (uses
// window.SERVER_EMPLOYEES, already scoped by role/scope from
// includes/header.php, same as every other Payroll tab).
(function () {
  const empSelect    = document.getElementById('pslipEmployee');
  const periodSelect = document.getElementById('pslipPeriod');
  const openNewTab   = document.getElementById('pslipOpenNewTab');
  const emptyNote    = document.getElementById('pslipEmptyNote');
  const noRecords    = document.getElementById('pslipNoRecords');
  const frameWrap    = document.getElementById('pslipFrameWrap');
  const frame        = document.getElementById('pslipFrame');

  if (!empSelect) return; // not on this page

  // ---- Populate the Employee dropdown from the live roster ----
  const employees = Array.isArray(window.SERVER_EMPLOYEES) ? window.SERVER_EMPLOYEES.slice() : [];
  employees.sort(function (a, b) { return a.name.localeCompare(b.name); });
  employees.forEach(function (e) {
    const opt = document.createElement('option');
    opt.value = e.id;
    opt.textContent = e.name + (e.dept ? ' — ' + e.dept : '');
    empSelect.appendChild(opt);
  });

  function resetPeriodSelect(placeholder) {
    periodSelect.innerHTML = '';
    const opt = document.createElement('option');
    opt.value = '';
    opt.textContent = placeholder;
    periodSelect.appendChild(opt);
    periodSelect.disabled = true;
  }

  function showFrame(url) {
    frame.src = url;
    frameWrap.style.display = 'block';
    openNewTab.href = url;
    openNewTab.style.display = 'inline-block';
    emptyNote.style.display = 'none';
    noRecords.style.display = 'none';
  }

  function hideFrame() {
    frame.src = 'about:blank';
    frameWrap.style.display = 'none';
    openNewTab.style.display = 'none';
  }

  empSelect.addEventListener('change', function () {
    const employeeId = empSelect.value;
    hideFrame();
    resetPeriodSelect('Loading…');

    if (!employeeId) {
      resetPeriodSelect('Select an employee first…');
      emptyNote.style.display = 'block';
      noRecords.style.display = 'none';
      return;
    }

    fetch('get_employee_payslips.php?employee_id=' + encodeURIComponent(employeeId))
      .then(function (res) { return res.json(); })
      .then(function (records) {
        resetPeriodSelect(records.length ? 'Select a pay period…' : 'No payslips for this employee');
        periodSelect.disabled = records.length === 0;

        if (!records.length) {
          emptyNote.style.display = 'none';
          noRecords.style.display = 'block';
          return;
        }
        emptyNote.style.display = 'none';
        noRecords.style.display = 'none';

        records.forEach(function (r) {
          const opt = document.createElement('option');
          opt.value = r.id;
          let text = r.label + ' — Net ' + formatPeso(r.net);
          if (r.statusLabel) text += ' (' + r.statusLabel + ')';
          opt.textContent = text;
          periodSelect.appendChild(opt);
        });

        // Only one payslip for this employee — show it right away.
        if (records.length === 1) {
          periodSelect.value = records[0].id;
          showFrame('../payslip.php?id=' + encodeURIComponent(records[0].id));
        }
      })
      .catch(function () {
        resetPeriodSelect('Could not load payslips — try again');
      });
  });

  periodSelect.addEventListener('change', function () {
    const payrollId = periodSelect.value;
    if (!payrollId) { hideFrame(); emptyNote.style.display = 'block'; return; }
    showFrame('../payslip.php?id=' + encodeURIComponent(payrollId));
  });

  function formatPeso(n) {
    n = Number(n) || 0;
    return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
})();
