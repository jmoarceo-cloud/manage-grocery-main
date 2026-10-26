<?php
require_once 'includes/hr_letters.php';
requireHR();
$id = (int) ($_GET['id'] ?? 0);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    try {
        $employee = (int) ($_POST['employee_id'] ?? 0);
        $applicant = (int) ($_POST['applicant_id'] ?? 0);
        $name = trim($_POST['recipient_name'] ?? '');
        $email = trim($_POST['recipient_email'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body_html'] ?? '');
        $type = trim($_POST['letter_type'] ?? 'official');
        $startDate = trim($_POST['contract_start_date'] ?? '') ?: null;
        $endDate = trim($_POST['contract_end_date'] ?? '') ?: null;
        $duration = trim($_POST['contract_duration_label'] ?? '') ?: null;
        if (!$employee && !$applicant) {
            throw new RuntimeException('Select an employee or applicant before saving.');
        }
        if ($employee && $applicant) {
            throw new RuntimeException('Select either an employee or an applicant, not both.');
        }
        if ($applicant) {
            $applicantCheck = $pdo->prepare("
                SELECT a.applicant_id, a.first_name, a.last_name, a.email, a.position_applied, a.employment_type, a.status
                FROM job_applicants a
                WHERE a.applicant_id = ?
                  AND a.status IN ('for_resume', 'for_initial', 'for_final', 'for_requirements', 'for_admin_approval')
            ");
            $applicantCheck->execute([$applicant]);
            $applicantRecord = $applicantCheck->fetch();
            if (!$applicantRecord) {
                throw new RuntimeException('Select an active applicant who has not been hired yet.');
            }
            if ($type === 'contract') {
                if ($applicantRecord['status'] !== 'for_admin_approval') {
                    throw new RuntimeException('Applicant contracts can only be prepared after the applicant reaches the final hiring approval stage.');
                }
                $name = trim($applicantRecord['first_name'] . ' ' . $applicantRecord['last_name']);
                $email = trim((string) $applicantRecord['email']);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('This applicant does not have a valid email address for contract delivery.');
                }
            }
        }
        if ($name === '' || $subject === '' || $body === '') {
            throw new RuntimeException('Recipient, subject and letter content are required.');
        }
        if ($type === 'contract') {
            if (!$startDate || !$endDate || $endDate < $startDate) {
                throw new RuntimeException('Contract letters require a valid start date and end date.');
            }
            if (!$duration) {
                throw new RuntimeException('Enter the contract duration, such as 6 months or 30 days.');
            }
        } else {
            $startDate = $endDate = $duration = null;
        }
        $safeBody = hrLetterBody($body);
        if ($id) {
            $existing = $pdo->prepare('SELECT created_by, status FROM hr_letters WHERE id = ?');
            $existing->execute([$id]);
            $existingLetter = $existing->fetch();
            if (!$existingLetter || ((int) $existingLetter['created_by'] !== (int) $_SESSION['user_id']
                && !in_array($_SESSION['role'] ?? '', ['admin', 'hr_manager'], true))) {
                throw new RuntimeException('You are not allowed to edit this letter.');
            }
            if (in_array($existingLetter['status'], ['sent', 'accepted', 'declined'], true)) {
                throw new RuntimeException('This letter has already been sent to the recipient and can no longer be edited.');
            }
            $pdo->prepare("UPDATE hr_letters SET employee_id=?,applicant_id=?,recipient_name=?,recipient_email=?,letter_type=?,contract_start_date=?,contract_end_date=?,contract_duration_label=?,subject=?,body_html=?,status='draft' WHERE id=?")
                ->execute([$employee ?: null, $applicant ?: null, $name, $email, $type, $startDate, $endDate, $duration, $subject, $safeBody, $id]);
        } else {
            $ref = 'LTR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO hr_letters(reference_no,employee_id,applicant_id,created_by,recipient_name,recipient_email,letter_type,contract_start_date,contract_end_date,contract_duration_label,subject,body_html,access_token) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$ref, $employee ?: null, $applicant ?: null, $_SESSION['user_id'], $name, $email, $type, $startDate, $endDate, $duration, $subject, $safeBody, $token]);
            $id = (int) $pdo->lastInsertId();
        }
        hrLetterLog($pdo, $id, 'saved');
        header('Location: hr_letters.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$employees = $pdo->query("SELECT employee_id,first_name,last_name,email FROM employees WHERE status IN ('active','on-leave') ORDER BY last_name,first_name")->fetchAll();
$applicants = $pdo->query("
    SELECT a.applicant_id, a.first_name, a.last_name, a.email, a.position_applied, a.employment_type, a.status,
           EXISTS (
               SELECT 1 FROM employees e
               WHERE LOWER(TRIM(e.email)) = LOWER(TRIM(a.email))
           ) AS has_employee_profile
    FROM job_applicants a
    WHERE a.status IN ('for_resume', 'for_initial', 'for_final', 'for_requirements', 'for_admin_approval')
    ORDER BY a.last_name, a.first_name
")->fetchAll();
$letter = null;
if ($id) {
    $s = $pdo->prepare('SELECT * FROM hr_letters WHERE id=?');
    $s->execute([$id]);
    $letter = $s->fetch();
    if (!$letter) {
        die('Letter not found.');
    }
}
$selectedApplicantId = (int) ($letter['applicant_id'] ?? ($_GET['applicant_id'] ?? 0));
$selectedLetterType = (string) ($letter['letter_type'] ?? ($_GET['letter_type'] ?? 'official'));
$selectedApplicant = null;
foreach ($applicants as $candidate) {
    if ((int) $candidate['applicant_id'] === $selectedApplicantId) {
        $selectedApplicant = $candidate;
        break;
    }
}
$formApplicantId = (int) ($_POST['applicant_id'] ?? $selectedApplicantId);
$formLetterType = (string) ($_POST['letter_type'] ?? $selectedLetterType);
$formRecipientName = (string) ($_POST['recipient_name'] ?? ($letter['recipient_name'] ?? ''));
$formRecipientEmail = (string) ($_POST['recipient_email'] ?? ($letter['recipient_email'] ?? ''));
$formSubject = (string) ($_POST['subject'] ?? ($letter['subject'] ?? ($formLetterType === 'contract' ? 'Contractual Employment Agreement' : '')));
$formStartDate = (string) ($_POST['contract_start_date'] ?? ($letter['contract_start_date'] ?? ''));
$formEndDate = (string) ($_POST['contract_end_date'] ?? ($letter['contract_end_date'] ?? ''));
$formDuration = (string) ($_POST['contract_duration_label'] ?? ($letter['contract_duration_label'] ?? ''));
$formBody = (string) ($_POST['body_html'] ?? ($letter['body_html'] ?? ''));
if ($formBody === '' && $formLetterType === 'contract') {
    $templateApplicant = null;
    foreach ($applicants as $candidate) {
        if ((int) $candidate['applicant_id'] === $formApplicantId) {
            $templateApplicant = $candidate;
            break;
        }
    }
    $formBody = hrLetterContractTemplate(
        $formRecipientName ?: ($templateApplicant ? $templateApplicant['first_name'] . ' ' . $templateApplicant['last_name'] : ''),
        $templateApplicant['position_applied'] ?? '',
        $templateApplicant['employment_type'] ?? ''
    );
} elseif ($formBody === '') {
    $formBody = '<p>Date: ' . date('F j, Y') . '</p><p>Dear [Recipient],</p><p></p><p>Sincerely,<br>Human Resources</p>';
}

hrLetterHeader($id ? 'Edit letter' : 'Create letter');
?>
<style>
.letter-editor{max-width:1000px;margin:0 auto}
.letter-editor-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}
.letter-editor-toolbar p{margin:0;color:var(--ink-soft);font-size:13px}
.letter-card{background:var(--white);border:1px solid var(--line);padding:28px;border-radius:18px;box-shadow:0 10px 28px rgba(15,23,42,.055)}
.letter-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 18px}
.letter-field{min-width:0}
.letter-field--full{grid-column:1/-1}
.letter-card label{color:var(--ink);font-size:11px;font-weight:800;letter-spacing:.01em;display:block;margin:0 0 7px}
.letter-card input,.letter-card select,.letter-card textarea:not([hidden]){display:block;padding:12px 13px;width:100%;min-height:44px;box-sizing:border-box;margin:0 0 16px;border:1px solid var(--line);border-radius:10px;background:var(--white);color:var(--ink);font:inherit;font-size:13px;line-height:1.35}
.letter-card textarea[hidden]{display:none!important}
.letter-card select{cursor:pointer}
.letter-card textarea{min-height:300px;line-height:1.6;resize:vertical}
.letter-body-toolbar{display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding:8px;border:1px solid var(--line);border-bottom:0;border-radius:10px 10px 0 0;background:var(--cream)}
.letter-body-toolbar button{min-width:34px;min-height:32px;padding:0 9px;border:1px solid var(--line);border-radius:7px;background:var(--white);color:var(--ink);font-weight:700;cursor:pointer}
.letter-body-toolbar button:hover{background:var(--green-pale)}
.letter-body-editor{min-height:300px;margin:0 0 16px;padding:14px;border:1px solid var(--line);border-radius:0 0 10px 10px;background:var(--white);color:var(--ink);font:inherit;font-size:13px;line-height:1.65;overflow:auto}
.letter-body-editor:focus{outline:0;border-color:var(--green-mid);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.letter-body-editor p{margin:0 0 12px}
.letter-body-editor ul,.letter-body-editor ol{padding-left:24px}
.contract-body-hint{grid-column:1/-1;margin:-6px 0 14px;color:var(--ink-soft);font-size:12px;line-height:1.5}
.letter-card input:focus,.letter-card select:focus,.letter-card textarea:focus{outline:0;border-color:var(--green-mid);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.letter-card input::placeholder{color:#94a3b8}
.contract-fields{grid-column:1/-1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0 14px;margin-top:2px}
.contract-fields .letter-field{min-width:0}
.form-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;padding-top:4px}
.letter-editor .btn{display:inline-flex;align-items:center;gap:8px;min-height:40px;padding:0 15px;background:var(--green-deep);color:#fff;border:0;border-radius:10px;font-weight:700;text-decoration:none;cursor:pointer}
.letter-editor .btn:hover{background:var(--green-mid);color:#fff}
.letter-editor .btn--light{background:var(--white);color:var(--text-accent);border:1px solid var(--line)}
.letter-editor .btn--light:hover{background:var(--green-pale);color:var(--text-accent)}
.letter-error{color:#b91c1c;background:#fee2e2;border:1px solid #fecaca;border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:13px;font-weight:600}
@media (max-width:760px){.letter-form-grid,.contract-fields{grid-template-columns:1fr}.letter-field--full,.contract-fields{grid-column:auto}}
@media (max-width:640px){.letter-editor-toolbar{align-items:stretch;flex-direction:column}.letter-editor-toolbar .btn{width:100%;justify-content:center}.letter-card{padding:20px}.form-actions{justify-content:stretch}.form-actions .btn{flex:1;justify-content:center}}
</style>
<div class="letter-editor">
    <div class="letter-editor-toolbar">
        <p>Create a clear, review-ready contract or official HR document for an employee or applicant.</p>
        <a class="btn btn--light" href="hr_letters.php"><i class="fa-solid fa-arrow-left"></i> Back to letters</a>
    </div>
    <?php if ($error): ?><div class="letter-error"><?= hrLetterEsc($error) ?></div><?php endif; ?>
    <div class="letter-card">
        <form method="post">
            <?php csrfField(); ?>
            <div class="letter-form-grid">
            <div class="letter-field"><label>Employee</label><select name="employee_id"><option value="">Select an employee</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['employee_id'] ?>" <?= (int) ($_POST['employee_id'] ?? ($letter['employee_id'] ?? 0)) === (int) $employee['employee_id'] ? 'selected' : '' ?>><?= hrLetterEsc($employee['last_name'] . ', ' . $employee['first_name']) ?></option><?php endforeach; ?></select></div>
            <div class="letter-field"><label>Applicant</label><select name="applicant_id"><option value="">Select an applicant</option><?php foreach ($applicants as $applicant): ?><option value="<?= (int) $applicant['applicant_id'] ?>" <?= $formApplicantId === (int) $applicant['applicant_id'] ? 'selected' : '' ?>><?= hrLetterEsc($applicant['last_name'] . ', ' . $applicant['first_name']) ?><?= $applicant['status'] === 'for_admin_approval' ? ' — ready for contract' : ' — ' . hrLetterEsc(ucwords(str_replace('_', ' ', $applicant['status']))) ?><?= !empty($applicant['has_employee_profile']) ? ' — email matches an existing employee profile' : '' ?></option><?php endforeach; ?></select></div>
            <div class="letter-field"><label>Recipient name</label><input name="recipient_name" required value="<?= hrLetterEsc($formRecipientName) ?>"></div>
            <div class="letter-field"><label>Email</label><input type="email" name="recipient_email" value="<?= hrLetterEsc($formRecipientEmail) ?>"></div>
            <div class="letter-field letter-field--full"><label>Letter type</label><select name="letter_type"><?php foreach (['contract','appointment','certificate','notice','official'] as $type): ?><option <?= $formLetterType === $type ? 'selected' : '' ?>><?= $type ?></option><?php endforeach; ?></select></div>
            <div class="contract-fields">
                <div class="letter-field"><label>Contract start date</label><input type="date" name="contract_start_date" value="<?= hrLetterEsc($formStartDate) ?>"></div>
                <div class="letter-field"><label>Contract end date</label><input type="date" name="contract_end_date" value="<?= hrLetterEsc($formEndDate) ?>"></div>
                <div class="letter-field"><label>Contract duration</label><input name="contract_duration_label" placeholder="Example: 6 months" value="<?= hrLetterEsc($formDuration) ?>"></div>
            </div>
            <div class="letter-field letter-field--full"><label>Subject</label><input name="subject" required value="<?= hrLetterEsc($formSubject) ?>"></div>
            <div class="letter-field letter-field--full">
                <label>Letter body</label>
                <div class="letter-body-toolbar" role="toolbar" aria-label="Letter formatting">
                    <button type="button" data-format="bold" aria-label="Bold"><strong>B</strong></button>
                    <button type="button" data-format="italic" aria-label="Italic"><em>I</em></button>
                    <button type="button" data-format="insertUnorderedList" aria-label="Bulleted list">• List</button>
                </div>
                <div class="letter-body-editor" id="letterBodyEditor" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Letter body"><?= hrLetterBody($formBody) ?></div>
                <textarea name="body_html" id="letterBodySource" hidden><?= hrLetterEsc($formBody) ?></textarea>
                <p class="contract-body-hint">Review all highlighted bracketed instructions before sending, including the approved pay, work schedule, and contract dates. Contracts are emailed to the applicant for review and electronic signature after approval.</p>
            </div>
            </div>
            <div class="form-actions"><button class="btn" type="submit"><i class="fa-regular fa-floppy-disk"></i> Save draft</button></div>
        </form>
    </div>
</div>
<script>
const applicantSelect = document.querySelector('[name="applicant_id"]');
const employeeSelect = document.querySelector('[name="employee_id"]');
const recipientEmail = document.querySelector('[name="recipient_email"]');
const applicants = <?= json_encode(array_map(static function ($applicant) {
    return ['id' => (int) $applicant['applicant_id'], 'name' => $applicant['first_name'] . ' ' . $applicant['last_name'], 'email' => $applicant['email'], 'position' => $applicant['position_applied'], 'employmentType' => $applicant['employment_type']];
}, $applicants), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const letterBodyEditor = document.getElementById('letterBodyEditor');
const letterBodySource = document.getElementById('letterBodySource');
let bodyTemplateMode = <?= !$id && !isset($_POST['body_html']) ? 'true' : 'false' ?>;
function syncLetterBody() {
    if (letterBodyEditor && letterBodySource) letterBodySource.value = letterBodyEditor.innerHTML;
}
function escapeLetterText(value) {
    return String(value || '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
}
function fillContractBody(applicant) {
    if (!letterBodyEditor || !bodyTemplateMode) return;
    const name = applicant ? applicant.name : '[Recipient name]';
    const position = applicant && applicant.position ? applicant.position : '[Position title]';
    const employmentType = applicant && applicant.employmentType ? applicant.employmentType : '[Employment type]';
    letterBodyEditor.innerHTML = '<p><strong>CONTRACTUAL EMPLOYMENT AGREEMENT</strong></p>'
        + '<p>Date: <?= date('F j, Y') ?></p>'
        + '<p>Dear ' + escapeLetterText(name) + ',</p>'
        + '<p>We are pleased to offer you employment with HCI GroceryOne, subject to the terms below and the company policies explained to you by Human Resources.</p>'
        + '<p><strong>Position:</strong> ' + escapeLetterText(position) + '<br><strong>Employment type:</strong> ' + escapeLetterText(employmentType)
        + '<br><strong>Contract period:</strong> See the contract details shown with this agreement.</p>'
        + '<p><strong>Compensation and pay schedule:</strong> [Enter the approved salary or rate and pay frequency before sending.]</p>'
        + '<p><strong>Work assignment and schedule:</strong> [Enter the work location, expected schedule, and reporting details.]</p>'
        + '<p>Your employment is subject to applicable Philippine labor laws, company policies, and completion of any remaining pre-employment requirements. Human Resources will confirm your reporting instructions and discuss any questions before your start date.</p>'
        + '<p>Please review this agreement carefully. To accept, use the secure link in the email containing this contract and draw your electronic signature. Your signed response will be recorded with this contract. If you do not agree with a term or need clarification, contact Human Resources before signing.</p>'
        + '<p>Sincerely,<br>Human Resources<br>HCI GroceryOne</p>';
    syncLetterBody();
}
if (letterBodyEditor) {
    letterBodyEditor.addEventListener('input', () => {
        bodyTemplateMode = false;
        syncLetterBody();
    });
    document.querySelectorAll('[data-format]').forEach(button => button.addEventListener('click', () => {
        letterBodyEditor.focus();
        document.execCommand(button.dataset.format, false, null);
        syncLetterBody();
    }));
    letterBodyEditor.closest('form').addEventListener('submit', event => {
        syncLetterBody();
        if (!letterBodyEditor.innerText.trim()) {
            event.preventDefault();
            letterBodyEditor.focus();
        }
    });
}
if (applicantSelect) {
    applicantSelect.addEventListener('change', function () {
        const applicant = applicants.find(item => String(item.id) === this.value);
        if (!applicant) return;
        if (employeeSelect) employeeSelect.value = '';
        document.querySelector('[name="recipient_name"]').value = applicant.name;
        document.querySelector('[name="recipient_email"]').value = applicant.email || '';
        if (recipientEmail) recipientEmail.required = document.querySelector('[name="letter_type"]').value === 'contract';
        const subject = document.querySelector('[name="subject"]');
        if (!subject.value) subject.value = 'Contractual Employment Agreement';
        const type = document.querySelector('[name="letter_type"]');
        type.value = 'contract';
        fillContractBody(applicant);
    });
    if (applicantSelect.value && <?= $id ? 'false' : 'true' ?>) {
        applicantSelect.dispatchEvent(new Event('change'));
    }
}
if (employeeSelect) {
    employeeSelect.addEventListener('change', function () {
        if (!this.value) return;
        if (applicantSelect) applicantSelect.value = '';
        if (recipientEmail) recipientEmail.required = false;
    });
}
document.querySelector('[name="letter_type"]').addEventListener('change', function () {
    if (recipientEmail) recipientEmail.required = this.value === 'contract' && Boolean(applicantSelect && applicantSelect.value);
    if (this.value === 'contract' && bodyTemplateMode) {
        const applicant = applicants.find(item => String(item.id) === (applicantSelect && applicantSelect.value));
        fillContractBody(applicant || null);
    }
});
</script>
<?php hrLetterFooter(); ?>
