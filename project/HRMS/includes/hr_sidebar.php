<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// Shared sidebar navigation for HR Staff / HR Manager / Admin pages.
// Keeps the module list identical to whatever hr_dashboard.php /
// hr_manager_dashboard.php / admin_dashboard.php show, so every page
// under this role can jump straight to any module instead of going
// back to the dashboard first.
//
// Sized and styled to match the POS "My Profile" sidebar (same
// 288px width, icon chips, and account footer) so the HRMS &
// Payroll module feels like the same product as the POS side.
//
// Usage: set $activeNav to one of
// 'dashboard' | 'employees' | 'attendance' | 'employee_schedule' | 'hr_notifications' | 'announcements' | 'application_form' | 'payroll' | 'hr_reports' | 'hr_letters' | 'purchase_orders' | 'my_profile'
// (or, for admin only: 'pos_dashboard' | 'pos_reports' | 'pos_products' |
// 'pos_shift_report' | 'pos_inventory' | 'pos_inventory_requests' |
// 'pos_inventory_reports' | 'pos_finance' | 'pos_notifications')
// then include this file. Meant for 'hr' (HR Staff), 'hr_manager',
// and 'admin' roles. 'hr_reports' only renders as a link for
// 'hr_manager' and 'admin'. Admin also gets a "POS Modules" section with
// direct links into every POS-side module, so they don't have to bounce
// back through the POS sidebar to reach them.

if (!isset($activeNav)) { $activeNav = ''; }
// Allows this shared sidebar to be included from a subfolder (e.g. the
// payroll module, one level deeper than the rest of HRMS) — hrefs below
// are prefixed with this so links still resolve correctly either way.
if (!isset($__navBase)) { $__navBase = ''; }
// POS modules live one level above the HRMS folder. $__navBase already
// accounts for how deep the current page is (root HRMS pages: '',
// payroll pages: '../'), so appending one more '../' lands on the POS
// project root from either location.
$__posBase = $__navBase . '../';
// From inside the payroll module itself, the Payroll link should point
// to the local payroll.php instead of back out to payroll/payroll.php.
if (!isset($__payrollHref)) { $__payrollHref = 'payroll/payroll.php'; }
$__role = $_SESSION['role'] ?? '';
$__fullName = $_SESSION['full_name'] ?? '';
$__uid = $_SESSION['user_id'] ?? 'guest';
$__roleLabel = function_exists('roleLabel') ? roleLabel($__role) : ucwords(str_replace('_', ' ', $__role));
$__initial = $__fullName !== '' ? strtoupper(substr($__fullName, 0, 1)) : '?';
$__avatarFile = $_SESSION['profile_picture'] ?? null;

