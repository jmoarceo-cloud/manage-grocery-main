<?php
require_once 'includes/config.php';
requireLogin();

$document_id = dec_id($_GET['id'] ?? '');

$stmt = $pdo->prepare("SELECT * FROM employee_documents WHERE document_id = ?");
$stmt->execute([$document_id]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    die("Document not found.");
}

$isHrAccess = in_array($_SESSION['role'] ?? '', ['admin', 'hr', 'hr_manager'], true);
$isOwnerAccess = ($_SESSION['role'] ?? '') === 'employee'
    && !empty($_SESSION['employee_id'])
    && (string) $_SESSION['employee_id'] === (string) $doc['employee_id'];
if (!$isHrAccess && !$isOwnerAccess) {
    http_response_code(403);
    die("You are not allowed to download this document.");
}

$filepath = __DIR__ . '/uploads/employee_documents/' . $doc['file_name'];

// Item 13 — path-traversal containment check, same pattern already
// used in applicant_document_download.php. $doc['file_name'] comes
// from the DB (server-generated random name at upload time), so this
// isn't reachable via user input today — added purely as defense in
// depth to match the safer sibling script.
$realBase = realpath(__DIR__ . '/uploads/employee_documents');
$realPath = realpath($filepath);
if ($realPath === false || $realBase === false || strpos($realPath, $realBase) !== 0 || !is_file($realPath)) {
    http_response_code(404);
    die("Document file is missing on the server.");
}
$filepath = $realPath;

$ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
$mimeTypes = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];
$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

$downloadName = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $doc['document_type'] . '_' . $doc['original_name']);

// Discard anything already echoed (e.g. requireLogin()'s session-watcher
// <script>) before sending the file — otherwise those stray bytes get
// prepended to the response body while Content-Length still only counts
// the real file size, corrupting the file (browser's viewer then fails
// with "We can't open this file"). Same fix as jsonResponse().
// See the ob_start() call in includes/config.php.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($filepath));
header('X-Content-Type-Options: nosniff');
readfile($filepath);
exit();