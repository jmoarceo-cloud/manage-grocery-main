<?php
// ============================================================
// applicant_review.php — Resume review page for a single applicant
// ------------------------------------------------------------
// Applicant details + Download Resume + Pass / Reject at the bottom.
// Uses the shared includes/applicant_actions.php, so the logic here
// matches the one in employees.php.
// Access: HR Staff + Admin can view AND act. HR Manager can view only
// (oversight role — the dedicated Applicant Review section is viewable
// by HR Manager while HR Staff and Admin can perform actions).
// ============================================================
require_once 'includes/config.php';
require_once 'includes/mailer.php';
require_once 'includes/application_form.php';
require_once 'includes/applicant_actions.php';
requireHR(); // dating requireHRStaff() — nire-redirect palabas ang HR Manager
// kahit nakikita niya ang "Review" button sa employees.php

$success_message = $_SESSION['flash_success'] ?? '';
$error_message = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // PHP gotcha: kapag lumagpas sa server's post_max_size ang KABUUANG laki
    // ng na-submit na request (madaling mangyari dito dahil sinasadyang
    // batch/sabay-sabay ang pag-upload ng ilang document nang sabay —
    // hanggang 10 requirement x 5MB), tuluyang ginagawang WALANG-LAMAN ni
    // PHP ang $_POST at $_FILES (kahit ilan man ang na-type/na-pili) —
    // walang halimbawang action, walang CSRF token. Dahil dito, hindi
    // nakikilala ng dispatcher ang action (''), kaya tahimik lang itong
    // "walang nangyari" sa halip na mag-error nang malinaw. I-detect ito
    // bago pa man tumakbo ang normal na handler, para malinaw ang paliwanag.
    $__postTooLarge = empty($_POST) && empty($_FILES)
        && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;

    if ($__postTooLarge) {
        $error_message = "The files you selected were too large to upload all together (the total exceeded this server's upload limit). Please select fewer files at a time — or smaller ones — then submit again.";
    } else {
        // Perform the action (pass/reject/hire) using the shared handler.
        $__aa = processApplicantAction($pdo);
        if ($__aa['handled']) {
            $success_message = $__aa['success'];
            $error_message = $__aa['error'];
        }
    }

    // Post/Redirect/Get — without this, hitting reload/F5 (or Chrome/Edge's
    // "Resubmit the form?" prompt) after Pass/Reject/Confirm Hire/Upload
    // Document re-sends the same POST and repeats it (e.g. re-hiring the
    // same applicant, re-sending an email).
    $_SESSION['flash_success'] = $success_message;
    $_SESSION['flash_error'] = $error_message;
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: applicant_review.php' . ($qs !== '' ? ('?' . $qs) : ''));
    exit;
}

// Auto-disqualify: the deadline has passed but the requirements are still incomplete.
sweepDisqualifiedApplicants($pdo);

$applicant_id = dec_id($_GET['id'] ?? '');

