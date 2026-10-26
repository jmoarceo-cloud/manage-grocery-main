<?php
require_once __DIR__ . '/includes/config.php';
requireInventoryStaff();

$filename = basename((string) ($_GET['f'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}\.(?:jpg|png)$/', $filename)) {
    http_response_code(404);
    exit('Not found.');
}
$path = __DIR__ . '/uploads/ai_labels/' . $filename;
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found.');
}
$mime = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
