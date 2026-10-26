<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// ============================================================
// includes/sidebar.php — Iisang sidebar para sa lahat ng module
// (Dashboard, Sales Report, Products, Shift Report, Inventory,
// Notifications, Settings, atbp.) para consistent na "account"
// ang itsura kahit saang page — may icons at may account footer
// (avatar + pangalan + role), tulad ng ginamit na sa POS at
// My Profile side.
//
// Paano gamitin sa isang page:
//   $active = 'dashboard'; then include 'includes/sidebar.php';
//
// $active values: dashboard, reports, products, shift_report,
//                  inventory, notifications, settings, my_profile, pos
// ============================================================

$__role = $_SESSION['role'] ?? '';
$__scanner_return = $__role === 'admin' ? 'admin' : ($__role === 'inventory_staff' ? 'inventory' : 'cashier');
$active = $active ?? '';
$__uid  = $_SESSION['user_id'] ?? 'guest';

if (!function_exists('__sidebarNavLink')) {
    function __sidebarNavLink($href, $key, $active, $label, $iconSvg) {
        $isActive = ($key === $active);
        echo '<a href="' . $href . '" class="nav-link' . ($isActive ? ' is-active' : '') . '"' . ($isActive ? ' aria-current="page"' : '') . '>';
        echo '<span class="nav-icon-box">' . $iconSvg . '</span>';
        echo '<span class="nav-label">' . $label . '</span>';
        echo '</a>';
    }
}

// Reusable icon set (20x20 viewBox, currentColor stroke) — same
// visual language na ginamit na sa POS / My Profile sidebar.
$__icon_dashboard     = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="2.5" y="2.5" width="6.5" height="6.5" rx="1.3" stroke="currentColor" stroke-width="1.6"/><rect x="11" y="2.5" width="6.5" height="6.5" rx="1.3" stroke="currentColor" stroke-width="1.6"/><rect x="2.5" y="11" width="6.5" height="6.5" rx="1.3" stroke="currentColor" stroke-width="1.6"/><rect x="11" y="11" width="6.5" height="6.5" rx="1.3" stroke="currentColor" stroke-width="1.6"/></svg>';
$__icon_reports       = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M3 17V8M9 17V3M15 17v-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M2.5 17h15" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
$__icon_products      = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="3" y="6" width="14" height="10" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M3 6l2-3h10l2 3" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>';
$__icon_shift_report  = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M4 4h12v12H4z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M7 8h6M7 11h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>';
$__icon_inventory     = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M4 8.5l6-5 6 5V16a1 1 0 01-1 1h-3v-5H8v5H5a1 1 0 01-1-1V8.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>';
$__icon_notifications = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M6 8a4 4 0 018 0c0 3.2 1 4.5 1.5 5H4.5C5 12.5 6 11.2 6 8z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M8.2 15.5a1.8 1.8 0 003.6 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
$__icon_announcements = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M3 8.5v3a1 1 0 001 1h1.5l5.5 3.2V4.3L5.5 7.5H4a1 1 0 00-1 1z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M14.5 8a2.3 2.3 0 010 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M11 13v2.3a1.2 1.2 0 01-2.3.4l-.9-2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$__icon_inv_reports   = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="3" y="3" width="14" height="14" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M6.5 13.5v-4M10 13.5v-7M13.5 13.5v-2.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
$__icon_finance       = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.6"/><path d="M10 6v8M12.3 8.1c0-1-1-1.7-2.3-1.7s-2.3.7-2.3 1.7c0 2.2 4.6 1.1 4.6 3.2 0 1-1 1.7-2.3 1.7s-2.3-.7-2.3-1.7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>';
$__icon_accounts_payable = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="2.5" y="4" width="15" height="12" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M2.5 8h15" stroke="currentColor" stroke-width="1.6"/><path d="M5.5 12h4M5.5 14.2h2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M13 11.5l1.5 1.5 2.5-2.7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$__icon_financial_statements = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="4" y="2.5" width="12" height="15" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M7 7h6M7 10h6M7 13h3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
$__icon_financial_analysis = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M3 14l4-5 3 3 6-7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M13 5h4v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M2.5 17h15" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
$__icon_budgeting = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="2.5" y="4" width="15" height="12" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M2.5 8h15" stroke="currentColor" stroke-width="1.6"/><circle cx="14" cy="12.2" r="1.4" fill="currentColor"/></svg>';
$__icon_audit_log = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6"/><path d="M9 5.5V9l2.5 1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 15l3 3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';
$__icon_requests      = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M3 6.5l7-3.5 7 3.5v7L10 17l-7-3.5v-7z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M3 6.5l7 3.5 7-3.5M10 10v7" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>';
$__icon_suppliers     = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="2.5" y="4.5" width="15" height="11" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M2.5 8.5h15" stroke="currentColor" stroke-width="1.6"/><path d="M6 12h3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
$__icon_vendor_ratings = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M10 3l2.1 4.3 4.7.7-3.4 3.3.8 4.7L10 13.8l-4.2 2.2.8-4.7-3.4-3.3 4.7-.7L10 3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>';
$__icon_purchase_orders = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M5 3h7l3 3v11a1 1 0 01-1 1H5a1 1 0 01-1-1V4a1 1 0 011-1z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M12 3v3h3" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M6.5 10.5l2 2 4-4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$__icon_operating_expenses = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M4 6.5h9.5a2.5 2.5 0 012.5 2.5v4a2.5 2.5 0 01-2.5 2.5H6a2 2 0 01-2-2v-7z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M4 6.5V5a1.5 1.5 0 011.5-1.5H12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="13.5" cy="11" r="1.1" fill="currentColor"/></svg>';
$__icon_expense_categories = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M3 4.5h5l1.5 2H17a1 1 0 011 1V15a1 1 0 01-1 1H3a1 1 0 01-1-1V5.5a1 1 0 011-1z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>';
$__icon_settings      = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="2.6" stroke="currentColor" stroke-width="1.5"/><path d="M10 3v1.6M10 15.4V17M17 10h-1.6M4.6 10H3M14.9 5.1l-1.1 1.1M6.2 13.7l-1.1 1.1M14.9 14.9l-1.1-1.1M6.2 6.3L5.1 5.1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
$__icon_my_profile    = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="7" r="3.2" stroke="currentColor" stroke-width="1.6"/><path d="M3.5 17c1-3.5 4-5.2 6.5-5.2s5.5 1.7 6.5 5.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
$__icon_pos           = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><rect x="2" y="4" width="16" height="13" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M2 8h16" stroke="currentColor" stroke-width="1.6"/><path d="M6 12h2M10 12h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>';
$__icon_logout        = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M7 17H4a1 1 0 01-1-1V4a1 1 0 011-1h3M13 14l4-4-4-4M17 10H7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$__icon_back_hrms     = '<svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M12 16l-6-6 6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 10h11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

