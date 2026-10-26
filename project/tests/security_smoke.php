<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test must run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$failures = [];

function check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function source(string $relativePath): string
{
    global $root;
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Could not read {$relativePath}");
    }
    return $contents;
}

$config = source('includes/config.php');
$hrmsConfig = source('HRMS/includes/config.php');
$posLogin = source('login.php');
$hrmsLogin = source('HRMS/login.php');
$twoFactor = source('includes/twofactor.php');
$hrmsTwoFactor = source('HRMS/includes/twofactor.php');
$twoFactorSetup = source('setup_2fa.php');
$mailer = source('includes/mailer.php');

foreach ([
    'product_details_edit.php',
    'products.php',
    'purchase_orders.php',
    'accounts_payable.php',
    'pos.php',
    'HRMS/payroll/save_record.php',
    'HRMS/payroll/approve_record.php',
    'HRMS/payroll/submit_for_approval.php',
] as $endpoint) {
    $contents = source($endpoint);
    check(
        preg_match('/require(?:Login|InventoryStaff|HR|Finance|Admin|CSRF|CSRFJson)\s*\(/', $contents) === 1,
        "{$endpoint} must contain an authentication or authorization guard"
    );
}

foreach ([
    'product_details_edit.php',
    'products.php',
    'purchase_orders.php',
    'accounts_payable.php',
    'pos.php',
    'HRMS/payroll/save_record.php',
    'HRMS/payroll/approve_record.php',
    'HRMS/payroll/submit_for_approval.php',
] as $endpoint) {
    $contents = source($endpoint);
    check(
        strpos($contents, 'requireCSRF(') !== false
        || strpos($contents, 'requireCSRFJson(') !== false,
        "{$endpoint} must enforce CSRF on state-changing requests"
    );
}

check(
    strpos(source('product_details_edit.php'), "if (\$_SERVER['REQUEST_METHOD'] === 'POST')") !== false,
    'product_details_edit.php must restrict writes to POST'
);
check(
    strpos(source('HRMS/payroll/upload_cash_ack.php'), "if (\$_SERVER['REQUEST_METHOD'] !== 'POST')") !== false,
    'upload_cash_ack.php must reject non-POST requests'
);

foreach ([$config, $hrmsConfig] as $contents) {
    check(strpos($contents, "env('SESSION_SECURE'") !== false, 'SESSION_SECURE must control session cookies');
    check(strpos($contents, "'httponly' => true") !== false, 'session cookies must be HttpOnly');
    check(strpos($contents, "'samesite' => 'Lax'") !== false, 'session cookies must use SameSite=Lax');
}

foreach (['products.php', 'inventory.php', 'accounts_payable.php', 'inventory_requests.php', 'purchase_orders.php'] as $endpoint) {
    check(
        strpos(source($endpoint), 'addslashes(clean(') === false,
        "{$endpoint} must not mix HTML escaping with JavaScript escaping"
    );
}

check(
    strpos($hrmsConfig, "ATTENDANCE_MAX_ACCURACY_METERS") !== false
    && strpos($hrmsConfig, "validateAttendanceLocation") !== false
    && strpos($hrmsConfig, "attendance (employee_id, date, time_in") !== false,
    'HRMS attendance must enforce accuracy, geofence, and one record per employee/date'
);

check(
    strpos($twoFactorSetup, 'SMTP_USERNAME') !== false
    && strpos($twoFactorSetup, 'SMTP_PASSWORD') !== false
    && strpos($mailer, 'Gmail App Password') !== false,
    'POS 2FA mail failures must distinguish missing SMTP credentials and explain Gmail App Password setup'
);
check(
    strpos(source('HRMS/payroll/save_record.php'), 'Released payroll cannot be edited.') !== false,
    'Payroll saves must reject changes to released records'
);
foreach ([$posLogin, $hrmsLogin] as $contents) {
    check(strpos($contents, 'session_regenerate_id(true)') !== false, 'Login must regenerate the session ID');
    check(strpos($contents, 'isLoginIpThrottled') !== false, 'Login must enforce IP throttling');
    check(strpos($contents, 'password_verify') !== false, 'Login must verify password hashes');
}
foreach ([$twoFactor, $hrmsTwoFactor] as $contents) {
    check(strpos($contents, 'otp_expires') !== false, '2FA OTPs must expire');
    check(strpos($contents, 'password_hash') !== false, '2FA secrets must be hashed');
    check(strpos($contents, 'twofaRecordFailedAttempt') !== false, '2FA failures must be persisted per account');
}
check(
    strpos($config, 'ALTER TABLE users ADD COLUMN two_factor_failed_attempts') !== false
    && strpos($hrmsConfig, 'ALTER TABLE users ADD COLUMN two_factor_failed_attempts') !== false
    && strpos($twoFactor, "\$_SESSION['tfa_otp_attempts'] =") === false
    && strpos($hrmsTwoFactor, "\$_SESSION['tfa_otp_attempts'] =") === false,
    '2FA attempt limits must survive new sessions in POS and HRMS'
);
check(
    strpos(source('HRMS/resume_download.php'), '$realBase . DIRECTORY_SEPARATOR') !== false
    && strpos(source('HRMS/applicant_document_download.php'), '$realBase . DIRECTORY_SEPARATOR') !== false,
    'HR downloads must enforce directory-boundary checks against traversal and prefix-confusion paths'
);
check(
    strpos(source('HRMS/employees.php'), 'htmlspecialchars($__resumeUrl, ENT_QUOTES, \'UTF-8\')') !== false,
    'Generated resume links must be HTML-escaped'
);
check(
    strpos($config, "ini_set('display_errors', '0')") !== false
    && strpos($hrmsConfig, "ini_set('display_errors', '0')") !== false,
    'Production configuration must disable displayed PHP errors'
);
check(
    strpos(source('uploads/products/.htaccess'), 'php_flag engine off') !== false
    && strpos(source('HRMS/uploads/employee_documents/.htaccess'), 'Require all denied') !== false
    && strpos(source('uploads/receipts/.htaccess'), 'php_flag engine off') !== false,
    'Upload directories must block script execution or direct private access'
);

if ($failures) {
    fwrite(STDERR, "Security smoke test failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Security smoke test passed: authorization, CSRF, POST guards, session cookies, and output-encoding checks.\n";
