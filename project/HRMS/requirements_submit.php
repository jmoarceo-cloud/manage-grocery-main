<?php
// ============================================================
// requirements_submit.php — Applicant self-service requirements portal
// ------------------------------------------------------------
// PUBLIC page (no login) — reached only through the magic link sent by
// sendRequirementsEmail() / sendDocumentRejectedEmail() (see
// includes/mailer.php and applicantRequirementsPortalUrl() in
// includes/config.php). Access is gated by a long random token tied
// to this one applicant instead of a session.
//
// The applicant submits their requirement files here directly; they
// land in the SAME applicant_documents table (and therefore the SAME
// "Requirements" section of applicant_review.php) that HR already
// uses to review/accept/reject — so HR doesn't look anywhere new,
// they just see submissions appear there. Rejecting one requirement
// only removes THAT one row — every other already-submitted
// requirement is untouched — and emails the applicant a link back to
// this same page to resend just that one.
// ============================================================
require_once 'includes/config.php';
require_once 'includes/mailer.php';
require_once 'includes/applicant_actions.php';

// Auto-disqualify: reflect an expired deadline immediately, even if no
// HR staff has opened applicant_review.php since it passed.
sweepDisqualifiedApplicants($pdo);

$applicant_id = dec_id($_GET['aid'] ?? ($_POST['aid'] ?? ''));
$token        = trim($_GET['token'] ?? ($_POST['token'] ?? ''));

$success_message = $_SESSION['req_portal_success'] ?? '';
$error_message   = $_SESSION['req_portal_error']   ?? '';
unset($_SESSION['req_portal_success'], $_SESSION['req_portal_error']);

$stmt = $pdo->prepare("SELECT * FROM job_applicants WHERE applicant_id = ?");
$stmt->execute([$applicant_id]);
$ap = $stmt->fetch();

$tokenValid = $ap && !empty($ap['requirements_token']) && $token !== '' && hash_equals($ap['requirements_token'], $token);

if (!$tokenValid) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head><meta charset="UTF-8"><title>Link Not Found — HR Department</title>
    <style>body{font-family:Arial,sans-serif; max-width:480px; margin:80px auto; text-align:center; color:#17222E;} h2{color:#123C5C;}</style>
    </head>
    <body>
        <h2>This link isn't valid</h2>
        <p>It may have been typed incorrectly, or has expired. Please check the email again, or contact our HR office for a new link.</p>
    </body>
    </html>
    <?php
    exit;
}

// Handle submission (upload) — Post/Redirect/Get so refreshing the
// page never re-submits the same files.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $result = handlePublicRequirementsUpload($pdo, (int) $applicant_id, $token, $_FILES['document'] ?? null);
    $_SESSION['req_portal_success'] = $result['success'];
    $_SESSION['req_portal_error']   = $result['error'];
    header('Location: requirements_submit.php?aid=' . urlencode($_GET['aid'] ?? $_POST['aid'] ?? '') . '&token=' . urlencode($token));
    exit;
}

// GET only from here on (the POST branch above always redirects+exits).
$uploadedDocs = getApplicantDocuments($pdo, $applicant_id);
$missingDocs  = applicantMissingDocuments($pdo, $applicant_id);

// Whether the applicant can still submit/resubmit anything right now.
$canSubmit = in_array($ap['status'], ['for_requirements', 'for_admin_approval'], true);
$hasMissingEditable = $canSubmit && !empty($missingDocs);

