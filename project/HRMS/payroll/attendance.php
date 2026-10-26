<?php
require_once __DIR__ . '/../includes/config.php';
requireHR();
$activeTab = 'attendance';
$pageTitle = 'Attendance · HRMS Payroll';
$pageScript = 'attendance.js';
include __DIR__ . '/includes/header.php';
?>
<div class="panel active" id="panel-attendance">
    <div class="period-row">
      <div class="field">
        <label class="label">Date</label>
        <div style="display:flex; align-items:center; gap:8px;">
          <button type="button" class="year-nav-btn" id="attPrevBtn" title="Previous day">&#8249;</button>
          <input class="period-input" id="attDate" type="date" style="width:168px;" />
          <button type="button" class="year-nav-btn" id="attNextBtn" title="Next day">&#8250;</button>
        </div>
      </div>
      <div class="field">
        <label class="label">Day</label>
        <div class="period-display" id="attDateDisplay">—</div>
      </div>
      <div class="period-quick">
        <button type="button" id="attTodayBtn" onclick="setAttendanceToday()">Today</button>
      </div>
    </div>

    <div class="emp-toolbar">
      <div class="emp-search-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <input type="text" class="emp-search" id="attSearch" placeholder="Search by name…" />
      </div>
      <select class="emp-filter-select" id="attFilterStatus">
        <option value="">All statuses</option>
        <option value="Present">Present</option>
        <option value="Late">Late</option>
        <option value="Half Day">Half Day</option>
        <option value="On Leave">On Leave</option>
        <option value="Absent">Absent</option>
      </select>
      <select class="emp-filter-select" id="attFilterDept">
        <option value="">All departments</option>
      </select>
      <select class="emp-sort-select" id="attSort">
        <option value="name-asc">Name (A–Z)</option>
        <option value="name-desc">Name (Z–A)</option>
        <option value="status">Status</option>
        <option value="dept">Department</option>
      </select>
      <button type="button" class="emp-clear-btn" id="attClearFilters">Clear</button>
    </div>

    <div class="table-scroll">
      <table id="attTable">
        <thead>
          <tr>
            <th style="text-align:left;">Employee</th>
            <th style="text-align:left;">Department</th>
            <th>Status</th>
            <th>Time In</th>
            <th>Time Out</th>
            <th>Hours</th>
            <th style="text-align:left;">Notes</th>
          </tr>
        </thead>
        <tbody id="attRows"></tbody>
      </table>
      <div class="emp-no-results" id="attNoResults" style="display:none;">No employees match your search or filters.</div>
    </div>
    <div class="empty-note" id="attEmptyNote" style="display:none;">No employees yet — add one in the Employees tab.</div>

    <div class="toolbar">
      <div class="btn-group">
        <button class="add-btn" onclick="markAllPresent()">Mark all Present</button>
        <button class="add-btn" onclick="exportAttendanceCSV()">Export CSV</button>
      </div>
      <span class="emp-count" id="attCount">0 present · 0 total</span>
    </div>

    <div class="card-box" style="margin-top:22px;">
      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
        <h3 style="font-family:var(--font-display); font-size:16px; color:var(--text-accent); margin:0;">Monthly Summary — <span id="attMonthLabel"></span></h3>
      </div>
      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th style="text-align:left;">Employee</th>
              <th>Present</th>
              <th>Late</th>
              <th>Half Day</th>
              <th>On Leave</th>
              <th>Absent</th>
              <th class="computed">Total Hours</th>
            </tr>
          </thead>
          <tbody id="attSummaryRows"></tbody>
        </table>
      </div>
      <div class="empty-note" id="attSummaryEmptyNote" style="display:none; margin-top:12px;">No attendance recorded yet for this month.</div>
    </div>
  </div>
<?php include __DIR__ . '/includes/footer.php'; ?>