switch ($__role) {
    case 'admin':
        $__foot_role = 'Administrator';
        break;
    case 'inventory_staff':
        $__foot_role = 'Inventory Staff';
        break;
    case 'finance':
        $__foot_role = 'Finance';
        break;
    default:
        $__foot_role = 'Cashier';
}

$__full_name = trim($_SESSION['full_name'] ?? '');
$__initial   = $__full_name !== '' ? mb_strtoupper(mb_substr($__full_name, 0, 1)) : '?';
$__avatarFile = $_SESSION['profile_picture'] ?? null;
if (
    !is_string($__avatarFile)
    || !preg_match('/^[A-Za-z0-9._-]+$/', $__avatarFile)
    || !is_file(__DIR__ . '/../uploads/avatars/' . $__avatarFile)
) {
    $__avatarFile = null;
}
?>
<aside class="sidebar" id="sidebar">
    <script>
    (function () {
        // Apply saved collapse state ASAP (before the rest of the sidebar
        // and the main content render) so there's no flash/jump.
        var shell = document.querySelector('.app-shell');
        if (shell && localStorage.getItem('siaa_sidebar_collapsed') === '1') {
            shell.classList.add('sidebar-collapsed');
            document.body.classList.add('sidebar-collapsed');
        }
        // Apply saved light/dark theme ASAP din, para hindi mag-flash
        // ng light bago mag-dark. Naka-attach sa <html> (data-theme)
        // dahil dun binabase ang lahat ng CSS variable overrides.
        var themeKey = 'siaa_theme_<?= clean((string)$__uid) ?>';
        var savedTheme = localStorage.getItem('manage-grocery-theme');
        if (savedTheme === 'dark' || (savedTheme !== 'light' && localStorage.getItem(themeKey) === 'dark')) {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else if (savedTheme === 'light') {
            document.documentElement.removeAttribute('data-theme');
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
            <span class="brand-tagline">POS &amp; Inventory System</span>
        </div>
        <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Collapse sidebar" title="Collapse sidebar">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M9 3v18" stroke="currentColor" stroke-width="1.5"/><path class="toggle-arrow-collapse" d="m15 9-3 3 3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path class="toggle-arrow-expand" d="m12 9 3 3-3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
    </div>

    <div class="nav-section-label">Menu</div>
    <nav class="sidebar-nav">
        <?php if ($__role === 'admin'): ?>
            <?php __sidebarNavLink('dashboard.php', 'dashboard', $active, 'Dashboard', $__icon_dashboard); ?>
            <?php __sidebarNavLink('reports.php', 'reports', $active, 'Sales Report', $__icon_reports); ?>
            <?php __sidebarNavLink('products.php', 'products', $active, 'Products', $__icon_products); ?>
            <?php __sidebarNavLink('scan_insights.php', 'scan_insights', $active, 'Scan Insights', $__icon_reports); ?>
            <?php __sidebarNavLink('scanner.php?return='.rawurlencode($__scanner_return), 'scanner', $active, 'Scan QR / Barcode', $__icon_reports); ?>
            <?php __sidebarNavLink('ai_settings.php', 'ai_settings', $active, 'AI Settings', $__icon_settings); ?>
            <?php __sidebarNavLink('procurement_signature_enrollment.php', 'procurement_signature_enrollment', $active, 'Procurement Signatures', $__icon_settings); ?>
            <?php __sidebarNavLink('shelf_labels.php', 'shelf_labels', $active, 'Shelf Labels', $__icon_products); ?>
            <?php __sidebarNavLink('shift_report.php', 'shift_report', $active, 'Shift Report', $__icon_shift_report); ?>
            <?php __sidebarNavLink('inventory.php', 'inventory', $active, 'Inventory', $__icon_inventory); ?>
            <?php __sidebarNavLink('inventory_requests.php', 'inventory_requests', $active, 'Inventory Requests', $__icon_requests); ?>
            <?php __sidebarNavLink('inventory_reports.php', 'inventory_reports', $active, 'Inventory Reports', $__icon_inv_reports); ?>
            <?php __sidebarNavLink('stock_recommendations.php', 'stock_recommendations', $active, 'Stock Recommendations', $__icon_inv_reports); ?>
            <?php __sidebarNavLink('suppliers.php', 'suppliers', $active, 'Suppliers', $__icon_suppliers); ?>
            <?php __sidebarNavLink('vendor_ratings.php', 'vendor_ratings', $active, 'Supplier Ratings', $__icon_vendor_ratings); ?>
            <?php __sidebarNavLink('purchase_orders.php', 'purchase_orders', $active, 'Purchase Orders', $__icon_purchase_orders); ?>
            <?php __sidebarNavLink('operating_expenses.php', 'operating_expenses', $active, 'Operating Expenses', $__icon_operating_expenses); ?>
            <?php __sidebarNavLink('expense_categories.php', 'expense_categories', $active, 'Expense Categories', $__icon_expense_categories); ?>
            <?php __sidebarNavLink('finance.php', 'finance', $active, 'Finance', $__icon_finance); ?>
            <?php __sidebarNavLink('finance.php#payroll-approval', 'payroll', $active, 'Payroll', $__icon_finance); ?>
            <?php __sidebarNavLink('accounts_payable.php', 'accounts_payable', $active, 'Accounts Payable', $__icon_accounts_payable); ?>
            <?php __sidebarNavLink('financial_statements.php', 'financial_statements', $active, 'Financial Statements', $__icon_financial_statements); ?>
            <?php __sidebarNavLink('financial_analysis.php', 'financial_analysis', $active, 'Financial Analysis', $__icon_financial_analysis); ?>
            <?php __sidebarNavLink('budgeting.php', 'budgeting', $active, 'Budgeting', $__icon_budgeting); ?>
            <?php __sidebarNavLink('financial_audit_log.php', 'financial_audit_log', $active, 'Financial Audit Log', $__icon_audit_log); ?>
            <?php __sidebarNavLink('notifications.php', 'notifications', $active, 'Notifications', $__icon_notifications); ?>
            <?php __sidebarNavLink('announcements.php', 'announcements', $active, 'Announcements', $__icon_announcements); ?>
        <?php elseif ($__role === 'inventory_staff'): ?>
            <?php __sidebarNavLink('inventory_dashboard.php', 'inventory_dashboard', $active, 'Dashboard', $__icon_dashboard); ?>
            <?php __sidebarNavLink('inventory.php', 'inventory', $active, 'Inventory', $__icon_inventory); ?>
            <?php __sidebarNavLink('inventory_requests.php', 'inventory_requests', $active, 'Inventory Requests', $__icon_requests); ?>
            <?php __sidebarNavLink('products.php', 'products', $active, 'Products', $__icon_products); ?>
            <?php __sidebarNavLink('shelf_labels.php', 'shelf_labels', $active, 'Shelf Labels', $__icon_products); ?>
            <?php __sidebarNavLink('scanner.php?return='.rawurlencode($__scanner_return), 'scanner', $active, 'Scan QR / Barcode', $__icon_reports); ?>
            <?php __sidebarNavLink('scan_insights.php', 'scan_insights', $active, 'Scan Insights', $__icon_reports); ?>
            <?php __sidebarNavLink('inventory_reports.php', 'inventory_reports', $active, 'Inventory Reports', $__icon_inv_reports); ?>
            <?php __sidebarNavLink('suppliers.php', 'suppliers', $active, 'Suppliers', $__icon_suppliers); ?>
            <?php __sidebarNavLink('vendor_ratings.php', 'vendor_ratings', $active, 'Supplier Ratings', $__icon_vendor_ratings); ?>
            <?php __sidebarNavLink('purchase_orders.php', 'purchase_orders', $active, 'Purchase Orders', $__icon_purchase_orders); ?>
            <?php __sidebarNavLink('notifications.php', 'notifications', $active, 'Notifications', $__icon_notifications); ?>
            <?php __sidebarNavLink('announcements.php', 'announcements', $active, 'Announcements', $__icon_announcements); ?>
            <?php __sidebarNavLink('my_profile.php', 'my_profile', $active, 'My Profile', $__icon_my_profile); ?>
        <?php elseif ($__role === 'finance'): ?>
            <?php __sidebarNavLink('finance_dashboard.php', 'finance_dashboard', $active, 'Dashboard', $__icon_dashboard); ?>
            <?php __sidebarNavLink('finance.php', 'finance', $active, 'Finance', $__icon_finance); ?>
            <?php __sidebarNavLink('inventory_reports.php', 'inventory_reports', $active, 'Inventory & Financial Reports', $__icon_inv_reports); ?>
            <?php __sidebarNavLink('accounts_payable.php', 'accounts_payable', $active, 'Accounts Payable', $__icon_accounts_payable); ?>
            <?php __sidebarNavLink('financial_statements.php', 'financial_statements', $active, 'Financial Statements', $__icon_financial_statements); ?>
            <?php __sidebarNavLink('financial_analysis.php', 'financial_analysis', $active, 'Financial Analysis', $__icon_financial_analysis); ?>
            <?php __sidebarNavLink('budgeting.php', 'budgeting', $active, 'Budgeting', $__icon_budgeting); ?>
            <?php __sidebarNavLink('financial_audit_log.php', 'financial_audit_log', $active, 'Financial Audit Log', $__icon_audit_log); ?>
            <?php __sidebarNavLink('purchase_orders.php', 'purchase_orders', $active, 'Purchase Orders', $__icon_purchase_orders); ?>
            <?php __sidebarNavLink('operating_expenses.php', 'operating_expenses', $active, 'Operating Expenses', $__icon_operating_expenses); ?>
            <?php __sidebarNavLink('expense_categories.php', 'expense_categories', $active, 'Expense Categories', $__icon_expense_categories); ?>
            <?php __sidebarNavLink('vendor_ratings.php', 'vendor_ratings', $active, 'Supplier Ratings', $__icon_vendor_ratings); ?>
            <?php __sidebarNavLink('notifications.php', 'notifications', $active, 'Notifications', $__icon_notifications); ?>
            <?php __sidebarNavLink('announcements.php', 'announcements', $active, 'Announcements', $__icon_announcements); ?>
            <?php __sidebarNavLink('my_profile.php', 'my_profile', $active, 'My Profile', $__icon_my_profile); ?>
        <?php else: ?>
            <?php __sidebarNavLink('pos.php', 'pos', $active, 'Point of Sale', $__icon_pos); ?>
            <?php __sidebarNavLink('scanner.php?return='.rawurlencode($__scanner_return), 'scanner', $active, 'Scan QR / Barcode', $__icon_reports); ?>
            <?php __sidebarNavLink('shift_report.php', 'shift_report', $active, 'Shift Report', $__icon_shift_report); ?>
            <?php if ($__role === 'cashier'): ?>
                <?php __sidebarNavLink('notifications.php', 'notifications', $active, 'Notifications', $__icon_notifications); ?>
            <?php endif; ?>
            <?php __sidebarNavLink('announcements.php', 'announcements', $active, 'Announcements', $__icon_announcements); ?>
            <?php __sidebarNavLink('my_profile.php', 'my_profile', $active, 'My Profile', $__icon_my_profile); ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-account-group">
        <?php if ($__role === 'admin'): ?>
            <a href="HRMS/admin_dashboard.php" class="nav-link nav-link--back-big">
                <span class="nav-icon-box"><?= $__icon_back_hrms ?></span>
                <span class="nav-label">Back to HRMS</span>
            </a>
        <?php else: ?>
            <a href="logout.php" class="nav-link nav-link--logout-big" onclick="return confirmNav(event, 'logout.php', {icon:'question', title:'Log out?', text:'Are you sure you want to leave your staff session?', confirmButtonText:'Yes, log out', confirmButtonColor:'#2563EB'})">
                <span class="nav-icon-box"><?= $__icon_logout ?></span>
                <span class="nav-label">Logout</span>
            </a>
        <?php endif; ?>
    </div>

</aside>

<?php
$__sidebarCssPath = __DIR__ . '/../assets/css/sidebar.css';
$__sidebarCssUrl = systemBaseUrl() . '/assets/css/sidebar.css?v=' . (filemtime($__sidebarCssPath) ?: time()) . '&rev=2';
$__topbarCssPath = __DIR__ . '/../assets/css/topbar.css';
$__topbarJsPath = __DIR__ . '/../assets/js/shared-topbar.js';
$__topbarConfig = [
    'name' => $__full_name !== '' ? $__full_name : 'User',
    'role' => $__foot_role,
    'initial' => $__initial ?: '?',
    'avatarUrl' => !empty($__avatarFile)
        ? systemBaseUrl() . '/uploads/avatars/' . rawurlencode(basename((string) $__avatarFile))
        : '',
    'showNotifications' => in_array($__role, ['admin', 'inventory_staff', 'finance', 'cashier'], true),
    'notificationUnreadCount' => in_array($__role, ['admin', 'inventory_staff', 'finance', 'cashier'], true)
        ? getVisibleUnreadNotificationCount($pdo, $__role, currentPosUserId())
        : 0,
    'notificationsUrl' => systemUrl('notifications.php'),
    'notificationsLabel' => 'POS notifications',
    'profileUrl' => $__role === 'admin'
        ? systemUrl('HRMS/admin_profile.php')
        : systemUrl('my_profile.php'),
    'logoutUrl' => systemUrl('logout.php'),
    'themeStorageKey' => 'siaa_theme_' . (string) $__uid,
];
?>
<link rel="stylesheet" href="<?= htmlspecialchars($__sidebarCssUrl, ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(systemUrl('assets/css/topbar.css') . '?v=' . (filemtime($__topbarCssPath) ?: time()), ENT_QUOTES, 'UTF-8') ?>">
<script type="application/json" id="sharedTopbarConfig"><?= json_encode($__topbarConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
<script src="<?= htmlspecialchars(systemUrl('assets/js/shared-topbar.js') . '?v=' . (filemtime($__topbarJsPath) ?: time()), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php $__mobileNavJsPath = __DIR__ . '/../assets/js/mobile-navigation.js'; ?>
<script src="<?= htmlspecialchars(systemUrl('assets/js/mobile-navigation.js') . '?v=' . (filemtime($__mobileNavJsPath) ?: time()), ENT_QUOTES, 'UTF-8') ?>" defer></script>

<script>
(function () {
    var shell  = document.querySelector('.app-shell');
    var toggle = document.getElementById('sidebarToggle');
    if (!shell || !toggle) return;

    var STORAGE_KEY = 'siaa_sidebar_collapsed';

    function applyState(collapsed) {
        shell.classList.toggle('sidebar-collapsed', collapsed);
        document.body.classList.toggle('sidebar-collapsed', collapsed);
        setTitle(collapsed);
    }

    function setTitle(collapsed) {
        var label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
        toggle.setAttribute('title', label);
        toggle.setAttribute('aria-label', label);
    }
    applyState(localStorage.getItem(STORAGE_KEY) === '1');

    toggle.addEventListener('click', function () {
        var collapsed = !shell.classList.contains('sidebar-collapsed');
        localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
        applyState(collapsed);
    });

    window.addEventListener('pageshow', function () {
        applyState(localStorage.getItem(STORAGE_KEY) === '1');
    });
    window.addEventListener('storage', function (event) {
        if (event.key === STORAGE_KEY) {
            applyState(event.newValue === '1');
        }
    });
})();

</script>
<script src="<?= htmlspecialchars(systemBaseUrl() . '/assets/js/scroll-preservation.js?v=' . (filemtime(__DIR__ . '/../assets/js/scroll-preservation.js') ?: time()), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<script src="<?= htmlspecialchars(systemBaseUrl() . '/theme-sync.js?v=3', ENT_QUOTES, 'UTF-8') ?>"></script>