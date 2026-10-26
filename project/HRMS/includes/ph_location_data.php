<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// ========================================================
// ph_location_data.php — PH Region -> Province -> City/Municipality
// -> Barangay lookup helpers, backed by the PSGC-derived JSON files
// in includes/ph_psgc/.
//
// Used by:
//   - ph_location_lookup.php (AJAX endpoint the cascading <select>s
//     call as the applicant picks each level)
//   - apply.php (server-side, to re-render an already-selected chain
//     when the form reloads after a validation error, so the
//     dropdowns come back pre-filled without waiting on JS)
//
// Each JSON file is only read once per request and cached in a static
// var — the big one (barangays.json, ~41k rows) is never sent to the
// browser whole; callers always ask for one city's barangays at a time.
// ========================================================

function ph_loc_load($file) {
    static $cache = [];
    if (!isset($cache[$file])) {
        $path = __DIR__ . '/ph_psgc/' . $file;
        $json = is_file($path) ? file_get_contents($path) : '[]';
        $cache[$file] = json_decode($json, true) ?: [];
    }
    return $cache[$file];
}

function ph_regions() {
    $rows = ph_loc_load('regions.json');
    usort($rows, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    return $rows;
}

function ph_provinces($regionCode) {
    if ($regionCode === '' || $regionCode === null) return [];
    $rows = array_values(array_filter(ph_loc_load('provinces.json'), function ($p) use ($regionCode) {
        return $p['region'] === $regionCode;
    }));
    usort($rows, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    return $rows;
}

function ph_cities($provinceCode) {
    if ($provinceCode === '' || $provinceCode === null) return [];
    $rows = array_values(array_filter(ph_loc_load('cities.json'), function ($c) use ($provinceCode) {
        return $c['province'] === $provinceCode;
    }));
    usort($rows, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    return $rows;
}

function ph_barangays($cityCode) {
    if ($cityCode === '' || $cityCode === null) return [];
    $rows = array_values(array_filter(ph_loc_load('barangays.json'), function ($b) use ($cityCode) {
        return $b['city'] === $cityCode;
    }));
    usort($rows, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    return $rows;
}

// Render a chain of <select> elements already populated for a
// previously-submitted set of codes (used when apply.php reloads after
// a validation error). $prefix becomes the id/name prefix, e.g.
// "pob" -> pob_region, pob_province, pob_city[, pob_barangay].
function ph_render_cascade_options($prefix, $selected, $includeBarangay = true) {
    $regionCode   = $selected[$prefix . '_region']   ?? '';
    $provinceCode = $selected[$prefix . '_province'] ?? '';
    $cityCode     = $selected[$prefix . '_city']     ?? '';
    $barangayCode = $selected[$prefix . '_barangay'] ?? '';

    $provinces = $regionCode   !== '' ? ph_provinces($regionCode)   : [];
    $cities    = $provinceCode !== '' ? ph_cities($provinceCode)    : [];
    $barangays = ($includeBarangay && $cityCode !== '') ? ph_barangays($cityCode) : [];

    return [
        'region'   => $regionCode,
        'province' => $provinceCode,
        'city'     => $cityCode,
        'barangay' => $barangayCode,
        'provinces' => $provinces,
        'cities'    => $cities,
        'barangays' => $barangays,
    ];
}
