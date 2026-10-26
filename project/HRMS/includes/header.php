<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// $activeTab must be set by the including page before this file is
// included, e.g. $activeTab = 'payroll';
// $pageTitle is optional and shown in the browser tab title.
if (!isset($activeTab)) { $activeTab = ''; }
if (!isset($pageTitle)) { $pageTitle = 'HRMS · Payroll — HCI GroceryOne'; }

// Employees and Attendance now live only in the main HRMS (this module's
// own copies were removed to avoid duplicate/out-of-sync data). This
// module only handles Payroll Run, History, and 13th Month.
$navItems = [
    'payroll'    => ['label' => 'Payroll Run',  'href' => 'payroll.php'],
    'history'    => ['label' => 'History',      'href' => 'history.php'],
    'payslip'    => ['label' => 'Payslip View', 'href' => 'payslip_lookup.php'],
    'thirteenth' => ['label' => '13th Month',   'href' => 'thirteenth.php'],
];

// ---- Live employee list from the real HRMS database. Since this module
//      no longer has its own Employees tab, the HRMS `employees` table is
//      now the single source of truth — every page pulls the current
//      active roster straight from it instead of a locally-stored copy.
//      The list is also scoped based on role (Admin/HR Staff/
//      HR Manager) — see getPayrollScopedEmployees() in config.php. ----
$__scopeOverride = $_GET['scope'] ?? null;
// 13th Month should include ALL employees (Employees + HR Staff +
// HR Manager), not just the ones Admin pays directly. So on this
// tab, default the scope to 'all' for Admin unless there's an
// explicit ?scope= override in the URL.
if ($activeTab === 'thirteenth' && ($_SESSION['role'] ?? '') === 'admin' && $__scopeOverride === null) {
    $__scopeOverride = 'all';
}
$__payrollDbEmployees = getPayrollScopedEmployees($pdo, $__scopeOverride);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/style.css'); ?>">
</head>
<body>
<script>
  // Live roster from HRMS Employees (see header.php) — every payroll page
  // syncs its local state to this on load so names/departments/basic pay
  // always match the real HRMS records.
  window.SERVER_EMPLOYEES = <?php echo json_encode($__payrollDbEmployees); ?>;
  // CSRF token for AJAX save (save_record.php) — needed so the server
  // can verify the request came from a genuinely logged-in session.
  window.CSRF_TOKEN = <?php echo json_encode(generateCSRFToken()); ?>;
  // Role of the currently logged-in user — used by payroll.js to know
  // whether to show the "Submit for Approval" button (HR Staff/HR
  // Manager only; Admin's payroll is released immediately, no draft step).
  window.SESSION_ROLE = <?php echo json_encode($_SESSION['role'] ?? ''); ?>;
</script>
<?php $__showHrSidebar = in_array($_SESSION['role'] ?? '', ['hr', 'hr_manager', 'admin'], true); ?>
<?php if ($__showHrSidebar): ?>
<div class="app-shell">
<?php
  $activeNav    = 'payroll';
  $__navBase    = '../';
  $__payrollHref = 'payroll.php';
  include __DIR__ . '/../../includes/hr_sidebar.php';
