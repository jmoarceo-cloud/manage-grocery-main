<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test must run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$failures = [];

function source(string $path): string
{
    global $root;
    $contents = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
    if ($contents === false) {
        throw new RuntimeException("Could not read {$path}");
    }
    return $contents;
}

function check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$hrmsConfig = source('HRMS/includes/config.php');
$posConfig = source('includes/config.php');
$employeeSchedule = source('HRMS/employee_schedule.php');
$supervisorSchedule = source('HRMS/supervisor_schedule.php');
$profileCard = source('HRMS/includes/personal_info_card.php');
$posProfileCard = source('includes/personal_info_card.php');
$posProfile = source('my_profile.php');
$posPage = source('pos.php');
$catalog = source('catalog.php');
$catalogAccess = source('catalog_supervisor_login.php');
$scanner = source('scanner.php');
$scanEndpoint = source('scan.php');
$productDetail = source('product_detail.php');
$logout = source('logout.php');
$publicBootstrap = source('includes/public_bootstrap.php');
$resetRequest = source('password_reset_request.php');
$hrmsLogin = source('HRMS/login.php');
$resetFlow = source('includes/password_reset.php');
$resetStyles = source('assets/css/password-reset.css');
$resetCompletion = source('password_reset.php');
$resetApproval = source('HRMS/password_reset_requests.php');
$posAdminDashboard = source('dashboard.php');
$posSidebar = source('includes/sidebar.php');
$mobileNavigation = source('assets/js/mobile-navigation.js');
$hrLetters = source('HRMS/hr_letters.php');
$hrLetterBootstrap = source('HRMS/includes/hr_letters.php');
$applicantReview = source('HRMS/applicant_review.php');
$hrEmployees = source('HRMS/employees.php');
$hrLetterPrint = source('HRMS/hr_letter_print.php');
$hrLetterDownload = source('HRMS/hr_letter_download.php');
$hrLetterSigned = source('HRMS/hr_letter_signed.php');
$productsPage = source('products.php');
$suppliersPage = source('suppliers.php');
$catalogSetting = source('HRMS/supervisor_catalog_security.php');
$posMailer = source('includes/mailer.php');
$hrmsMailer = source('HRMS/includes/mailer.php');
$hrNotifications = source('HRMS/hr_notifications.php');
$supervisorSidebar = source('HRMS/includes/supervisor_sidebar.php');
$employeeDashboard = source('HRMS/employee_dashboard.php');
$posNotifications = source('notifications.php');
$posSidebar = source('includes/sidebar.php');
$sharedTopbar = source('assets/js/shared-topbar.js');
$sharedTopbarCss = source('assets/css/topbar.css');
$hrAdminDashboard = source('HRMS/admin_dashboard.php');

