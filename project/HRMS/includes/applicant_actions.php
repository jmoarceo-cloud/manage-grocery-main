<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}



require_once __DIR__ . '/mailer.php';

// Susunod na employee code (EMP-0001, EMP-0002, ...)
if (!function_exists('generateEmployeeCode')) {
    function generateEmployeeCode($pdo) {
        $stmt = $pdo->query("SELECT employee_code FROM employees ORDER BY employee_id DESC LIMIT 1");
        $last = $stmt->fetchColumn();
        if (!$last) {
            return 'EMP-0001';
        }
        return 'EMP-' . str_pad(((int) substr($last, 4)) + 1, 4, '0', STR_PAD_LEFT);
    }
}

function applicantActionList() {
    return [
        'advance_to_interview', 'pass_initial', 'pass_final', 'confirm_hire',
        'resend_requirements_email',
        'set_orientation_date', 'reject_applicant',
        'upload_applicant_document', 'delete_applicant_document',
    ];
}

// Max size, shared by all requirements regardless of type. Which
// extensions are allowed varies PER requirement — see
// applicantDocAllowedExtensions() in includes/config.php (e.g. Valid
// Government ID / 2x2 ID Picture are JPEG-only, everything else is
// PDF-only).
define('APPLICANT_DOC_MAX_BYTES', 5 * 1024 * 1024); // 5 MB
define('APPLICANT_DOC_DIR', __DIR__ . '/../uploads/applicant_documents/');

// Valid na petsa ng interview: tamang format at hindi nakaraan.
function isValidInterviewDate($d) {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && $d >= date('Y-m-d');
}

function normalizeApplicantIds($ids, $singleId = 0) {
    if (!is_array($ids)) {
        $ids = [$ids];
    }
    if (empty($ids) && (int) $singleId > 0) {
        $ids = [$singleId];
    }
    return array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
        return $id > 0;
    })));
}

function hasSignedApplicantContract($pdo, $applicant_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT id
            FROM hr_letters
            WHERE applicant_id = ?
              AND letter_type = 'contract'
              AND status IN ('sent', 'approved', 'accepted')
              AND (
                  (signed_file IS NOT NULL AND signed_file <> '')
                  OR (recipient_signature IS NOT NULL AND recipient_signature <> ''
                      AND recipient_signature_at IS NOT NULL)
              )
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([(int) $applicant_id]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log("Signed applicant contract check failed (applicant $applicant_id): " . $e->getMessage());
        return false;
    }
}

// Applicant requirement key (APPLICANT_REQUIRED_DOCUMENTS in config.php)
// -> matching label sa $DOCUMENT_TYPES ng employee_documents.php.
// Walang exact match na 'Birth Certificate' o '2x2 ID Picture' sa employee
// side kaya doon na lang sa 'Other' isasama, pero panatilihin ang orihinal
// na label sa notes para malinaw pa rin kung ano talaga ito.
define('APPLICANT_TO_EMPLOYEE_DOC_TYPE', [
    'nbi_clearance'      => 'NBI Clearance',
    'barangay_clearance' => 'Barangay Clearance',
    'birth_certificate'  => 'Other',
    'sss'                => 'SSS Registration',
    'philhealth'         => 'PhilHealth Registration',
    'pagibig'            => 'Pag-IBIG Registration',
    'tin'                => 'TIN / BIR Form',
    'valid_id'           => 'Valid ID',
    'id_picture'         => 'Other',
    'medical_cert'       => 'Medical Certificate',
]);

// Kinokopya ang mga na-upload na requirement files ng applicant (Valid ID,
// NBI Clearance, atbp.) papunta sa Employee Documents ng bagong-hire, para
// hindi na sila magsimula sa blangkong Documents tab. Tinatawag ito sa loob
// ng confirm_hire, kaya guaranteed na kumpleto na ang lahat ng requirements
// (see applicantMissingDocuments()) bago ito tumakbo.
function copyApplicantDocumentsToEmployee($pdo, $applicant_id, $employee_id, $performed_by) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM applicant_documents WHERE applicant_id = ?");
        $stmt->execute([$applicant_id]);
        $docs = $stmt->fetchAll();
        if (empty($docs)) {
            return;
        }

        $destDir = __DIR__ . '/../uploads/employee_documents/';
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }
        if (!is_dir($destDir) || !is_writable($destDir)) {
            error_log("copyApplicantDocumentsToEmployee: uploads/employee_documents/ is missing or not writable — skipping document copy for employee_id $employee_id.");
            return;
        }
        $insertStmt = $pdo->prepare("
            INSERT INTO employee_documents (employee_id, document_type, file_name, original_name, uploaded_by, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($docs as $doc) {
            $srcPath = APPLICANT_DOC_DIR . $doc['file_name'];
            if (!is_file($srcPath)) {
                continue; // baka na-delete/nawala na yung physical file — laktawan na lang
            }

            $ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
            $newStoredName = 'doc_' . bin2hex(random_bytes(12)) . ($ext !== '' ? '.' . $ext : '');
            if (!copy($srcPath, $destDir . $newStoredName)) {
                continue;
            }

            $originalLabel  = APPLICANT_REQUIRED_DOCUMENTS[$doc['document_type']] ?? $doc['document_type'];
            $employeeDocType = APPLICANT_TO_EMPLOYEE_DOC_TYPE[$doc['document_type']] ?? 'Other';
            $notes = 'Auto-copied from applicant requirements (' . $originalLabel . ').';

            $insertStmt->execute([
                $employee_id,
                $employeeDocType,
                $newStoredName,
                $doc['original_name'],
                $performed_by,
                $notes,
            ]);
        }
    } catch (PDOException $e) {
        // Hindi natin gustong mabigo yung buong hiring kapag may isyu lang
        // sa pagkopya ng documents — i-log na lang at ituloy.
        error_log("copyApplicantDocumentsToEmployee error: " . $e->getMessage());
    }
}