if ($__role === 'admin') {
    $__dashboardHref = 'admin_dashboard.php';
    $__profileHref   = 'admin_profile.php';
} elseif ($__role === 'hr_manager') {
    $__dashboardHref = 'hr_manager_dashboard.php';
    $__profileHref   = 'my_profile.php';
} elseif ($__role === 'accountant') {
    $__dashboardHref = 'payroll/accountant.php';
    $__profileHref   = 'my_profile.php';
} else {
    $__dashboardHref = 'hr_dashboard.php';
    $__profileHref   = 'my_profile.php';
}
?>
<aside class="sidebar" id="hrmsSidebar">
    <?php
    // Public URLs are rewritten from /new ui/HRMS/* into /new ui/SYSTEM/HRMS/*.
    // Always use the canonical application URL so the browser does not
    // request the non-existent public /HRMS/assets directory.
    $__themeUrl = rtrim((string) appBaseUrl(), '/')
        . '/assets/hrms-theme.css?v='
        . (filemtime(__DIR__ . '/../assets/hrms-theme.css') ?: time());
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($__themeUrl, ENT_QUOTES, 'UTF-8') ?>">
    <script>
    (function () {
        // Apply saved collapse state ASAP (before the rest of the sidebar
        // and the main content render) so there's no flash/jump — same
        // behavior as the POS sidebar (includes/sidebar.php).
        var shell = document.querySelector('.app-shell');
        if (shell && localStorage.getItem('siaa_sidebar_collapsed') === '1') {
            shell.classList.add('sidebar-collapsed');
        }
    })();
    </script>

    <div class="sidebar-brand">
        <div class="brand-mark">
            <svg viewBox="0 0 48 48" class="brand-icon" aria-hidden="true">
                <path d="M10 18h28l-2.5 18.5a3 3 0 0 1-3 2.5h-17a3 3 0 0 1-3-2.5L10 18z" fill="#ffffff"/>
                <path d="M10 18h28v4a2 2 0 0 1-2 2H12a2 2 0 0 1-2-2v-4z" fill="#dbeafe"/>
                <path d="M16 18c0-5 3.5-9 8-9s8 4 8 9" fill="none" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
        </div>
        <div class="brand-text">
            <span class="brand-name">Manage <span>Grocery</span></span>
            <span class="brand-tagline">HRMS &amp; Payroll</span>
        </div>
        <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Collapse sidebar" title="Collapse sidebar">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M9 3v18" stroke="currentColor" stroke-width="1.5"/><path class="toggle-arrow-collapse" d="m15 9-3 3 3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path class="toggle-arrow-expand" d="m12 9 3 3-3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
    </div>

    <div class="nav-section-label">Menu</div>
    <nav class="sidebar-nav">
        <?php if ($__role !== 'accountant'): ?>
        <a href="<?= $__navBase . $__dashboardHref ?>" class="nav-link<?= $activeNav === 'dashboard' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M3 9.5L10 4l7 5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 8.5V16a1 1 0 0 0 1 1h3v-4h2v4h3a1 1 0 0 0 1-1V8.5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></span>
            <span>Dashboard</span>
        </a>
        <a href="<?= $__navBase ?>employees.php" class="nav-link<?= $activeNav === 'employees' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><circle cx="7" cy="7" r="2.4" stroke="currentColor" stroke-width="1.6"/><path d="M2.5 16c.7-3 2.5-4.5 4.5-4.5s3.8 1.5 4.5 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="14.2" cy="7.6" r="2" stroke="currentColor" stroke-width="1.5"/><path d="M12.9 11.7c1.6.3 2.9 1.6 3.6 3.9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
            <span>Employees</span>
        </a>
        <a href="<?= $__navBase ?>attendance.php" class="nav-link<?= $activeNav === 'attendance' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="12" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M3 8h14" stroke="currentColor" stroke-width="1.6"/><path d="M7 2.5v3M13 2.5v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M10 10.3v2l1.4 1.2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            <span>Attendance</span>
        </a>
        <a href="<?= $__navBase ?>employee_schedule.php" class="nav-link<?= $activeNav === 'employee_schedule' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="3" y="3.5" width="14" height="13" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M3 7.5h14" stroke="currentColor" stroke-width="1.6"/><path d="M6.5 2v3M13.5 2v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M6 10.5h2.5M6 13.3h2.5M10.7 10.5h3.3M10.7 13.3h3.3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg></span>
            <span>Employee Schedule</span>
        </a>
        <a href="<?= $__navBase ?>late_timein_requests.php" class="nav-link<?= $activeNav === 'late_timein' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="12" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M3 8h14" stroke="currentColor" stroke-width="1.6"/><path d="M10 10.3v2l1.4 1.2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            <span>Time In Requests</span>
        </a>
        <a href="<?= $__navBase ?>hr_notifications.php" class="nav-link<?= $activeNav === 'hr_notifications' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M6 8a4 4 0 018 0c0 3.2 1 4.5 1.5 5H4.5C5 12.5 6 11.2 6 8z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M8.2 15.5a1.8 1.8 0 003.6 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
            <span>Notifications</span>
        </a>
        <a href="<?= $__navBase ?>password_reset_requests.php" class="nav-link<?= $activeNav === 'password_reset_requests' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="3" y="8" width="14" height="9" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M6 8V6a4 4 0 018 0v2M7 12h6M10 12v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
            <span>Password Requests</span>
        </a>
        <a href="<?= $__navBase ?>announcements.php" class="nav-link<?= $activeNav === 'announcements' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M3 6.5l8-3 8 3v2l-8 3-8-3v-2z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M5.5 10v4c0 1.4 2 2.5 4.5 2.5s4.5-1.1 4.5-2.5v-4" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg></span>
            <span>Announcements</span>
            <?php if (in_array($__role, ['admin', 'hr_manager'], true) && function_exists('getPendingAnnouncementsForApproval')):
                $__pendingAnnCount = count(getPendingAnnouncementsForApproval($pdo));
                if ($__pendingAnnCount > 0): ?>
                    <span class="badge" style="background:var(--mustard); color:#FFFFFF; margin-left:auto;"><?= $__pendingAnnCount ?></span>
                <?php endif; endif; ?>
        </a>
        <?php if (in_array($__role, ['hr', 'hr_manager', 'admin'], true)): ?>
        <a href="<?= $__navBase ?>applicant_review_queue.php" class="nav-link<?= $activeNav === 'applicant_review' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="4" y="2.5" width="12" height="15" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M7 7h6M7 10h6M7 13h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            <span>Applicant Review</span>
        </a>
        <?php endif; ?>
        <?php if ($__role === 'admin'): ?>
        <a href="<?= $__navBase ?>privacy_requests.php" class="nav-link<?= $activeNav === 'privacy_requests' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M10 2.5 16 5v4.3c0 3.7-2.5 6.7-6 8.2-3.5-1.5-6-4.5-6-8.2V5l6-2.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M7 10h6M10 7v6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            <span>Privacy Requests</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>
        <?php if (in_array($__role, ['hr', 'hr_manager', 'admin', 'accountant'], true)): ?>
        <a href="<?= $__payrollHref ?>" class="nav-link<?= $activeNav === 'payroll' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.6"/><path d="M7.3 6.8h3.4a2 2 0 0 1 0 4H7.3m0 0V14m0-3.2H12M7.3 8.4H12" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg></span>
            <span>Payroll</span>
        </a>
        <?php endif; ?>
        <?php if ($__role !== 'accountant'): ?>
        <?php if ($__role === 'hr_manager' || $__role === 'admin'): // HR Reports is HR Manager + Admin only ?>
        <a href="<?= $__navBase ?>hr_reports.php" class="nav-link<?= $activeNav === 'hr_reports' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M5 3h7l3 3v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M6.5 8.5h7M6.5 11.5h7M6.5 14.5h4.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            <span>HR Reports</span>
        </a>
        <?php endif; ?>
        <a href="<?= $__navBase ?>hr_letters.php" class="nav-link<?= $activeNav === 'hr_letters' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="3" y="4.5" width="14" height="11" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="m4 6 6 4.5L16 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <span>Contracts &amp; Letters</span>
        </a>
        <?php if (in_array($__role, ['hr', 'hr_manager'], true)): ?>
        <a href="<?= $__navBase ?>purchase_orders.php" class="nav-link<?= $activeNav === 'purchase_orders' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M5 3.5h7l3 3V16a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M12 3.8V7h3M7 10h6M7 13h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
            <span>Purchase Orders</span>
        </a>
        <?php endif; ?>
        <?php if ($__role === 'admin'): // Supervisor Teams + direct Supervisor Tools access are Admin only ?>
        <a href="<?= $__navBase ?>manage_supervisor_teams.php" class="nav-link<?= $activeNav === 'supervisor_teams' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><circle cx="6" cy="6" r="2.2" stroke="currentColor" stroke-width="1.6"/><circle cx="14" cy="6" r="2.2" stroke="currentColor" stroke-width="1.6"/><path d="M2.3 15c.6-2.6 2.1-4 3.7-4s3.1 1.4 3.7 4M10.3 15c.6-2.6 2.1-4 3.7-4s3.1 1.4 3.7 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
            <span>Supervisor Teams</span>
        </a>
        <a href="<?= $__navBase ?>supervisor_dashboard.php" class="nav-link<?= $activeNav === 'supervisor_tools' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M3 9.5L10 4l7 5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 8.5V16a1 1 0 0 0 1 1h3v-4h2v4h3a1 1 0 0 0 1-1V8.5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></span>
            <span>Supervisor Tools</span>
        </a>
        <?php endif; ?>
        <?php if ($__role === 'admin'): // Single entry point into the POS side; POS Dashboard's own sidebar (includes/sidebar.php) already lists Sales Report, Products, Shift Report, Inventory, Inventory Requests, Inventory Reports, Finance, and Notifications, so we don't duplicate those links here ?>
        <div class="nav-section-label">POS Modules</div>
        <a href="<?= $__posBase ?>dashboard.php" class="nav-link<?= in_array($activeNav, ['pos_dashboard', 'pos_reports', 'pos_products', 'pos_shift_report', 'pos_inventory', 'pos_inventory_requests', 'pos_inventory_reports', 'pos_finance', 'pos_notifications'], true) ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="2.5" y="2.5" width="6.5" height="6.5" rx="1.3" stroke="currentColor" stroke-width="1.6"/><rect x="11" y="2.5" width="6.5" height="6.5" rx="1.3" stroke="currentColor" stroke-width="1.6"/><rect x="2.5" y="11" width="6.5" height="6.5" rx="1.3" stroke="currentColor" stroke-width="1.6"/><rect x="11" y="11" width="6.5" height="6.5" rx="1.3" stroke="currentColor" stroke-width="1.6"/></svg></span>
            <span>POS Dashboard</span>
        </a>
        <a href="<?= $__posBase ?>ai_settings.php" class="nav-link">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M10 2.8l1.7 4.5 4.5 1.7-4.5 1.7-1.7 4.5-1.7-4.5-4.5-1.7 4.5-1.7L10 2.8z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M15.8 13.5l.7 1.8 1.7.7-1.7.7-.7 1.8-.7-1.8-1.7-.7 1.7-.7.7-1.8z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg></span>
            <span>AI Settings</span>
        </a>
        <div class="nav-section-label">HRMS &amp; Payroll</div>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($__role !== 'accountant'): ?>
        <a href="<?= $__navBase . $__profileHref ?>" class="nav-link<?= $activeNav === 'my_profile' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="7" r="3.2" stroke="currentColor" stroke-width="1.6"/><path d="M3.5 17c1-3.5 4-5.2 6.5-5.2s5.5 1.7 6.5 5.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span>
            <span>My Profile</span>
        </a>
        <?php endif; ?>
        <a href="<?= function_exists('logoutUrl') ? logoutUrl() : 'HRMS/logout.php' ?>" class="nav-link nav-link--logout" data-confirm-logout="true" data-confirm="Are you sure you want to logout?" data-confirm-title="Log out?" data-confirm-ok="Yes, logout" data-confirm-color="#123C5C">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M7 17H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h3M13 14l4-4-4-4M17 10H7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <span>Logout</span>
        </a>
    </nav>

