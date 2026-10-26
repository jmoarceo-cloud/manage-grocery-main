<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test must run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$checks = [
    'HRMS/apply.php' => [
        'APPLICANT_PRIVACY_NOTICE_VERSION',
        'privacy_consent_at',
        'Please review and acknowledge the applicant privacy notice',
        'csrfField()',
    ],
    'HRMS/privacy_request.php' => [
        'csrfField()',
        'INSERT INTO data_subject_requests',
        '$pdo->prepare(',
    ],
    'HRMS/privacy_requests.php' => [
        'requireAdmin()',
        'csrfField()',
        'identity_verified',
        'INSERT INTO hr_audit_log',
    ],
    'HRMS/cron/erase_rejected_applicants.php' => [
        "PHP_SAPI !== 'cli'",
        "status = 'rejected'",
        'data_subject_requests',
        'applicant_documents',
        'job_applicant_logs',
        'in_array',
        "'--execute'",
    ],
    'HRMS/privacy_response_download.php' => [
        'privacyResponseSignature',
        'dec_id($requestIdToken)',
        "status'] !== 'fulfilled'",
        'readfile($filepath)',
        'response_expires_at',
    ],
    'HRMS/includes/privacy_response_links.php' => [
        'hash_hmac(',
        'enc_id($requestId)',
        'privacyResponseSigningKey',
        'privacy_response_signing_key',
    ],
    'includes/export.php' => [
        'function exportPostForm',
        'function requireExportPostCsrf',
        'requireCSRF();',
        "format       ENUM('xlsx','pdf','csv')",
    ],
    'HRMS/employee_document_download.php' => [
        'requireLogin();',
        '$isHrAccess',
        '$isOwnerAccess',
        'not allowed to download this document.',
    ],
    'HRMS/includes/config.php' => [
        'ensureApplicantPrivacySchema',
        'privacy_notice_version',
        'privacy_consent_at',
        'CREATE TABLE IF NOT EXISTS data_subject_requests',
    ],
    'assets/css/theme.css' => [
        ':root',
        '[data-theme="dark"]',
        '--accent: #2563eb',
    ],
];

$failures = [];
foreach ($checks as $file => $needles) {
    $contents = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file));
    if ($contents === false) {
        $failures[] = "Could not read {$file}.";
        continue;
    }
    foreach ($needles as $needle) {
        if (strpos($contents, $needle) === false) {
            $failures[] = "{$file} is missing required privacy control marker: {$needle}";
        }
    }
}

if ($failures) {
    fwrite(STDERR, "Privacy controls contract test failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Privacy controls contract test passed: notice/consent, DSAR intake and review, retention job, and theme tokens.\n";