// Shared core of the requirements upload: validates and saves every
// file in a document[KEY] batch for one applicant. Used by BOTH the
// HR-side upload (upload_applicant_document below, $performed_by =
// the logged-in HR user's id) AND the applicant's own self-service
// portal (requirements_submit.php, $performed_by = null — meaning
// "submitted by the applicant themselves", not an HR user). Pulled
// out on its own so both call sites share the exact same validation
// (size/extension/magic-byte checks) instead of drifting apart.
function saveApplicantRequirementFiles($pdo, $applicant_id, $files, $performed_by) {
    $uploaded_labels = [];
    $row_errors      = [];

    if (!is_dir(APPLICANT_DOC_DIR)) {
        @mkdir(APPLICANT_DOC_DIR, 0755, true);
    }

    foreach ($files['name'] as $doc_type => $file_name) {
        $file_error = $files['error'][$doc_type] ?? UPLOAD_ERR_NO_FILE;

        // Blangkong slot (walang pinili para dito) -> laktawan.
        if ($file_error === UPLOAD_ERR_NO_FILE || $file_name === '') {
            continue;
        }

        if (!array_key_exists($doc_type, APPLICANT_REQUIRED_DOCUMENTS)) {
            // Hindi dapat mangyari galing sa sariling form, pero
            // sino'ng nakakaalam — laktawan na lang nang tahimik.
            continue;
        }

        $label = APPLICANT_REQUIRED_DOCUMENTS[$doc_type];

        if ($file_error !== UPLOAD_ERR_OK) {
            $row_errors[] = "$label: there was a problem with the upload.";
            continue;
        }

        $file_tmp  = $files['tmp_name'][$doc_type] ?? '';
        $file_size = $files['size'][$doc_type] ?? 0;

        if ($file_size > APPLICANT_DOC_MAX_BYTES) {
            $row_errors[] = "$label: file is too large (max 5 MB).";
            continue;
        }

        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowedExt = applicantDocAllowedExtensions($doc_type);
        if (!in_array($ext, $allowedExt, true)) {
            $row_errors[] = "$label: file type not supported (only " . implode(', ', $allowedExt) . ").";
            continue;
        }

        // Item 13 — extension says .pdf/.jpg but content isn't
        // really that format (magic-byte check, same pattern as
        // apply.php resume upload). Reject before it touches the
        // filesystem. Which check runs depends on what this
        // particular requirement is supposed to be (see
        // APPLICANT_DOC_IMAGE_TYPES in includes/config.php).
        $isImageType = in_array($doc_type, APPLICANT_DOC_IMAGE_TYPES, true);
        $signatureOk = $isImageType ? isValidJpegSignature($file_tmp) : isValidPdfSignature($file_tmp);
        if (!$signatureOk) {
            $row_errors[] = $isImageType
                ? "$label: that file doesn't look like a valid JPEG image."
                : "$label: that file doesn't look like a valid PDF.";
            continue;
        }

        $stored = 'apdoc_' . bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file($file_tmp, APPLICANT_DOC_DIR . $stored)) {
            $row_errors[] = "$label: the file was not saved. Please try again.";
            continue;
        }

        try {
            // Kung may dating file para sa requirement na ito, burahin ito.
            $old = $pdo->prepare("SELECT file_name FROM applicant_documents WHERE applicant_id = ? AND document_type = ?");
            $old->execute([$applicant_id, $doc_type]);
            if ($oldFile = $old->fetchColumn()) {
                @unlink(APPLICANT_DOC_DIR . $oldFile);
            }

            $pdo->prepare("
                INSERT INTO applicant_documents (applicant_id, document_type, file_name, original_name, uploaded_by)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    file_name = VALUES(file_name),
                    original_name = VALUES(original_name),
                    uploaded_by = VALUES(uploaded_by),
                    uploaded_at = CURRENT_TIMESTAMP
            ")->execute([$applicant_id, $doc_type, $stored, $file_name, $performed_by]);

            logApplicantAction($pdo, $applicant_id, 'document_uploaded', $label);
            $uploaded_labels[] = $label;
        } catch (PDOException $e) {
            error_log("Upload applicant document error ($doc_type): " . $e->getMessage());
            @unlink(APPLICANT_DOC_DIR . $stored);
            $row_errors[] = "$label: the document could not be saved. Please try again.";
        }
    }

    return ['uploaded_labels' => $uploaded_labels, 'row_errors' => $row_errors];
}

