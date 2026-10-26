<?php
// ============================================================
// applicant_document_download.php
// ------------------------------------------------------------
// Securely serves an applicant's uploaded requirement.
// uploads/applicant_documents/ is denied by .htaccess, so this is the
// only way to retrieve the file — and only for authenticated HR roles.
// Call as: applicant_document_download.php?id=<document_id>
// ============================================================
require_once 'includes/config.php';
// Applicant Review is viewable by HR Staff, HR Manager, and Admin.
// Keep the viewer permission aligned with that page; upload/reject actions
// remain restricted separately by applicant_review.php.
requireHR();

$document_id = dec_id($_GET['id'] ?? '');

$stmt = $pdo->prepare("SELECT * FROM applicant_documents WHERE document_id = ?");
$stmt->execute([$document_id]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    die("Document not found.");
}

$path = __DIR__ . '/uploads/applicant_documents/' . $doc['file_name'];

// Protection against path traversal — the file must really be inside the folder.
$realBase = realpath(__DIR__ . '/uploads/applicant_documents');
$realPath = realpath($path);
if ($realPath === false || $realBase === false || strpos($realPath, $realBase . DIRECTORY_SEPARATOR) !== 0 || !is_file($realPath)) {
    http_response_code(404);
    die("File not found on the server.");
}

$ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$mime = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
][$ext] ?? 'application/octet-stream';

// PDFs/images can be embedded in the in-system preview. Office documents
// cannot be rendered by the browser without a conversion service, so the
// preview request gets an explanatory page instead of silently downloading.
$canPreview = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true);
if (!$canPreview && isset($_GET['preview'])) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    $safeName = htmlspecialchars($doc['original_name'], ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><style>'
        . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f8fafc;color:#0f172a;font:15px Arial,sans-serif;text-align:center;padding:24px;box-sizing:border-box}'
        . '.box{max-width:460px;padding:28px;border:1px solid #dbeafe;border-radius:16px;background:#fff;box-shadow:0 12px 30px rgba(15,23,42,.08)}'
        . 'h2{margin:0 0 10px;color:#1d4ed8;font-size:19px}.box p{margin:0;line-height:1.5;color:#475569}'
        . '</style></head><body><div class="box"><h2>Preview unavailable</h2><p><strong>' . $safeName
        . '</strong> is an Office document. PDF and image requirements can be viewed here; this file must be converted to PDF before it can be previewed inside the system.</p></div></body></html>';
    exit;
}
$disposition = $canPreview ? 'inline' : 'attachment';

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
header('Content-Length: ' . filesize($realPath));
header('Content-Disposition: ' . $disposition . '; filename="' . basename($doc['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($realPath);
exit;