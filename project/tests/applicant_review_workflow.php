<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../HRMS/includes/applicant_actions.php';
require_once __DIR__ . '/../HRMS/includes/hr_letters.php';

function applicantWorkflowAssert($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

class ApplicantWorkflowStatement extends PDOStatement {
    private $result;
    public function __construct($result) { $this->result = $result; }
    public function execute(?array $params = null): bool { return true; }
    public function fetchColumn(int $column = 0): mixed { return $this->result; }
}

class ApplicantWorkflowPDO extends PDO {
    public $result;
    public $query;
    public $params;
    public function __construct($result) { $this->result = $result; }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->query = $query;
        return new ApplicantWorkflowStatement($this->result);
    }
}

applicantWorkflowAssert(
    normalizeApplicantIds(['4', 'invalid', 0, -2, '4', '9']) === [4, 9],
    'Bulk applicant IDs must be positive, unique integers.'
);
applicantWorkflowAssert(normalizeApplicantIds([], '7') === [7], 'Single applicant ID fallback failed.');
applicantWorkflowAssert(normalizeApplicantIds('5') === [5], 'Scalar applicant selection failed.');

$signedContractDb = new ApplicantWorkflowPDO(17);
applicantWorkflowAssert(hasSignedApplicantContract($signedContractDb, 42), 'A signed applicant contract should be accepted.');
applicantWorkflowAssert(strpos($signedContractDb->query, "letter_type = 'contract'") !== false, 'Only contract letters may satisfy the hiring guard.');
applicantWorkflowAssert(strpos($signedContractDb->query, "status IN ('sent', 'approved', 'accepted')") !== false, 'Unsigned drafts must not satisfy the hiring guard.');
applicantWorkflowAssert(strpos($signedContractDb->query, 'recipient_signature_at IS NOT NULL') !== false, 'A drawn signature must include its recorded signature timestamp.');

$unsignedContractDb = new ApplicantWorkflowPDO(false);
applicantWorkflowAssert(!hasSignedApplicantContract($unsignedContractDb, 42), 'An unsigned applicant must not pass the contract guard.');

