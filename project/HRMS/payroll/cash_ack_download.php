<?php
// ============================================================
// payroll/cash_ack_download.php
// Serves the signed cash-release receipt uploaded via
// upload_cash_ack.php. Access rules mirror payslip.php: HR tier
// can view anyone's, an Employee can only view their own (it's
// proof of their own salary release).
// Called via: payroll/cash_ack_download.php?id=<payroll_id>
// ============================================================
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$payroll_id = dec_id($_GET['id'] ?? '');

$stmt = $pdo->prepare("
    SELECT pr.payroll_id, pr.employee_id, pr.cash_ack_file, pr.cash_ack_original_name
    FROM payroll_records pr
    WHERE pr.payroll_id = ?
");
$stmt->execute([$payroll_id]);
$rec = $stmt->fetch();

if (!$rec) {
    http_response_code(404);
    die("Record not found.");
}

$role    = $_SESSION['role'] ?? '';
$myEmpId = $_SESSION['employee_id'] ?? null;
$isHRTier = in_array($role, ['admin', 'hr', 'hr_manager'], true);

if (!$isHRTier && (int) $rec['employee_id'] !== (int) $myEmpId) {
    http_response_code(403);
    die("You do not have access to this file.");
}

if (empty($rec['cash_ack_file'])) {
    http_response_code(404);
    die("No receipt has been uploaded for this payroll record.");
}

$filepath = __DIR__ . '/../uploads/cash_ack/' . $rec['cash_ack_file'];

// Item 13 — path-traversal containment check, same pattern already
// used in applicant_document_download.php / resume_download.php /
// employee_document_download.php. cash_ack_file comes from the DB
// (server-generated random name at upload time — see
// upload_cash_ack.php), so this isn't reachable via user input today —
// added purely as defense in depth to match the safer sibling scripts.
$realBase = realpath(__DIR__ . '/../uploads/cash_ack');
$realPath = realpath($filepath);
if ($realPath === false || $realBase === false || strpos($realPath, $realBase) !== 0 || !is_file($realPath)) {
    http_response_code(404);
    die("Receipt file is missing on the server.");
}
$filepath = $realPath;

$ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
$mimeTypes = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];
$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

$downloadName = preg_replace('/[^A-Za-z0-9_\-.]/', '_', 'cash_receipt_' . ($rec['cash_ack_original_name'] ?: $rec['cash_ack_file']));

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($filepath));
header('X-Content-Type-Options: nosniff');
readfile($filepath);
exit();
