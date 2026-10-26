<?php
// ============================================================
// backfill_hired_documents.php
// ONE-TIME SCRIPT — run this once, then delete it from the server.
//
// Ginagawa nito ang ginagawa na ngayon ng confirm_hire (auto-copy ng
// applicant requirements papunta sa Employee Documents), pero para sa
// mga EMPLOYEE na na-hire na BAGO pa dumating ang fix na iyon — kaya
// blangko pa rin ang Documents tab nila.
//
// Paano gamitin:
//   1. I-drop ito sa root ng HRMS folder mo (kasabay ng index.php).
//   2. Mag-login bilang Admin sa parehong browser/tab.
//   3. Buksan sa browser: http://localhost/HRMS/backfill_hired_documents.php
//   4. Basahin ang report — sinasabi nito kung ilang employee ang na-
//      backfill at kung ilan ang nilaktawan (may laktawan dahil meron
//      na silang laman sa Documents, para hindi ma-duplicate).
//   5. Pag tapos na — BURAHIN ang file na ito sa server. Hindi na ito
//      kailangan pagkatapos, at Admin-only naman ito pero mas ligtas
//      na tanggalin.
// ============================================================

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/applicant_actions.php';
requireAdmin(); // redirect kung hindi Admin

$report = [];

// Lahat ng 'hired' na applicant, itugma sa employees table gamit ang
// email (walang direktang applicant_id column sa employees, kaya email
// ang pinaka-maaasahang key dito — parehong-parehas dapat kasi ito ang
// ginamit noong ginawa ang employee record sa confirm_hire).
$applicants = $pdo->query("
    SELECT applicant_id, first_name, last_name, email
    FROM job_applicants
    WHERE status = 'hired'
")->fetchAll();

foreach ($applicants as $ap) {
    $empStmt = $pdo->prepare("SELECT employee_id, employee_code FROM employees WHERE email = ? LIMIT 1");
    $empStmt->execute([$ap['email']]);
    $employee = $empStmt->fetch();

    $name = $ap['first_name'] . ' ' . $ap['last_name'];

    if (!$employee) {
        $report[] = "⚠️  $name — no matching employee record (maybe a different email is used, or they haven't actually been 'confirm hired' yet). Skipped.";
        continue;
    }

    // Kung meron nang "Auto-copied from applicant requirements" na entry
    // dati (galing na sa bagong confirm_hire code, o naitakbo na dati
    // itong script), huwag nang ulitin — iwasan ang duplicate.
    $checkStmt = $pdo->prepare("
        SELECT COUNT(*) FROM employee_documents
        WHERE employee_id = ? AND notes LIKE 'Auto-copied from applicant requirements%'
    ");
    $checkStmt->execute([$employee['employee_id']]);
    if ((int) $checkStmt->fetchColumn() > 0) {
        $report[] = "⏭️  $name ({$employee['employee_code']}) — already backfilled previously. Skipped.";
        continue;
    }

    copyApplicantDocumentsToEmployee($pdo, $ap['applicant_id'], $employee['employee_id'], $_SESSION['user_id']);

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) FROM employee_documents
        WHERE employee_id = ? AND notes LIKE 'Auto-copied from applicant requirements%'
    ");
    $countStmt->execute([$employee['employee_id']]);
    $copied = (int) $countStmt->fetchColumn();

    $report[] = "✅ $name ({$employee['employee_code']}) — $copied document(s) backfilled.";
}
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Backfill Report</title>
<style>
body{font-family:sans-serif; max-width:700px; margin:40px auto; line-height:1.7;}
li{margin-bottom:4px;}
.warn{background:#FBEFD8; border:1px solid #E0A23B; padding:12px 16px; border-radius:8px; margin-bottom:20px;}
</style>
</head>
<body>
<h2>Backfill Report — Applicant Documents → Employee Documents</h2>
<div class="warn">⚠️ This should only be run once. After you've read this report, <strong>delete this file</strong> (<code>backfill_hired_documents.php</code>) from the server.</div>
<?php if (empty($applicants)): ?>
    <p>No 'hired' applicants found.</p>
<?php else: ?>
    <ul>
        <?php foreach ($report as $line): ?>
            <li><?= htmlspecialchars($line) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<p><a href="employees.php">← Back to Employees</a></p>
</body>
</html>