</aside>

<?php
$__sharedAvatar = $__avatarFile;
if (
    !is_string($__sharedAvatar)
    || !preg_match('/^[A-Za-z0-9._-]+$/', $__sharedAvatar)
    || !is_file(__DIR__ . '/../uploads/avatars/' . $__sharedAvatar)
) {
    $__sharedAvatar = null;
}
$__sharedTopbarConfig = [
    'name' => $__fullName !== '' ? $__fullName : 'User',
    'role' => $__roleLabel,
    'initial' => $__initial ?: '?',
    'avatarUrl' => $__sharedAvatar
        ? rtrim(appBaseUrl(), '/') . '/uploads/avatars/' . rawurlencode(basename($__sharedAvatar))
        : '',
    'notificationUnreadCount' => getHrmsNotificationBadgeCount($pdo, $posPdo, $__role, $_SESSION['employee_id'] ?? null),
    'notificationsLabel' => 'HRMS notifications',
    'notificationsUrl' => rtrim(appBaseUrl(), '/') . '/hr_notifications.php',
    'profileUrl' => rtrim(appBaseUrl(), '/') . '/' . ltrim($__profileHref, '/'),
    'logoutUrl' => logoutUrl(),
    'themeStorageKey' => 'siaa_theme_' . (string) $__uid,
];
$__sharedTopbarCssPath = __DIR__ . '/../../assets/css/topbar.css';
$__sharedTopbarJsPath = __DIR__ . '/../../assets/js/shared-topbar.js';
$__sharedScrollJsPath = __DIR__ . '/../../assets/js/scroll-preservation.js';
$__sharedSystemBase = rtrim(dirname(rtrim(appBaseUrl(), '/')), '/\\');
?>
<link rel="stylesheet" href="<?= htmlspecialchars($__sharedSystemBase . '/assets/css/topbar.css?v=' . (filemtime($__sharedTopbarCssPath) ?: time()), ENT_QUOTES, 'UTF-8') ?>">
<script type="application/json" id="sharedTopbarConfig"><?= json_encode($__sharedTopbarConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
<script src="<?= htmlspecialchars($__sharedSystemBase . '/assets/js/shared-topbar.js?v=' . (filemtime($__sharedTopbarJsPath) ?: time()), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<script src="<?= htmlspecialchars($__sharedSystemBase . '/assets/js/scroll-preservation.js?v=' . (filemtime($__sharedScrollJsPath) ?: time()), ENT_QUOTES, 'UTF-8') ?>" defer></script>

<style>
.btn.btn-danger {
    background: #dc2626 !important;
    color: #fff !important;
    border-color: #b91c1c !important;
    box-shadow: 0 2px 8px rgba(220, 38, 38, .2) !important;
}
.btn.btn-danger:hover {
    background: #b91c1c !important;
    color: #fff !important;
}
/* Keep the shared rail accent aligned with the app's blue brand palette. */
.sidebar {
    --green-deep: #2F80ED;
    --green-mid:  #4C8DFF;
    --green-leaf: #7AB2FF;
    --green-pale: #0E2A52;
    background:
        radial-gradient(420px 260px at 100% 0%, rgba(76,141,255,.14), transparent 60%),
        var(--green-deep);
}
.sidebar-foot .foot-avatar { background: linear-gradient(150deg, var(--green-leaf), #7c6cff); }

/* Hide the native scrollbar on the sidebar's own scroll — it still
   scrolls fine (overflow-y: auto is untouched), we're just not
   showing the browser's default track/arrows for it anymore. Also
   what was crowding/covering the toggle pill below, since the native
   scrollbar sits right on that same edge. */
.sidebar {
    scrollbar-width: none;       /* Firefox */
    -ms-overflow-style: none;    /* old Edge/IE */
}
.sidebar::-webkit-scrollbar { display: none; }  /* Chrome/Safari/new Edge */

/* Dark mode palette override. Wins over each page's own :root because
   html[data-theme="dark"] (0,1,1) has higher specificity than :root
   (0,1,0) — applies regardless of where this <style> sits in the DOM. */
html[data-theme="dark"] {
    --cream:         #121417;
    --white:         #1C1F24;
    --ink:           #ECEDEE;
    --ink-soft:      #A6ACB5;
    --line:          #2A2E35;
    --green-deep:    #2F80ED;
    --green-mid:     #4C8DFF;
    --green-leaf:    #7AB2FF;
    --green-pale:    #0E2A52;
    --mustard:       #E8A33D;
    --mustard-pale:  #33260E;
    --red-alert:     #F16565;
    --red-pale:      #33191A;
    --text-accent:   var(--green-leaf);
    --mustard-text:  var(--mustard);
    --purple-bg:     #2a2249;
    --purple-text:   #b7a9f5;
    --blue-bg:       #182449;
    --blue-text:     #8fa4ff;
    --green-succ-bg:   var(--green-pale);
    --green-succ-text: var(--green-leaf);
    --salmon-bg:     var(--red-pale);
    --mustard-text2: var(--mustard);
    --gray-bg:       var(--cream);
    --gray-text:     var(--ink-soft);
}
/* Safety net: table values (dates, amounts, plain cell text) must
   always read against the dark card background — force it here in
   case any page's own table/td rule doesn't correctly inherit --ink. */
html[data-theme="dark"] table td,
html[data-theme="dark"] .card td { color: var(--ink); }
html[data-theme="dark"] .sidebar {
    background:
        radial-gradient(420px 260px at 100% 0%, rgba(76,141,255,.14), transparent 60%),
        linear-gradient(180deg,var(--white),var(--cream));
}
html, body { transition: background-color .35s cubic-bezier(.16,1,.3,1), color .35s cubic-bezier(.16,1,.3,1); }
.sidebar, .card, .topbar, input, select, textarea, .content, table {
    transition: background-color .35s cubic-bezier(.16,1,.3,1), color .35s cubic-bezier(.16,1,.3,1), border-color .35s cubic-bezier(.16,1,.3,1);
}

/* ── Sidebar collapse ("cover") — same spacing behavior as the POS
   sidebar: shrinks to an icon-only rail and gives the module content
   extra breathing room once tucked away. ── */
.sidebar { transition: width .42s cubic-bezier(.16,1,.3,1), padding .42s cubic-bezier(.16,1,.3,1), box-shadow .35s cubic-bezier(.16,1,.3,1); }
.main-area { transition: margin-left .42s cubic-bezier(.16,1,.3,1), width .42s cubic-bezier(.16,1,.3,1), background-color .35s cubic-bezier(.16,1,.3,1); }

.sidebar-toggle {
    /* Fixed instead of absolute, and positioned via left (not the old
       right: -13px), so it tracks the sidebar's actual edge directly
       instead of relying on an ancestor's box — and a much higher
       z-index so nothing (the old native scrollbar included) ever
       renders on top of it again. */
    position: fixed;
    top: 34px;
    left: 275px;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    border: 1px solid var(--line);
    background: var(--white);
    color: var(--text-accent);
    box-shadow: 0 1px 2px rgba(18,60,92,0.06), 0 6px 16px rgba(18,60,92,0.06);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 20;
    padding: 0;
    transition: left .42s cubic-bezier(.16,1,.3,1), background .28s cubic-bezier(.16,1,.3,1), transform .28s cubic-bezier(.16,1,.3,1), box-shadow .28s cubic-bezier(.16,1,.3,1);
}
.main-area { margin-left: 248px; }
.sidebar-toggle { left: 235px !important; }
.app-shell.sidebar-collapsed .main-area { margin-left: 84px; }
.app-shell.sidebar-collapsed .sidebar-toggle { left: 71px !important; }
.sidebar-toggle:hover { background: var(--green-pale); }
.sidebar-toggle svg { width: 13px; height: 13px; transition: transform 0.22s ease; }
.app-shell.sidebar-collapsed .sidebar-toggle svg { transform: rotate(180deg); }
.app-shell.sidebar-collapsed .sidebar-toggle { left: 71px; }

.app-shell.sidebar-collapsed .sidebar { width: 84px; padding-left: 14px; padding-right: 14px; }
.app-shell.sidebar-collapsed .brand-text,
.app-shell.sidebar-collapsed .nav-link span:not(.nav-icon-box),
.app-shell.sidebar-collapsed .nav-section-label,
.app-shell.sidebar-collapsed .sidebar-foot .foot-text,
.app-shell.sidebar-collapsed .tt-label {
    display: none;
}
.app-shell.sidebar-collapsed .sidebar-brand { justify-content: center; padding-left: 0; padding-right: 0; }
.app-shell.sidebar-collapsed .nav-link { justify-content: center; gap: 0; }
.app-shell.sidebar-collapsed .sidebar-foot { justify-content: center; padding: 10px; }
.app-shell.sidebar-collapsed .theme-toggle-btn { justify-content: center; }
.app-shell.sidebar-collapsed .main-area { margin-left: 84px; }

/* Keep the signed-in identity in the normal sidebar flow. It must not
   follow the document while the page content is scrolled. */
.sidebar .sidebar-foot {
    position: static;
    margin-top: auto;
    background: #f8fafc;
    box-shadow: none;
}
.sidebar .sidebar-foot .foot-avatar {
    width: 40px;
    height: 40px;
    overflow: hidden;
    border-radius: 50%;
}
.sidebar .sidebar-foot .foot-avatar img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}
html[data-theme="dark"] .sidebar .sidebar-foot {
    background: #0d1013;
    box-shadow: none;
}

/* Extra spacing for module content once the sidebar is tucked away —
   mirrors POS's style.css so the modules feel like the same product. */
.app-shell.sidebar-collapsed .topbar,
.app-shell.sidebar-collapsed .pos-topbar,
.app-shell.sidebar-collapsed .container,
.app-shell.sidebar-collapsed .content,
.app-shell.sidebar-collapsed .wrap {
    padding-left: 48px;
    padding-right: 48px;
}

.theme-toggle-btn {
    display: flex; align-items: center; gap: 8px; width: 100%;
    padding: 9px 12px; margin-top: 8px;
    border: 1px solid rgba(255,255,255,.12); border-radius: 10px;
    background: rgba(255,255,255,.05); color: #FFFFFF;
    font-family: var(--font-body); font-size: 12.5px; font-weight: 600; cursor: pointer;
}
.theme-toggle-btn:hover { background: rgba(255,255,255,.1); }
.theme-toggle-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
.theme-toggle-btn .tt-icon-dark { display: none; }
html[data-theme="dark"] .theme-toggle-btn .tt-icon-light { display: none; }
html[data-theme="dark"] .theme-toggle-btn .tt-icon-dark { display: block; }

/* White catalog-inspired sidebar shared by Admin, HR, and Payroll roles. */
.sidebar {
    background: #ffffff;
    color: #1e293b;
    border-right: 1px solid #e2e8f0;
    box-shadow: 8px 0 26px rgba(15,23,42,.06);
}
.sidebar-brand { border-bottom-color: #e2e8f0; }
.sidebar .brand-mark { box-shadow: 0 8px 18px rgba(37,99,235,.2); }
.sidebar .brand-mark { transform-origin: center; transition: transform .24s cubic-bezier(.2,.8,.2,1), box-shadow .24s ease, filter .24s ease; }
.sidebar-brand:hover .brand-mark { transform: translateY(-3px) rotate(-3deg) scale(1.05); filter: saturate(1.08); box-shadow: 0 14px 28px rgba(37,99,235,.3); }
.sidebar-brand:hover .brand-mark .brand-icon { transform: rotate(4deg) scale(1.04); }
.sidebar .brand-name { color: #0f172a; }
.sidebar .brand-name span { color: #2563eb; }
.sidebar .brand-tagline, .sidebar .nav-section-label { color: #64748b; }
.sidebar .nav-link { color: #334155; }
.sidebar .nav-icon-box { color: #2563eb; background: #eff6ff; border: 1px solid #dbeafe; }
.sidebar .nav-link:hover { color: #1d4ed8; background: #f8fbff; border-color: #dbeafe; transform: translateX(3px); }
.sidebar .nav-link:hover .nav-icon-box { color: #1d4ed8; background: #dbeafe; }
.sidebar .nav-link.is-active { color: #ffffff !important; background-color: #2563eb !important; background-image: linear-gradient(100deg,#2563eb,#38bdf8) !important; border-color: #60a5fa !important; box-shadow: 0 10px 22px rgba(37,99,235,.22); }
.sidebar .nav-link.is-active .nav-icon-box { color: #1d4ed8; background: rgba(255,255,255,.82); border-color: rgba(255,255,255,.55); }
.sidebar .nav-link--logout { color: #64748b; border-top-color: #e2e8f0; }
.sidebar .nav-link--logout:hover { color: #b42318; background: #fff1f2; }
.sidebar .sidebar-foot { background: #f8fafc; border: 1px solid #e2e8f0; }
.sidebar .sidebar-foot .foot-name { color: #0f172a; }
.sidebar .sidebar-foot .foot-role { color: #64748b; }
.sidebar .theme-toggle-btn { color: #334155; background: #ffffff; border-color: #dbe3ef; }
.sidebar .theme-toggle-btn:hover { color: #1d4ed8; background: #eff6ff; border-color: #bfdbfe; }
html[data-theme="dark"] .sidebar { background: linear-gradient(180deg,var(--white),var(--cream)); color: var(--ink); border-right-color: var(--line); }
html[data-theme="dark"] .sidebar .brand-name { color: var(--ink); }
html[data-theme="dark"] .sidebar .brand-name span { color: var(--green-leaf); }
html[data-theme="dark"] .sidebar .nav-link { color: var(--ink-soft); }
html[data-theme="dark"] .sidebar .nav-section-label { color: var(--ink-soft); }
html[data-theme="dark"] .sidebar .nav-icon-box { color: var(--green-leaf); background: var(--green-pale); border-color: var(--line); }
html[data-theme="dark"] .sidebar .sidebar-foot { background: var(--white); border-color: var(--line); }

/* Admin navigation keeps the shared blue brand with restrained motion. */
.sidebar.sidebar-admin {
    --admin-ink: #e8efff;
    --admin-muted: #91a0bd;
    --admin-blue: #4b7dff;
    --admin-cyan: #55d6ff;
    width: 248px;
    padding: 24px 16px 18px;
    background:
        radial-gradient(360px 260px at 80% -5%, rgba(75,125,255,.28), transparent 62%),
        linear-gradient(180deg, #0b1530 0%, #080f24 58%, #070c1b 100%);
    border-right: 1px solid rgba(126,155,255,.16);
    box-shadow: 10px 0 30px rgba(8,15,36,.12);
}
.sidebar.sidebar-admin .sidebar-brand {
    gap: 12px;
    padding: 0 8px 22px;
    margin-bottom: 20px;
    border-bottom-color: rgba(157,178,235,.16);
}
.sidebar.sidebar-admin .brand-mark {
    width: 44px;
    height: 44px;
    border-radius: 14px;
    background: linear-gradient(145deg, #2b63ea, #1645bd);
    border: 1px solid rgba(128,190,255,.45);
    box-shadow: 0 10px 24px rgba(39,102,235,.28);
    transition: transform .2s ease, box-shadow .2s ease;
}
.sidebar.sidebar-admin .brand-mark .brand-icon { width: 26px; height: 26px; }
.sidebar.sidebar-admin .sidebar-brand:hover .brand-mark {
    transform: translateY(-2px) rotate(-2deg);
    box-shadow: 0 14px 28px rgba(39,102,235,.4);
}
.sidebar.sidebar-admin .brand-name { color: var(--admin-ink); font-size: 16px; }
.sidebar.sidebar-admin .brand-name span { color: var(--admin-cyan); }
.sidebar.sidebar-admin .brand-tagline { color: #83a9ff; font-size: 10px; }
.sidebar.sidebar-admin .nav-section-label {
    color: #7383a7;
    padding: 0 12px;
    margin: 6px 0 8px;
    letter-spacing: .12em;
}
.sidebar.sidebar-admin .sidebar-nav { gap: 4px; }
.sidebar.sidebar-admin .nav-link {
    position: relative;
    gap: 12px;
    min-height: 44px;
    padding: 8px 11px;
    border: 1px solid transparent;
    border-radius: 13px;
    color: var(--admin-muted);
    font-size: 13.5px;
    transition: transform .2s ease, background .2s ease, color .2s ease, border-color .2s ease, box-shadow .2s ease;
}
.sidebar.sidebar-admin .nav-link::after {
    content: "";
    position: absolute;
    right: 10px;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: transparent;
    transition: background .2s ease, box-shadow .2s ease;
}
.sidebar.sidebar-admin .nav-icon-box {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    color: #91a9e7;
    background: rgba(125,151,220,.1);
    border: 1px solid rgba(151,174,242,.1);
    transition: background .2s ease, color .2s ease, transform .2s ease;
}
.sidebar.sidebar-admin .nav-link:hover {
    color: #f4f7ff;
    background: rgba(75,125,255,.11);
    border-color: rgba(113,150,255,.2);
    transform: translateX(3px);
}
.sidebar.sidebar-admin .nav-link:hover .nav-icon-box { color: var(--admin-cyan); transform: scale(1.05); }
.sidebar.sidebar-admin .nav-link.is-active {
    color: #fff;
    background: linear-gradient(100deg, #4a7cff, #43c9f1);
    border-color: rgba(142,220,255,.45);
    box-shadow: 0 9px 22px rgba(52,111,235,.28);
    transform: translateX(2px);
}
.sidebar.sidebar-admin .nav-link.is-active::after {
    background: #fff;
    box-shadow: 0 0 10px rgba(255,255,255,.9);
}
.sidebar.sidebar-admin .nav-link.is-active .nav-icon-box {
    color: #1645bd;
    background: rgba(255,255,255,.72);
    border-color: rgba(255,255,255,.45);
}
.sidebar.sidebar-admin .nav-link--logout {
    margin-top: 12px;
    padding-top: 12px;
    border-top-color: rgba(157,178,235,.14);
    color: #a8b3cc;
}
.sidebar.sidebar-admin .nav-link--logout:hover { color: #ffb7c3; background: rgba(220,70,100,.12); border-color: rgba(245,117,140,.2); }
.sidebar.sidebar-admin .sidebar-foot {
    padding: 10px;
    margin-top: 18px;
    border: 1px solid rgba(142,164,228,.14);
    border-radius: 14px;
    background: rgba(85,111,184,.11);
}
.sidebar.sidebar-admin .sidebar-foot .foot-avatar {
    width: 38px;
    height: 38px;
    background: linear-gradient(145deg, #55d6ff, #6375ff);
    color: #111b43;
    box-shadow: 0 5px 16px rgba(70,129,255,.24);
}
.sidebar.sidebar-admin .sidebar-foot .foot-name { color: #edf2ff; font-size: 12.5px; }
.sidebar.sidebar-admin .sidebar-foot .foot-role { color: #8fa6d4; font-size: 10.5px; }
.sidebar.sidebar-admin .theme-toggle-btn {
    margin-top: 8px;
    border-color: rgba(142,164,228,.18);
    border-radius: 11px;
    background: rgba(85,111,184,.1);
    color: #b8c5e2;
    transition: background .2s ease, border-color .2s ease, color .2s ease, transform .2s ease;
}
.sidebar.sidebar-admin .theme-toggle-btn:hover {
    color: #fff;
    background: rgba(75,125,255,.18);
    border-color: rgba(113,150,255,.38);
    transform: translateY(-1px);
}
@media (max-width: 900px) {
    .sidebar.sidebar-admin { width: 76px; padding: 24px 12px 18px; }
    .sidebar.sidebar-admin .sidebar-brand { padding-left: 0; padding-right: 0; }
}
/* Final shared rail contract: every role uses the Dashboard dimensions and
   the same white catalog-style navigation in light mode. */
.sidebar,
.sidebar.sidebar-admin {
    width: var(--sidebar-w, 248px);
    padding: 24px 16px 18px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    color: #1e293b;
    border-right: 1px solid #e2e8f0;
    box-shadow: 8px 0 26px rgba(15,23,42,.06);
}
.sidebar .sidebar-brand,
.sidebar.sidebar-admin .sidebar-brand { gap: 12px; padding: 0 8px 22px; margin-bottom: 20px; border-bottom-color: #e2e8f0; }
.sidebar .brand-mark,
.sidebar.sidebar-admin .brand-mark { width: 44px; height: 44px; border-radius: 14px; background: linear-gradient(135deg,#1d4ed8,#0284c7); border: 1px solid rgba(96,165,250,.3); box-shadow: 0 10px 24px rgba(37,99,235,.18); transition: transform .24s cubic-bezier(.2,.8,.2,1), box-shadow .24s ease, filter .24s ease; }
.sidebar .sidebar-brand:hover .brand-mark,
.sidebar.sidebar-admin .sidebar-brand:hover .brand-mark { transform: translateY(-3px) rotate(-3deg) scale(1.05); filter: saturate(1.08); box-shadow: 0 14px 28px rgba(37,99,235,.22); }
.sidebar .brand-name,
.sidebar.sidebar-admin .brand-name { color: #0f172a; font-size: 16px; }
.sidebar .brand-name span,
.sidebar.sidebar-admin .brand-name span { color: #2563eb; }
.sidebar .brand-tagline,
.sidebar .nav-section-label,
.sidebar.sidebar-admin .brand-tagline,
.sidebar.sidebar-admin .nav-section-label { color: #64748b; }
.sidebar .nav-link,
.sidebar.sidebar-admin .nav-link { min-height: 44px; padding: 8px 11px; border: 1px solid transparent; border-radius: 13px; color: #334155; transition: transform .2s ease, background .2s ease, color .2s ease, border-color .2s ease, box-shadow .2s ease; }
.sidebar .nav-icon-box,
.sidebar.sidebar-admin .nav-icon-box { width: 32px; height: 32px; border-radius: 10px; color: #2563eb; background: #eff6ff; border: 1px solid #dbeafe; transition: background .2s ease, color .2s ease, transform .2s ease; }
.sidebar .nav-link:hover,
.sidebar.sidebar-admin .nav-link:hover { color: #1d4ed8; background: #f8fbff; border-color: #dbeafe; transform: translateX(3px); }
.sidebar .nav-link:hover .nav-icon-box,
.sidebar.sidebar-admin .nav-link:hover .nav-icon-box { color: #1d4ed8; background: #dbeafe; transform: scale(1.05); }
.sidebar .nav-link.is-active,
.sidebar.sidebar-admin .nav-link.is-active { color: #fff !important; background: linear-gradient(100deg,#2563eb,#38bdf8) !important; border-color: rgba(96,165,250,.7) !important; box-shadow: 0 9px 22px rgba(37,99,235,.2); transform: translateX(2px); }
.sidebar .nav-link.is-active .nav-icon-box,
.sidebar.sidebar-admin .nav-link.is-active .nav-icon-box { color: #1d4ed8; background: rgba(255,255,255,.82); border-color: rgba(255,255,255,.55); }
.sidebar .sidebar-foot,
.sidebar.sidebar-admin .sidebar-foot { padding: 10px; border: 1px solid #e2e8f0; border-radius: 14px; background: #f8fafc; }
.sidebar .sidebar-foot .foot-name,
.sidebar.sidebar-admin .sidebar-foot .foot-name { color: #0f172a; font-size: 12.5px; }
.sidebar .sidebar-foot .foot-role,
.sidebar.sidebar-admin .sidebar-foot .foot-role { color: #64748b; font-size: 10.5px; }
.sidebar .theme-toggle-btn,
.sidebar.sidebar-admin .theme-toggle-btn { border-color: #dbe3ef; border-radius: 11px; background: #fff; color: #334155; }
.sidebar-toggle {
    width: 28px;
    height: 28px;
    border: 1px solid #bfdbfe !important;
    background: #ffffff !important;
    color: #2563eb !important;
    box-shadow: 0 6px 16px rgba(37,99,235,.14) !important;
    transition: transform .2s ease, background .2s ease, border-color .2s ease, box-shadow .2s ease !important;
}
.sidebar-toggle:hover {
    background: #eff6ff !important;
    border-color: #60a5fa !important;
    box-shadow: 0 9px 20px rgba(37,99,235,.2) !important;
    transform: scale(1.06);
}
.sidebar-toggle svg { width: 14px; height: 14px; }
.sidebar .nav-link { background-color: transparent; }
.sidebar .nav-link.is-active { background-color: #2563eb !important; }
body { background: #f8fafc; }
.main-area { background: #f8fafc; }
html[data-theme="dark"] .sidebar,
html[data-theme="dark"] .sidebar.sidebar-admin { background: linear-gradient(180deg,var(--white),var(--cream)); color: var(--ink); border-right-color: var(--line); }
html[data-theme="dark"] .sidebar-toggle { background: var(--white) !important; border-color: var(--green-mid) !important; color: var(--green-leaf) !important; }
html[data-theme="dark"] body,
html[data-theme="dark"] .main-area { background: var(--cream) !important; color: var(--ink); }
html[data-theme="dark"] .sidebar .brand-name,
html[data-theme="dark"] .sidebar.sidebar-admin .brand-name { color: var(--ink); }
html[data-theme="dark"] .sidebar .brand-name span,
html[data-theme="dark"] .sidebar.sidebar-admin .brand-name span { color: var(--green-leaf); }
html[data-theme="dark"] .sidebar .nav-link,
html[data-theme="dark"] .sidebar.sidebar-admin .nav-link { color: var(--ink-soft); }
html[data-theme="dark"] .sidebar .nav-icon-box,
html[data-theme="dark"] .sidebar.sidebar-admin .nav-icon-box { color: var(--green-leaf); background: var(--green-pale); border-color: var(--line); }
html[data-theme="dark"] .sidebar .nav-link:hover,
html[data-theme="dark"] .sidebar.sidebar-admin .nav-link:hover { color: var(--ink); background: var(--green-pale); border-color: var(--green-mid); }
html[data-theme="dark"] .sidebar .nav-link.is-active,
html[data-theme="dark"] .sidebar.sidebar-admin .nav-link.is-active { color: var(--ink) !important; background: var(--green-deep) !important; border-color: var(--green-mid) !important; }
html[data-theme="dark"] .sidebar .nav-link.is-active .nav-icon-box,
html[data-theme="dark"] .sidebar.sidebar-admin .nav-link.is-active .nav-icon-box { color: var(--green-deep); background: var(--white); border-color: var(--line); }
html[data-theme="dark"] .sidebar .sidebar-foot,
html[data-theme="dark"] .sidebar.sidebar-admin .sidebar-foot { background: var(--white); border-color: var(--line); }
html[data-theme="dark"] .sidebar .sidebar-foot .foot-name,
html[data-theme="dark"] .sidebar.sidebar-admin .sidebar-foot .foot-name { color: var(--ink); }
html[data-theme="dark"] .sidebar .sidebar-foot .foot-role,
html[data-theme="dark"] .sidebar.sidebar-admin .sidebar-foot .foot-role { color: var(--ink-soft); }
html[data-theme="dark"] .sidebar .theme-toggle-btn,
html[data-theme="dark"] .sidebar.sidebar-admin .theme-toggle-btn { background: var(--white); color: var(--ink); border-color: var(--line); }
html[data-theme="dark"] .stat-row .stat-value { color: var(--ink) !important; }
html[data-theme="dark"] .stat-row .stat-label { color: var(--ink-soft) !important; }
.sidebar-toggle { width:34px !important; height:34px !important; min-height:0 !important; left:calc(var(--sidebar-w,248px) - 17px) !important; border-radius:50% !important; border:1px solid #bfdbfe !important; background:#fff !important; color:#2563eb !important; box-shadow:0 6px 18px rgba(37,99,235,.18) !important; }
.app-shell.sidebar-collapsed .sidebar-toggle { left:calc(var(--sidebar-w-collapsed,84px) - 17px) !important; }
.sidebar-toggle svg { width:16px !important; height:16px !important; }
html[data-theme="dark"] .sidebar-toggle { background:var(--white) !important; color:var(--green-leaf) !important; border-color:var(--green-mid) !important; }
@media (max-width:700px) { .sidebar-toggle,.app-shell.sidebar-collapsed .sidebar-toggle { width:30px !important; height:30px !important; left:49px !important; } }
.topbar { transition: min-height .42s cubic-bezier(.16,1,.3,1), padding .42s cubic-bezier(.16,1,.3,1), background-color .35s cubic-bezier(.16,1,.3,1), border-color .35s cubic-bezier(.16,1,.3,1), box-shadow .35s cubic-bezier(.16,1,.3,1); }
html[data-theme="dark"] body,
html[data-theme="dark"] .main-area { background: var(--cream) !important; color: var(--ink); }
html[data-theme="dark"] .sidebar,
html[data-theme="dark"] .sidebar.sidebar-admin {
    background:
        radial-gradient(420px 260px at 100% 0%, rgba(76,141,255,.14), transparent 60%),
        linear-gradient(180deg, #0B1530 0%, #080F24 58%, #070C1B 100%) !important;
    color: var(--ink);
    border-right-color: rgba(126,155,255,.18);
}
html[data-theme="dark"] .sidebar .brand-name,
html[data-theme="dark"] .sidebar.sidebar-admin .brand-name { color: var(--ink); }
html[data-theme="dark"] .sidebar .brand-name span,
html[data-theme="dark"] .sidebar.sidebar-admin .brand-name span { color: var(--green-leaf); }
html[data-theme="dark"] .sidebar .nav-link,
html[data-theme="dark"] .sidebar.sidebar-admin .nav-link { color: var(--ink-soft); }
html[data-theme="dark"] .sidebar .nav-link:hover,
html[data-theme="dark"] .sidebar.sidebar-admin .nav-link:hover {
    color: var(--ink);
    background: var(--green-pale);
    border-color: var(--green-mid);
}
html[data-theme="dark"] .sidebar .nav-link.is-active,
html[data-theme="dark"] .sidebar.sidebar-admin .nav-link.is-active {
    color: var(--ink) !important;
    background: var(--green-deep) !important;
    border-color: var(--green-mid) !important;
}
html[data-theme="dark"] .sidebar .nav-icon-box,
html[data-theme="dark"] .sidebar.sidebar-admin .nav-icon-box {
    color: var(--green-leaf);
    background: var(--green-pale);
    border-color: var(--line);
}
html[data-theme="dark"] .sidebar .sidebar-foot,
html[data-theme="dark"] .sidebar.sidebar-admin .sidebar-foot,
html[data-theme="dark"] .sidebar .theme-toggle-btn,
html[data-theme="dark"] .sidebar.sidebar-admin .theme-toggle-btn {
    background: rgba(85,111,184,.12);
    color: var(--ink);
    border-color: rgba(142,164,228,.18);
}
html[data-theme="dark"] .sidebar-toggle {
    background: var(--white) !important;
    color: var(--green-leaf) !important;
    border-color: var(--green-mid) !important;
}
</style>
<?php
$__sidebarCssPath = __DIR__ . '/../../assets/css/sidebar.css';
$__sidebarCssUrl = rtrim((string) dirname(appBaseUrl()), '/')
    . '/assets/css/sidebar.css?v='
    . (filemtime($__sidebarCssPath) ?: time());
?>
<link rel="stylesheet" href="<?= htmlspecialchars($__sidebarCssUrl, ENT_QUOTES, 'UTF-8') ?>">
<script src="<?= htmlspecialchars(rtrim((string) appBaseUrl(), '/') . '/assets/hrms-sidebar.js?v=' . (filemtime(__DIR__ . '/../assets/hrms-sidebar.js') ?: time()), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<script>
(function () {
    // Keep the existing per-account key as a fallback for theme settings
    // saved before the app-wide preference was introduced.
    var themeKey = 'siaa_theme_<?= htmlspecialchars((string)$__uid) ?>';
    var savedTheme = localStorage.getItem('manage-grocery-theme');
    if (savedTheme === 'dark' || (savedTheme !== 'light' && localStorage.getItem(themeKey) === 'dark')) {
        document.documentElement.setAttribute('data-theme', 'dark');
    } else if (savedTheme === 'light') {
        document.documentElement.removeAttribute('data-theme');
    }
})();
</script>
<script src="<?= htmlspecialchars(rtrim((string) dirname(appBaseUrl()), '/') . '/theme-sync.js?v=3', ENT_QUOTES, 'UTF-8') ?>"></script>