// -------------------------------------------------------------
// PUBLIC SELF-SERVICE UPLOAD (requirements_submit.php) — walang
// login/session, token na lang ang nagpapatunay na ito talaga ang
// tamang applicant. Ginagaya ang parehong resulta/summary text ng
// HR-side upload sa taas, pero galing na ito sa sariling salita ng
// applicant (hindi "HR uploaded" pero "you submitted").
// -------------------------------------------------------------
function handlePublicRequirementsUpload($pdo, $applicant_id, $token, $filesInput) {
    $res = ['success' => '', 'error' => ''];

    if ($applicant_id <= 0 || empty($token)) {
        $res['error'] = "This link is invalid.";
        return $res;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM job_applicants WHERE applicant_id = ?");
        $stmt->execute([$applicant_id]);
        $applicant = $stmt->fetch();

        if (!$applicant || empty($applicant['requirements_token']) || !hash_equals($applicant['requirements_token'], (string) $token)) {
            $res['error'] = "This link is invalid.";
            return $res;
        }

        if (!in_array($applicant['status'], ['for_requirements', 'for_admin_approval'], true)) {
            $res['error'] = "This link is no longer active — your application isn't currently at the requirements stage.";
            return $res;
        }

        if (!$filesInput || !is_array($filesInput['name'] ?? null)) {
            $res['error'] = "Choose at least one file to upload.";
            return $res;
        }

        $out = saveApplicantRequirementFiles($pdo, $applicant_id, $filesInput, null);
        $uploaded_labels = $out['uploaded_labels'];
        $row_errors      = $out['row_errors'];

        if (empty($uploaded_labels) && empty($row_errors)) {
            $res['error'] = "Choose at least one file to upload.";
            return $res;
        }

        $summary = '';
        if (!empty($uploaded_labels)) {
            $summary = count($uploaded_labels) === 1
                ? "Thank you — your " . $uploaded_labels[0] . " has been submitted."
                : "Thank you — " . count($uploaded_labels) . " document(s) submitted: " . implode(', ', $uploaded_labels) . ".";

            // Direct HR staff's attention to this submission instead of
            // them having to keep re-checking applicant_review.php.
            global $posPdo;
            notifyApplicantRequirementSubmitted(
                $posPdo,
                $applicant['first_name'] . ' ' . $applicant['last_name'],
                $applicant['position_applied'],
                $uploaded_labels
            );
        }

        // Kumpleto na ba lahat? -> handa nang tingnan ng Admin
        if ($applicant['status'] === 'for_requirements' && applicantRequirementsComplete($pdo, $applicant_id)) {
            $pdo->prepare("UPDATE job_applicants SET status = 'for_admin_approval' WHERE applicant_id = ?")
                ->execute([$applicant_id]);
            logApplicantAction($pdo, $applicant_id, 'requirements_complete', 'All requirements are complete (submitted by the applicant).');
            $summary .= " All your requirements are now complete — our HR team will review them shortly.";
        } elseif (!empty($uploaded_labels)) {
            $missing = count(applicantMissingDocuments($pdo, $applicant_id));
            if ($missing > 0) {
                $summary .= " $missing requirement(s) still remaining.";
            }
        }

        if (!empty($row_errors)) {
            $res['error'] = implode(' ', $row_errors);
        }
        if ($summary !== '') {
            $res['success'] = trim($summary);
        }
    } catch (PDOException $e) {
        error_log("Public applicant requirements upload error: " . $e->getMessage());
        $res['error'] = "Something went wrong while saving your document(s). Please try again.";
    }

    return $res;
}

