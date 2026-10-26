<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// Sidebar for the Supervisor role. Same visual system as hr_sidebar.php
// (288px width, icon chips, account footer) but its own nav list, since
// a Supervisor's scope of work (their assigned team only) is completely
// different from HR Staff/HR Manager/Admin.
//
// Admin can also land on these pages (see requireSupervisorRole()) to
// preview a specific Supervisor's team via ?supervisor_id=. When that's
// the case, this sidebar carries that ?supervisor_id= across every nav
// link so Admin doesn't lose the team they're viewing, shows the real
// "Administrator" role in the footer instead of a hardcoded "Supervisor",
// and adds a link back to the Admin Dashboard.
//
// Usage: set $activeNav to one of
// 'dashboard' | 'attendance' | 'schedule' | 'leave' | 'overtime' | 'official_business' | 'late_timein' |
// 'performance' | 'inventory' | 'pos_monitor' | 'purchase_orders' | 'reports' | 'notifications' | 'my_profile'
// then include this file.

if (!isset($activeNav)) { $activeNav = ''; }
$__fullName = $_SESSION['full_name'] ?? '';
$__initial  = $__fullName !== '' ? strtoupper(substr($__fullName, 0, 1)) : '?';
$__avatarFile = $_SESSION['profile_picture'] ?? null;
$__isAdminViewing = ($_SESSION['role'] ?? '') === 'admin';
$__uid = $_SESSION['user_id'] ?? 'guest';
// Just forward the already-encrypted token as-is (no need to decrypt
// then re-encrypt it — it's the same value, still opaque in the URL).
$__qs = $__isAdminViewing ? ('?supervisor_id=' . rawurlencode($_GET['supervisor_id'] ?? '')) : '';
$__profileHref = $__isAdminViewing ? 'admin_profile.php' : 'my_profile.php';
?>
<aside class="sidebar" id="hrmsSidebar">
    <link rel="stylesheet" href="<?= htmlspecialchars(rtrim((string) appBaseUrl(), '/') . '/assets/hrms-theme.css?v=' . (filemtime(__DIR__ . '/../assets/hrms-theme.css') ?: time()), ENT_QUOTES, 'UTF-8') ?>">
    <script src="<?= htmlspecialchars(rtrim((string) appBaseUrl(), '/') . '/assets/hrms-sidebar.js?v=' . (filemtime(__DIR__ . '/../assets/hrms-sidebar.js') ?: time()), ENT_QUOTES, 'UTF-8') ?>" defer></script>
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
            <span class="brand-tagline"><?= $__isAdminViewing ? 'Viewing: Supervisor' : 'Supervisor' ?></span>
        </div>
        <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Collapse sidebar" title="Collapse sidebar">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M9 3v18" stroke="currentColor" stroke-width="1.5"/><path class="toggle-arrow-collapse" d="m15 9-3 3 3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path class="toggle-arrow-expand" d="m12 9 3 3-3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
    </div>

    <div class="nav-section-label">Menu</div>
    <nav class="sidebar-nav">
        <a href="supervisor_dashboard.php<?= $__qs ?>" class="nav-link<?= $activeNav === 'dashboard' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M3 9.5L10 4l7 5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 8.5V16a1 1 0 0 0 1 1h3v-4h2v4h3a1 1 0 0 0 1-1V8.5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></span>
            <span>Dashboard</span>
        </a>
        <a href="supervisor_attendance.php<?= $__qs ?>" class="nav-link<?= $activeNav === 'attendance' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="12" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M3 8h14" stroke="currentColor" stroke-width="1.6"/><path d="M7 2.5v3M13 2.5v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M10 10.3v2l1.4 1.2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            <span>Team Attendance</span>
        </a>
        <a href="supervisor_schedule.php<?= $__qs ?>" class="nav-link<?= $activeNav === 'schedule' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="12" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M3 8h14" stroke="currentColor" stroke-width="1.6"/><path d="M7 2.5v3M13 2.5v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M6.5 11h2M11.5 11h2M6.5 14h2M11.5 14h2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            <span>Team Schedule</span>
        </a>
        <a href="supervisor_leave.php<?= $__qs ?>" class="nav-link<?= $activeNav === 'leave' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M6 2.5h6l3 3V17a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M7.5 9h5M7.5 12h5M7.5 15h3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            <span>Leave Requests</span>
        </a>
        <a href="supervisor_overtime.php<?= $__qs ?>" class="nav-link<?= $activeNav === 'overtime' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.6"/><path d="M10 6v4l3 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <span>Overtime</span>
        </a>
        <a href="supervisor_official_business.php<?= $__qs ?>" class="nav-link<?= $activeNav === 'official_business' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="3" y="6" width="14" height="10" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M7 6V4.5A1.5 1.5 0 0 1 8.5 3h3A1.5 1.5 0 0 1 13 4.5V6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span>
            <span>Official Business</span>
        </a>
        <a href="supervisor_late_timein.php<?= $__qs ?>" class="nav-link<?= $activeNav === 'late_timein' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="12" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M10 8v3l2 1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 8h14" stroke="currentColor" stroke-width="1.6"/></svg></span>
            <span>Time In Requests</span>
        </a>
        <a href="hr_notifications.php" class="nav-link<?= $activeNav === 'notifications' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M6 8a4 4 0 018 0c0 3.2 1 4.5 1.5 5H4.5C5 12.5 6 11.2 6 8z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M8.2 15.5a1.8 1.8 0 003.6 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
            <span>Notifications</span>
        </a>
        <a href="supervisor_performance.php<?= $__qs ?>" class="nav-link<?= $activeNav === 'performance' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M4 16V9M10 16V4M16 16v-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></span>
            <span>Performance</span>
        </a>
        <a href="supervisor_inventory.php<?= $__qs ?>" class="nav-link<?= $activeNav === 'inventory' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M3 6l7-3.5L17 6v8l-7 3.5L3 14V6z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M3 6l7 3.5L17 6M10 9.5V17" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg></span>
            <span>Inventory Monitoring</span>
        </a>
        <a href="supervisor_pos_monitor.php<?= $__qs ?>" class="nav-link<?= $activeNav === 'pos_monitor' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="9" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M7 16.5h6M10 13v3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
            <span>POS Monitor</span>
        </a>
        <a href="supervisor_purchase_orders.php<?= $__qs ?>" class="nav-link<?= $activeNav === 'purchase_orders' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M5 3h7l3 3v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M12 3v3h3M6.5 10h7M6.5 13h7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <span>Purchase Orders</span>
        </a>
        <a href="supervisor_catalog_security.php<?= $__qs ?>" class="nav-link<?= $activeNav === 'catalog_security' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="4.5" y="9" width="11" height="8" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M7 9V6.5a3 3 0 0 1 6 0V9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="12.5" r="1" fill="currentColor"/><path d="M10 13.5V15" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            <span>Private Catalog Access</span>
        </a>
        <a href="supervisor_reports.php<?= $__qs ?>" class="nav-link<?= $activeNav === 'reports' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M5 3h7l3 3v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M6.5 8.5h7M6.5 11.5h7M6.5 14.5h4.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            <span>Team Report</span>
        </a>
        <a href="announcements.php" class="nav-link<?= $activeNav === 'announcements' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M3 6.5l8-3 8 3v2l-8 3-8-3v-2z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M5.5 10v4c0 1.4 2 2.5 4.5 2.5s4.5-1.1 4.5-2.5v-4" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg></span>
            <span>Announcements</span>
        </a>
        <?php if (!$__isAdminViewing): ?>
        <a href="<?= $__profileHref ?>" class="nav-link<?= $activeNav === 'my_profile' ? ' is-active' : '' ?>">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="7" r="3.2" stroke="currentColor" stroke-width="1.6"/><path d="M3.5 17c1-3.5 4-5.2 6.5-5.2s5.5 1.7 6.5 5.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span>
            <span>My Profile</span>
        </a>
        <?php endif; ?>
        <?php if ($__isAdminViewing): ?>
        <a href="admin_dashboard.php" class="nav-link nav-link--back">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M12 16l-6-6 6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 10h11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <span>Back to Admin Dashboard</span>
        </a>
        <?php else: ?>
        <a href="<?= logoutUrl() ?>" class="nav-link nav-link--logout" data-confirm-logout="true" data-confirm="Are you sure you want to logout?" data-confirm-title="Log out?" data-confirm-ok="Yes, logout" data-confirm-color="#123C5C">
            <span class="nav-icon-box"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M7 17H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h3M13 14l4-4-4-4M17 10H7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <span>Logout</span>
        </a>
        <?php endif; ?>
    </nav>

