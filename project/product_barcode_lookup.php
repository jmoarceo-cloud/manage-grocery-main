<?php
require_once __DIR__ . '/includes/config.php';
requireInventoryStaff();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function barcode_lookup_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    barcode_lookup_response(405, ['ok' => false, 'error' => 'Only GET requests are supported.']);
}

$barcode = trim((string) ($_GET['barcode'] ?? ''));
if (!preg_match('/^\d{8}$|^\d{12,14}$/', $barcode)) {
    barcode_lookup_response(400, ['ok' => false, 'error' => 'Enter a valid 8, 12, 13, or 14-digit barcode.']);
}
if (!function_exists('curl_init')) {
    barcode_lookup_response(503, ['ok' => false, 'error' => 'Barcode lookup is unavailable because cURL is not enabled on this server.']);
}

$url = 'https://world.openfoodfacts.org/api/v2/product/' . rawurlencode($barcode)
    . '.json?fields=product_name,product_name_en,brands,quantity,categories,categories_tags';
$curl = curl_init($url);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_USERAGENT => 'WalaInventory/1.0 (barcode product lookup)',
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$response = curl_exec($curl);
$httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curlError = curl_error($curl);
curl_close($curl);

if ($response === false || $httpStatus < 200 || $httpStatus >= 300) {
    error_log('Open Food Facts barcode lookup failed: ' . ($curlError !== '' ? $curlError : 'HTTP ' . $httpStatus));
    barcode_lookup_response(502, ['ok' => false, 'error' => 'The barcode information service could not be reached. Please try again or enter the details manually.']);
}

$data = json_decode($response, true);
if (!is_array($data)) {
    error_log('Open Food Facts barcode lookup returned invalid JSON.');
    barcode_lookup_response(502, ['ok' => false, 'error' => 'The barcode information service returned an invalid response. Please try again or enter the details manually.']);
}
if ((int) ($data['status'] ?? 0) !== 1 || !is_array($data['product'] ?? null)) {
    barcode_lookup_response(200, ['ok' => true, 'found' => false]);
}

$sourceProduct = $data['product'];
$product = [];
foreach (['product_name', 'product_name_en', 'brands', 'quantity', 'categories'] as $field) {
    if (isset($sourceProduct[$field]) && is_scalar($sourceProduct[$field])) {
        $product[$field] = trim((string) $sourceProduct[$field]);
    }
}
$product['categories_tags'] = is_array($sourceProduct['categories_tags'] ?? null)
    ? array_values(array_filter($sourceProduct['categories_tags'], 'is_string'))
    : [];

barcode_lookup_response(200, ['ok' => true, 'found' => true, 'product' => $product]);
