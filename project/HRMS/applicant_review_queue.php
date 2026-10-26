<?php
require_once 'includes/config.php';
require_once 'includes/application_form.php';
require_once 'includes/applicant_actions.php';
requireHR();

$successMessage = $_SESSION['flash_success'] ?? '';
$errorMessage = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actionResult = processApplicantAction($pdo);
    if ($actionResult['handled']) {
        $_SESSION['flash_success'] = $actionResult['success'];
        $_SESSION['flash_error'] = $actionResult['error'];
        header('Location: applicant_review_queue.php');
        exit;
    }
}

sweepDisqualifiedApplicants($pdo);
$statuses = ['for_resume', 'for_initial', 'for_final', 'for_requirements', 'for_admin_approval'];
$marks = implode(',', array_fill(0, count($statuses), '?'));
$stmt = $pdo->prepare("SELECT ja.*, ru.full_name AS reviewed_by_name
    FROM job_applicants ja LEFT JOIN users ru ON ja.reviewed_by = ru.id
    WHERE ja.status IN ($marks) ORDER BY ja.applied_at ASC");
$stmt->execute($statuses);
$applicants = $stmt->fetchAll();
$resumeApplicants = array_filter($applicants, static function ($applicant) {
    return $applicant['status'] === 'for_resume';
});

$counts = [];
foreach ($pdo->query("SELECT status, COUNT(*) AS total FROM job_applicants GROUP BY status")->fetchAll() as $row) {
    $counts[$row['status']] = (int) $row['total'];
}
$statusLabels = [
    'for_resume' => ['Resume Screening', 'status-badge--review'],
    'for_initial' => ['Initial Interview', 'status-badge--review'],
    'for_final' => ['Final Interview', 'status-badge--review'],
    'for_requirements' => ['For Requirements', 'status-badge--review'],
    'for_admin_approval' => ['Final Approval', 'status-badge--approval'],
];
$canManageApplicants = isAdminRole() || isHRStaffRole();

$activeNav = 'applicant_review';
$pageTitle = 'Applicant Review — HRMS & Payroll';
$__navBase = '';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Applicant Review | HCI GroceryOne HRMS</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="<?= htmlspecialchars(appBaseUrl() . '/payroll/assets/style.css?v=' . (filemtime(__DIR__ . '/payroll/assets/style.css') ?: time()), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="applicant-queue-page">
<div class="app-shell">
<?php
include __DIR__ . '/includes/hr_sidebar.php';
?>
<main class="main-area applicant-review-page">
    <div class="topbar">
        <h1>Applicant Review</h1>
        <div class="user-info">
            <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="<?= htmlspecialchars(appBaseUrl() . '/uploads/avatars/' . rawurlencode(basename((string) $_SESSION['profile_picture'])), ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><?= htmlspecialchars(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span>
            <span><?= htmlspecialchars($_SESSION['full_name'] ?? '') ?> (<?= htmlspecialchars(roleLabel($_SESSION['role'] ?? '')) ?>)</span>
        </div>
    </div>
    <div class="container">
        <div class="page-heading">
            <div>
                <h2>Applicant pipeline</h2>
                <p>Review applications, track interview stages, and move qualified candidates through hiring.</p>
            </div>
            <a class="btn btn-secondary" href="employees.php">Open Employees</a>
        </div>
        <div class="summary-grid">
            <?php foreach ([
                ['Active queue', count($applicants)],
                ['Resume screening', $counts['for_resume'] ?? 0],
                ['For requirements', $counts['for_requirements'] ?? 0],
                ['Final approval', $counts['for_admin_approval'] ?? 0],
            ] as $card): ?>
                <div class="panel">
                    <div class="summary-label"><?= htmlspecialchars($card[0]) ?></div>
                    <div class="summary-value"><?= (int) $card[1] ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($successMessage !== ''): ?><div class="workflow-alert workflow-alert--success" role="status"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($errorMessage !== ''): ?><div class="workflow-alert workflow-alert--error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <section class="panel">
            <div class="panel-header">
                <h3>Applications requiring review</h3>
                <p>Open an applicant to view the resume, requirements, interview progress, audit trail, and actions.</p>
            </div>
            <?php if (!$applicants): ?>
                <div class="empty-state applicant-empty-state">
                    <h3>No applications are waiting for review</h3>
                    <p>New applications submitted through the public Application Form will appear here automatically.</p>
                </div>
            <?php else: ?>
                <?php if ($canManageApplicants && $resumeApplicants): ?>
                <form method="post" id="advanceApplicantsForm" class="applicant-bulk-form">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="advance_to_interview">
                    <div class="applicant-bulk-actions">
                        <label>Initial Interview date
                            <input type="date" name="interview_date" required min="<?= date('Y-m-d') ?>">
                        </label>
                        <button type="button" class="btn btn-secondary btn-sm" id="selectAllApplicants">Select all eligible</button>
                        <button type="submit" class="btn btn-primary btn-sm" id="advanceSelectedApplicants">Advance selected to Initial Interview</button>
                        <span class="queue-count"><?= count($resumeApplicants) ?> applicant(s) in Resume Screening</span>
                    </div>
                <?php endif; ?>
                <div class="table-scroll applicant-table-scroll">
                    <table class="data-table">
                        <thead><tr><?php if ($canManageApplicants && $resumeApplicants): ?><th>Select</th><?php endif; ?><th>Applicant</th><th>Position</th><th>Applied</th><th>Status</th><th>Reviewer</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($applicants as $applicant):
                            $badge = $statusLabels[$applicant['status']] ?? [ucwords(str_replace('_', ' ', $applicant['status'])), 'status-badge--approval']; ?>
                            <tr>
                                <?php if ($canManageApplicants && $resumeApplicants): ?><td><?php if ($applicant['status'] === 'for_resume'): ?><input type="checkbox" name="applicant_ids[]" value="<?= (int) $applicant['applicant_id'] ?>" form="advanceApplicantsForm" aria-label="Select <?= htmlspecialchars(trim($applicant['first_name'].' '.$applicant['last_name']), ENT_QUOTES, 'UTF-8') ?>"><?php else: ?>—<?php endif; ?></td><?php endif; ?>
                                <td><strong><?= htmlspecialchars(trim($applicant['first_name'].' '.$applicant['last_name'])) ?></strong><br><small><?= htmlspecialchars($applicant['email'] ?? '') ?></small></td>
                                <td><?= htmlspecialchars($applicant['position_applied'] ?? '—') ?></td>
                                <td><?= htmlspecialchars(date('M d, Y', strtotime($applicant['applied_at']))) ?></td>
                                <td><span class="status-badge <?= htmlspecialchars($badge[1], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($badge[0]) ?></span></td>
                                <td><?= htmlspecialchars($applicant['reviewed_by_name'] ?? 'Not assigned') ?></td>
                                <td><a class="btn btn-primary btn-sm" href="applicant_review.php?id=<?= urlencode(enc_id($applicant['applicant_id'])) ?>">Review applicant</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($canManageApplicants && $resumeApplicants): ?>
                </form>
                <script>
                    (function () {
                        var form = document.getElementById('advanceApplicantsForm');
                        var checkboxes = Array.prototype.slice.call(document.querySelectorAll('input[name="applicant_ids[]"]'));
                        document.getElementById('selectAllApplicants').addEventListener('click', function () {
                            var selectAll = checkboxes.some(function (checkbox) { return !checkbox.checked; });
                            checkboxes.forEach(function (checkbox) { checkbox.checked = selectAll; });
                        });
                        form.addEventListener('submit', function (event) {
                            if (!checkboxes.some(function (checkbox) { return checkbox.checked; })) {
                                event.preventDefault();
                                Swal.fire({icon: 'warning', title: 'No applicant selected', text: 'Select at least one applicant to advance.', confirmButtonText: 'Okay'});
                            }
                        });
                    }());
                </script>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</main>
</div>
</body>
</html>