</aside>

<style>
/* Sidebar accent, matched to the homepage's (index.php) cyan/violet
   night palette. Scoped to .sidebar only — and placed after each page's
   own <style> block in the document — so it overrides just the sidebar's
   look without touching the rest of the module (buttons, badges, etc.). */
.sidebar {
    --green-deep: #0a1024;
    --green-mid:  #6d7cff;
    --green-leaf: #55d6ff;
    --green-pale: rgba(85,214,255,.16);
    background:
        radial-gradient(420px 260px at 100% 0%, rgba(85,214,255,.18), transparent 60%),
        radial-gradient(360px 320px at 0% 100%, rgba(176,108,255,.16), transparent 60%),
        var(--green-deep);
}
.sidebar-foot .foot-avatar { background: linear-gradient(150deg, var(--green-leaf), #7c6cff); }

/* Dark mode palette override. Wins over each page's own :root because
   html[data-theme="dark"] (0,1,1) has higher specificity than :root
   (0,1,0) — applies regardless of where this <style> sits in the DOM. */
html[data-theme="dark"] {
    --green-deep:    #1D4ED8;
    --text-accent:   #60A5FA;
    --green-mid:     #2563EB;
    --green-leaf:    #60A5FA;
    --green-pale:    #172554;
    --mustard:       #E0A23B;
    --mustard-pale:  #3A2E14;
    --red-alert:     #E5644A;
    --red-pale:      #3A1912;
    --cream:         #090B0D;
    --white:         #0D1013;
    --ink:           #F1F5F9;
    --ink-soft:      #94A3B8;
    --line:          #26313A;
    --mustard-text:  #E0A23B;
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
        radial-gradient(420px 260px at 100% 0%, rgba(85,214,255,.16), transparent 60%),
        radial-gradient(360px 320px at 0% 100%, rgba(176,108,255,.14), transparent 60%),
        #05070f;
}
html, body { transition: background-color .18s ease, color .18s ease; }
.sidebar, .card, .topbar, input, select, textarea, .content, table {
    transition: background-color .18s ease, color .18s ease, border-color .18s ease;
}

/* ── Sidebar collapse ("cover") — same spacing behavior as the POS
   sidebar: shrinks to an icon-only rail and gives the module content
   extra breathing room once tucked away. ── */
.sidebar { transition: width 0.22s ease, padding 0.22s ease; }
.main-area { transition: margin-left 0.22s ease; }

/* Hide the native sidebar scrollbar (stays scrollable via overflow-y:
   auto) — it was also what crowded/covered the toggle pill below. */
.sidebar {
    scrollbar-width: none;
    -ms-overflow-style: none;
}
.sidebar::-webkit-scrollbar { display: none; }

.sidebar-toggle {
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
    transition: left 0.22s ease, background 0.15s ease;
}
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

/* White catalog-inspired sidebar for Supervisor pages. */
.sidebar {
    background: #ffffff;
    color: #1e293b;
    border-right: 1px solid #e2e8f0;
    box-shadow: 8px 0 26px rgba(15,23,42,.06);
}
.sidebar-brand { border-bottom-color: #e2e8f0; }
.sidebar .brand-name { color: #0f172a; }
.sidebar .brand-name span { color: #2563eb; }
.sidebar .brand-mark { transform-origin: center; transition: transform .24s cubic-bezier(.2,.8,.2,1), box-shadow .24s ease, filter .24s ease; }
.sidebar-brand:hover .brand-mark { transform: translateY(-3px) rotate(-3deg) scale(1.05); filter: saturate(1.08); box-shadow: 0 14px 28px rgba(37,99,235,.3); }
.sidebar-brand:hover .brand-mark .brand-icon { transform: rotate(4deg) scale(1.04); }
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
html[data-theme="dark"] .sidebar { background: linear-gradient(180deg,#0b1530 0%,#080f24 58%,#070c1b 100%); color: #e8efff; border-right-color: rgba(126,155,255,.16); }
html[data-theme="dark"] .sidebar .brand-name { color: #e8efff; }
html[data-theme="dark"] .sidebar .brand-name span { color: #55d6ff; }
html[data-theme="dark"] .sidebar .nav-link { color: #91a0bd; }
html[data-theme="dark"] .sidebar .nav-section-label { color: #7383a7; }
html[data-theme="dark"] .sidebar .nav-icon-box { color: #91a9e7; background: rgba(125,151,220,.1); border-color: rgba(151,174,242,.1); }
html[data-theme="dark"] .sidebar .sidebar-foot { background: rgba(85,111,184,.11); border-color: rgba(142,164,228,.14); }
.sidebar { width:var(--sidebar-w,248px); padding:24px 16px 18px; background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%); color:#1e293b; border-right:1px solid #e2e8f0; box-shadow:8px 0 26px rgba(15,23,42,.06); }
.sidebar .sidebar-brand { gap:12px; padding:0 8px 22px; margin-bottom:20px; border-bottom-color:#e2e8f0; }
.sidebar .brand-mark { width:44px; height:44px; border-radius:14px; background:linear-gradient(135deg,#1d4ed8,#0284c7); border:1px solid rgba(96,165,250,.3); box-shadow:0 10px 24px rgba(37,99,235,.18); transition:transform .24s cubic-bezier(.2,.8,.2,1),box-shadow .24s ease,filter .24s ease; }
.sidebar .sidebar-brand:hover .brand-mark { transform:translateY(-3px) rotate(-3deg) scale(1.05); filter:saturate(1.08); box-shadow:0 14px 28px rgba(37,99,235,.22); }
.sidebar .brand-name { color:#0f172a; font-size:16px; } .sidebar .brand-name span { color:#2563eb; } .sidebar .brand-tagline,.sidebar .nav-section-label { color:#64748b; }
.sidebar .nav-link { min-height:44px; padding:8px 11px; border:1px solid transparent; border-radius:13px; color:#334155; transition:transform .2s ease,background .2s ease,color .2s ease,border-color .2s ease,box-shadow .2s ease; }
.sidebar .nav-icon-box { width:32px; height:32px; border-radius:10px; color:#2563eb; background:#eff6ff; border:1px solid #dbeafe; transition:background .2s ease,color .2s ease,transform .2s ease; }
.sidebar .nav-link:hover { color:#1d4ed8; background:#f8fbff; border-color:#dbeafe; transform:translateX(3px); } .sidebar .nav-link:hover .nav-icon-box { color:#1d4ed8; background:#dbeafe; transform:scale(1.05); }
.sidebar .nav-link.is-active { color:#fff !important; background:linear-gradient(100deg,#2563eb,#38bdf8) !important; border-color:rgba(96,165,250,.7) !important; box-shadow:0 9px 22px rgba(37,99,235,.2); transform:translateX(2px); } .sidebar .nav-link.is-active .nav-icon-box { color:#1d4ed8; background:rgba(255,255,255,.82); border-color:rgba(255,255,255,.55); }
.sidebar .sidebar-foot { padding:10px; border:1px solid #e2e8f0; border-radius:14px; background:#f8fafc; } .sidebar .sidebar-foot .foot-name { color:#0f172a; font-size:12.5px; } .sidebar .sidebar-foot .foot-role { color:#64748b; font-size:10.5px; } .sidebar .theme-toggle-btn { border-color:#dbe3ef; border-radius:11px; background:#fff; color:#334155; }
.sidebar-toggle { border:1px solid #bfdbfe !important; background:#fff !important; color:#2563eb !important; box-shadow:0 6px 16px rgba(37,99,235,.14) !important; }
.main-area { margin-left: 248px; }
.sidebar-toggle { left: 235px !important; }
.app-shell.sidebar-collapsed .main-area { margin-left: 84px; }
.app-shell.sidebar-collapsed .sidebar-toggle { left: 71px !important; }
.sidebar-toggle:hover { background:#eff6ff !important; border-color:#60a5fa !important; transform:scale(1.06); }
body,.main-area { background:#f8fafc; }
html[data-theme="dark"] .sidebar { background:linear-gradient(180deg,#0b1530 0%,#080f24 58%,#070c1b 100%); color:#e8efff; border-right-color:rgba(126,155,255,.16); } html[data-theme="dark"] .sidebar .brand-name { color:#e8efff; } html[data-theme="dark"] .sidebar .brand-name span { color:#55d6ff; } html[data-theme="dark"] .sidebar .nav-link { color:#91a0bd; } html[data-theme="dark"] .sidebar .nav-icon-box { color:#91a9e7; background:rgba(125,151,220,.1); border-color:rgba(151,174,242,.1); }
html[data-theme="dark"] .sidebar-toggle { background:#1e293b !important; border-color:#60a5fa !important; color:#93c5fd !important; } html[data-theme="dark"] body,html[data-theme="dark"] .main-area { background:#0f172a; }
html[data-theme="dark"] body,html[data-theme="dark"] .main-area { background:#090B0D !important; }
html[data-theme="dark"] .sidebar { background:radial-gradient(420px 260px at 100% 0%,rgba(37,99,235,.18),transparent 60%),linear-gradient(180deg,#0D1013 0%,#090B0D 100%) !important; color:#F1F5F9; border-right-color:#26313A; }
html[data-theme="dark"] .sidebar .brand-name { color:#F1F5F9; }
html[data-theme="dark"] .sidebar .brand-name span { color:#60A5FA; }
html[data-theme="dark"] .sidebar .nav-link { color:#94A3B8; }
html[data-theme="dark"] .sidebar .nav-link:hover { color:#DBEAFE; background:rgba(37,99,235,.16); border-color:rgba(96,165,250,.28); }
html[data-theme="dark"] .sidebar .nav-link.is-active { color:#FFFFFF !important; background:linear-gradient(100deg,#1D4ED8,#2563EB) !important; border-color:rgba(96,165,250,.62) !important; }
html[data-theme="dark"] .sidebar .sidebar-foot { background:#111518; border-color:#26313A; }
</style>
<?php
$__supervisorRole = (string) ($_SESSION['role'] ?? '');
$__supervisorAvatar = $_SESSION['profile_picture'] ?? null;
if (
    !is_string($__supervisorAvatar)
    || !preg_match('/^[A-Za-z0-9._-]+$/', $__supervisorAvatar)
    || !is_file(__DIR__ . '/../uploads/avatars/' . $__supervisorAvatar)
) {
    $__supervisorAvatar = null;
}
$__supervisorTopbarConfig = [
    'name' => $__fullName !== '' ? $__fullName : 'User',
    'role' => $__isAdminViewing ? 'Admin' : 'Supervisor',
    'initial' => $__initial ?: '?',
    'avatarUrl' => $__supervisorAvatar
        ? rtrim(appBaseUrl(), '/') . '/uploads/avatars/' . rawurlencode(basename($__supervisorAvatar))
        : '',
    'notificationUnreadCount' => getHrmsNotificationBadgeCount($pdo, $posPdo, $__supervisorRole, $_SESSION['employee_id'] ?? null),
    'notificationsLabel' => 'HRMS notifications',
    'notificationsUrl' => rtrim(appBaseUrl(), '/') . '/hr_notifications.php',
    'profileUrl' => rtrim(appBaseUrl(), '/') . '/' . ltrim($__profileHref, '/'),
    'logoutUrl' => logoutUrl(),
    'themeStorageKey' => 'siaa_theme_' . (string) $__uid,
];
$__supervisorTopbarCssPath = __DIR__ . '/../../assets/css/topbar.css';
$__supervisorTopbarJsPath = __DIR__ . '/../../assets/js/shared-topbar.js';
$__supervisorScrollJsPath = __DIR__ . '/../../assets/js/scroll-preservation.js';
$__supervisorSystemBase = rtrim(dirname(rtrim(appBaseUrl(), '/')), '/\\');
?>
<link rel="stylesheet" href="<?= htmlspecialchars($__supervisorSystemBase . '/assets/css/topbar.css?v=' . (filemtime($__supervisorTopbarCssPath) ?: time()), ENT_QUOTES, 'UTF-8') ?>">
<script type="application/json" id="sharedTopbarConfig"><?= json_encode($__supervisorTopbarConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
<script src="<?= htmlspecialchars($__supervisorSystemBase . '/assets/js/shared-topbar.js?v=' . (filemtime($__supervisorTopbarJsPath) ?: time()), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<script src="<?= htmlspecialchars($__supervisorSystemBase . '/assets/js/scroll-preservation.js?v=' . (filemtime($__supervisorScrollJsPath) ?: time()), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php
$__sidebarCssPath = __DIR__ . '/../../assets/css/sidebar.css';
$__sidebarCssUrl = rtrim((string) dirname(appBaseUrl()), '/')
    . '/assets/css/sidebar.css?v='
    . (filemtime($__sidebarCssPath) ?: time());
?>
<link rel="stylesheet" href="<?= htmlspecialchars($__sidebarCssUrl, ENT_QUOTES, 'UTF-8') ?>">
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