<?php
// ========================================================
// ph_location_lookup.php — AJAX endpoint for the cascading
// Region -> Province -> City/Municipality -> Barangay dropdowns.
//
// Public (no login) on purpose: apply.php is the public job-application
// form, and this endpoint only ever returns static PSGC reference data
// (region/province/city/barangay names), never anything user-specific.
//
// Request:  GET ph_location_lookup.php?level=province&region=CODE
//           GET ph_location_lookup.php?level=city&province=CODE
//           GET ph_location_lookup.php?level=barangay&city=CODE
// Response: 200 JSON array of {code, name}, e.g.
//           [{"code":"0128","name":"Ilocos Norte"}, ...]
// ========================================================

require_once __DIR__ . '/includes/ph_location_data.php';

header('Content-Type: application/json');

$level = $_GET['level'] ?? '';

switch ($level) {
    case 'province':
        $rows = ph_provinces(trim($_GET['region'] ?? ''));
        break;
    case 'city':
        $rows = ph_cities(trim($_GET['province'] ?? ''));
        break;
    case 'barangay':
        $rows = ph_barangays(trim($_GET['city'] ?? ''));
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid level. Use province, city, or barangay.']);
        exit;
}

$out = array_map(function ($r) {
    return ['code' => $r['code'], 'name' => $r['name']];
}, $rows);

echo json_encode($out);
