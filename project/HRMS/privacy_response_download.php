<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/privacy_response_links.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$requestIdToken = (string) ($_GET['id'] ?? '');
$requestId = dec_id($requestIdToken);
if ($requestId <= 0 && ctype_digit($requestIdToken)) {
    $requestId = (int) $requestIdToken;
}
$expiresAt = filter_input(INPUT_GET, 'expires', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$signature = $_GET['signature'] ?? null;
if (
    $requestId === false || $requestId === null || $expiresAt === false || $expiresAt === null
    || !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D', $signature) || $expiresAt <= time()
) {
    http_response_code(404);
    exit('Response file not found or link expired.');
}

$stmt = $pdo->prepare("
    SELECT response_filename, response_original_name, response_expires_at, status, identity_verified_at
    FROM data_subject_requests
    WHERE request_id = ?
");
$stmt->execute([$requestId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);
if (
    !$request || $request['status'] !== 'fulfilled' || empty($request['identity_verified_at'])
    || empty($request['response_filename']) || empty($request['response_expires_at'])
) {
    http_response_code(404);
    exit('Response file not found or link expired.');
}

$storedExpiry = strtotime((string) $request['response_expires_at']);
if (
    $storedExpiry === false || $storedExpiry !== $expiresAt || $storedExpiry <= time()
    || !hash_equals(privacyResponseSignature((int) $requestId, (int) $expiresAt, (string) $request['response_filename']), $signature)
) {
    http_response_code(404);
    exit('Response file not found or link expired.');
}

$filename = (string) $request['response_filename'];
if (basename($filename) !== $filename || !preg_match('/^dsr_[a-f0-9]{32}\.pdf$/D', $filename)) {
    http_response_code(404);
    exit('Response file not found or link expired.');
}
$baseDirectory = realpath(privacyResponseDirectory());
$filepath = $baseDirectory === false ? false : realpath($baseDirectory . DIRECTORY_SEPARATOR . $filename);
if ($filepath === false || strpos($filepath, $baseDirectory . DIRECTORY_SEPARATOR) !== 0 || !is_file($filepath)) {
    http_response_code(404);
    exit('Response file not found or link expired.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
$downloadName = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) ($request['response_original_name'] ?: 'privacy-response.pdf'));
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($filepath));
header('X-Content-Type-Options: nosniff');
readfile($filepath);
exit;
