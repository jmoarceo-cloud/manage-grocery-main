<?php
require_once __DIR__ . '/includes/config.php';

$requestId = dec_id($_GET['request_id'] ?? '');
$field = (string) ($_GET['field'] ?? '');
$signatureFields = [
    'requester_signature',
    'requester_signature_reference',
    'finance_signature',
    'supplier_signature',
    'procurement_signature_reference',
];
if ($requestId <= 0 || !in_array($field, $signatureFields, true)) {
    http_response_code(404);
    exit;
}

$isAuthenticatedStaff = !empty($_SESSION['user_id']);
if ($isAuthenticatedStaff) {
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', ['admin', 'inventory_staff', 'finance'], true)) {
        http_response_code(403);
        exit;
    }
} else {
    $token = (string) ($_GET['token'] ?? '');
    if ($token === '' || !in_array($field, ['requester_signature', 'finance_signature', 'procurement_signature_reference'], true)) {
        http_response_code(404);
        exit;
    }
}

$stmt = $pdo->prepare("
    SELECT ir.*, s.procurement_signature_reference, u.procurement_signature_reference AS requester_signature_reference
    FROM inventory_requests ir
    LEFT JOIN suppliers s ON s.id = ir.supplier_id
    LEFT JOIN users u ON u.employee_id = ir.requested_by_employee_id
    WHERE ir.request_id = ?
    LIMIT 1
");
$stmt->execute([$requestId]);
$request = $stmt->fetch();
if (!$request) {
    http_response_code(404);
    exit;
}

if (!$isAuthenticatedStaff) {
    $tokenHash = hash('sha256', (string) ($_GET['token'] ?? ''));
    if (
        $request['status'] !== 'pending_supplier'
        || !hash_equals((string) ($request['supplier_approval_token_hash'] ?? ''), $tokenHash)
        || empty($request['supplier_approval_expires_at'])
        || strtotime($request['supplier_approval_expires_at']) < time()
    ) {
        http_response_code(404);
        exit;
    }
}

$dataUrl = $field === 'requester_signature_reference'
    ? ($request['requester_signature_reference'] ?? '')
    : ($request[$field] ?? '');
if (!hasDrawnPngSignature($dataUrl)) {
    http_response_code(404);
    exit;
}
$image = base64_decode(substr($dataUrl, 22), true);
if ($image === false) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($image));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $image;
