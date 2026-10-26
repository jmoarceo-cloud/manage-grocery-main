<?php
require_once __DIR__ . '/../includes/config.php';
requireHR();
$activeTab = 'employees';
$pageTitle = 'Employees · HRMS Payroll';
$pageScript = 'employees.js';
include __DIR__ . '/includes/header.php';
?>
<div class="panel active" id="panel-employees">
    <div class="card-box">
      <div class="emp-toolbar">
        <div class="emp-search-wrap">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <input type="text" class="emp-search" id="empSearch" placeholder="Search by name…" />
        </div>
        <select class="emp-filter-select" id="empFilterStatus">
          <option value="">All statuses</option>
          <option value="Active">Active</option>
          <option value="LOA">LOA</option>
          <option value="Inactive">Inactive</option>
        </select>
        <select class="emp-filter-select" id="empFilterDept">
          <option value="">All departments</option>
        </select>
        <select class="emp-sort-select" id="empSort">
          <option value="name-asc">Name (A–Z)</option>
          <option value="name-desc">Name (Z–A)</option>
          <option value="basic-desc">Basic Pay (High–Low)</option>
          <option value="basic-asc">Basic Pay (Low–High)</option>
          <option value="status">Status</option>
          <option value="dept">Department</option>
        </select>
        <button type="button" class="emp-clear-btn" id="empClearFilters">Clear</button>
      </div>
      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th>Employee</th>
              <th>Position</th>
              <th>Department</th>
              <th>Status</th>
              <th>Monthly Basic Pay</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="empRows"></tbody>
        </table>
        <div class="emp-no-results" id="empNoResults" style="display:none;">No employees match your search or filters.</div>
      </div>
      <div class="toolbar">
        <button class="add-btn" onclick="addEmployee()">+ Add employee</button>
        <div style="display:flex; align-items:center; gap:14px;">
          <span class="emp-count" id="empCount">0 active · 0 total</span>
        </div>
      </div>
    </div>
  </div>
<?php include __DIR__ . '/includes/footer.php'; ?>
