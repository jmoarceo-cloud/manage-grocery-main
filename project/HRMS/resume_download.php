<?php
require_once 'includes/config.php';
requireHRStaff(); // HR Staff + Admin only (HR Manager is excluded from this page) // Only HR and Admin may view resumes

$applicant_id = dec_id($_GET['id'] ?? '');

$stmt = $pdo->prepare("SELECT resume_filename, resume_original_name, first_name, last_name FROM job_applicants WHERE applicant_id = ?");
$stmt->execute([$applicant_id]);
$applicant = $stmt->fetch();

if (!$applicant) {
    http_response_code(404);
    die("Resume not found.");
}

$filepath = __DIR__ . '/uploads/resumes/' . $applicant['resume_filename'];

// Item 13 — path-traversal containment check, same pattern already
// used in applicant_document_download.php. resume_filename comes from
// the DB (server-generated random name at upload time), so this isn't
// reachable via user input today — added purely as defense in depth
// to match the safer sibling script.
$realBase = realpath(__DIR__ . '/uploads/resumes');
$realPath = realpath($filepath);
if ($realPath === false || $realBase === false || strpos($realPath, $realBase . DIRECTORY_SEPARATOR) !== 0 || !is_file($realPath)) {
    http_response_code(404);
    die("Resume file is missing on the server.");
}
$filepath = $realPath;

$ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
$mimeTypes = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

$downloadName = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $applicant['last_name'] . '_' . $applicant['first_name'] . '_resume.' . $ext);

// Discard anything already echoed (e.g. requireLogin()'s session-watcher
// <script>) before sending the file — otherwise those stray bytes get
// prepended to the response body while Content-Length still only counts
// the real file size, corrupting the PDF/DOCX (browser's viewer then
// fails with "We can't open this file"). Same fix as jsonResponse().
// See the ob_start() call in includes/config.php.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($filepath));
readfile($filepath);
exit();