?>
<div class="main-area">
<?php endif; ?>
<?php if (!$__showHrSidebar): ?>
<?php
$__payrollAvatar = $_SESSION['profile_picture'] ?? null;
if (
    !is_string($__payrollAvatar)
    || !preg_match('/^[A-Za-z0-9._-]+$/', $__payrollAvatar)
    || !is_file(__DIR__ . '/../uploads/avatars/' . $__payrollAvatar)
) {
    $__payrollAvatar = null;
}
$__payrollName = trim((string) ($_SESSION['full_name'] ?? ''));
$__payrollRole = roleLabel($_SESSION['role'] ?? '');
$__payrollTopbarConfig = [
    'name' => $__payrollName !== '' ? $__payrollName : 'User',
    'role' => $__payrollRole,
    'initial' => mb_strtoupper(mb_substr($__payrollName, 0, 1)) ?: '?',
    'avatarUrl' => $__payrollAvatar
        ? rtrim(appBaseUrl(), '/') . '/uploads/avatars/' . rawurlencode(basename($__payrollAvatar))
        : '',
    'showNotifications' => true,
    'notificationUnreadCount' => getHrmsNotificationBadgeCount($pdo, $posPdo, (string) ($_SESSION['role'] ?? ''), $_SESSION['employee_id'] ?? null),
    'notificationsUrl' => rtrim(appBaseUrl(), '/') . '/hr_notifications.php',
    'notificationsLabel' => 'HRMS notifications',
    'profileUrl' => rtrim(appBaseUrl(), '/') . '/my_profile.php',
    'logoutUrl' => logoutUrl(),
];
$__payrollTopbarCss = __DIR__ . '/../../assets/css/topbar.css';
$__payrollTopbarJs = __DIR__ . '/../../assets/js/shared-topbar.js';
$__payrollSystemBase = rtrim(dirname(rtrim(appBaseUrl(), '/')), '/\\');
?>
<link rel="stylesheet" href="<?= htmlspecialchars($__payrollSystemBase . '/assets/css/topbar.css?v=' . (filemtime($__payrollTopbarCss) ?: time()), ENT_QUOTES, 'UTF-8') ?>">
<script type="application/json" id="sharedTopbarConfig"><?= json_encode($__payrollTopbarConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
<script src="<?= htmlspecialchars($__payrollSystemBase . '/assets/js/shared-topbar.js?v=' . (filemtime($__payrollTopbarJs) ?: time()), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
  <div class="topbar">
    <h1>HRMS &amp; Payroll — Payroll</h1>
    <div class="user-info">
      <span class="user-avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?'); ?></span>
      <span><?php echo htmlspecialchars($_SESSION['full_name'] ?? ''); ?> (<?php echo htmlspecialchars(roleLabel($_SESSION['role'] ?? '')); ?>)</span>
    </div>
  </div>

<div class="wrap">

  <div class="tabs">
    <?php foreach ($navItems as $key => $item): ?>
      <a class="tab-btn<?php echo $activeTab === $key ? ' active' : ''; ?>"
         href="<?php echo $item['href']; ?>"><?php echo $item['label']; ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (isAdminRole()): ?>
  <div class="scope-switch" style="display:flex; align-items:center; gap:8px; padding:10px 4px; font-size:13px;">
    <span style="color:var(--ink-soft, #667);">Showing:</span>
    <?php
      $__scopes = [
        'hr'       => 'HR Staff & HR Manager',
        'employee' => 'Employees',
        'all'      => 'All',
      ];
      $__currentScope = in_array($__scopeOverride, ['employee','hr','all'], true) ? $__scopeOverride : 'hr';
      foreach ($__scopes as $__val => $__label):
        $__qs = $_GET;
        $__qs['scope'] = $__val;
        $__url = basename($_SERVER['PHP_SELF']) . '?' . http_build_query($__qs);
    ?>
      <a href="<?php echo htmlspecialchars($__url); ?>"
         class="tab-btn<?php echo $__currentScope === $__val ? ' active' : ''; ?>"
         style="padding:4px 10px; font-size:12.5px;"><?php echo htmlspecialchars($__label); ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (isHRManagerRole()): ?>
  <div class="scope-switch" style="display:flex; align-items:center; gap:8px; padding:10px 4px; font-size:13px; flex-wrap:wrap;">
    <span style="color:var(--ink-soft, #667);">Showing:</span>
    <?php
      $__hrmScopes = [
        'employee' => 'Employees (default)',
        'hr'       => 'Fellow HR Staff — requires Admin approval',
      ];
      $__hrmCurrentScope = in_array($__scopeOverride, ['employee', 'hr'], true) ? $__scopeOverride : 'employee';
      foreach ($__hrmScopes as $__val => $__label):
        $__qs = $_GET;
        $__qs['scope'] = $__val;
        $__url = basename($_SERVER['PHP_SELF']) . '?' . http_build_query($__qs);
    ?>
      <a href="<?php echo htmlspecialchars($__url); ?>"
         class="tab-btn<?php echo $__hrmCurrentScope === $__val ? ' active' : ''; ?>"
         style="padding:4px 10px; font-size:12.5px;"><?php echo htmlspecialchars($__label); ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($__hrmCurrentScope === 'hr' && $activeTab === 'payroll'): ?>
  <div style="background:var(--mustard-pale,#FBEFD8); color:var(--mustard-text2, #7A5A1E); border:1px solid var(--mustard,#E0A23B); border-radius:10px; padding:10px 14px; margin-bottom:14px; font-size:13px;">
    ⚠️ The payroll you save here for a fellow <strong>HR Staff</strong> member will not be released right away. It will first become
    <strong>Draft</strong> (auto-save only, not yet visible to Admin) — you'll still need to tick their row and click <strong>&ldquo;Submit Selected for Approval&rdquo;</strong> below once you've filled in the values. It will stay completely hidden (not visible in History or their Payslip) until Admin approves it.
  </div>
  <?php endif; ?>
  <?php endif; ?>