// Applicant Review is opened from the dedicated Applicant Review queue with
// an encoded applicant ID. If someone opens the page directly, take them to
// that queue instead of showing a dead-end error screen.
if (!$applicant_id) {
    header('Location: applicant_review_queue.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT ja.*, ru.full_name AS reviewed_by_name, du.full_name AS decided_by_name
    FROM job_applicants ja
    LEFT JOIN users ru ON ja.reviewed_by = ru.id
    LEFT JOIN users du ON ja.decided_by  = du.id
    WHERE ja.applicant_id = ?
");
$stmt->execute([$applicant_id]);
$ap = $stmt->fetch();

if (!$ap) {
    header('Location: applicant_review_queue.php');
    exit;
}

// Audit trail of decisions
$logs = [];
try {
    $stmt = $pdo->prepare("
        SELECT l.*, u.full_name AS performed_by_name
        FROM job_applicant_logs l
        LEFT JOIN users u ON u.id = l.performed_by
        WHERE l.applicant_id = ?
        ORDER BY l.log_id DESC
    ");
    $stmt->execute([$applicant_id]);
    $logs = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Applicant logs error: " . $e->getMessage());
}

$field_label_map = getFieldLabelMap($pdo);
$extra = formatCustomResponses($ap['custom_responses'] ?? null, $field_label_map);

function reviewBadge($status)
{
    $map = [
        'for_resume' => ['label' => 'Resume Screening', 'class' => 'review-status--pending'],
        'for_initial' => ['label' => 'Initial Interview', 'class' => 'review-status--pending'],
        'for_final' => ['label' => 'Final Interview', 'class' => 'review-status--pending'],
        'for_requirements' => ['label' => 'For Requirements', 'class' => 'review-status--pending'],
        'for_admin_approval' => ['label' => 'For Final Approval', 'class' => 'review-status--active'],
        'hired' => ['label' => 'Hired', 'class' => 'review-status--active'],
        'rejected' => ['label' => 'Rejected', 'class' => 'review-status--danger'],
        'disqualified' => ['label' => 'Disqualified', 'class' => 'review-status--danger'],
    ];
    return $map[$status] ?? ['label' => ucfirst($status), 'class' => 'review-status--neutral'];
}
$badge = reviewBadge($ap['status']);
$isActive = in_array($ap['status'], ['for_resume', 'for_initial', 'for_final', 'for_requirements', 'for_admin_approval'], true);

// HR Manager: view-only (oversight role) — hindi siya pwede mag-Pass/Reject/
// Upload/Delete ng applicant documents, sa HR Staff at Admin lang ito.
$canAct = isAdminRole() || isHRStaffRole();
$hasSignedContract = $ap['status'] === 'for_admin_approval'
    ? hasSignedApplicantContract($pdo, $applicant_id)
    : false;

// Requirements: shown from the requirements stage onwards.
$showRequirements = in_array($ap['status'], ['for_requirements', 'for_admin_approval', 'hired', 'disqualified'], true);
$uploadedDocs = getApplicantDocuments($pdo, $applicant_id);
$missingDocs = applicantMissingDocuments($pdo, $applicant_id);
$canEditDocs = $canAct && in_array($ap['status'], ['for_requirements', 'for_admin_approval'], true);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Applicant Review — <?= clean($ap['first_name'] . ' ' . $ap['last_name']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet"
        href="assets/hrms-theme.css?v=<?= filemtime(__DIR__ . '/assets/hrms-theme.css') ?: time() ?>">
    <link rel="stylesheet"
        href="<?= htmlspecialchars(appBaseUrl() . '/payroll/assets/style.css?v=' . (filemtime(__DIR__ . '/payroll/assets/style.css') ?: time()), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        .document-status {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .02em;
            margin-right: 6px;
        }

        .document-status--complete {
            color: var(--text-accent);
            background: var(--green-pale);
        }

        .document-status--missing {
            color: var(--ink-soft);
            background: var(--cream);
        }

        .review-status {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border: 1px solid var(--line);
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            vertical-align: middle;
        }

        .review-status--pending {
            color: var(--mustard);
            background: var(--mustard-pale);
            border-color: color-mix(in srgb, var(--mustard) 32%, var(--line));
        }

        .review-status--active {
            color: var(--text-accent);
            background: var(--green-pale);
            border-color: color-mix(in srgb, var(--green-mid) 30%, var(--line));
        }

        .review-status--danger {
            color: var(--red-alert);
            background: var(--red-pale);
            border-color: color-mix(in srgb, var(--red-alert) 30%, var(--line));
        }

        .review-status--neutral {
            color: var(--ink-soft);
            background: var(--cream);
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', Arial, sans-serif;
            background: var(--cream);
            color: var(--ink);
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .topbar {
            background: var(--white);
            border-bottom: 1px solid var(--line);
            padding: 16px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .topbar h1 {
            font-size: 19px;
            color: var(--text-accent);
        }

        .topbar .right {
            font-size: 13.5px;
            color: var(--ink-soft);
            display: flex;
            gap: 16px;
            align-items: center;
        }

        .topbar a.logout {
            color: var(--red-alert);
            font-weight: 600;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 28px 24px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 20px;
        }

        .alert--success {
            background: var(--green-pale);
            color: var(--text-accent);
        }

        .alert--error {
            background: var(--red-pale);
            color: var(--red-alert);
        }

        .card {
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 24px 28px;
            margin-bottom: 20px;
        }

        .card h2 {
            font-size: 16px;
            color: var(--text-accent);
            margin-bottom: 14px;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px 24px;
        }

        .item .label {
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--ink-soft);
        }

        .item .value {
            font-size: 14px;
            font-weight: 600;
            margin-top: 2px;
        }

        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-block;
            transition: filter .15s ease, box-shadow .15s ease, transform .15s ease, border-color .15s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--green-mid), var(--green-deep));
            color: #FFFFFF;
            box-shadow: 0 2px 8px color-mix(in srgb, var(--green-mid) 22%, transparent);
        }

        .btn-primary:hover {
            filter: brightness(1.08);
            box-shadow: 0 5px 16px color-mix(in srgb, var(--green-mid) 32%, transparent);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--ink);
            border: 1.5px solid var(--line);
        }

        .btn-secondary:hover {
            background: var(--cream);
            border-color: var(--green-leaf);
            transform: translateY(-2px);
            box-shadow: 0 3px 10px color-mix(in srgb, var(--green-mid) 8%, transparent);
        }

        .btn-danger {
            background: var(--red-pale);
            color: var(--red-alert);
        }

        .btn-danger:hover {
            background: color-mix(in srgb, var(--red-alert) 14%, var(--red-pale));
            transform: translateY(-2px);
            box-shadow: 0 3px 10px color-mix(in srgb, var(--red-alert) 16%, transparent);
        }

        .btn-sm {
            padding: 7px 14px;
            font-size: 13px;
        }

        .review-page .main-area {
            min-height: 100vh;
        }

        .review-page .container {
            max-width: 1180px;
            margin: 0;
            padding: 26px 32px 52px;
        }

        .review-page .card {
            max-width: none;
            border-radius: 18px;
            padding: 22px 24px;
            margin-bottom: 18px;
        }

        .review-page .card h2 {
            font-size: 17px;
            margin-bottom: 16px;
        }

        .review-page .card>h2:first-child {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .review-page .item .value {
            color: var(--ink);
        }

        .review-page .actions {
            background: var(--green-pale);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 16px;
        }

        .review-page .log {
            border-left-color: var(--green-mid);
            background: var(--green-pale);
            border-radius: 0 10px 10px 0;
            padding: 10px 14px;
        }

        .review-page .review-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .review-page .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 38px;
            padding: 9px 13px;
            border: 1px solid var(--line);
            border-radius: 11px;
            background: var(--green-pale);
            color: var(--green-deep);
            font-weight: 700;
            font-size: 13px;
            line-height: 1.2;
            box-shadow: 0 5px 14px color-mix(in srgb, var(--green-mid) 8%, transparent);
            transition: background .18s ease, border-color .18s ease, box-shadow .18s ease, transform .18s ease;
        }

        .review-page .back-link:hover {
            background: var(--white);
            border-color: var(--green-mid);
            box-shadow: 0 8px 18px color-mix(in srgb, var(--green-mid) 14%, transparent);
            transform: translateY(-1px);
        }

        @media (max-width:700px) {
            .review-page .container {
                padding: 20px 16px 36px;
            }

            .review-page .review-title {
                align-items: flex-start;
                flex-direction: column;
            }

            .review-page .grid {
                grid-template-columns: 1fr;
            }
        }

        .btn {
            border-radius: 11px;
            font-weight: 700;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--green-mid), var(--green-deep));
            box-shadow: 0 8px 18px color-mix(in srgb, var(--green-mid) 16%, transparent);
        }

        .btn-secondary {
            background: var(--green-pale);
            color: var(--green-deep);
            border-color: var(--line);
        }

        .btn-danger {
            background: var(--red-pale);
            color: var(--red-alert);
            border-color: var(--red-alert);
        }

        .card {
            border-color: var(--line);
            box-shadow: var(--shadow-card);
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            border-top: 1px solid var(--line);
            padding-top: 18px;
            margin-top: 4px;
        }

        .date-input {
            padding: 8px 10px;
            border: 1.5px solid var(--line);
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
        }

        .log {
            border-left: 3px solid var(--line);
            padding: 8px 0 8px 14px;
            margin-bottom: 10px;
        }

        .log .head {
            font-size: 13px;
            font-weight: 600;
        }

        .log .meta {
            font-size: 11.5px;
            color: var(--ink-soft);
        }

        .log .reason {
            font-size: 13px;
            margin-top: 4px;
        }

        @media (max-width:640px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .container {
                padding: 20px 16px;
            }
        }

        .document-preview-backdrop {
            position: fixed;
            inset: 0;
            z-index: 10050;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(15, 23, 42, .62);
            backdrop-filter: blur(3px);
        }

        .document-preview-backdrop.is-open {
            display: flex;
        }

        .document-preview-card {
            width: min(920px, 100%);
            height: min(780px, 92vh);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 24px 70px rgba(15, 23, 42, .28);
        }

        .document-preview-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 13px 16px;
            border-bottom: 1px solid var(--line);
        }

        .document-preview-title {
            min-width: 0;
            font-weight: 700;
            color: var(--ink);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .document-preview-close {
            flex: 0 0 auto;
            min-height: 36px;
            padding: 8px 14px;
            border: 1px solid var(--line);
            border-radius: 9px;
            background: var(--green-pale);
            color: var(--green-deep);
            font: inherit;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
        }

        .document-preview-close:hover {
            background: var(--white);
            border-color: var(--green-mid);
        }

        .document-preview-frame {
            flex: 1;
            width: 100%;
            border: 0;
            background: var(--cream);
        }

        @media (max-width: 640px) {
            .document-preview-backdrop {
                padding: 10px;
            }

            .document-preview-card {
                height: 94vh;
                border-radius: 14px;
            }
        }
    </style>
</head>

<body>
    <div class="app-shell review-page">
        <?php
        $activeNav = 'applicant_review';
        $__navBase = '';
        include __DIR__ . '/includes/hr_sidebar.php';
        ?>
        <main class="main-area">
            <div class="topbar">
                <h1>Applicant Review</h1>
                <div class="user-info">
                    <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img
                                src="<?= htmlspecialchars(appBaseUrl() . '/uploads/avatars/' . rawurlencode(basename((string) $_SESSION['profile_picture'])), ENT_QUOTES, 'UTF-8') ?>"
                                alt=""><?php else: ?><?= htmlspecialchars(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span>
                    <span><?= htmlspecialchars($_SESSION['full_name'] ?? '') ?>
                        (<?= htmlspecialchars(roleLabel($_SESSION['role'] ?? '')) ?>)</span>
                </div>
            </div>

            <div class="container">

                <?php if (!empty($success_message)): ?>
                    <div class="alert alert--success"><?= clean($success_message) ?></div>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert--error"><?= clean($error_message) ?></div>
                <?php endif; ?>

                <!-- ================= APPLICANT DETAILS ================= -->
                <div class="card">
                    <div class="review-title">
                        <h2><?= clean($ap['first_name'] . ' ' . $ap['last_name']) ?>
                            <span
                                class="review-status <?= htmlspecialchars($badge['class'], ENT_QUOTES, 'UTF-8') ?>"><?= clean($badge['label']) ?></span>
                        </h2>
                        <a class="back-link" href="applicant_review_queue.php">&larr; Back to Applicant Review</a>
                    </div>
                    <div class="item" style="margin-top:6px;">
                        <div class="label">Applicant ID</div>
                        <div class="value"><?= clean(formatApplicantCode($ap['applicant_id'])) ?></div>
                    </div>

                    <h2 style="margin-top:22px;">Personal Information</h2>
                    <div class="grid">
                        <div class="item">
                            <div class="label">Date of Birth</div>
                            <div class="value">
                                <?= !empty($ap['date_of_birth']) ? clean(date('M d, Y', strtotime($ap['date_of_birth']))) : '—' ?>
                            </div>
                        </div>
                        <div class="item">
                            <div class="label">Age</div>
                            <div class="value">
                                <?php $age = calculateAgeFromDOB($ap['date_of_birth'] ?? null);
                                echo $age !== null ? clean($age) . ' years old' : '—'; ?>
                            </div>
                        </div>
                        <div class="item">
                            <div class="label">Place of Birth</div>
                            <div class="value"><?= clean($ap['place_of_birth'] ?: '—') ?></div>
                        </div>
                        <div class="item">
                            <div class="label">Sex/Gender</div>
                            <div class="value"><?= clean($ap['sex'] ?: '—') ?></div>
                        </div>
                        <div class="item">
                            <div class="label">Civil Status</div>
                            <div class="value"><?= clean($ap['civil_status'] ?: '—') ?></div>
                        </div>
                        <div class="item">
                            <div class="label">Home Address</div>
                            <div class="value"><?= clean($ap['home_address'] ?: '—') ?></div>
                        </div>
                        <div class="item">
                            <div class="label">Email</div>
                            <div class="value"><?= clean($ap['email']) ?></div>
                        </div>
                        <div class="item">
                            <div class="label">Contact Number</div>
                            <div class="value"><?= clean($ap['contact_number'] ?: '—') ?></div>
                        </div>
                    </div>
                    <h2 style="margin-top:22px;">Position Details</h2>
                    <div class="grid">
                        <div class="item">
                            <div class="label">Position Applied</div>
                            <div class="value"><?= clean($ap['position_applied'] ?: '—') ?></div>
                        </div>
                        <div class="item">
                            <div class="label">Employment Type</div>
                            <div class="value"><?= clean($ap['employment_type'] ?: '—') ?></div>
                        </div>
                        <div class="item">
                            <div class="label">Applied</div>
                            <div class="value"><?= clean(date('M d, Y', strtotime($ap['applied_at']))) ?></div>
                        </div>
                        <div class="item">
                            <div class="label">Initial Interview</div>
                            <div class="value">
                                <?= !empty($ap['initial_interview_date']) ? clean(date('M d, Y', strtotime($ap['initial_interview_date']))) : '—' ?>
                            </div>
                        </div>
                        <div class="item">
                            <div class="label">Final Interview</div>
                            <div class="value">
                                <?= !empty($ap['final_interview_date']) ? clean(date('M d, Y', strtotime($ap['final_interview_date']))) : '—' ?>
                            </div>
                        </div>
                    </div>

                    <h2 style="margin-top:22px;">Education</h2>
                    <div class="grid">
                        <div class="item">
                            <div class="label">Highest Educational Attainment</div>
                            <div class="value"><?= clean($ap['highest_educational_attainment'] ?: '—') ?></div>
                        </div>
                        <div class="item">
                            <div class="label">Year Graduated</div>
                            <div class="value"><?= clean($ap['year_graduated'] ?: '—') ?></div>
                        </div>
                    </div>

                    <?php
                    $references = [];
                    if (!empty($ap['character_references'])) {
                        $decoded = json_decode($ap['character_references'], true);
                        if (is_array($decoded))
                            $references = $decoded;
                    }
                    ?>
                    <?php if (!empty($references)): ?>
                        <h2 style="margin-top:22px;">Character References</h2>
                        <div class="grid">
                            <?php foreach ($references as $i => $ref): ?>
                                <div class="item" style="border:1px solid var(--line); border-radius:10px; padding:10px 12px;">
                                    <div class="label">Reference <?= $i + 1 ?></div>
                                    <div class="value" style="font-size:13px;">
                                        <?= clean($ref['name'] ?? '—') ?><br>
                                        <span
                                            style="font-weight:400; color:var(--ink-soft);"><?= clean($ref['position'] ?? '') ?><?= !empty($ref['company']) ? ' · ' . clean($ref['company']) : '' ?></span><br>
                                        <span
                                            style="font-weight:400; color:var(--ink-soft);"><?= clean($ref['contact_number'] ?? '') ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($ap['cover_note'])): ?>
                        <div style="margin-top:18px;">
                            <div class="item">
                                <div class="label">Cover Note</div>
                            </div>
                            <div style="font-size:13.5px; margin-top:6px; white-space:pre-wrap;">
                                <?= clean($ap['cover_note']) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($extra)): ?>
                        <div style="margin-top:18px;">
                            <div class="item">
                                <div class="label">Additional Info</div>
                            </div>
                            <div style="margin-top:6px; font-size:13.5px;">
                                <?php foreach ($extra as $item): ?>
                                    <div><strong><?= clean($item['label']) ?>:</strong> <?= clean($item['value']) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ================= RESUME ================= -->
                <div class="card">
                    <h2>Resume</h2>
                    <p style="font-size:13.5px; color:var(--ink-soft); margin-bottom:14px;">
                        Original file: <strong><?= clean($ap['resume_original_name']) ?></strong>
                    </p>
                    <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                        <a href="resume_download.php?id=<?= enc_id($ap['applicant_id']) ?>"
                            class="btn btn-primary resume-preview-trigger"
                            data-document-name="<?= clean($ap['resume_original_name']) ?>"
                            title="Preview resume in this page">Preview Resume</a>
                        <a href="resume_download.php?id=<?= enc_id($ap['applicant_id']) ?>"
                            class="btn btn-secondary">Open / Download Resume</a>
                    </div>
                </div>

                <!-- ================= DECISION ================= -->
                <div class="card">
                    <h2>Decision</h2>

                    <?php if (!$isActive): ?>
                        <p style="font-size:13.5px; color:var(--ink-soft);">
                            This applicant has already been processed (<strong><?= $badge['label'] ?></strong>). No further
                            action can be taken here.
                        </p>
                    <?php elseif (!$canAct): ?>
                        <p style="font-size:13.5px; color:var(--ink-soft);">
                            View-only (HR Manager oversight role). HR Staff or Admin can Pass/Reject this applicant.
                        </p>
                    <?php else: ?>
                        <div class="actions">
                            <?php if ($ap['status'] === 'for_resume'): ?>
                                <span style="font-size:13px; color:var(--ink-soft);">
                                    Review the resume from the Applicant Review queue, then advance this applicant to the
                                    Initial Interview. Reject is still available below.
                                </span>

                            <?php elseif ($ap['status'] === 'for_initial'): ?>
                                <form method="POST" style="display:inline-flex; gap:8px; align-items:center;"
                                    data-confirm="Pass this applicant on the Initial Interview? The Final Interview date will be emailed to them."
                                    data-confirm-title="Pass Initial Interview" data-confirm-icon="question"
                                    data-confirm-ok="Yes, pass" data-reason="optional">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="pass_initial">
                                    <input type="hidden" name="applicant_id" value="<?= (int) $ap['applicant_id'] ?>">
                                    <label style="font-size:13px; color:var(--ink-soft);">Final Interview:</label>
                                    <input type="date" name="interview_date" class="date-input" required
                                        min="<?= date('Y-m-d') ?>">
                                    <button type="submit" class="btn btn-primary btn-sm">Schedule Final Interview</button>
                                </form>
                                <span style="font-size:12.5px; color:var(--ink-soft);">— or Reject below if they didn't pass the
                                    Initial Interview.</span>

                            <?php elseif ($ap['status'] === 'for_final'): ?>
                                <form method="POST" style="display:inline-flex; gap:8px; align-items:center; flex-wrap:wrap;"
                                    data-confirm="Pass this applicant on the Final Interview? The list of requirements will be emailed to them."
                                    data-confirm-title="Pass Final Interview" data-confirm-icon="question"
                                    data-confirm-ok="Yes, pass" data-reason="optional">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="pass_final">
                                    <input type="hidden" name="applicant_id" value="<?= (int) $ap['applicant_id'] ?>">
                                    <label style="font-size:13px; color:var(--ink-soft);">Requirements deadline:</label>
                                    <input type="date" name="requirements_deadline" class="date-input" required
                                        min="<?= date('Y-m-d') ?>">
                                    <button type="submit" class="btn btn-primary btn-sm">Pass Final Interview</button>
                                </form>

                            <?php elseif ($ap['status'] === 'for_requirements'): ?>
                                <span style="font-size:13px; color:var(--ink-soft);">
                                    <strong><?= count($missingDocs) ?></strong> requirement(s) still missing. Complete them
                                    below before hiring.
                                </span>
                                <form method="POST" style="display:inline-flex; margin-left:10px;"
                                    data-confirm="Resend the requirements email (with the submission link) to this applicant?"
                                    data-confirm-title="Resend Requirements Email" data-confirm-icon="question"
                                    data-confirm-ok="Yes, resend">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="resend_requirements_email">
                                    <input type="hidden" name="applicant_id" value="<?= (int) $ap['applicant_id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm">Resend Requirements Email</button>
                                </form>

                            <?php elseif ($ap['status'] === 'for_admin_approval'): ?>
                                <span style="font-size:13px; color:var(--ink-soft);">
                                    <?= $hasSignedContract ? 'Signed contract on file.' : 'A signed contract is required before hiring.' ?>
                                </span>
                                <?php if (!$hasSignedContract && $canAct): ?>
                                    <a class="btn btn-secondary btn-sm"
                                        href="hr_letter_edit.php?applicant_id=<?= enc_id($ap['applicant_id']) ?>&amp;letter_type=contract">Create
                                        contract</a>
                                <?php endif; ?>
                                <?php if (isAdminRole()): ?>
                                    <?php if ($hasSignedContract): ?>
                                        <form method="POST" style="display:inline;"
                                            data-confirm="Confirm this applicant as hired? This will create an employee record."
                                            data-confirm-title="Confirm Hire" data-confirm-icon="question" data-confirm-ok="Yes, hire"
                                            data-reason="optional">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="confirm_hire">
                                            <input type="hidden" name="applicant_id" value="<?= (int) $ap['applicant_id'] ?>">
                                            <button type="submit" class="btn btn-primary btn-sm">Hire</button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="font-size:13px; color:var(--ink-soft);">Waiting for Admin hire confirmation.</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <form method="POST" style="display:inline;"
                                data-confirm="Why is this applicant being rejected? The reason will be included in the email sent to them."
                                data-confirm-title="Reject Applicant" data-confirm-danger="1" data-confirm-ok="Yes, reject"
                                data-reason="required">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="reject_applicant">
                                <input type="hidden" name="applicant_id" value="<?= (int) $ap['applicant_id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ================= REQUIREMENTS ================= -->
                <?php if ($showRequirements): ?>
                    <div class="card">
                        <h2>Requirements (Pre-Employment Documents)</h2>
                        <p style="font-size:13.5px; color:var(--ink-soft); margin-bottom:14px;">
                            <?php if (!empty($ap['requirements_deadline'])): ?>
                                Deadline: <strong
                                    style="color:<?= (strtotime($ap['requirements_deadline']) < strtotime('today')) ? 'var(--red-alert)' : 'var(--ink)' ?>;">
                                    <?= clean(date('M d, Y', strtotime($ap['requirements_deadline']))) ?>
                                </strong> ·
                            <?php endif; ?>
                            <?php if (empty($missingDocs)): ?>
                                <strong style="color:var(--text-accent);">Complete ✔</strong>
                            <?php else: ?>
                                Missing: <strong style="color:var(--red-alert);"><?= count($missingDocs) ?></strong> of
                                <?= count(APPLICANT_REQUIRED_DOCUMENTS) ?>
                            <?php endif; ?>
                        </p>

                        <?php if ($canAct && in_array($ap['status'], ['for_requirements', 'for_admin_approval'], true)): ?>
                            <?php $portalUrl = applicantRequirementsPortalUrl($pdo, $applicant_id); ?>
                            <p style="font-size:12px; color:var(--ink-soft); margin-bottom:14px; word-break:break-all;">
                                Applicant's submission link (auto-emailed, share again only if they lost it):
                                <a href="<?= clean($portalUrl) ?>" target="_blank"
                                    style="color:var(--green-mid);"><?= clean($portalUrl) ?></a>
                            </p>
                        <?php endif; ?>

                        <?php
                        // Kapag may kahit isang missing requirement pa at pwede mag-edit,
                        // isang form/isang POST na lang ang gagamitin para sa lahat ng
                        // upload — pwedeng mag-choose ng ilang file muna bago i-submit
                        // lahat nang sabay (batch), kaysa isa-isahang "Upload" click bawat
                        // requirement row. Ang bawat <input type="file"> ay may pangalang
                        // document[REQUIREMENT_KEY], kaya fixed na kung saang requirement
                        // ito mapupunta — hindi ito dropdown/free-choice, kaya hindi
                        // pwedeng mali ang pagkakalagay ng file sa maling document type.
                        $hasMissingEditable = $canEditDocs && !empty($missingDocs);
                        ?>

                        <?php if ($hasMissingEditable): ?>
                            <!--
            IMPORTANT: this form is intentionally NOT wrapped around the
            requirement rows below. Several rows render their own small
            <form> (the per-row Delete button) — physically nesting a
            <form> inside another <form> is invalid HTML, and browsers
            silently close the OUTER form as soon as they hit the first
            nested one. That used to detach this very Submit button (and
            everything after the first already-uploaded requirement) from
            the form once at least one requirement had been uploaded —
            which is why the batch submit only ever worked the very first
            time, before any checkmarks existed yet.
            Every field this form needs (file inputs, and the Submit
            button below) is instead tied to it explicitly via the
            form="requirements-upload-form" attribute, the same way the
            file inputs already were.
        -->
                            <form method="POST" enctype="multipart/form-data" id="requirements-upload-form">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="upload_applicant_document">
                                <input type="hidden" name="applicant_id" value="<?= (int) $ap['applicant_id'] ?>">
                            </form>
                        <?php endif; ?>

                        <?php foreach (APPLICANT_REQUIRED_DOCUMENTS as $key => $label): ?>
                            <?php $doc = $uploadedDocs[$key] ?? null; ?>
                            <div
                                style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; padding:10px 0; border-bottom:1px solid var(--line);">
                                <div style="flex:1 1 220px; font-size:13.5px;">
                                    <span
                                        class="document-status <?= $doc ? 'document-status--complete' : 'document-status--missing' ?>"
                                        aria-label="<?= $doc ? 'Complete' : 'Not submitted' ?>"><?= $doc ? 'Complete' : 'Not submitted' ?></span>
                                    <strong><?= clean($label) ?></strong>
                                    <span
                                        style="font-size:11px; color:var(--ink-soft); font-weight:600;">(<?= clean(applicantDocFormatHint($key)) ?>)</span>
                                    <?php if ($doc): ?>
                                        <div style="font-size:11.5px; color:var(--ink-soft);">
                                            <?= clean($doc['original_name']) ?> ·
                                            <?= clean(date('M d, Y', strtotime($doc['uploaded_at']))) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($doc): ?>
                                    <a href="applicant_document_download.php?id=<?= enc_id($doc['document_id']) ?>&preview=1"
                                        class="btn btn-secondary btn-sm document-preview-trigger"
                                        data-document-name="<?= clean($doc['original_name']) ?>" title="View in system"
                                        aria-label="View"><i class="fa-regular fa-eye"></i></a>
                                    <?php if ($canEditDocs): ?>
                                        <form method="POST" style="display:inline;"
                                            data-confirm="Reject the <?= clean($label) ?>? The applicant will be emailed and asked to resend just this requirement."
                                            data-confirm-title="Reject Document" data-confirm-danger="1" data-confirm-ok="Yes, reject"
                                            data-reason="required">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_applicant_document">
                                            <input type="hidden" name="document_id" value="<?= (int) $doc['document_id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm"
                                                title="Reject — unreadable or wrong document" aria-label="Reject"><i
                                                    class="fa-regular fa-circle-xmark"></i> Reject</button>
                                        </form>
                                    <?php endif; ?>
                                <?php elseif ($canEditDocs): ?>
                                    <input type="file" name="document[<?= clean($key) ?>]"
                                        accept="<?= clean(applicantDocAcceptAttr($key)) ?>" style="font-size:12px; max-width:220px;"
                                        form="requirements-upload-form">
                                <?php else: ?>
                                    <span style="font-size:12.5px; color:var(--ink-soft);">Not submitted</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($hasMissingEditable): ?>
                            <div style="margin-top:14px;">
                                <button type="submit" id="submitDocsBtn" class="btn btn-primary btn-sm"
                                    form="requirements-upload-form">Submit Selected Documents</button>
                            </div>
                            <script>
                                (function () {
                                    // Ang server's post_max_size ang talagang bumabara sa
                                    // KABUUANG laki ng buong request (hindi lang bawat file) —
                                    // kaya kahit 5MB ang limit per-file dito, puwede pa ring
                                    // ma-reject ang buong batch kapag pinagsama-sama ang ilang
                                    // file nang sabay. Chineck ito muna dito bago pa man
                                    // i-submit, gamit ang AKTWAL na post_max_size ng server
                                    // (see iniSizeToBytes() sa includes/config.php) — kaysa
                                    // hintayin pang mag-"walang nangyari" pagbalik mula server.
                                    var POST_MAX_BYTES = <?= (int) iniSizeToBytes(ini_get('post_max_size')) ?>;
                                    var SAFETY_MARGIN = 0.9; // konting puwang para sa ibang POST fields/multipart overhead

                                    var form = document.getElementById('requirements-upload-form');
                                    if (!form) return;

                                    form.addEventListener('submit', function (e) {
                                        var fileInputs = form.querySelectorAll('input[type="file"]');
                                        var total = 0;
                                        var anySelected = false;
                                        fileInputs.forEach(function (input) {
                                            if (input.files && input.files.length > 0) {
                                                anySelected = true;
                                                total += input.files[0].size;
                                            }
                                        });

                                        if (!anySelected) return; // let the server show its own "choose a file" message

                                        if (POST_MAX_BYTES > 0 && total > POST_MAX_BYTES * SAFETY_MARGIN) {
                                            e.preventDefault();
                                            e.stopImmediatePropagation();
                                            var totalMB = (total / (1024 * 1024)).toFixed(1);
                                            var limitMB = (POST_MAX_BYTES / (1024 * 1024)).toFixed(1);
                                            var msg = 'The files you selected add up to about ' + totalMB + ' MB, which is over this server\'s ' + limitMB + ' MB upload limit. Please submit fewer files at a time (e.g. 2-3 documents per batch instead of all at once).';
                                            if (typeof Swal !== 'undefined') {
                                                Swal.fire({ icon: 'warning', title: 'Files too large to submit together', text: msg, confirmButtonColor: '#2563eb' });
                                            } else {
                                                Swal.fire({
                                                    icon: 'error',
                                                    title: 'Unable to upload file',
                                                    text: msg,
                                                    confirmButtonText: 'Okay'
                                                });
                                            }
                                            // Naka-disable na ang button dahil sa generic anti-double-submit
                                            // handler sa sweetalert.php (submit event din 'yon) — i-release
                                            // ulit dito para makapag-retry agad ang user nang di kailangang
                                            // mag-refresh.
                                            form.dataset.submitting = '0';
                                            var btns = form.querySelectorAll('button[type="submit"], input[type="submit"]');
                                            for (var i = 0; i < btns.length; i++) { btns[i].disabled = false; }
                                        }
                                    }, true); // capture phase: bago pa man umabot sa generic handler ng sweetalert.php
                                })();
                            </script>
                        <?php endif; ?>

                        <?php if ($canEditDocs): ?>
                            <p style="font-size:11.5px; color:var(--ink-soft); margin-top:12px;">
                                Each requirement accepts the format shown next to it — <strong>PDF format</strong> for most
                                documents and <strong>JPEG format</strong> for the Valid Government ID and 2x2 ID Picture
                                (maximum 5 MB per file). You can choose files for several requirements at once, then submit them
                                together — but if you select many large files, submit in smaller batches (e.g. 2-3 at a time) so
                                the total stays under this server's upload limit. Applicants normally submit these themselves
                                through the link emailed to them — the fields below are for HR to upload on their behalf instead
                                (e.g. a walk-in applicant who hands over physical copies). If a document turns out unreadable or
                                wrong, use <strong>Reject</strong> on it — only that one requirement is cleared and the
                                applicant is automatically emailed a link to resend just that one.
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- ================= AUDIT TRAIL ================= -->
                <div class="card">
                    <h2>Decision History</h2>
                    <?php if (empty($logs)): ?>
                        <p style="font-size:13.5px; color:var(--ink-soft);">No decisions recorded yet.</p>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <div class="log">
                                <div class="head"><?= clean(ucwords(str_replace('_', ' ', $l['action']))) ?></div>
                                <div class="meta">
                                    <?= clean(date('M d, Y h:i A', strtotime($l['logged_at']))) ?>
                                    <?= !empty($l['performed_by_name']) ? ' · ' . clean($l['performed_by_name']) : '' ?>
                                </div>
                                <?php if (!empty($l['reason'])): ?>
                                    <div class="reason"><?= nl2br(clean($l['reason'])) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>

            <?php include 'includes/sweetalert.php'; ?>

        </main>
        <div class="document-preview-backdrop" id="documentPreview" role="dialog" aria-modal="true"
            aria-labelledby="documentPreviewTitle">
            <div class="document-preview-card">
                <div class="document-preview-head">
                    <div class="document-preview-title" id="documentPreviewTitle">Document preview</div>
                    <button type="button" class="document-preview-close" id="documentPreviewClose">← Back</button>
                </div>
                <iframe class="document-preview-frame" id="documentPreviewFrame"
                    title="Applicant document preview"></iframe>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var modal = document.getElementById('documentPreview');
            var frame = document.getElementById('documentPreviewFrame');
            var title = document.getElementById('documentPreviewTitle');
            var close = document.getElementById('documentPreviewClose');
            if (!modal || !frame || !title || !close) return;

            function closePreview() {
                modal.classList.remove('is-open');
                frame.src = 'about:blank';
                document.body.style.overflow = '';
            }
            document.querySelectorAll('.document-preview-trigger, .resume-preview-trigger').forEach(function (link) {
                link.addEventListener('click', function (event) {
                    event.preventDefault();
                    title.textContent = link.getAttribute('data-document-name') || 'Document preview';
                    frame.src = link.href;
                    modal.classList.add('is-open');
                    document.body.style.overflow = 'hidden';
                });
            });
            close.addEventListener('click', closePreview);
            modal.addEventListener('click', function (event) {
                if (event.target === modal) closePreview();
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && modal.classList.contains('is-open')) closePreview();
            });
        })();
    </script>
</body>

</html>