$statusMessage = null; // shown instead of the form when nothing can be submitted
if ($ap['status'] === 'disqualified') {
    $statusMessage = "Unfortunately, the deadline for these requirements has passed and this application is no longer active. Please contact our HR office if you have questions.";
} elseif ($ap['status'] === 'rejected') {
    $statusMessage = "This application is no longer active.";
} elseif ($ap['status'] === 'hired') {
    $statusMessage = "All requirements are already complete — congratulations, you're confirmed as hired! You don't need to submit anything further here.";
} elseif ($ap['status'] === 'for_admin_approval' && empty($missingDocs)) {
    $statusMessage = "All your requirements have been submitted and are complete. Our HR team is reviewing them — we'll email you if anything needs to be resent.";
} elseif (!$canSubmit) {
    $statusMessage = "This link isn't active for submissions at this time.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Submit Your Requirements — HR Department</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --green-deep:#123C5C; --green-mid:#1978A8; --green-leaf:#4FC3D1;
    --mustard:#E0A23B; --red-alert:#C8472D; --red-pale:#FBE7E2;
    --cream:#F3F6F9; --white:#fff; --ink:#17222E; --ink-soft:#3D4C5C; --line:#DCE5EC;
}
body { font-family:'Inter',Arial,sans-serif; background:var(--cream); color:var(--ink); }
.topbar { background:var(--white); border-bottom:1px solid var(--line); padding:18px 24px; text-align:center; }
.topbar h1 { font-size:19px; color:var(--green-deep); }
.container { max-width:640px; margin:0 auto; padding:28px 20px 60px; }
.alert { padding:12px 16px; border-radius:12px; font-size:14px; font-weight:500; margin-bottom:20px; }
.alert--success { background:#e6f4ea; color:#1a7a3b; }
.alert--error { background:var(--red-pale); color:var(--red-alert); }
.card { background:var(--white); border:1px solid var(--line); border-radius:16px; padding:24px 26px; margin-bottom:20px; }
.card h2 { font-size:16px; color:var(--green-deep); margin-bottom:10px; }
.card p.sub { font-size:13.5px; color:var(--ink-soft); margin-bottom:14px; }
.row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; padding:12px 0; border-bottom:1px solid var(--line); }
.row:last-of-type { border-bottom:none; }
.row .name { flex:1 1 220px; font-size:13.5px; }
.row .name .hint { font-size:11px; color:var(--ink-soft); font-weight:600; }
.row .meta { font-size:11.5px; color:var(--ink-soft); margin-top:2px; }
.row .rejected-note { font-size:12px; color:var(--red-alert); margin-top:4px; }
.row input[type=file] { font-size:12px; max-width:220px; }
.pill { font-size:11px; font-weight:700; padding:3px 9px; border-radius:999px; white-space:nowrap; }
.pill--ok { background:#E3F2F6; color:var(--green-deep); }
.pill--missing { background:#EFEFEF; color:var(--ink-soft); }
.pill--rejected { background:var(--red-pale); color:var(--red-alert); }
.btn { padding:11px 22px; border:none; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer; background:linear-gradient(135deg, var(--green-mid), var(--green-deep)); color:#fff; }
.btn:hover { filter:brightness(1.08); }
.deadline { font-size:13.5px; color:var(--ink-soft); margin-bottom:14px; }
</style>
</head>
<body>

<div class="topbar"><h1>Requirements Submission</h1></div>

<div class="container">

    <?php if (!empty($success_message)): ?>
        <div class="alert alert--success"><?= clean($success_message) ?></div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="alert alert--error"><?= clean($error_message) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>Hi <?= clean($ap['first_name']) ?>!</h2>
        <p class="sub">
            Requirements for the position of <strong><?= clean($ap['position_applied']) ?></strong>.
        </p>

        <?php if (!empty($ap['requirements_deadline']) && $canSubmit): ?>
            <p class="deadline">
                Deadline: <strong style="color:<?= (strtotime($ap['requirements_deadline']) < strtotime('today')) ? 'var(--red-alert)' : 'var(--ink)' ?>;">
                    <?= clean(date('M d, Y', strtotime($ap['requirements_deadline']))) ?>
                </strong>
            </p>
        <?php endif; ?>

        <?php if ($statusMessage !== null): ?>
            <p style="font-size:14px;"><?= clean($statusMessage) ?></p>
        <?php endif; ?>

        <?php if ($hasMissingEditable): ?>
        <form method="POST" enctype="multipart/form-data" id="req-form">
            <?php csrfField(); ?>
            <input type="hidden" name="aid" value="<?= clean($_GET['aid'] ?? '') ?>">
            <input type="hidden" name="token" value="<?= clean($token) ?>">
        </form>
        <?php endif; ?>

        <?php foreach (APPLICANT_REQUIRED_DOCUMENTS as $key => $label): ?>
            <?php
            $doc      = $uploadedDocs[$key] ?? null;
            $rejected = $doc ? null : getApplicantDocumentRejectionReason($pdo, $applicant_id, $key);
            ?>
            <div class="row">
                <div class="name">
                    <strong><?= clean($label) ?></strong>
                    <span class="hint">(<?= clean(applicantDocFormatHint($key)) ?>)</span>
                    <?php if ($doc): ?>
                        <div class="meta"><?= clean($doc['original_name']) ?> · submitted <?= clean(date('M d, Y', strtotime($doc['uploaded_at']))) ?></div>
                    <?php elseif ($rejected): ?>
                        <div class="rejected-note"><strong>Needs to be resent:</strong> <?= nl2br(clean($rejected['reason'])) ?></div>
                    <?php endif; ?>
                </div>

                <?php if ($doc): ?>
                    <span class="pill pill--ok">Submitted</span>
                <?php elseif (!$canSubmit): ?>
                    <span class="pill pill--missing">Not submitted</span>
                <?php elseif ($rejected): ?>
                    <span class="pill pill--rejected">Rejected — resend</span>
                    <input type="file" name="document[<?= clean($key) ?>]" accept="<?= clean(applicantDocAcceptAttr($key)) ?>" form="req-form">
                <?php else: ?>
                    <span class="pill pill--missing">Not submitted</span>
                    <input type="file" name="document[<?= clean($key) ?>]" accept="<?= clean(applicantDocAcceptAttr($key)) ?>" form="req-form">
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($hasMissingEditable): ?>
            <div style="margin-top:18px;">
                <button type="submit" form="req-form" class="btn">Submit Requirements</button>
            </div>
            <p style="font-size:11.5px; color:var(--ink-soft); margin-top:12px;">
                Each requirement accepts the format shown next to it — PDF format for most documents, JPEG format for the Valid Government ID and 2x2 ID Picture (maximum 5 MB per file). You can pick several files at once and submit them together. Submitting a requirement that was rejected only resends that one — everything else you already submitted stays as is.
            </p>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