check(
    strpos($hrmsConfig, 'processSelfTimeInOut (time in) error:') !== false
    && strpos($hrmsConfig, 'Time In could not be recorded. Please try again or contact HR.') !== false,
    'HRMS self Time In database failures must be logged and reported without a fatal error'
);
check(
    strpos($posConfig, 'function processCashierSelfTimeInOut($hrmsPdo, $employee_id)') !== false
    && strpos($posConfig, "Time In/Out cannot be processed.") !== false
    && strpos($posProfile, 'processCashierSelfTimeInOut($hrmsPdo, $myEmployeeId)') !== false,
    'POS employee Time In must use the linked HRMS database and handle it being unavailable'
);
check(
    strpos($posConfig, 'if (!$latValid || !$lngValid || !$accuracyValid || !$locationPolicy[\'valid\'])') !== false
    && strpos($posConfig, 'validateAttendanceLocation($lat, $lng, $accuracy)') !== false
    && strpos($posPage, 'Your GPS location will be captured when you Time In.') !== false
    && strpos($posPage, 'navigator.geolocation') !== false
    && strpos($posConfig, 'POS_CASHIER_TIMEIN_LOCATION_REQUIRED') === false
    && strpos($posPage, 'POS_CASHIER_TIMEIN_LOCATION_REQUIRED') === false
    && strpos($posPage, 'GPS is temporarily disabled') === false,
    'POS cashier Time In must require a valid GPS geofence reading'
);
foreach ([$employeeSchedule, $supervisorSchedule] as $schedule) {
    check(
        strpos($schedule, "return '-';") !== false
        && strpos($schedule, "preg_match('/^\\d{2}:\\d{2}(?::\\d{2})?$/',") !== false,
        'Schedule views must render missing or invalid shift times as a dash'
    );
}
foreach ([
    [$employeeSchedule, 'employeeScheduleTime'],
    [$supervisorSchedule, 'supervisorScheduleTime'],
] as [$schedule, $functionName]) {
    if (preg_match('/function ' . preg_quote($functionName, '/') . '\(\$time\) \{\r?\n.*?^\}/ms', $schedule, $match)) {
        eval ($match[0]);
        check($functionName('09:00:00') === '9:00 AM', "{$functionName} must format a valid shift time");
        check($functionName(null) === '-', "{$functionName} must show a dash for a missing shift time");
        check($functionName('24:00') === '-', "{$functionName} must reject invalid shift times");
    } else {
        check(false, "{$functionName} must be defined for schedule display");
    }
}
check(
    strpos($catalogAccess, "password_verify(\$password, \$passwordHash)") !== false
    && strpos($catalogAccess, "\$_SESSION['catalog_private_kiosk'] = true") !== false
    && strpos($catalogAccess, "\$_SESSION['catalog_private_authorized'] = true") !== false
    && strpos($catalogAccess, "requireCSRF();") !== false
    && strpos($catalogAccess, 'name="username"') === false
    && strpos($catalogAccess, "login.php?return=catalog") === false
    && strpos($catalogAccess, 'twofa') === false
    && strpos($catalogAccess, 'type="password"') !== false,
    'Private catalog gate must use only the Supervisor-configured password and must not require a staff login or 2FA'
);
check(
    strpos($publicBootstrap, 'function public_write_pdo(): PDO') !== false
    && strpos($resetRequest, 'CREATE TABLE IF NOT EXISTS rate_limit_public') !== false
    && strpos($resetRequest, "pub_rate_limit(\$pdo, 'password_reset_request'") !== false
    && strpos($resetRequest, 'password_reset_request_csrf') !== false
    && strpos($resetRequest, 'hash_equals(') !== false
    && strpos($resetFlow, 'function ensurePublicPasswordResetRequestStorage(') !== false
    && strpos($resetRequest, 'ensurePublicPasswordResetRequestStorage($pdo)') !== false
    && strpos($resetStyles, '.password-reset-card') !== false,
    'Password reset requests must use the writable database, rate-limit, validate CSRF, provision storage, and use shared styling'
);
check(
    strpos($resetRequest, 'Password reset request received') !== false
    && strpos($resetRequest, 'acknowledgement email could not be sent') !== false
    && strpos($resetApproval, 'The request could not be emailed.') !== false,
    'Password reset request and approval email failures must be reported explicitly'
);
check(
    strpos($resetFlow, 'bin2hex(random_bytes(32))') !== false
    && strpos($resetFlow, 'hash(\'sha256\', $token)') !== false
    && strpos($resetFlow, "\$_SERVER['SERVER_NAME']") !== false
    && strpos($resetFlow, "\$_SERVER['HTTP_HOST']") === false
    && strpos($resetFlow, 'time() + 1800') !== false
    && strpos($resetFlow, "(int) (\$user['is_active'] ?? 0) !== 1") !== false
    && strpos($resetFlow, '$approve->rowCount() !== 1') !== false
    && strpos($resetFlow, '$pdo->rowCount()') === false
    && strpos($resetFlow, 'password_reset.php?system=') !== false
    && strpos($resetFlow, '&token=') !== false
    && strpos($resetFlow, 'rawurlencode($rawToken)') !== false
    && strpos($resetFlow, "htmlspecialchars(\$url, ENT_QUOTES, 'UTF-8')") !== false
    && strpos($resetFlow, 'sendSystemEmail($email, (string) ($user[\'full_name\'] ?? \'User\'), \'Password reset link\', $body)') !== false,
    'Admin/HR approval must email a URL containing a cryptographically random, 30-minute reset token'
);
check(
    strpos($hrmsLogin, 'password_reset_request.php?system=hrms') !== false
    && strpos($resetRequest, "\$_POST['account_system']") !== false
    && strpos($resetRequest, 'public_hrms_write_pdo()') !== false
    && strpos($resetRequest, 'account_system, user_id') !== false,
    'HRMS password reset requests must resolve and retain the HRMS account target'
);
check(
    strpos($resetApproval, 'account_system') !== false
    && strpos($resetApproval, '$accountSystem === \'hrms\' ? $pdo : $posPdo') !== false
    && strpos($resetApproval, '$eligiblePendingCount') !== false
    && strpos($resetApproval, 'FILTER_VALIDATE_EMAIL') !== false
    && strpos($resetApproval, 'disabled title=') !== false,
    'Password request approvals must resolve the correct POS/HRMS account, expose email blockers, and prevent ineligible links'
);
check(
    strpos($hrLetters, 'hr_letter_edit.php?id=<?= enc_id(') !== false
    && strpos($hrLetters, 'hr_letter_print.php?id=<?= enc_id(') !== false
    && strpos($hrLetters, 'hr_letter_download.php?id=<?= enc_id(') !== false
    && strpos($hrLetters, 'hr_letter_signed.php?id=<?= enc_id(') !== false
    && strpos($hrLetterBootstrap, "foreach (['id', 'applicant_id']") !== false
    && strpos($hrLetterBootstrap, '$__hrLetterId = dec_id($__hrLetterIdToken)') !== false
    && strpos($hrLetterBootstrap, 'ctype_digit($__hrLetterIdToken)') !== false
    && strpos($applicantReview, 'hr_letter_edit.php?applicant_id=<?= enc_id(') !== false
    && strpos($hrEmployees, 'hr_letter_edit.php?applicant_id=<?= enc_id(') !== false
    && strpos($hrLetterPrint, "require_once 'includes/hr_letters.php'") !== false
    && strpos($hrLetterDownload, "require_once 'includes/hr_letters.php'") !== false
    && strpos($hrLetterSigned, "require_once 'includes/hr_letters.php'") !== false,
    'HR letter and applicant record URLs must use opaque IDs and decode them through the shared receiver'
);
check(
    strpos($suppliersPage, 'products.php?supplier_id=<?= enc_id($supplier[\'id\']) ?>') !== false
    && strpos($productsPage, '$decodedSupplierId = dec_id($supplierFilterToken)') !== false
    && strpos($productsPage, 'value="<?= enc_id($sup[\'id\']) ?>"') !== false,
    'Supplier record IDs in product filter URLs must be opaque and decoded before querying'
);
check(
    strpos($hrAdminDashboard, '>Reset</button>') !== false
    && strpos($hrAdminDashboard, 'strlen($new_password) < 8') !== false
    && strpos($hrAdminDashboard, "preg_match('/[A-Za-z]/', \$new_password)") !== false
    && strpos($hrAdminDashboard, "preg_match('/\\d/', \$new_password)") !== false
    && strpos(preg_replace('/\s+/', ' ', $hrAdminDashboard), 'minlength="8" pattern=') !== false,
    'Admin account reset must use the short Reset label and require an 8-character password with a letter and number'
);
check(
    strpos($posAdminDashboard, 'id="dashboardMenuToggle"') === false
    && strpos($posSidebar, 'id="sidebarToggle"') !== false
    && strpos($mobileNavigation, "topbar.insertBefore(menuButton, topbar.firstChild)") !== false
    && strpos($mobileNavigation, "event.key === 'Escape'") !== false,
    'Admin dashboard collapse control must stay in the sidebar and mobile navigation must use the shared responsive toggle'
);
check(
    strpos($sharedTopbar, 'data-confirm-logout') !== false
    && strpos($sharedTopbar, "event.target.closest('a[data-confirm-logout]')") !== false
    && strpos($sharedTopbar, "logoutLink.hasAttribute('data-confirm')") !== false
    && strpos($sharedTopbar, 'Are you sure you want to log out?') !== false
    && strpos($sharedTopbar, "confirmButtonText: 'Log Out'") !== false
    && strpos($sharedTopbar, "cancelButtonText: 'Cancel'") !== false,
    'Admin logout must require explicit confirmation'
);
check(
    strpos(source('HRMS/includes/hr_sidebar.php'), 'data-confirm-logout="true"') !== false
    && strpos(source('HRMS/includes/supervisor_sidebar.php'), 'data-confirm-logout="true"') !== false
    && strpos(source('HRMS/assets/hrms-sidebar.js'), "profileLogoutLink.setAttribute('data-confirm-logout', 'true')") !== false,
    'HRMS and Supervisor logout links must use the shared confirmation dialog'
);
check(
    strpos($resetFlow, 'token_hash = ?') !== false
    && strpos($resetFlow, "r.status = 'approved'") !== false
    && strpos($resetFlow, 'r.email_sent_at IS NOT NULL') !== false
    && strpos($resetFlow, 'r.token_expires_at > NOW()') !== false
    && strpos($resetFlow, 'r.used_at IS NULL') !== false,
    'Reset token lookup must validate its hash, approval, successful delivery, expiry, and unused state'
);
check(
    strpos($resetCompletion, 'WHERE request_id = ? AND token_hash = ? AND status = \'approved\'') !== false
    && strpos($resetCompletion, 'AND email_sent_at IS NOT NULL AND token_expires_at > NOW()') !== false
    && strpos($resetCompletion, 'AND used_at IS NULL') !== false
    && strpos($resetCompletion, '$consume->rowCount() !== 1') !== false
    && strpos($resetCompletion, "SET used_at = NOW(), status = 'handled'") !== false
    && strpos($resetCompletion, 'password_hash($password, PASSWORD_DEFAULT)') !== false
    && strpos($resetCompletion, 'strlen($password) < 8') !== false
    && strpos($resetCompletion, 'minlength="8"') !== false
    && strpos($resetCompletion, 'data-password-target="password"') !== false
    && strpos($resetCompletion, 'data-password-target="password_confirm"') !== false
    && strpos($resetCompletion, "input.type = showPassword ? 'text' : 'password'") !== false
    && strpos($resetStyles, '.password-reset-visibility') !== false
    && strpos($resetStyles, 'background: #fff;') !== false
    && strpos($resetCompletion, 'strlen($password) < 12') === false,
    'Password reset must atomically consume the emailed token once and change the account password'
);
check(
    strpos($catalog, "!empty(\$_SESSION['catalog_private_kiosk'])") !== false
    && strpos($catalog, "!empty(\$_SESSION['catalog_private_authorized'])") !== false
    && strpos($catalog, 'time() - 1800') !== false
    && strpos($catalog, "\$privateCatalogUrl = 'catalog.php?'") !== false
    && strpos($catalog, "header('Location: ' . \$privateCatalogUrl)") !== false
    && strpos($catalogAccess, "\$_SESSION['catalog_private_kiosk']") !== false
    && strpos($catalogAccess, "unset(") !== false
    && strpos($catalogAccess, "catalog_private_granted_at") !== false
    && strpos($catalogSetting, 'requireSupervisorRole();') !== false
    && strpos($catalogSetting, 'password_hash($password, PASSWORD_DEFAULT)') !== false
    && strpos($catalogSetting, "strlen(\$password) < 8") !== false
    && strpos($catalogSetting, "catalog_private_password_hash") !== false
    && strpos($catalogAccess, 'session_regenerate_id(true)') !== false,
    'Private mode must stay kiosk-locked until a Supervisor enters the configured password to exit'
);
check(
    strpos($scanner, "\$hasPrivateCatalogAccess = !empty(\$_SESSION['catalog_private_kiosk'])") !== false
    && strpos($scanner, "\$_SESSION['catalog_private_authorized']") !== false
    && strpos($scanner, "\$isCatalogKioskRequest && \$hasPrivateCatalogAccess") !== false,
    'The kiosk QR scanner must require the private catalog password session and return to private browsing'
);
check(
    strpos($scanner, '$scanReturnKey') !== false
    && strpos($scanner, "destinationUrl.searchParams.set('return', scanReturnKey)") !== false
    && strpos($scanEndpoint, '$returnTargets = [') !== false
    && strpos($scanEndpoint, "rawurlencode(\$returnKey)") !== false
    && strpos($scanEndpoint, '$returnQuery') !== false
    && strpos($scanEndpoint, 'name="return" value="<?= public_escape($returnKey) ?>"') !== false
    && strpos($productDetail, "'admin' => ['href' => 'dashboard.php'") !== false
    && strpos($productDetail, '$detailBackLabel') !== false
    && strpos($productDetail, '$detailReturnQuery') !== false
    && strpos($productDetail, "\$productSearchAction = in_array(\$returnKey, ['admin', 'inventory'], true) ? 'products.php'") !== false,
    'Admin scanner product lookups must keep their dashboard return context through product details'
);
check(
    strpos($logout, "redirect('catalog_supervisor_login.php?exit=1')") !== false,
    'The catalog logout route must require the Supervisor password before leaving kiosk mode'
);
foreach ([$posMailer, $hrmsMailer] as $mailer) {
    check(
        strpos($mailer, 'System email delivery failed:') !== false
        && strpos($mailer, 'return false;') !== false,
        'Both mailers must log delivery failures and return failure to callers'
    );
}
check(
    strpos($profileCard, 'name="action" value="update_personal_info"') !== false
    && strpos($posProfileCard, 'name="action" value="update_personal_info"') !== false
    && strpos($profileCard, '$__piPosted') !== false
    && strpos($posProfileCard, '$__piPosted') !== false
    && strpos($hrmsConfig, 'function processPersonalInfoUpdate($pdo, $employeeId)') !== false
    && strpos($posConfig, 'function processPersonalInfoUpdate($hrmsPdo, $employeeId)') !== false,
    'Profile edit must submit to the HRMS employee record on both HRMS and POS pages'
);
check(
    strpos($posConfig, "['admin', 'inventory_staff', 'finance', 'cashier']") !== false
    && strpos($posNotifications, '$__scopeSql = "(n.user_id = :uid"') !== false
    && strpos($posNotifications, '$__seesLegacyGlobal = in_array($__role, [\'admin\', \'inventory_staff\'], true)') !== false,
    'Cashier notifications must be available without exposing shared low-stock or HR alerts'
);
check(
    strpos($hrNotifications, "['supervisor', 'employee', 'accountant']") !== false
    && strpos($hrNotifications, 'getSupervisorTeamEmployeeIds($pdo, (int) ($_SESSION[\'employee_id\'] ?? 0))') !== false
    && strpos($hrNotifications, 'WHERE r.status = \'pending\' AND r.employee_id IN ({$placeholders})') !== false
    && strpos($hrNotifications, 'WHERE employee_id = ?') !== false
    && strpos($hrNotifications, "FROM attendance") !== false
    && strpos($hrNotifications, 'getEmployeeShiftForDate($pdo, $employeeId, $shiftDate)') !== false,
    'HRMS notifications must use current team or employee-owned request, attendance, schedule, performance, and payroll records'
);
check(
    strpos($supervisorSidebar, 'href="hr_notifications.php"') !== false
    && strpos($employeeDashboard, 'class="employee-notifications" href="hr_notifications.php"') !== false
    && strpos($posSidebar, '\'showNotifications\' => in_array($__role, [\'admin\', \'inventory_staff\', \'finance\', \'cashier\'], true)') !== false
    && strpos($hrmsConfig, 'function getHrmsNotificationBadgeCount(') !== false
    && strpos($posConfig, 'function getVisibleUnreadNotificationCount(') !== false
    && strpos($sharedTopbar, 'function updateNotificationBadge(') !== false
    && strpos($sharedTopbar, 'existingBell.href = config.notificationsUrl') !== false
    && strpos($sharedTopbar, 'config.notificationsLabel ||') !== false
    && strpos($sharedTopbar, 'existingActions.classList.add(\'topbar-actions--shared\')') !== false
    && strpos($sharedTopbarCss, 'border-radius: var(--radius-sm, 8px)') !== false
    && strpos($hrAdminDashboard, 'rtrim(appBaseUrl(), \'/\') . \'/hr_notifications.php\'') !== false
    && strpos($posNotifications, '$__announcementAlerts') !== false
    && strpos($posNotifications, 'INSERT IGNORE INTO announcement_reads') !== false
    && strpos($posNotifications, '$__totalUnreadCount > 0') !== false,
    'Supervisor, Employee, and Cashier notification entry points must be wired into their current headers or navigation'
);
check(
    strpos($posConfig, "function checkLowStock(\$pdo)") !== false
    && strpos($posConfig, "n.message LIKE 'Low stock alert:%'") !== false
    && strpos($posConfig, 'isset($keptByProduct[$productId])') !== false
    && strpos($posConfig, 'SET is_archived = 1') !== false
    && strpos($sharedTopbar, 'existingBell.href = config.notificationsUrl') !== false
    && strpos($sharedTopbar, 'config.notificationsLabel ||') !== false,
    'POS low-stock inbox must deduplicate active alerts and each application bell must keep its configured inbox destination'
);

if ($failures) {
    fwrite(STDERR, "Reported flow contract test failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Reported flow contract test passed: Time In, schedules, catalog access, reset email, profiles, and role-scoped notifications.\n";