$actions = file_get_contents(__DIR__ . '/../HRMS/includes/applicant_actions.php');
$queue = file_get_contents(__DIR__ . '/../HRMS/applicant_review_queue.php');
$review = file_get_contents(__DIR__ . '/../HRMS/applicant_review.php');
$employeeReview = file_get_contents(__DIR__ . '/../HRMS/employees.php');
$contractMigration = file_get_contents(__DIR__ . '/../database/migrations/2026_09_25_hr_contract_workflow.sql');
$letters = file_get_contents(__DIR__ . '/../HRMS/hr_letters.php');
$letterEditor = file_get_contents(__DIR__ . '/../HRMS/hr_letter_edit.php');
$letterRecipient = file_get_contents(__DIR__ . '/../HRMS/letter_recipient.php');
$letterHelpers = file_get_contents(__DIR__ . '/../HRMS/includes/hr_letters.php');
foreach ([$actions, $queue, $review, $employeeReview, $contractMigration, $letters, $letterEditor, $letterRecipient, $letterHelpers] as $source) {
    applicantWorkflowAssert($source !== false, 'Could not read an applicant workflow source file.');
}
foreach (['requireCSRF()', 'isAdminRole()', 'hasSignedApplicantContract($pdo, $applicant_id)', 'beginTransaction()', 'FOR UPDATE'] as $required) {
    applicantWorkflowAssert(strpos($actions, $required) !== false, "Applicant action handler is missing {$required}.");
}
applicantWorkflowAssert(strpos($actions, 'Only HR Staff or Admin can perform this action.') !== false, 'Existing HR Staff/Admin action permissions were changed.');
applicantWorkflowAssert(strpos($queue, 'name="applicant_ids[]"') !== false, 'The queue does not allow selecting individual applicants.');
applicantWorkflowAssert(strpos($queue, 'Select all eligible') !== false, 'The queue does not offer a select-all workflow.');
applicantWorkflowAssert(strpos($review, 'A signed contract is required before hiring.') !== false, 'Applicant review does not explain the contract requirement.');
applicantWorkflowAssert(strpos($employeeReview, 'A signed contract is required before hiring.') !== false, 'Employees applicant review does not explain the contract requirement.');
foreach (['applicant_id', 'recipient_signature', 'recipient_signature_at'] as $column) {
    applicantWorkflowAssert(strpos($contractMigration, 'ADD COLUMN ' . $column) !== false, "The contract workflow migration is missing {$column}.");
}
applicantWorkflowAssert(
    strpos($letters, "\$letter['letter_type'] === 'contract' && !empty(\$letter['applicant_id'])") !== false
        && strpos($letters, 'hrLetterEmail($pdo, $letter)') !== false
        && strpos($letters, "SET status = 'sent', sent_at = NOW()") !== false
        && strpos($letters, "An Admin or HR Manager must approve it here; after approval, it will be emailed to the applicant for e-signature.") !== false
        && strpos($letters, '<strong>Pending approval</strong>') !== false
        && strpos($letters, 'value="send">Send email</button>') !== false
        && strpos($letters, 'APP_PUBLIC_BASE_URL') !== false
        && strpos($letters, 'Resend e-sign email') !== false,
    'Approving an applicant contract must automatically email it for e-signature and mark it sent only after successful delivery.'
);
applicantWorkflowAssert(
    strpos($letterEditor, 'contenteditable="true"') !== false
        && strpos($letterEditor, 'CONTRACTUAL EMPLOYMENT AGREEMENT') !== false
        && strpos($letterEditor, "\$applicantRecord['status'] !== 'for_admin_approval'") !== false
        && strpos($letterEditor, "a.status IN ('for_resume', 'for_initial', 'for_final', 'for_requirements', 'for_admin_approval')") !== false
        && strpos($letterEditor, 'email matches an existing employee profile') !== false
        && substr_count($letterEditor, '<label>Employee</label>') === 1,
    'Applicant contract creation must use a formatted editor, list final-stage applicants, and show any existing employee email match.'
);
applicantWorkflowAssert(
    strpos($letterHelpers, 'Review and e-sign contract') !== false
        && strpos($letterHelpers, 'hrLetterContractDetails($letter)') !== false
        && strpos($letterHelpers, "rtrim(appFullBaseUrl(), '/') . '/letter_recipient.php?token='") !== false
        && strpos($letterHelpers, 'function hrLetterEmailHtml(') !== false
        && strpos($letterHelpers, 'role="presentation"') !== false
        && strpos($letterHelpers, 'function hrLetterEmailText(') !== false
        && strpos($letterHelpers, 'This is an official automated message from the Human Resources Department') !== false
        && strpos($letterHelpers, "'HCI GroceryOne | Human Resources'") !== false
        && strpos($letterRecipient, 'Please draw your electronic signature before accepting the contract.') !== false
        && strpos($letterRecipient, 'recipient_signature_at = CASE') !== false,
    'The formal contract email must use an absolute link, show its terms, and require an electronic signature before acceptance.'
);
putenv('APP_PUBLIC_BASE_URL=https://contracts.example.test');
$applicantLink = hrLetterUrl(str_repeat('a', 64));
putenv('APP_PUBLIC_BASE_URL');
applicantWorkflowAssert(
    strpos($applicantLink, 'https://contracts.example.test/') === 0
        && strpos($applicantLink, '/letter_recipient.php?token=' . str_repeat('a', 64)) !== false,
    'Applicant contract email links must use the configured public origin and point to the e-signature page.'
);
$emailLetter = [
    'subject' => 'Contractual Employment Agreement',
    'recipient_name' => 'Jamie Applicant',
    'letter_type' => 'contract',
    'applicant_id' => 12,
    'reference_no' => 'LTR-TEST',
    'contract_duration_label' => '6 months',
    'contract_start_date' => '2026-10-01',
    'contract_end_date' => '2027-03-31',
    'body_html' => '<p>Agreement terms</p>',
];
$emailHtml = hrLetterEmailHtml($emailLetter, $applicantLink);
$emailText = hrLetterEmailText($emailLetter, $applicantLink);
applicantWorkflowAssert(
    strpos($emailHtml, 'Dear Jamie Applicant,') !== false
        && strpos($emailHtml, 'Contract duration') !== false
        && strpos($emailHtml, 'Review and e-sign contract') !== false
        && strpos($emailHtml, 'role="presentation"') !== false
        && strpos($emailText, 'Contract duration: 6 months') !== false
        && strpos($emailText, 'Start date: October 1, 2026') !== false
        && strpos($emailText, 'Agreement terms') !== false
        && strpos($emailText, 'Review and e-sign contract:') !== false,
    'Formal contract email output must retain its salutation, formatted terms, dates, signature action, and plain-text fallback.'
);
applicantWorkflowAssert(
    hrLetterBody('<p onclick="alert(1)"><strong style="color:red">Contract terms</strong></p>') === '<p><strong>Contract terms</strong></p>',
    'The formatted letter body must keep allowed formatting while removing unsafe tag attributes.'
);
$contractBody = hrLetterContractTemplate('Jamie Applicant', 'Cashier', 'probationary');
applicantWorkflowAssert(
    strpos($contractBody, 'Jamie Applicant') !== false
        && strpos($contractBody, 'Cashier') !== false
        && strpos($contractBody, 'Compensation and pay schedule') !== false
        && strpos(hrLetterContractDetails([
            'letter_type' => 'contract',
            'contract_duration_label' => '6 months',
            'contract_start_date' => '2026-10-01',
            'contract_end_date' => '2027-03-31',
        ]), 'October 1, 2026') !== false,
    'Contract starter body and emailed terms must include applicant context and formatted contract dates.'
);
applicantWorkflowAssert(
    strpos($actions, 'the applicant\'s email is already linked to an employee profile') !== false,
    'Final hiring must prevent duplicate employee profiles when an applicant email already exists.'
);

echo "Applicant review workflow checks passed.\n";