function processApplicantAction($pdo) {
    $res = ['success' => '', 'error' => '', 'handled' => false];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $res;
    }
    $action = $_POST['action'] ?? '';
    if (!in_array($action, applicantActionList(), true)) {
        return $res;
    }

    requireCSRF();
    $res['handled'] = true;

    // HR Manager ay view-only sa applicant pipeline (oversight role lang) —
    // ang confirm_hire/set_orientation_date ay meron nang sariling isAdminRole()
    // check sa baba, pero ang ibang actions (pass/reject/upload/delete) ay para
    // lang sa HR Staff + Admin.
    $hrStaffOnlyActions = [
        'advance_to_interview', 'pass_initial', 'pass_final', 'reject_applicant',
        'upload_applicant_document', 'delete_applicant_document', 'resend_requirements_email',
    ];
    if (in_array($action, $hrStaffOnlyActions, true) && !(isAdminRole() || isHRStaffRole())) {
        $res['error'] = "Only HR Staff or Admin can perform this action.";
        return $res;
    }

    $applicant_id   = (int) ($_POST['applicant_id'] ?? 0);
    $reason         = trim($_POST['reason'] ?? '');           // required sa reject, optional sa iba
    $interview_date = trim($_POST['interview_date'] ?? '');   // advance_to_interview / pass_initial

    // -------------------------------------------------
    // ADVANCE TO INTERVIEW (Resume Screening -> Initial Interview)
    // -------------------------------------------------
    // Replaces the old one-applicant-at-a-time "Pass Resume" step.
    // HR reviews ALL resumes first (via the resume modal in
    // employees.php), then filters out who moves on to the Initial
    // Interview — either one at a time (a single ID) or as a batch
    // (several IDs, all sharing one interview date). Accepts
    // applicant_ids[] (batch, from the list's checkbox toolbar) and
    // also falls back to applicant_id (single, from the resume modal's
    // inline form), so both call sites can share this one handler.
    if ($action === 'advance_to_interview') {

        $ids = normalizeApplicantIds($_POST['applicant_ids'] ?? [], $applicant_id);

        if (!isValidInterviewDate($interview_date)) {
            $res['error'] = "Select a valid Initial Interview date (not a past date).";
            return $res;
        }
        if (empty($ids)) {
            $res['error'] = "Select at least one applicant to advance to the Initial Interview.";
            return $res;
        }

        $advancedNames = [];
        $skipped       = 0;
        foreach ($ids as $aid) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT * FROM job_applicants WHERE applicant_id = ? AND status = 'for_resume' FOR UPDATE");
                $stmt->execute([$aid]);
                $applicant = $stmt->fetch();

                if (!$applicant) {
                    $pdo->rollBack();
                    $skipped++;
                    continue;
                }

                $update = $pdo->prepare("
                    UPDATE job_applicants
                    SET status = 'for_initial', initial_interview_date = ?, reviewed_by = ?
                    WHERE applicant_id = ? AND status = 'for_resume'
                ");
                $update->execute([$interview_date, $_SESSION['user_id'], $aid]);
                if ($update->rowCount() !== 1) {
                    $pdo->rollBack();
                    $skipped++;
                    continue;
                }

                logApplicantAction($pdo, $aid, 'advance_to_interview', $reason);
                $pdo->commit();
                try {
                    $mailSent = sendInterviewScheduleEmail(
                        $applicant['email'],
                        $applicant['first_name'] . ' ' . $applicant['last_name'],
                        $applicant['position_applied'],
                        'initial',
                        $interview_date
                    );
                } catch (Throwable $mailError) {
                    error_log("Initial interview email error (applicant $aid): " . $mailError->getMessage());
                    $mailSent = false;
                }
                $advancedNames[] = $applicant['first_name'] . ' ' . $applicant['last_name'] . ($mailSent ? '' : ' (email FAILED)');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Advance to interview error (applicant $aid): " . $e->getMessage());
                $skipped++;
            }
        }

        if (empty($advancedNames)) {
            $res['error'] = "Could not advance the selected applicant(s) — they may have already been processed.";
        } else {
            $who = (count($advancedNames) <= 3)
                ? implode(', ', $advancedNames)
                : (count($advancedNames) . ' applicants');
            $res['success'] = "Advanced $who to the Initial Interview ("
                . date('M d, Y', strtotime($interview_date)) . "). The schedule has been emailed to them.";
            if ($skipped > 0) {
                $res['success'] .= " ($skipped skipped — already processed.)";
            }
        }
    }

    // -------------------------------------------------
    // PASS INITIAL -> Final Interview (may petsa + email)
    // -------------------------------------------------
    elseif ($action === 'pass_initial') {

        if (!isValidInterviewDate($interview_date)) {
            $res['error'] = "Select a valid Final Interview date (not a past date).";
            return $res;
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM job_applicants WHERE applicant_id = ? AND status = 'for_initial'");
            $stmt->execute([$applicant_id]);
            $applicant = $stmt->fetch();

            if (!$applicant) {
                $res['error'] = "Applicant not found or this has already been processed.";
            } else {
                $pdo->prepare("
                    UPDATE job_applicants
                    SET status = 'for_final', final_interview_date = ?, reviewed_by = ?
                    WHERE applicant_id = ?
                ")->execute([$interview_date, $_SESSION['user_id'], $applicant_id]);

                logApplicantAction($pdo, $applicant_id, 'pass_initial', $reason);
                $mailSent = sendInterviewScheduleEmail(
                    $applicant['email'],
                    $applicant['first_name'] . ' ' . $applicant['last_name'],
                    $applicant['position_applied'],
                    'final',
                    $interview_date
                );
                if ($mailSent) {
                    $res['success'] = "Passed the Initial Interview. The Final Interview schedule has been sent via email ("
                        . date('M d, Y', strtotime($interview_date)) . ").";
                } else {
                    // The DB update above already succeeded — only the email
                    // failed (e.g. SMTP/app-password issue). Say so clearly
                    // instead of falsely claiming it was emailed; see
                    // error_log for "Mailer error:" for the exact cause.
                    $res['success'] = "Passed the Initial Interview (Final Interview: "
                        . date('M d, Y', strtotime($interview_date))
                        . "), BUT the email to the applicant FAILED to send. Please inform them manually or check the mailer settings.";
                }
            }
        } catch (PDOException $e) {
            error_log("Pass initial error: " . $e->getMessage());
            $res['error'] = "Could not process this applicant. Please try again.";
        }
    }

    // -------------------------------------------------
    // PASS FINAL -> Requirements stage (deadline only). Orientation
    // date is intentionally NOT set here anymore — in real life you
    // don't schedule orientation until the applicant has actually
    // finished their requirements and is about to be hired. That's
    // handled later by the set_orientation_date action, once (admin
    // only), after confirm_hire.
    // -------------------------------------------------
    elseif ($action === 'pass_final') {

        $deadline = trim($_POST['requirements_deadline'] ?? '');
        if (!isValidInterviewDate($deadline)) {
            $res['error'] = "Select a valid requirements deadline (not a past date).";
            return $res;
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM job_applicants WHERE applicant_id = ? AND status = 'for_final'");
            $stmt->execute([$applicant_id]);
            $applicant = $stmt->fetch();

            if (!$applicant) {
                $res['error'] = "Applicant not found or this has already been processed.";
            } else {
                $pdo->prepare("
                    UPDATE job_applicants
                    SET status = 'for_requirements', requirements_deadline = ?, reviewed_by = ?
                    WHERE applicant_id = ?
                ")->execute([$deadline, $_SESSION['user_id'], $applicant_id]);

                logApplicantAction($pdo, $applicant_id, 'pass_final', $reason);
                $mailSent = sendRequirementsEmail(
                    $applicant['email'],
                    $applicant['first_name'] . ' ' . $applicant['last_name'],
                    $applicant['position_applied'],
                    $deadline,
                    APPLICANT_REQUIRED_DOCUMENTS,
                    applicantRequirementsPortalUrl($pdo, $applicant_id)
                );
                if ($mailSent) {
                    $res['success'] = "Passed the Final Interview. The list of requirements (deadline: "
                        . date('M d, Y', strtotime($deadline))
                        . ") has been sent via email. The applicant can only be hired once all documents are complete.";
                } else {
                    $res['success'] = "Passed the Final Interview (requirements deadline: "
                        . date('M d, Y', strtotime($deadline))
                        . "), BUT the requirements email FAILED to send. Please inform the applicant manually or check the mailer settings.";
                }
            }
        } catch (PDOException $e) {
            error_log("Pass final error: " . $e->getMessage());
            $res['error'] = "Could not process this applicant. Please try again.";
        }
    }

    // -------------------------------------------------
    // RESEND the requirements email — for applicants already sitting at
    // 'for_requirements' who never got a working copy the first time
    // (e.g. the requirements_token column was missing then, or the
    // portal link pointed at an unreachable host). Re-uses the SAME
    // deadline already on file and the SAME token/link — safe to click
    // more than once.
    // -------------------------------------------------
    elseif ($action === 'resend_requirements_email') {
        try {
            $stmt = $pdo->prepare("SELECT * FROM job_applicants WHERE applicant_id = ? AND status = 'for_requirements'");
            $stmt->execute([$applicant_id]);
            $applicant = $stmt->fetch();

            if (!$applicant) {
                $res['error'] = "Applicant not found or not currently at the requirements stage.";
            } else {
                $mailSent = sendRequirementsEmail(
                    $applicant['email'],
                    $applicant['first_name'] . ' ' . $applicant['last_name'],
                    $applicant['position_applied'],
                    $applicant['requirements_deadline'],
                    APPLICANT_REQUIRED_DOCUMENTS,
                    applicantRequirementsPortalUrl($pdo, $applicant_id)
                );
                if ($mailSent) {
                    $res['success'] = "Requirements email resent to " . $applicant['first_name'] . ' ' . $applicant['last_name'] . ".";
                } else {
                    $res['error'] = "The requirements email FAILED to send again. Please check the mailer settings.";
                }
            }
        } catch (PDOException $e) {
            error_log("Resend requirements email error: " . $e->getMessage());
            $res['error'] = "Could not resend the email. Please try again.";
        }
    }

    // -------------------------------------------------
    // UPLOAD ng isang requirement (HR ang nag-a-upload para sa applicant).
    // Kapag kumpleto na lahat -> awtomatikong pupunta sa Admin approval.
    // -------------------------------------------------
    elseif ($action === 'upload_applicant_document') {

        // Batch upload: ang form ay maaaring maglaman ng ilang file nang
        // sabay-sabay (isa bawat missing requirement na pinili), kaya ang
        // $_FILES['document'] dito ay isang associative array na naka-key
        // sa requirement key mismo — hal. $_FILES['document']['name']['nbi_clearance'].
        // Dahil ang key ay galing sa fixed na name="document[KEY]" ng bawat
        // <input type="file"> (hindi dropdown/free-text), guaranteed na
        // tama ang pagkakatugma ng bawat na-upload na file sa kaukulang
        // requirement — walang paraan para mapunta ang isang file sa
        // maling document type.
        try {
            $stmt = $pdo->prepare("SELECT * FROM job_applicants WHERE applicant_id = ?");
            $stmt->execute([$applicant_id]);
            $applicant = $stmt->fetch();

            if (!$applicant || !in_array($applicant['status'], ['for_requirements', 'for_admin_approval'], true)) {
                $res['error'] = "Uploading is not allowed for this applicant at the current stage.";
                return $res;
            }

            $files = $_FILES['document'] ?? null;
            if (!$files || !is_array($files['name'] ?? null)) {
                $res['error'] = "Choose at least one file to upload.";
                return $res;
            }

            $out = saveApplicantRequirementFiles($pdo, $applicant_id, $files, $_SESSION['user_id']);
            $uploaded_labels = $out['uploaded_labels'];
            $row_errors      = $out['row_errors'];

            if (empty($uploaded_labels) && empty($row_errors)) {
                $res['error'] = "Choose at least one file to upload.";
                return $res;
            }

            $summary = '';
            if (!empty($uploaded_labels)) {
                $summary = count($uploaded_labels) === 1
                    ? "Uploaded the " . $uploaded_labels[0] . "."
                    : "Uploaded " . count($uploaded_labels) . " documents: " . implode(', ', $uploaded_labels) . ".";
            }

            // Kumpleto na ba lahat? -> handa nang i-hire
            if ($applicant['status'] === 'for_requirements' && applicantRequirementsComplete($pdo, $applicant_id)) {
                $pdo->prepare("UPDATE job_applicants SET status = 'for_admin_approval' WHERE applicant_id = ?")
                    ->execute([$applicant_id]);
                logApplicantAction($pdo, $applicant_id, 'requirements_complete', 'All requirements are complete.');
                $summary .= " Requirements are complete — ready to be hired by the Admin.";
            } elseif (!empty($uploaded_labels)) {
                $missing = count(applicantMissingDocuments($pdo, $applicant_id));
                if ($missing > 0) {
                    $summary .= " $missing requirement(s) still missing.";
                }
            }

            if (!empty($row_errors)) {
                $res['error'] = implode(' ', $row_errors);
            }
            if ($summary !== '') {
                $res['success'] = trim($summary);
            }
        } catch (PDOException $e) {
            error_log("Upload applicant document error: " . $e->getMessage());
            $res['error'] = "The document was not saved. Please try again.";
        }
    }

    // -------------------------------------------------
    // REJECT ng isang na-upload na requirement (hal. malabo/hindi
    // mabasa ang ID, o mali ang na-upload). Kailangan ng dahilan, at
    // ini-email agad sa applicant kung ALIN sa mga requirement ang
    // kailangan i-resend at kung bakit — hindi lahat, iisa lang ang
    // mawawala/babalik sa "missing" (yung na-reject), hindi
    // naaapektuhan ang ibang na-submit na nang requirements.
    // -------------------------------------------------
    elseif ($action === 'delete_applicant_document') {

        $document_id = (int) ($_POST['document_id'] ?? 0);

        if ($reason === '') {
            $res['error'] = "Please state why this document is being rejected (e.g. unreadable, wrong document) — this is included in the email sent to the applicant.";
            return $res;
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM applicant_documents WHERE document_id = ?");
            $stmt->execute([$document_id]);
            $doc = $stmt->fetch();

            if (!$doc) {
                $res['error'] = "Document not found.";
                return $res;
            }
            $applicant_id = (int) $doc['applicant_id'];

            $appStmt = $pdo->prepare("SELECT * FROM job_applicants WHERE applicant_id = ?");
            $appStmt->execute([$applicant_id]);
            $applicant = $appStmt->fetch();

            @unlink(APPLICANT_DOC_DIR . $doc['file_name']);
            $pdo->prepare("DELETE FROM applicant_documents WHERE document_id = ?")->execute([$document_id]);

            $label = APPLICANT_REQUIRED_DOCUMENTS[$doc['document_type']] ?? $doc['document_type'];
            logApplicantAction($pdo, $applicant_id, 'document_rejected', "$label — $reason");

            // Kung dati nang handa sa hire pero kulang na ulit -> balik sa requirements
            if ($applicant && $applicant['status'] === 'for_admin_approval' && !applicantRequirementsComplete($pdo, $applicant_id)) {
                $pdo->prepare("UPDATE job_applicants SET status = 'for_requirements' WHERE applicant_id = ?")
                    ->execute([$applicant_id]);
                logApplicantAction($pdo, $applicant_id, 'requirements_incomplete', $label . ' was rejected — missing again.');
            }

            if ($applicant) {
                sendDocumentRejectedEmail(
                    $applicant['email'],
                    $applicant['first_name'] . ' ' . $applicant['last_name'],
                    $applicant['position_applied'],
                    $label,
                    $reason,
                    applicantRequirementsPortalUrl($pdo, $applicant_id)
                );
            }

            $res['success'] = "Rejected $label. The applicant has been emailed and asked to resend just this requirement — the rest of their submitted documents are untouched.";
        } catch (PDOException $e) {
            error_log("Reject applicant document error: " . $e->getMessage());
            $res['error'] = "The document was not rejected. Please try again.";
        }
    }

    // -------------------------------------------------
    // CONFIRM HIRE (ADMIN LANG) -> gumagawa ng employee record
    // -------------------------------------------------
    elseif ($action === 'confirm_hire') {

        if (!isAdminRole()) {
            $res['error'] = "Only the Admin can confirm the final hire.";
            return $res;
        }
        $hired_date = date('Y-m-d');
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM job_applicants WHERE applicant_id = ? AND status = 'for_admin_approval' FOR UPDATE");
            $stmt->execute([$applicant_id]);
            $applicant = $stmt->fetch();

            if (!$applicant) {
                $pdo->rollBack();
                $res['error'] = "Applicant not found or this has already been processed.";
            } else {
                $missing = applicantMissingDocuments($pdo, $applicant_id);
                if (!empty($missing)) {
                    $pdo->rollBack();
                    $res['error'] = "Cannot be hired yet — requirements are still incomplete:" . implode(', ', $missing) . ".";
                    return $res;
                }
                if (!hasSignedApplicantContract($pdo, $applicant_id)) {
                    $pdo->rollBack();
                    $res['error'] = "Cannot be hired yet — this applicant must have a signed contract on file.";
                    return $res;
                }
                $existingEmployee = $pdo->prepare("
                    SELECT employee_id
                    FROM employees
                    WHERE email IS NOT NULL
                      AND TRIM(email) <> ''
                      AND LOWER(TRIM(email)) = LOWER(TRIM(?))
                    LIMIT 1
                    FOR UPDATE
                ");
                $existingEmployee->execute([$applicant['email']]);
                if ($existingEmployee->fetchColumn()) {
                    $pdo->rollBack();
                    $res['error'] = "Cannot complete this hire yet — the applicant's email is already linked to an employee profile. Ask Admin to verify the existing employee record before proceeding, so a duplicate profile is not created.";
                    return $res;
                }

                $update = $pdo->prepare("
                    UPDATE job_applicants SET status = 'hired', decided_by = ?
                    WHERE applicant_id = ? AND status = 'for_admin_approval'
                ");
                $update->execute([$_SESSION['user_id'], $applicant_id]);
                if ($update->rowCount() !== 1) {
                    $pdo->rollBack();
                    $res['error'] = "Applicant not found or this has already been processed.";
                    return $res;
                }

                $employee_code = generateEmployeeCode($pdo);
                // New hires start with payment_method = 'cash' (Walk-in) —
                // a first-day employee almost never has bank/card details
                // on file yet. HR switches this to 'bank' in Employees ->
                // Edit once the employee opens an account.
                $pdo->prepare("
                    INSERT INTO employees
                    (employee_code, first_name, last_name, contact_number, email, position,
                     employment_type, date_hired, basic_salary, status, payment_method,
                     birthdate, address, place_of_birth, sex, civil_status,
                     highest_educational_attainment, year_graduated, character_references)
                    VALUES (?, ?, ?, ?, ?, ?, 'probationary', ?, 0, 'active', 'cash', ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $employee_code,
                    $applicant['first_name'],
                    $applicant['last_name'],
                    $applicant['contact_number'] ?: null,
                    $applicant['email'],
                    $applicant['position_applied'] ?: null,
                    $hired_date,
                    // Everything below came from their application (apply.php) —
                    // carried over so HR/the employee doesn't have to retype it
                    // on Employees -> Edit or my_profile.php "Personal Information".
                    $applicant['date_of_birth'] ?: null,
                    $applicant['home_address'] ?: null,
                    $applicant['place_of_birth'] ?: null,
                    $applicant['sex'] ?: null,
                    $applicant['civil_status'] ?: null,
                    $applicant['highest_educational_attainment'] ?: null,
                    $applicant['year_graduated'] ?: null,
                    $applicant['character_references'] ?: null,
                ]);
                $new_employee_id = (int) $pdo->lastInsertId();

                // Kopyahin ang mga na-upload na applicant requirements papunta
                // sa Employee Documents, para hindi na mag-start sa blangko
                // yung bagong-hire — kumpleto na sila dahil kailangan bago
                // pumasa dito sa confirm_hire (see applicantMissingDocuments above).
                copyApplicantDocumentsToEmployee($pdo, $applicant_id, $new_employee_id, $_SESSION['user_id']);

                logApplicantAction($pdo, $applicant_id, 'hired', $reason);
                $pdo->commit();
                try {
                    $mailSent = sendHiredEmail($applicant['email'], $applicant['first_name'] . ' ' . $applicant['last_name'], $applicant['position_applied']);
                } catch (Throwable $mailError) {
                    error_log("Hired email error (applicant $applicant_id): " . $mailError->getMessage());
                    $mailSent = false;
                }
                $res['success'] = "The applicant has been confirmed as hired. ($employee_code) — hired date: "
                    . date('M d, Y', strtotime($hired_date))
                    . ($mailSent
                        ? ". The email has been sent to the applicant, and they have appeared on the Employees list."
                        : ". The employee record was created, but the email could not be sent. Please inform the applicant manually.")
                    . (empty($applicant['orientation_date'])
                        ? " The orientation date can now be set in Recent Decisions."
                        : " Orientation date: " . date('M d, Y', strtotime($applicant['orientation_date'])) . ".");
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Confirm hire error: " . $e->getMessage());
            $res['error'] = "Could not confirm this applicant. Please try again.";
        }
    }

    // -------------------------------------------------
    // SET ORIENTATION DATE (ADMIN LANG, isang beses lang)
    // -------------------------------------------------
    elseif ($action === 'set_orientation_date') {

        if (!isAdminRole()) {
            $res['error'] = "Only the admin can set the orientation date.";
            return $res;
        }
        $orientation_date = trim($_POST['orientation_date'] ?? '');
        if ($orientation_date === '') {
            $res['error'] = "Select an orientation date.";
            return $res;
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM job_applicants WHERE applicant_id = ? AND status = 'hired'");
            $stmt->execute([$applicant_id]);
            $applicant = $stmt->fetch();

            if (!$applicant) {
                $res['error'] = "Applicant not found or not yet hired.";
            } elseif (!empty($applicant['orientation_date'])) {
                $res['error'] = "The orientation date has already been set. ("
                    . date('M d, Y', strtotime($applicant['orientation_date']))
                    . ")";
            } else {
                $pdo->prepare("UPDATE job_applicants SET orientation_date = ? WHERE applicant_id = ?")
                    ->execute([$orientation_date, $applicant_id]);
                logApplicantAction($pdo, $applicant_id, 'orientation_set', $reason);
                sendOrientationEmail($applicant['email'], $applicant['first_name'] . ' ' . $applicant['last_name'], $applicant['position_applied'], $orientation_date);
                $res['success'] = "The orientation date has been set. The details have already been emailed to the applicant.";
            }
        } catch (PDOException $e) {
            error_log("Set orientation date error: " . $e->getMessage());
            $res['error'] = "Could not set the orientation date. Please try again.";
        }
    }

    // -------------------------------------------------
    // REJECT (kahit anong active stage) — KAILANGAN ang dahilan
    // -------------------------------------------------
    elseif ($action === 'reject_applicant') {

        if ($reason === '') {
            $res['error'] = "A reason is required before rejecting the applicant.";
            return $res;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM job_applicants
                WHERE applicant_id = ?
                  AND status IN ('for_resume', 'for_initial', 'for_final', 'for_admin_approval')
            ");
            $stmt->execute([$applicant_id]);
            $applicant = $stmt->fetch();

            if (!$applicant) {
                $res['error'] = "Applicant not found or this has already been processed";
            } else {
                $pdo->prepare("UPDATE job_applicants SET status = 'rejected', reviewed_by = ? WHERE applicant_id = ?")
                    ->execute([$_SESSION['user_id'], $applicant_id]);
                logApplicantAction($pdo, $applicant_id, 'rejected', $reason);
                sendRejectionEmail($applicant['email'], $applicant['first_name'] . ' ' . $applicant['last_name'], $applicant['position_applied'], $reason);
                $res['success'] = "The applicant was rejected. The reason has already been sent to their email.";
            }
        } catch (PDOException $e) {
            error_log("Reject applicant error: " . $e->getMessage());
            $res['error'] = "Could not reject this applicant. Please try again.";
        }
    }

    return $res;
}