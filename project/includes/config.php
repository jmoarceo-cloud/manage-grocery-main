<?php

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit;
}
if (!defined('APP_LOADED')) {
    define('APP_LOADED', true);
}

// Match HRMS/includes/config.php — without this, PHP falls back to the
// server's default timezone (usually UTC), which made self time-in/out
// on the cashier's My Profile page save and display times 8 hours off
// from actual Philippine time.
date_default_timezone_set('Asia/Manila');

// Shared "Contact Number" country-selector + exact-digit-length
// validation, used by suppliers.php and the Personal Information
// self-edit form below. Mirrored at HRMS/includes/phone_countries.php.
require_once __DIR__ . '/phone_countries.php';
require_once __DIR__ . '/procurement_financial_summary.php';

// Load DB credentials + APP_ENV from .env (project root) instead of
// hardcoding them here. See includes/env.php and .env.example. Falls
// back to the previous root/no-password XAMPP defaults if no .env file
// exists yet, so a fresh checkout still works out of the box.
require_once __DIR__ . '/env.php';
loadEnv(__DIR__ . '/../.env');

function systemBaseUrl()
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $appRoot = realpath(__DIR__ . '/..');
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $base = '';
    if ($appRoot && $docRoot && strpos($appRoot, $docRoot) === 0) {
        $relative = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
        $segments = array_values(array_filter(explode('/', $relative), 'strlen'));
        // Empty at the domain root, so callers' `systemBaseUrl() . '/x'`
        // never becomes the protocol-relative `//x`.
        $base = $segments ? '/' . implode('/', array_map('rawurlencode', $segments)) : '';
    }

    return $base;
}

function systemUrl(string $path): string
{
    return rtrim(systemBaseUrl(), '/') . '/' . ltrim($path, '/');
}

function hasDrawnPngSignature($dataUrl, $maxBytes = 500000)
{
    if (!is_string($dataUrl) || !preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $dataUrl))
        return false;
    $raw = base64_decode(substr($dataUrl, 22), true);
    if ($raw === false || strlen($raw) > $maxBytes || substr($raw, 0, 8) !== "\x89PNG\r\n\x1a\n")
        return false;
    // On hosts without GD, a blank canvas produces only a tiny PNG while a
    // drawn signature contains substantially more image data.
    if (!function_exists('imagecreatefromstring'))
        return strlen($raw) > 500;
    $image = @imagecreatefromstring($raw);
    if (!$image)
        return false;
    $width = imagesx($image);
    $height = imagesy($image);
    $hasInk = false;
    for ($y = 0; $y < $height && !$hasInk; $y += max(1, (int) floor($height / 100))) {
        for ($x = 0; $x < $width; $x += max(1, (int) floor($width / 100))) {
            $rgba = imagecolorat($image, $x, $y);
            $alpha = ($rgba & 0x7F000000) >> 24;
            $red = ($rgba >> 16) & 0xFF;
            $green = ($rgba >> 8) & 0xFF;
            $blue = $rgba & 0xFF;
            if ($alpha < 120 && ($red < 245 || $green < 245 || $blue < 245)) {
                $hasInk = true;
                break;
            }
        }
    }
    imagedestroy($image);
    return $hasInk;
}

function procurementSignatureSimilarity($submittedDataUrl, $referenceDataUrl)
{
    if (!hasDrawnPngSignature($submittedDataUrl) || !hasDrawnPngSignature($referenceDataUrl) || !function_exists('imagecreatefromstring')) {
        return 0.0;
    }
    $images = [];
    foreach ([$submittedDataUrl, $referenceDataUrl] as $dataUrl) {
        $raw = base64_decode(substr($dataUrl, 22), true);
        $image = $raw === false ? false : @imagecreatefromstring($raw);
        if (!$image)
            return 0.0;
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $width = imagesx($image);
        $height = imagesy($image);
        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;
        for ($y = 0; $y < $height; $y += max(1, (int) floor($height / 120))) {
            for ($x = 0; $x < $width; $x += max(1, (int) floor($width / 120))) {
                $rgba = imagecolorat($image, $x, $y);
                $alpha = ($rgba & 0x7F000000) >> 24;
                $red = ($rgba >> 16) & 0xFF;
                $green = ($rgba >> 8) & 0xFF;
                $blue = $rgba & 0xFF;
                if ($alpha < 120 && ($red < 245 || $green < 245 || $blue < 245)) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }
        if ($maxX < 0) {
            imagedestroy($image);
            return 0.0;
        }
        $pad = 8;
        $minX = max(0, $minX - $pad);
        $minY = max(0, $minY - $pad);
        $maxX = min($width - 1, $maxX + $pad);
        $maxY = min($height - 1, $maxY + $pad);
        $crop = imagecreatetruecolor(64, 32);
        imagealphablending($crop, false);
        imagesavealpha($crop, true);
        $transparent = imagecolorallocatealpha($crop, 255, 255, 255, 127);
        imagefill($crop, 0, 0, $transparent);
        imagecopyresampled($crop, $image, 0, 0, $minX, $minY, 64, 32, $maxX - $minX + 1, $maxY - $minY + 1);
        imagedestroy($image);
        $images[] = $crop;
    }
    $matches = 0;
    $total = 64 * 32;
    for ($y = 0; $y < 32; $y++) {
        for ($x = 0; $x < 64; $x++) {
            $a = imagecolorat($images[0], $x, $y);
            $b = imagecolorat($images[1], $x, $y);
            $aInk = (($a & 0x7F000000) >> 24) < 120 && (($a & 0x00FFFFFF) < 0x00F5F5F5);
            $bInk = (($b & 0x7F000000) >> 24) < 120 && (($b & 0x00FFFFFF) < 0x00F5F5F5);
            if ($aInk === $bInk)
                $matches++;
        }
    }
    foreach ($images as $image)
        imagedestroy($image);
    return $matches / $total;
}

// Attendance location policy. "exact" is the production default. The
// explicit "approximate" mode is useful on desktops without GPS, but the
// stored accuracy value tells reviewers that the reading is only coarse.
$__locationMode = env('ATTENDANCE_LOCATION_MODE', 'exact');
$__locationMaxAccuracy = (float) env('ATTENDANCE_MAX_ACCURACY_METERS', '200');
if ($__locationMode === 'approximate') {
    $__locationMaxAccuracy = max($__locationMaxAccuracy, 50000);
}
define('ATTENDANCE_LOCATION_MODE', $__locationMode === 'approximate' ? 'approximate' : 'exact');
define('ATTENDANCE_MAX_ACCURACY_METERS', $__locationMaxAccuracy > 0 ? $__locationMaxAccuracy : 200);
define('ATTENDANCE_STORE_LATITUDE', (float) env('ATTENDANCE_STORE_LATITUDE', '14.2919444444'));
define('ATTENDANCE_STORE_LONGITUDE', (float) env('ATTENDANCE_STORE_LONGITUDE', '120.9586111111'));
define('ATTENDANCE_GEOFENCE_RADIUS_METERS', max(50.0, (float) env('ATTENDANCE_GEOFENCE_RADIUS_METERS', '500')));

function attendanceDistanceMeters($latitude, $longitude)
{
    $earthRadius = 6371000.0;
    $latDelta = deg2rad((float) $latitude - ATTENDANCE_STORE_LATITUDE);
    $lngDelta = deg2rad((float) $longitude - ATTENDANCE_STORE_LONGITUDE);
    $a = sin($latDelta / 2) ** 2
        + cos(deg2rad(ATTENDANCE_STORE_LATITUDE))
        * cos(deg2rad((float) $latitude))
        * sin($lngDelta / 2) ** 2;

    return $earthRadius * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
}

function validateAttendanceLocation($latitude, $longitude, $accuracy)
{
    $distance = attendanceDistanceMeters($latitude, $longitude);
    $allowedDistance = ATTENDANCE_GEOFENCE_RADIUS_METERS + (float) $accuracy;

    if (ATTENDANCE_LOCATION_MODE === 'exact' && $distance > $allowedDistance) {
        return [
            'valid' => false,
            'distance' => $distance,
            'error' => 'You are approximately ' . round($distance) . ' meters from the approved PCU Dasmariñas attendance site. Time In is allowed only within ' . round(ATTENDANCE_GEOFENCE_RADIUS_METERS) . ' meters of the site.',
        ];
    }

    return ['valid' => true, 'distance' => $distance, 'error' => ''];
}

// ID encryption/obfuscation helper (enc_id() / dec_id()) — shared with
// the HRMS side via HRMS/includes/config.php. See includes/id_crypt.php
// for the full explanation.
require_once __DIR__ . '/id_crypt.php';

// =====================================================
// PHP ERROR DISPLAY (Item 3 — Phase 1 security audit)
// ------------------------------------------------------
// Previously there was no explicit setting here at all, so whether PHP
// errors/SQL errors/stack traces were shown to the browser depended
// entirely on the machine's php.ini. XAMPP's default php.ini ships
// with display_errors On, which would leak sensitive info (queries,
// file paths, etc.) if this app ever ran on a production php.ini with
// the same default. APP_ENV=local (see .env) keeps errors visible for
// development; anything else (including APP_ENV simply not being set)
// defaults to hiding them, which is the safer default for production.
$__appEnv = env('APP_ENV', 'production');
if (in_array($__appEnv, ['local', 'development'], true)) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
error_reporting(E_ALL);
ini_set('log_errors', '1');

// Baseline browser hardening. HSTS is intentionally not sent here until
// the application is deployed behind HTTPS, because this site currently
// runs on localhost during development.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(self), microphone=(), geolocation=(self)');
$cspImageSource = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'products.php'
    ? "img-src 'self' data: blob:;"
    : "img-src 'self' data:;";
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; {$cspImageSource} script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; connect-src 'self'; frame-ancestors 'self';");

$host = env('DB_HOST', 'localhost');
$username = env('DB_USERNAME', '');
$password = env('DB_PASSWORD', '');
$database = env('DB_NAME_POS', 'grocery_pos');

if ($username === '') {
    http_response_code(503);
    die('Database configuration is incomplete. Please contact the system administrator.');
}

try {
    $pdo = new PDO(
        "mysql:host=$host;port=" . (int) env('DB_PORT', 3306) . ";dbname=$database;charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_TIMEOUT => 3]
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Was `catch (PDOException $e)` — too narrow. A bad/missing `pdo_mysql`
    // extension (e.g. not enabled in php.ini) throws an \Error, not a
    // PDOException, which slipped straight past this catch and crashed as
    // a blank white-screen HTTP 500 instead of showing this message.
    // Widening to \Throwable catches both cases.
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(503);
    die("
        <h3 style='color:red; font-family:sans-serif;'>
            Could not connect to the database!
        </h3>
        <p style='font-family:sans-serif;'>
            Please check the XAMPP Control Panel to see if MySQL is ON,
            or contact the system developer.
        </p>
    ");
}

// =====================================================
// MIGRATION GATE (Item 9 — Phase 3 reliability audit)
// ------------------------------------------------------
// Every ensureXTable()/ensureXColumn() call below is individually
// idempotent (CREATE TABLE IF NOT EXISTS, or SHOW COLUMNS then ALTER),
// so running them is always *safe* — but running 20-30+ of them on
// every single page load (each a SHOW COLUMNS/SHOW TABLES round-trip)
// adds real per-request overhead as traffic grows, and a few of the
// ALTER TABLE ones carry a small risk of colliding if two requests
// somehow raced on the very first request after a fresh migration was
// added (each already has its own try/catch + error_log so a collision
// is logged and skipped rather than fatal, but it's still needless
// work on every normal request once the schema is already up to date).
//
// This file's own mtime changes whenever a migration call is added or
// edited here, so it's used as a free, self-maintaining signal: "have
// the migrations for this exact version of config.php already run?"
// No manual version number to remember to bump — if this file is
// edited, the very next request re-verifies everything once, then
// goes back to skipping. If the marker file can't be read/written for
// any reason, this safely falls back to the original behavior (always
// verify), so it can never be *less* safe than before.
// =====================================================
$__migrationMarkerFile = __DIR__ . '/.migrations_verified';
$__configMtime = filemtime(__FILE__);
$__migrationsNeeded = true;
if (is_file($__migrationMarkerFile)) {
    $__storedMtime = @file_get_contents($__migrationMarkerFile);
    if ($__storedMtime !== false && ctype_digit(trim($__storedMtime)) && (int) trim($__storedMtime) === $__configMtime) {
        $__migrationsNeeded = false;
    }
}

// =====================================================
// INVENTORY REQUESTS TABLE (Supervisor -> Inventory Staff workflow)
// ------------------------------------------------------
// Also ensured here (mirrors HRMS/includes/config.php's copy of this
// same function) so the table exists on the POS side regardless of
// whether HRMS or POS pages get loaded first. Without this, loading
// inventory_requests.php before any HRMS page ever ran would fail
// with "Table 'grocery_pos.inventory_requests' doesn't exist".
// =====================================================
function ensureInventoryRequestsTable($posPdo)
{
    if (!$posPdo)
        return;
    try {
        $posPdo->exec("
            CREATE TABLE IF NOT EXISTS inventory_requests (
                request_id int(11) NOT NULL AUTO_INCREMENT,
                requested_by_employee_id int(11) NOT NULL,
                product_id int(11) DEFAULT NULL,
                item_name varchar(150) NOT NULL,
                quantity int(11) NOT NULL,
                estimated_cost decimal(10,2) DEFAULT NULL,
                validated_by int(11) DEFAULT NULL,
                validated_at timestamp NULL DEFAULT NULL,
                notes text DEFAULT NULL,
                status enum('pending_validation','pending_finance','pending_supplier','ready_to_receive','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending_validation',
                decided_by int(11) DEFAULT NULL,
                decided_at timestamp NULL DEFAULT NULL,
                received_by int(11) DEFAULT NULL,
                received_at timestamp NULL DEFAULT NULL,
                requested_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureInventoryRequestsTable (POS side) skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureInventoryRequestsTable($pdo);
}

function ensurePasswordResetRequestsTable($posPdo)
{
    if (!$posPdo)
        return;
    try {
        $posPdo->exec("
            CREATE TABLE IF NOT EXISTS password_reset_requests (
                request_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                username VARCHAR(254) NOT NULL,
                account_system ENUM('pos','hrms') NOT NULL DEFAULT 'pos',
                user_id INT DEFAULT NULL,
                ip_hash CHAR(64) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                status ENUM('pending','approved','handled','dismissed') NOT NULL DEFAULT 'pending',
                handled_by INT DEFAULT NULL,
                handled_at DATETIME DEFAULT NULL,
                approved_by INT DEFAULT NULL,
                approved_at DATETIME DEFAULT NULL,
                token_hash CHAR(64) DEFAULT NULL,
                token_expires_at DATETIME DEFAULT NULL,
                email_sent_at DATETIME DEFAULT NULL,
                used_at DATETIME DEFAULT NULL,
                notes VARCHAR(500) DEFAULT NULL,
                requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (request_id),
                KEY idx_password_reset_status (status, requested_at),
                KEY idx_password_reset_username (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log("ensurePasswordResetRequestsTable skipped: " . $e->getMessage());
    }
    try {
        $columns = [];
        foreach ($posPdo->query("SHOW COLUMNS FROM password_reset_requests")->fetchAll() as $column)
            $columns[$column['Field']] = true;
        $definitions = [
            'account_system' => "ENUM('pos','hrms') NOT NULL DEFAULT 'pos'",
            'user_id' => 'INT DEFAULT NULL',
            'approved_by' => 'INT DEFAULT NULL',
            'approved_at' => 'DATETIME DEFAULT NULL',
            'token_hash' => 'CHAR(64) DEFAULT NULL',
            'token_expires_at' => 'DATETIME DEFAULT NULL',
            'email_sent_at' => 'DATETIME DEFAULT NULL',
            'used_at' => 'DATETIME DEFAULT NULL',
        ];
        foreach ($definitions as $name => $definition)
            if (!isset($columns[$name]))
                $posPdo->exec("ALTER TABLE password_reset_requests ADD COLUMN {$name} {$definition}");
        $posPdo->exec("ALTER TABLE password_reset_requests MODIFY username VARCHAR(254) NOT NULL");
        $posPdo->exec("ALTER TABLE password_reset_requests MODIFY status ENUM('pending','approved','handled','dismissed') NOT NULL DEFAULT 'pending'");
    } catch (PDOException $e) {
        error_log("ensurePasswordResetRequestsTable migration skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensurePasswordResetRequestsTable($pdo);
}

// inventory_requests.status started out as enum('pending','approved',
// 'rejected','fulfilled') — before the Finance approval gate existed.
// A restock request now has to clear Finance ('pending_finance') before
// it reaches the usual Inventory/Admin review ('pending'). On a
// database created before that change, the enum never got widened, so
// a new request or the finance_approve/reject actions in finance.php
// would silently fail (invalid enum value). This makes sure the enum
// always includes it, without touching any other value.
function ensureInventoryRequestFinanceStatusEnum($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM inventory_requests LIKE 'status'");
        $col = $stmt->fetch();
        if ($col && stripos($col['Type'], 'pending_finance') === false) {
            $pdo->exec("ALTER TABLE inventory_requests MODIFY status ENUM('pending_finance','pending','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending_finance'");
        }
    } catch (PDOException $e) {
        error_log("ensureInventoryRequestFinanceStatusEnum skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureInventoryRequestFinanceStatusEnum($pdo);
}

function ensureProcurementSignatureReferenceColumns($pdo)
{
    try {
        foreach ([
            'users' => ['procurement_signature_reference' => 'LONGTEXT NULL', 'procurement_signature_enrolled_at' => 'DATETIME NULL'],
            'suppliers' => ['procurement_signature_reference' => 'LONGTEXT NULL', 'procurement_signature_enrolled_at' => 'DATETIME NULL'],
        ] as $table => $columns) {
            $existing = $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($columns as $name => $definition) {
                if (!in_array($name, $existing, true)) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
                }
            }
        }
        $requestColumns = $pdo->query("SHOW COLUMNS FROM inventory_requests")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('requester_signature_checked_by', $requestColumns, true)) {
            $pdo->exec("ALTER TABLE inventory_requests ADD COLUMN requester_signature_checked_by INT DEFAULT NULL AFTER requester_signature_at");
        }
        if (!in_array('requester_signature_checked_at', $requestColumns, true)) {
            $pdo->exec("ALTER TABLE inventory_requests ADD COLUMN requester_signature_checked_at DATETIME DEFAULT NULL AFTER requester_signature_checked_by");
        }
    } catch (PDOException $e) {
        error_log("ensureProcurementSignatureReferenceColumns skipped: " . $e->getMessage());
    }
}
ensureProcurementSignatureReferenceColumns($pdo);

// Restock workflow v2 (confirmed Aug 2026 — see the flow diagram):
// an Inventory Staff *validation* step now happens BEFORE Finance ever
// sees a request, instead of after:
//   Supervisor submits -> 'pending_validation' -> Inventory Staff checks
//   actual stock vs. the requested quantity and Validates/Confirms (or
//   Rejects) -> 'pending_finance' -> Finance reviews the estimated cost
//   and Approves/Rejects -> 'approved' -> Inventory Staff receives the
//   delivery and updates stock -> 'fulfilled'.
// This widens the enum, migrates any requests already sitting in the
// old two-stage flow, narrows the enum to the final value set, and
// adds the new tracking columns (estimated_cost for what Finance
// reviews, validated_by/at for the Inventory Staff validation step,
// received_by/at for the final receive-and-restock step — decided_by/at
// is kept for Finance's own approve/reject decision).
//   old 'pending_finance' (nobody has acted on it yet) -> pending_validation
//   old 'pending' or 'approved' (Finance already decided)  -> approved
function ensureInventoryRequestValidationWorkflow($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM inventory_requests LIKE 'status'");
        $col = $stmt->fetch();
        if ($col && stripos($col['Type'], 'pending_validation') === false) {
            $pdo->exec("ALTER TABLE inventory_requests MODIFY status ENUM('pending_finance','pending','pending_validation','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending_validation'");
            $pdo->exec("UPDATE inventory_requests SET status = 'pending_validation' WHERE status = 'pending_finance'");
            $pdo->exec("UPDATE inventory_requests SET status = 'approved' WHERE status = 'pending'");
            $pdo->exec("ALTER TABLE inventory_requests MODIFY status ENUM('pending_validation','pending_finance','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending_validation'");
        }

        $existingCols = $pdo->query("SHOW COLUMNS FROM inventory_requests")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('estimated_cost', $existingCols, true)) {
            $pdo->exec("ALTER TABLE inventory_requests ADD COLUMN estimated_cost decimal(10,2) DEFAULT NULL AFTER quantity");
        }
        if (!in_array('validated_by', $existingCols, true)) {
            $pdo->exec("ALTER TABLE inventory_requests ADD COLUMN validated_by int(11) DEFAULT NULL AFTER estimated_cost");
        }
        if (!in_array('validated_at', $existingCols, true)) {
            $pdo->exec("ALTER TABLE inventory_requests ADD COLUMN validated_at timestamp NULL DEFAULT NULL AFTER validated_by");
        }
        if (!in_array('received_by', $existingCols, true)) {
            $pdo->exec("ALTER TABLE inventory_requests ADD COLUMN received_by int(11) DEFAULT NULL AFTER decided_at");
        }
        if (!in_array('received_at', $existingCols, true)) {
            $pdo->exec("ALTER TABLE inventory_requests ADD COLUMN received_at timestamp NULL DEFAULT NULL AFTER received_by");
        }
        if (!in_array('finance_signature', $existingCols, true)) {
            $pdo->exec("ALTER TABLE inventory_requests ADD COLUMN finance_signature LONGTEXT NULL AFTER decided_at");
        }
        if (!in_array('finance_signature_at', $existingCols, true)) {
            $pdo->exec("ALTER TABLE inventory_requests ADD COLUMN finance_signature_at DATETIME NULL AFTER finance_signature");
        }
        $workflowColumns = [
            'requester_full_name' => "VARCHAR(150) NULL",
            'requester_signature' => "LONGTEXT NULL",
            'requester_signature_at' => "DATETIME NULL",
            'procurement_letter' => "LONGTEXT NULL",
            'supplier_approval_token_hash' => "CHAR(64) NULL",
            'supplier_approval_expires_at' => "DATETIME NULL",
            'supplier_signature' => "LONGTEXT NULL",
            'supplier_signature_at' => "DATETIME NULL",
        ];
        foreach ($workflowColumns as $name => $definition) {
            if (!in_array($name, $existingCols, true)) {
                $pdo->exec("ALTER TABLE inventory_requests ADD COLUMN {$name} {$definition}");
            }
        }
        $pdo->exec("ALTER TABLE inventory_requests MODIFY status ENUM('pending_validation','pending_finance','pending_supplier','ready_to_receive','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending_validation'");
    } catch (PDOException $e) {
        error_log("ensureInventoryRequestValidationWorkflow skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureInventoryRequestValidationWorkflow($pdo);
}

// =====================================================
// DISCOUNT REQUESTS TABLE (Cashier -> Finance approval workflow)
// ------------------------------------------------------
// A manual discount a cashier keys in at the register (flat percent
// or fixed peso amount) is discretionary — unlike the legally
// mandated Senior Citizen / PWD discount (RA 9994 / RA 10754 + BIR RR
// 7-2010), which only needs a valid OSCA/PWD ID and stays applied
// immediately. To stop cashiers from granting ad-hoc discounts
// unchecked, a percent/fixed discount request now has to be approved
// by Finance (or Admin) before it's actually subtracted from the
// total — see the 'percent'/'fixed' branch of the apply_discount
// action in pos.php (which now INSERTs a row here instead of
// activating the discount right away) and the finance_approve_discount
// / finance_reject_discount actions in finance.php.
// =====================================================
function ensureDiscountRequestsTable($pdo)
{
    if (!$pdo)
        return;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS discount_requests (
                request_id int(11) NOT NULL AUTO_INCREMENT,
                cashier_id int(11) NOT NULL,
                discount_type enum('percent','fixed') NOT NULL,
                discount_value decimal(10,2) NOT NULL,
                subtotal_at_request decimal(10,2) NOT NULL,
                computed_discount_amount decimal(10,2) NOT NULL,
                reason varchar(255) DEFAULT NULL,
                status enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
                reject_reason varchar(255) DEFAULT NULL,
                decided_by int(11) DEFAULT NULL,
                decided_at timestamp NULL DEFAULT NULL,
                requested_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureDiscountRequestsTable skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureDiscountRequestsTable($pdo);
}

// =====================================================
// PRODUCT DISCOUNT REQUESTS TABLE (Inventory -> Finance approval)
// ------------------------------------------------------
// Same idea as discount_requests above, but for the product-level
// discount an Inventory Staff/Admin sets while editing a product on
// products.php. A change to a product's discount_type/discount_value/
// discount_reason no longer writes straight to the products table —
// it lands here as 'pending' first, and only gets applied to the
// product once Finance approves it (finance_approve_product_discount
// / finance_reject_product_discount actions in finance.php). Every
// other field on the edit form (name, category, price, supplier,
// image) still saves immediately as before.
// =====================================================
function ensureProductDiscountRequestsTable($pdo)
{
    if (!$pdo)
        return;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS product_discount_requests (
                request_id int(11) NOT NULL AUTO_INCREMENT,
                product_id int(11) NOT NULL,
                requested_by int(11) NOT NULL,
                discount_type enum('none','percent','fixed') NOT NULL DEFAULT 'none',
                discount_value decimal(10,2) NOT NULL DEFAULT 0,
                discount_reason varchar(255) DEFAULT NULL,
                price_at_request decimal(10,2) NOT NULL,
                old_discount_type enum('none','percent','fixed') NOT NULL DEFAULT 'none',
                old_discount_value decimal(10,2) NOT NULL DEFAULT 0,
                old_discount_reason varchar(255) DEFAULT NULL,
                status enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
                reject_reason varchar(255) DEFAULT NULL,
                decided_by int(11) DEFAULT NULL,
                decided_at timestamp NULL DEFAULT NULL,
                requested_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureProductDiscountRequestsTable skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureProductDiscountRequestsTable($pdo);
}

// Shared by pos.php (cart total) and finance.php (approval preview) so
// the peso amount Finance sees when reviewing a request always matches
// what actually gets applied once it's approved.
function computeDiscountAmountFor($mode, $value, $subtotal)
{
    $value = (float) $value;
    $subtotal = (float) $subtotal;
    if ($mode === 'percent') {
        return round($subtotal * ($value / 100), 2);
    } elseif ($mode === 'fixed') {
        return min($value, $subtotal);
    }
    return 0.0;
}

// =====================================================
// HRMS DATABASE CONNECTION (para sa POS <-> HRMS integration)
// ------------------------------------------------------
// Ginagamit ito para makabasa ng HRMS employee/attendance/payslip
// data mula sa POS side (My Profile page) at para i-sync ang
// cashier accounts papunta sa "users" table ng POS — see
// syncCashierAccountsFromHRMS() below. Kung hindi ma-connect
// (halimbawa, wala pang HRMS database), hindi na natin ipapa-die
// yung buong POS — HRMS-related features lang ang mawawala.
// =====================================================
$hrmsPdo = null;
try {
    $hrmsPdo = new PDO(
        "mysql:host=$host;port=" . (int) env('DB_PORT', 3306) . ";dbname=" . env('DB_NAME_HRMS', 'hrms') . ";charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_TIMEOUT => 3]
    );
    $hrmsPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $hrmsPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Widened from `catch (PDOException $e)` — see the matching note on
    // the main $pdo connection above. Kept fail-open (null, no die()).
    error_log("HRMS database connection failed (POS<->HRMS features will be unavailable): " . $e->getMessage());
    $hrmsPdo = null;
}

// Keep the HRMS attendance table ready when a cashier reaches Time In
// through the POS app before anyone has opened an HRMS page.
function ensureCashierAttendanceAccuracyColumn($hrmsPdo)
{
    if (!$hrmsPdo)
        return;
    try {
        $stmt = $hrmsPdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'attendance' AND column_name = 'time_in_accuracy_meters'");
        $stmt->execute();
        if ((int) $stmt->fetchColumn() === 0) {
            $hrmsPdo->exec("ALTER TABLE attendance ADD COLUMN time_in_accuracy_meters DECIMAL(8,2) NULL AFTER time_in_longitude");
        }
    } catch (PDOException $e) {
        error_log("ensureCashierAttendanceAccuracyColumn skipped: " . $e->getMessage());
    }
}
ensureCashierAttendanceAccuracyColumn($hrmsPdo);

// =====================================================
// COMPANY-WIDE ANNOUNCEMENTS (read from the POS side)
// ------------------------------------------------------
// Mirrors getCompanyWideAnnouncementsForPos() in HRMS/includes/config.php
// byte-for-byte. POS pages (includes/announcements_panel.php,
// announcements.php) call this via $hrmsPdo, but POS's own config.php
// never requires HRMS's config.php — so without this local copy the
// function simply doesn't exist here, function_exists() silently
// returns false, and the fail-open check in those pages makes the
// Announcements panel/page always render empty. Keeping this in sync
// with the HRMS copy if the query/columns ever change there.
// =====================================================
function getCompanyWideAnnouncementsForPos($hrmsPdo, $limit = 8)
{
    if (!$hrmsPdo)
        return [];
    try {
        $limit = max(1, (int) $limit);
        return $hrmsPdo->query("
            SELECT id, title, content, published_at, expires_at
            FROM announcements
            WHERE scope = 'company_wide' AND status = 'published'
              AND (expires_at IS NULL OR expires_at >= CURDATE())
            ORDER BY published_at DESC
            LIMIT $limit
        ")->fetchAll();
    } catch (PDOException $e) {
        error_log("getCompanyWideAnnouncementsForPos skipped: " . $e->getMessage());
        return [];
    }
}

// Per-POS-account "read" state for company-wide announcements (see
// announcements.php). Lives on the POS side (not `hrms`) because it's
// keyed off THIS database's users.id — the announcements themselves
// stay in `hrms`, this table just remembers which of them a given POS
// account has already seen. No FK to hrms.announcements (different
// database/server entirely) — announcement_id is just stored as a
// plain int, same cross-database pattern already used elsewhere here.
function ensureAnnouncementReadsTable($pdo)
{
    if (!$pdo)
        return;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS announcement_reads (
                id int(11) NOT NULL AUTO_INCREMENT,
                user_id int(11) NOT NULL,
                announcement_id int(11) NOT NULL,
                read_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (id),
                UNIQUE KEY uniq_user_announcement (user_id, announcement_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureAnnouncementReadsTable skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureAnnouncementReadsTable($pdo);
}

// =====================================================
// HRMS-SIDE TABLES ALSO TOUCHED FROM THE POS SIDE
// ------------------------------------------------------
// overtime_requests and holidays live in the `hrms` database and
// are normally created by HRMS/includes/config.php's own copies of
// these functions. But POS pages (my_profile.php, etc.) read/write
// them too via $hrmsPdo, so we mirror the same "ensure" functions
// here — otherwise a POS page loaded before any HRMS page would hit
// "Table 'hrms.overtime_requests' doesn't exist" (same class of bug
// as ensureInventoryRequestsTable() above).
// =====================================================
function ensureOvertimeRequestsTable($pdo)
{
    if (!$pdo)
        return;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS overtime_requests (
                ot_id int(11) NOT NULL AUTO_INCREMENT,
                employee_id int(11) NOT NULL,
                ot_date date NOT NULL,
                hours_requested decimal(4,2) NOT NULL,
                reason text DEFAULT NULL,
                status enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                approved_by int(11) DEFAULT NULL,
                decided_at timestamp NULL DEFAULT NULL,
                requested_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (ot_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureOvertimeRequestsTable (POS side) skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureOvertimeRequestsTable($hrmsPdo);
}

// late_timein_requests — same idea, mirrors HRMS/includes/config.php's
// ensureLateTimeInRequestsTable(). Cashiers submit these from the POS
// "My Profile" page (see processCashierSelfTimeInOut() below); a
// Supervisor can act on them either remotely on the HRMS side
// (supervisor_late_timein.php) or right on the cashier's POS screen
// via the "Supervisor Time In Now" popup.
function ensureCashierLateTimeInRequestsTable($hrmsPdo)
{
    if (!$hrmsPdo)
        return;
    try {
        $hrmsPdo->exec("
            CREATE TABLE IF NOT EXISTS late_timein_requests (
                request_id int(11) NOT NULL AUTO_INCREMENT,
                employee_id int(11) NOT NULL,
                request_date date NOT NULL,
                requested_time time NOT NULL,
                reason text DEFAULT NULL,
                status enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                approved_by int(11) DEFAULT NULL,
                decided_at timestamp NULL DEFAULT NULL,
                requested_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (request_id),
                KEY idx_employee_date (employee_id, request_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureCashierLateTimeInRequestsTable skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureCashierLateTimeInRequestsTable($hrmsPdo);
}

// Most recent Time In request (any status) a cashier has made for a
// given date. Mirrors getLateTimeInRequestForDate() in
// HRMS/includes/config.php (same shared `late_timein_requests` table
// in the `hrms` database, read from both sides).
function getCashierLateTimeInRequestForDate($hrmsPdo, $employee_id, $date)
{
    if (!$hrmsPdo || !$employee_id)
        return null;
    try {
        $stmt = $hrmsPdo->prepare("
            SELECT * FROM late_timein_requests
            WHERE employee_id = ? AND request_date = ?
            ORDER BY requested_at DESC LIMIT 1
        ");
        $stmt->execute([$employee_id, $date]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("getCashierLateTimeInRequestForDate error: " . $e->getMessage());
        return null;
    }
}

// Whether $supervisorEmployeeId (an HRMS employee_id) is allowed to
// act on $targetEmployeeId — i.e. the cashier is on their team.
// Mirrors supervisorManagesEmployee() in HRMS/includes/config.php
// (same `supervisor_teams` table).
function cashierSupervisorManagesEmployee($hrmsPdo, $supervisorEmployeeId, $targetEmployeeId)
{
    if (!$hrmsPdo || !$supervisorEmployeeId || !$targetEmployeeId)
        return false;
    try {
        $stmt = $hrmsPdo->prepare("SELECT 1 FROM supervisor_teams WHERE supervisor_employee_id = ? AND member_employee_id = ? LIMIT 1");
        $stmt->execute([$supervisorEmployeeId, $targetEmployeeId]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("cashierSupervisorManagesEmployee error: " . $e->getMessage());
        return false;
    }
}

// Verifies a Supervisor's own HRMS login (username + password) right
// on the cashier's POS screen — powers the "Supervisor Time In Now"
// popup so a Supervisor standing at the terminal can Time In a
// cashier on the spot, instead of only being able to approve
// remotely from the HRMS side. Only succeeds for an ACTIVE Supervisor
// account who actually manages this cashier. Returns the HRMS
// `users` row (has employee_id, id, full_name) on success, or null.
function verifyCashierSupervisorLogin($hrmsPdo, $username, $password, $targetEmployeeId)
{
    if (!$hrmsPdo || $username === '' || $password === '' || !$targetEmployeeId)
        return null;
    try {
        $stmt = $hrmsPdo->prepare("SELECT * FROM users WHERE BINARY username = ? AND role = 'supervisor' AND is_active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password'])) {
            return null;
        }
        if (!cashierSupervisorManagesEmployee($hrmsPdo, (int) $user['employee_id'], $targetEmployeeId)) {
            return null;
        }
        return $user;
    } catch (PDOException $e) {
        error_log("verifyCashierSupervisorLogin error: " . $e->getMessage());
        return null;
    }
}

function ensureHolidaysTable($pdo)
{
    if (!$pdo)
        return;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS holidays (
                holiday_id   INT(11) NOT NULL AUTO_INCREMENT,
                holiday_date DATE NOT NULL,
                name         VARCHAR(100) NOT NULL,
                created_by   INT(11) NULL,
                created_at   TIMESTAMP NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (holiday_id),
                UNIQUE KEY uniq_holiday_date (holiday_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureHolidaysTable (POS side) skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureHolidaysTable($hrmsPdo);
}

// LEGACY — the one-time "Generate Code" discount approval flow has
// been replaced by verifyCashierSupervisorDiscountApproval() above
// (Supervisor enters their own username/password at the POS instead).
// This table/function is left in place (unused) only so old rows
// aren't lost; nothing calls into it anymore. Safe to drop later.
// Mirrors HRMS/includes/config.php's copy of this same
// function/table (see there for the full explanation) — ensured
// here too so the table exists regardless of whether HRMS or POS
// gets loaded first.
function ensureDiscountApprovalCodesTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS discount_approval_codes (
                code_id                  INT(11) NOT NULL AUTO_INCREMENT,
                code                     VARCHAR(6) NOT NULL,
                generated_by_employee_id INT(11) NOT NULL,
                generated_by_name        VARCHAR(150) NOT NULL,
                status                   ENUM('active','used','cancelled') NOT NULL DEFAULT 'active',
                used_by_cashier_id       INT(11) DEFAULT NULL,
                used_by_employee_id      INT(11) DEFAULT NULL,
                discount_type            VARCHAR(10) DEFAULT NULL,
                created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                used_at                  TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (code_id),
                KEY idx_code_status (code, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        // SELF-HEALING — see the matching comment in HRMS/includes/config.php's
        // copy of this function for the full explanation. Repairs an
        // old/partial table (missing columns, or a `status` ENUM that
        // doesn't yet include 'cancelled') instead of silently no-op'ing.
        $existingColumns = $pdo->query("SHOW COLUMNS FROM discount_approval_codes")
            ->fetchAll(PDO::FETCH_COLUMN, 0);

        $expectedColumns = [
            'code' => "VARCHAR(6) NOT NULL",
            'generated_by_employee_id' => "INT(11) NOT NULL DEFAULT 0",
            'generated_by_name' => "VARCHAR(150) NOT NULL DEFAULT ''",
            'status' => "ENUM('active','used','cancelled') NOT NULL DEFAULT 'active'",
            'used_by_cashier_id' => "INT(11) DEFAULT NULL",
            'used_by_employee_id' => "INT(11) DEFAULT NULL",
            'discount_type' => "VARCHAR(10) DEFAULT NULL",
            'created_at' => "TIMESTAMP NOT NULL DEFAULT current_timestamp()",
            'expires_at' => "TIMESTAMP NOT NULL DEFAULT current_timestamp()",
            'used_at' => "TIMESTAMP NULL DEFAULT NULL",
        ];

        foreach ($expectedColumns as $col => $definition) {
            if (!in_array($col, $existingColumns, true)) {
                $pdo->exec("ALTER TABLE discount_approval_codes ADD COLUMN $col $definition");
            }
        }

        $statusCol = $pdo->query("SHOW COLUMNS FROM discount_approval_codes LIKE 'status'")->fetch();
        if ($statusCol && strpos($statusCol['Type'], "'cancelled'") === false) {
            $pdo->exec("ALTER TABLE discount_approval_codes MODIFY status ENUM('active','used','cancelled') NOT NULL DEFAULT 'active'");
        }
    } catch (PDOException $e) {
        error_log("ensureDiscountApprovalCodesTable (POS side) skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureDiscountApprovalCodesTable($hrmsPdo);
}

// =====================================================
// EMPLOYEES: PERSONAL INFORMATION (mirrors
// ensureEmployeePersonalDetailsColumns() in HRMS/includes/config.php)
// ------------------------------------------------------
// The `employees` table lives in the `hrms` database, so this same
// idempotent column-check has to be mirrored here too — otherwise a
// POS page loaded before any HRMS page would hit "Unknown column"
// errors the first time my_profile.php tries to read/save these.
// `birthdate` and `address` are pre-existing columns, reused instead
// of duplicated.
// =====================================================
function ensureEmployeePersonalDetailsColumns($hrmsPdo)
{
    if (!$hrmsPdo)
        return;
    try {
        $checks = [
            'place_of_birth' => "ALTER TABLE employees ADD COLUMN place_of_birth VARCHAR(150) NULL AFTER birthdate",
            'sex' => "ALTER TABLE employees ADD COLUMN sex VARCHAR(20) NULL AFTER place_of_birth",
            'civil_status' => "ALTER TABLE employees ADD COLUMN civil_status VARCHAR(20) NULL AFTER sex",
            'highest_educational_attainment' => "ALTER TABLE employees ADD COLUMN highest_educational_attainment VARCHAR(60) NULL AFTER civil_status",
            'year_graduated' => "ALTER TABLE employees ADD COLUMN year_graduated VARCHAR(4) NULL AFTER highest_educational_attainment",
        ];
        foreach ($checks as $column => $alterSql) {
            $stmt = $hrmsPdo->prepare("
                SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'employees'
                  AND column_name = ?
            ");
            $stmt->execute([$column]);
            if (((int) $stmt->fetchColumn()) === 0) {
                $hrmsPdo->exec($alterSql);
            }
        }
    } catch (PDOException $e) {
        error_log("ensureEmployeePersonalDetailsColumns (POS side) skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureEmployeePersonalDetailsColumns($hrmsPdo);
}

// Dropdown choices for the Personal Information form (my_profile.php)
// — mirrors the same constants in HRMS/includes/config.php (kept in
// sync manually, since this is a separate app/config.php).
define('APPLICANT_SEX_OPTIONS', ['Male', 'Female']);
define('APPLICANT_CIVIL_STATUS_OPTIONS', ['Single', 'Married', 'Widowed', 'Separated', 'Divorced']);
define('APPLICANT_EDUCATION_OPTIONS', [
    'Elementary Graduate',
    'High School Graduate',
    'Senior High School Graduate',
    'Vocational / TESDA Graduate',
    'Some College (Undergraduate)',
    'College Graduate',
    'Post Graduate (Master\'s / Doctorate)',
]);

// Whole-years age as of today, computed from a Y-m-d date string —
// mirrors calculateAgeFromDOB() in HRMS/includes/config.php. Only
// used here to validate that Date of Birth is a real, non-future date.
function calculateAgeFromDOB($dob)
{
    if (empty($dob))
        return null;
    try {
        $birth = new DateTime($dob);
        $today = new DateTime('today');
        if ($birth > $today)
            return null;
        return $today->diff($birth)->y;
    } catch (Exception $e) {
        return null;
    }
}

// =====================================================
// "Personal Information" self-edit for the POS side — mirrors
// processPersonalInfoUpdate() in HRMS/includes/config.php so
// Cashiers/other POS-side employees can fill in / correct their
// Email, Contact Number, and application-form details too, right
// from my_profile.php (POS side) — same shared UI as the HRMS side,
// see includes/personal_info_card.php.
// =====================================================
function processPersonalInfoUpdate($hrmsPdo, $employeeId)
{
    $res = ['message' => '', 'error' => ''];

    requireCSRF();

    if (!$hrmsPdo || !$employeeId) {
        $res['error'] = "No employee record is linked to your account.";
        return $res;
    }

    $email = trim($_POST['email'] ?? '');
    $contact_country = trim($_POST['contact_number_country'] ?? 'PH');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $phone_error = validatePhoneNumber($contact_country, $contact_number);
    $contact_number = formatPhoneForStorage($contact_country, $contact_number);
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $place_of_birth = trim($_POST['place_of_birth'] ?? '');
    $sex = trim($_POST['sex'] ?? '');
    $civil_status = trim($_POST['civil_status'] ?? '');
    $home_address = trim($_POST['home_address'] ?? '');
    $education = trim($_POST['highest_educational_attainment'] ?? '');
    $year_graduated = trim($_POST['year_graduated'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $res['error'] = "Please enter a valid email address.";
    } elseif ($phone_error !== null) {
        $res['error'] = $phone_error;
    } elseif ($date_of_birth !== '' && calculateAgeFromDOB($date_of_birth) === null) {
        $res['error'] = "Please enter a valid Date of Birth.";
    } elseif ($sex !== '' && !in_array($sex, APPLICANT_SEX_OPTIONS, true)) {
        $res['error'] = "Please select a valid Sex/Gender.";
    } elseif ($civil_status !== '' && !in_array($civil_status, APPLICANT_CIVIL_STATUS_OPTIONS, true)) {
        $res['error'] = "Please select a valid Civil Status.";
    } elseif ($education !== '' && !in_array($education, APPLICANT_EDUCATION_OPTIONS, true)) {
        $res['error'] = "Please select a valid Highest Educational Attainment.";
    } elseif ($year_graduated !== '' && (!preg_match('/^\d{4}$/', $year_graduated) || (int) $year_graduated < 1950 || (int) $year_graduated > (int) date('Y'))) {
        $res['error'] = "Please enter a valid Year Graduated (e.g. " . date('Y') . ").";
    } else {
        try {
            // Email is used for correspondence (payslip notices, etc.) —
            // block duplicates against OTHER employees so notifications
            // don't end up mixed between two people.
            $dupStmt = $hrmsPdo->prepare("SELECT employee_id FROM employees WHERE email = ? AND employee_id != ?");
            $dupStmt->execute([$email, $employeeId]);
            if ($dupStmt->fetch()) {
                $res['error'] = "That email address is already used by another employee record.";
                return $res;
            }

            $stmt = $hrmsPdo->prepare("
                UPDATE employees SET
                    email = ?, contact_number = ?,
                    birthdate = ?, place_of_birth = ?, sex = ?, civil_status = ?, address = ?,
                    highest_educational_attainment = ?, year_graduated = ?
                WHERE employee_id = ?
            ");
            $stmt->execute([
                $email,
                $contact_number ?: null,
                $date_of_birth ?: null,
                $place_of_birth ?: null,
                $sex ?: null,
                $civil_status ?: null,
                $home_address ?: null,
                $education ?: null,
                $year_graduated ?: null,
                $employeeId,
            ]);
            $res['message'] = "Your personal information has been updated.";
        } catch (PDOException $e) {
            error_log("Update personal info error (POS side): " . $e->getMessage());
            $res['error'] = "Your personal information could not be saved. Please try again.";
        }
    }

    return $res;
}

// Verifies a Supervisor's (or Admin's) own HRMS username/password,
// entered right on the cashier's POS screen, to approve a Senior/PWD
// discount on the spot — replaces the old one-time "Generate Code"
// flow (Supervisor no longer needs to open their dashboard first).
// Mirrors verifyCashierSupervisorLogin() below, but is NOT restricted
// to the cashier's own assigned team supervisor — same as the old
// code system, whoever is on the floor (any active Supervisor or
// Admin) can approve. Returns the HRMS `users` row (has full_name)
// on success, or null if the username/password is wrong or the
// account isn't an active Supervisor/Admin.
function verifyCashierSupervisorDiscountApproval($hrmsPdo, $username, $password)
{
    if (!$hrmsPdo || $username === '' || $password === '')
        return null;
    try {
        $stmt = $hrmsPdo->prepare("SELECT * FROM users WHERE BINARY username = ? AND role IN ('supervisor', 'admin') AND is_active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password'])) {
            return null;
        }
        return $user;
    } catch (PDOException $e) {
        error_log("verifyCashierSupervisorDiscountApproval error: " . $e->getMessage());
        return null;
    }
}

// =====================================================
// PROFILE PICTURE — lets any logged-in user (Cashier, Inventory
// Staff, Finance, and — mirrored from the HRMS side — HR/HR
// Manager/Supervisor/Employee/Admin too) set/change their own
// profile picture. Saved to disk (uploads/avatars/) and remembered
// in users.profile_picture, so it's still there after logout/login,
// and they can change it anytime from My Profile.
//
// The POS and HRMS sides are two separate databases (and two
// separate uploads/ folders on disk), so a successful upload here
// is ALSO mirrored into the HRMS side (same filename copied into
// HRMS/uploads/avatars/, hrms.users.profile_picture updated) —
// matched via employee_id, same as the rest of the Cashier<->HRMS
// account sync — so the same picture shows up no matter which
// system/page it's viewed from.
// =====================================================
function ensureProfilePictureColumn($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL DEFAULT NULL AFTER full_name");
        }
    } catch (PDOException $e) {
        error_log("ensureProfilePictureColumn skipped: " . $e->getMessage());
    }
}

function processProfilePictureUpload($pdo, $hrmsPdo)
{
    $res = ['message' => '', 'error' => '', 'filename' => null];

    requireCSRF();

    $userId = $_SESSION['user_id'] ?? null;
    $employeeId = $_SESSION['employee_id'] ?? null;
    $username = $_SESSION['username'] ?? null;

    if (!$userId) {
        $res['error'] = "You're not logged in.";
        return $res;
    }

    if (empty($_FILES['profile_picture']['name']) || $_FILES['profile_picture']['error'] === UPLOAD_ERR_NO_FILE) {
        $res['error'] = "Please choose an image first.";
        return $res;
    }

    if ($_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
        $res['error'] = "Image upload failed (error code: " . $_FILES['profile_picture']['error'] . "). Please try a smaller file.";
        return $res;
    }

    $ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($ext, $allowed, true)) {
        $res['error'] = "Image must be JPG, PNG, or WEBP.";
        return $res;
    }

    if ($_FILES['profile_picture']['size'] > 2097152) {
        $res['error'] = "Image is too large. 2MB maximum.";
        return $res;
    }

    // Sanity-check na tunay itong image (hindi lang isang binago-pangalan
    // na file na may image extension).
    if (@getimagesize($_FILES['profile_picture']['tmp_name']) === false) {
        $res['error'] = "That file doesn't look like a valid image.";
        return $res;
    }

    $upload_folder = __DIR__ . '/../uploads/avatars/';
    if (!is_dir($upload_folder)) {
        mkdir($upload_folder, 0755, true);
    }

    $newFilename = 'avatar_' . $userId . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $destination = $upload_folder . $newFilename;

    if (!move_uploaded_file($_FILES['profile_picture']['tmp_name'], $destination)) {
        error_log("Profile picture move_uploaded_file failed: dest=$destination");
        $res['error'] = "Could not save the uploaded image. Please check folder permissions and try again.";
        return $res;
    }

    try {
        // Lumang picture, para ma-delete pagkatapos ma-save yung bago —
        // huwag mag-iwan ng mga orphan file sa uploads/avatars/.
        $oldStmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
        $oldStmt->execute([$userId]);
        $oldFile = $oldStmt->fetchColumn();

        $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?")
            ->execute([$newFilename, $userId]);

        if ($oldFile && $oldFile !== $newFilename && is_file($upload_folder . $oldFile)) {
            @unlink($upload_folder . $oldFile);
        }

        $_SESSION['profile_picture'] = $newFilename;
        $res['message'] = "Your profile picture has been updated.";
        $res['filename'] = $newFilename;

        // ---- Mirror sa HRMS side, kung naka-link ang account ----
        if ($hrmsPdo && ($employeeId || $username)) {
            try {
                $hrms_upload_folder = __DIR__ . '/../HRMS/uploads/avatars/';
                if (!is_dir($hrms_upload_folder)) {
                    mkdir($hrms_upload_folder, 0755, true);
                }
                if (copy($destination, $hrms_upload_folder . $newFilename)) {
                    $identityColumn = $employeeId ? 'employee_id' : 'username';
                    $identityValue = $employeeId ?: $username;
                    $hOldStmt = $hrmsPdo->prepare("SELECT profile_picture FROM users WHERE {$identityColumn} = ?");
                    $hOldStmt->execute([$identityValue]);
                    $hOldFile = $hOldStmt->fetchColumn();

                    $hrmsPdo->prepare("UPDATE users SET profile_picture = ? WHERE {$identityColumn} = ?")
                        ->execute([$newFilename, $identityValue]);

                    if ($hOldFile && $hOldFile !== $newFilename && is_file($hrms_upload_folder . $hOldFile)) {
                        @unlink($hrms_upload_folder . $hOldFile);
                    }
                }
            } catch (PDOException $e) {
                error_log("Profile picture mirror to HRMS skipped: " . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        error_log("Profile picture DB update error (POS side): " . $e->getMessage());
        $res['error'] = "The picture was uploaded but could not be saved to your account. Please try again.";
    }

    return $res;
}

// Clears the current user's profile picture (both POS and mirrored HRMS
// side, kung naka-link ang account), reverting the avatar back to the
// initials placeholder. Mirrors the same employee_id logic as
// processProfilePictureUpload() above.
function removeProfilePicture($pdo, $hrmsPdo)
{
    $res = ['message' => '', 'error' => ''];

    requireCSRF();

    $userId = $_SESSION['user_id'] ?? null;
    $employeeId = $_SESSION['employee_id'] ?? null;
    $username = $_SESSION['username'] ?? null;

    if (!$userId) {
        $res['error'] = "You're not logged in.";
        return $res;
    }

    $upload_folder = __DIR__ . '/../uploads/avatars/';

    try {
        $oldStmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
        $oldStmt->execute([$userId]);
        $oldFile = $oldStmt->fetchColumn();

        if (!$oldFile) {
            $res['error'] = "There's no profile picture to remove.";
            return $res;
        }

        $pdo->prepare("UPDATE users SET profile_picture = NULL WHERE id = ?")
            ->execute([$userId]);

        if (is_file($upload_folder . $oldFile)) {
            @unlink($upload_folder . $oldFile);
        }

        unset($_SESSION['profile_picture']);
        $res['message'] = "Your profile picture has been removed.";

        // ---- Mirror sa HRMS side, kung naka-link ang account ----
        if ($hrmsPdo && ($employeeId || $username)) {
            try {
                $hrms_upload_folder = __DIR__ . '/../HRMS/uploads/avatars/';
                $identityColumn = $employeeId ? 'employee_id' : 'username';
                $identityValue = $employeeId ?: $username;
                $hOldStmt = $hrmsPdo->prepare("SELECT profile_picture FROM users WHERE {$identityColumn} = ?");
                $hOldStmt->execute([$identityValue]);
                $hOldFile = $hOldStmt->fetchColumn();

                $hrmsPdo->prepare("UPDATE users SET profile_picture = NULL WHERE {$identityColumn} = ?")
                    ->execute([$identityValue]);

                if ($hOldFile && is_file($hrms_upload_folder . $hOldFile)) {
                    @unlink($hrms_upload_folder . $hOldFile);
                }
            } catch (PDOException $e) {
                error_log("Profile picture removal mirror to HRMS skipped: " . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        error_log("Profile picture removal DB error (POS side): " . $e->getMessage());
        $res['error'] = "Could not remove the picture. Please try again.";
    }

    return $res;
}

// =====================================================
// SESSION COOKIE HARDENING (Item 2 — Phase 1 security audit)
// ------------------------------------------------------
// Mirrors the SESSION COOKIE HARDENING block already present in
// HRMS/includes/config.php, which POS did not previously have — it
// only called session_start() with no explicit cookie params, so
// HttpOnly/Secure/SameSite depended entirely on the server's php.ini
// default instead of being guaranteed here.
$__isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) == 443)
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
);
$__sessionSecure = filter_var(
    env('SESSION_SECURE', $__isHttps ? 'true' : 'false'),
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE
);
if ($__sessionSecure === null) {
    $__sessionSecure = $__isHttps;
}
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $__sessionSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

define('APP_NAME', 'HCI GroceryOne');
define('LOW_STOCK', 10);
define('SESSION_TIMEOUT', 1800);

function redirect($url)
{
    header("Location: $url");
    exit();
}

function clean($text): string
{
    return htmlspecialchars((string) ($text ?? ''), ENT_QUOTES, 'UTF-8');
}

function peso($amount)
{
    return '₱ ' . number_format($amount, 2);
}

function jsonResponse($success, $message = '', $data = [])
{
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

function generateCSRFToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token)
{
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField()
{
    $token = generateCSRFToken();
    echo '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function requireCSRF()
{
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        die("
            <h3 style='color:red; font-family:sans-serif;'>
                Invalid request (CSRF check failed).
            </h3>
            <p style='font-family:sans-serif;'>
                Please refresh the page and try again.
            </p>
        ");
    }
}

function requireCSRFJson()
{
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verifyCSRFToken($token)) {
        jsonResponse(false, 'Invalid request (CSRF check failed). Please refresh the page.');
    }
}

// --- Idempotency token (para sa "submit once" guard, hal. sa POS checkout) ---
// Iba ito sa CSRF token: minsan lang dapat tanggapin ang value na ito bago
// mag-expire/ma-consume, para kahit ma-double-click nang mabilis ang isang
// button (o ma-resend ang parehong request habang mabagal ang koneksyon),
// isang beses lang matutuloy ang aksyon sa loob ng session na iyon.
function generateIdempotencyToken(string $key)
{
    if (empty($_SESSION['idempotency_tokens'][$key])) {
        $_SESSION['idempotency_tokens'][$key] = bin2hex(random_bytes(16));
    }
    return $_SESSION['idempotency_tokens'][$key];
}

function idempotencyField(string $key, string $fieldName = 'idempotency_token')
{
    $token = generateIdempotencyToken($key);
    echo '<input type="hidden" name="' . htmlspecialchars($fieldName) . '" value="' . $token . '">';
}

// I-verify at agad na i-consume (alisin sa session) ang token — kaya kahit
// dalawang request ang dumating nang halos sabay para sa parehong session
// (PHP's default file-based session storage locks ang session file, kaya
// nagse-serialize ang mga request), isa lang ang makikitang valid; ang
// pangalawa ay makakakita nang laging wala na (na-consume na) ang token.
function verifyAndConsumeIdempotencyToken(string $key, $token): bool
{
    $expected = $_SESSION['idempotency_tokens'][$key] ?? null;
    unset($_SESSION['idempotency_tokens'][$key]);

    if (empty($expected) || empty($token)) {
        return false;
    }
    return hash_equals($expected, $token);
}

function requireLogin()
{
    if (empty($_SESSION['user_id'])) {
        redirect(systemUrl('login.php'));
    }

    // Session timeout check
    if (!empty($_SESSION['last_activity'])) {
        $idle_time = time() - $_SESSION['last_activity'];
        if ($idle_time > SESSION_TIMEOUT) {
            session_unset();
            session_destroy();
            redirect(systemUrl('login.php?expired=1'));
        }
    }

    // Force logout check 
    global $pdo;
    $posUserId = currentPosUserId();
    if (!$posUserId) {
        session_unset();
        session_destroy();
        redirect(systemUrl('login.php'));
    }
    $stmt = $pdo->prepare("SELECT id, username, full_name, role, employee_id, profile_picture, force_logout, is_active FROM users WHERE id = ?");
    $stmt->execute([$posUserId]);
    $user = $stmt->fetch();
    if (!$user || (empty($_SESSION['pos_user_id']) && $user['role'] !== ($_SESSION['role'] ?? ''))) {
        session_unset();
        session_destroy();
        redirect(systemUrl('login.php'));
    }

    if ($user && $user['force_logout'] == 1) {
        // I-clear muna ang flag bago i-destroy ang session
        $pdo->prepare("UPDATE users SET force_logout = 0 WHERE id = ?")
            ->execute([$posUserId]);
        session_unset();
        session_destroy();
        redirect(systemUrl('login.php?reset=1'));
    }

    // Deactivated account check — mahalaga ito para sa cashier accounts
    // na naka-sync mula HRMS: kapag na-resign/na-terminate o binago ang
    // position ng employee sa HRMS (hindi na "Cashier"), automatic silang
    // ma-de-deactivate dito sa POS side rin (see syncCashierAccountsFromHRMS()).
    if ($user && (int) $user['is_active'] === 0) {
        session_unset();
        session_destroy();
        redirect(systemUrl('login.php?deactivated=1'));
    }

    // Keep every POS page on the same canonical identity data. Profile
    // changes made in My Profile or Admin Profile must be visible without
    // requiring a logout/login cycle, while the avatar is only accepted
    // when it is a safe basename pointing to an existing upload.
    if ($user) {
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['employee_id'] = $user['employee_id'];
        $_SESSION['profile_picture'] = (
            is_string($user['profile_picture'])
            && preg_match('/^[A-Za-z0-9._-]+$/', $user['profile_picture'])
            && is_file(__DIR__ . '/../uploads/avatars/' . $user['profile_picture'])
        ) ? $user['profile_picture'] : null;
    }

    $_SESSION['last_activity'] = time();
}

// Saan dapat mapunta ang isang naka-login na user base sa kanilang role —
// ginagamit bilang FALLBACK sa lahat ng require*() guards sa halip na
// nakatak na 'pos.php' o 'dashboard.php'. Bakit: kung hardcoded lang ang
// fallback, at ang landing page na iyon ay naka-gate din para sa IBANG
// role, puwede tayong ma-stuck sa infinite redirect loop (hal. finance
// role -> pos.php [requireCashier fails] -> dashboard.php [requireAdmin
// fails] -> pos.php -> ... ERR_TOO_MANY_REDIRECTS). Dapat laging tumuro
// ang fallback sa isang page na papasahan ng role na iyon.
function redirectToRoleHome()
{
    switch ($_SESSION['role'] ?? '') {
        case 'admin':
            redirect(systemUrl('dashboard.php'));
        case 'inventory_staff':
            redirect(systemUrl('inventory_dashboard.php'));
        case 'finance':
            redirect(systemUrl('finance_dashboard.php'));
        case 'cashier':
            redirect(systemUrl('pos.php'));
        default:
            // Hindi kilalang/walang role — mas ligtas na i-logout na lang
            // sa halip na mag-guess pa ng page (para di na-loloop ulit).
            session_unset();
            session_destroy();
            redirect(systemUrl('login.php'));
    }
}

function requireAdmin()
{
    requireLogin();
    if ($_SESSION['role'] !== 'admin') {
        redirectToRoleHome();
    }
}

function requireCashier()
{
    requireLogin();
    if ($_SESSION['role'] !== 'cashier') {
        redirectToRoleHome();
    }
}

// Admin sessions started from the HRMS login carry a `pos_user_id`
// (set during that login — see HRMS/login.php) because $_SESSION['user_id']
// in that case is the row id from the HRMS `users` table, not this POS
// database's `users` table. Everywhere on the POS side that needs "the
// current user's row id in THIS table" (password changes, audit trails,
// etc.) should call this instead of reading $_SESSION['user_id'] directly.
function currentPosUserId()
{
    if (!empty($_SESSION['pos_user_id'])) {
        return $_SESSION['pos_user_id'];
    }

    if (in_array($_SESSION['role'] ?? '', ['cashier', 'inventory_staff', 'finance'], true)) {
        return $_SESSION['user_id'] ?? null;
    }

    return null;
}

function requireAdminJson()
{
    if (empty($_SESSION['user_id']) || !currentPosUserId()) {
        jsonResponse(false, 'Not logged in. Please log in first.');
    }
    requireLogin();
    if ($_SESSION['role'] !== 'admin') {
        jsonResponse(false, 'Admin access only.');
    }
    $_SESSION['last_activity'] = time();
}

// =====================================================
// CASHIER <-> HRMS SYNC
// ------------------------------------------------------
// HRMS na ang "source of truth" para sa cashier accounts. Ang mga
// employee sa HRMS na may position na "Cashier" (at may active HRMS
// account) ay dito nire-reflect bilang cashier accounts sa POS
// "users" table — walang na manual/hardcoded na test account dito.
//
// ensureCashierSyncColumns()  — idempotent migration, dagdag ng mga
//                                columns kung wala pa (ligtas ulit-ulitin).
// syncCashierAccountsFromHRMS() — idempotent sync, tinatawag sa bawat
//                                page load (mabilis lang naman, simpleng
//                                SELECT + upsert).
// =====================================================
function ensureCashierSyncColumns($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'employee_id'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN employee_id INT NULL UNIQUE AFTER id");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_active'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'synced_from_hrms'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN synced_from_hrms TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (PDOException $e) {
        error_log("ensureCashierSyncColumns skipped: " . $e->getMessage());
    }
}

// The `role` column started out as enum('admin','cashier') — before
// Inventory Staff got their own POS-side role. On a database created
// before that change, the enum never got widened, so
// syncInventoryStaffAccountsFromHRMS() below silently fails every time
// it tries to write role = 'inventory_staff' (invalid enum value). This
// makes sure the enum always includes it, without touching any other
// value or the existing default.
function ensureInventoryStaffRoleEnum($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
        $col = $stmt->fetch();
        if ($col && stripos($col['Type'], 'inventory_staff') === false) {
            $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','cashier','inventory_staff') NOT NULL DEFAULT 'cashier'");
        }
    } catch (PDOException $e) {
        error_log("ensureInventoryStaffRoleEnum skipped: " . $e->getMessage());
    }
}

// Same idea as ensureInventoryStaffRoleEnum() above, but for the
// 'finance' role — added when the Finance module (finance.php) and
// the Finance approval gate on restock requests were introduced.
function ensureFinanceRoleEnum($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
        $col = $stmt->fetch();
        if ($col && stripos($col['Type'], "'finance'") === false) {
            $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','cashier','inventory_staff','finance') NOT NULL DEFAULT 'cashier'");
        }
    } catch (PDOException $e) {
        error_log("ensureFinanceRoleEnum skipped: " . $e->getMessage());
    }
}

// stock_logs.change_type started out as enum('sale','void','refund',
// 'restock','adjust') — before a dedicated "Issue Items" action existed.
// On a database created before that change, the enum never got widened,
// so recording an issuance would silently fail (invalid enum value).
// This makes sure the enum always includes it, without touching any
// other value or the existing default.
function ensureIssuanceChangeTypeEnum($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM stock_logs LIKE 'change_type'");
        $col = $stmt->fetch();
        if ($col && stripos($col['Type'], 'issuance') === false) {
            $pdo->exec("ALTER TABLE stock_logs MODIFY change_type ENUM('sale','void','refund','restock','adjust','issuance') NOT NULL");
        }
    } catch (PDOException $e) {
        error_log("ensureIssuanceChangeTypeEnum skipped: " . $e->getMessage());
    }
}

// stock_logs.validated_by / validated_at — lets a Supervisor mark a
// restock/adjustment/issuance entry as reviewed, from the Supervisor's
// Inventory Monitoring page. validated_by stores the HRMS employee_id
// of the Supervisor (not a POS users.id — Supervisors don't have a POS
// account), resolved to a name on the HRMS side, not via SQL join.
function ensureStockLogsValidationColumns($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM stock_logs LIKE 'validated_by'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE stock_logs ADD COLUMN validated_by INT NULL DEFAULT NULL, ADD COLUMN validated_at TIMESTAMP NULL DEFAULT NULL");
        }

        function ensureStockLogSaleReferenceColumn($pdo)
        {
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM stock_logs LIKE 'sale_id'");
                if (!$stmt->fetch()) {
                    $pdo->exec("ALTER TABLE stock_logs ADD COLUMN sale_id INT NULL AFTER product_id");
                }
                $indexStmt = $pdo->query("SHOW INDEX FROM stock_logs WHERE Key_name = 'idx_stock_logs_sale_id'");
                if (!$indexStmt->fetch()) {
                    $pdo->exec("CREATE INDEX idx_stock_logs_sale_id ON stock_logs (sale_id)");
                }
            } catch (PDOException $e) {
                error_log("ensureStockLogSaleReferenceColumn skipped: " . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        error_log("ensureStockLogsValidationColumns skipped: " . $e->getMessage());
    }
}

// AUDIT TRAIL PROTECTION (Item 3 — Inventory audit trail): stock_logs
// rows are meant to be permanent — every restock, adjustment, issuance,
// sale, void, refund, and PO receipt writes a row here and nothing in
// this codebase ever UPDATEs or DELETEs one. That's already true by
// omission, but "protected from unauthorized editing or deletion"
// deserves an actual guarantee, not just the absence of a button. These
// triggers enforce it at the database level: any UPDATE or DELETE
// against stock_logs — from this app, a future bug, or someone poking
// the database directly — is rejected outright. INSERT is untouched,
// since that's the only operation an audit trail is supposed to allow.
function ensureStockLogsAuditProtection($pdo)
{
    try {
        $stmt = $pdo->query("
            SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'stock_logs'
        ");
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        // Nothing in this codebase runs UPDATE stock_logs today — the
        // validated_by/validated_at columns exist for a possible future
        // "Supervisor marks this reviewed" feature, but that feature was
        // never actually built. Until it is, every field on a stock_logs
        // row is meant to be write-once, so the trigger blocks ALL
        // updates with no exception. If that review feature gets built
        // later, this trigger (and its accompanying migration flag) is
        // the one place to revisit.
        if (!in_array('trg_stock_logs_no_update', $existing, true)) {
            $pdo->exec("
                CREATE TRIGGER trg_stock_logs_no_update
                BEFORE UPDATE ON stock_logs
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Audit trail records cannot be modified.';
                END
            ");
        }
        if (!in_array('trg_stock_logs_no_delete', $existing, true)) {
            $pdo->exec("
                CREATE TRIGGER trg_stock_logs_no_delete
                BEFORE DELETE ON stock_logs
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Audit trail records cannot be deleted.';
                END
            ");
        }
    } catch (PDOException $e) {
        error_log("ensureStockLogsAuditProtection skipped: " . $e->getMessage());
    }
}

// sales.status started out as enum('completed','voided','refunded') —
// before per-item partial refunds existed. On a database created
// before that change, the enum never got widened, so marking a sale
// "partial_refunded" would silently fail (invalid enum value). This
// makes sure the enum always includes it, without touching any other
// value or the existing default.
function ensurePartialRefundedStatusEnum($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM sales LIKE 'status'");
        $col = $stmt->fetch();
        if ($col && stripos($col['Type'], 'partial_refunded') === false) {
            $pdo->exec("ALTER TABLE sales MODIFY status ENUM('completed','voided','refunded','partial_refunded') NOT NULL DEFAULT 'completed'");
        }
    } catch (PDOException $e) {
        error_log("ensurePartialRefundedStatusEnum skipped: " . $e->getMessage());
    }
}

// Per-line-item refund history. Isang row dito bawat product na
// na-refund sa isang refund action — kaya kahit isa or dalawang item
// lang ang gustong i-refund ng customer (hindi buong transaction),
// mare-record ito nang tama, at puwede pang paulit-ulit na i-partial
// refund ang parehong sale hanggat may natitirang di pa na-refund na
// quantity sa bawat linya (tina-track via SUM(quantity) per
// sales_detail_id).
function ensureSaleRefundsTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sale_refunds (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sale_id INT NOT NULL,
                sales_detail_id INT NOT NULL,
                product_id INT NOT NULL,
                quantity INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                refunded_by INT NULL,
                refunded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sale_refunds_sale (sale_id),
                INDEX idx_sale_refunds_detail (sales_detail_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureSaleRefundsTable skipped: " . $e->getMessage());
    }
}

// Which Supervisor/Admin approved a refund (whole or partial) by
// entering their username/password at the POS — mirrors
// discount_approved_by_name for the Senior/PWD discount flow (see
// ensureDiscountIdColumns() below and verifyCashierSupervisorDiscountApproval()).
// Unlike void (owner + same-day only), a refund can legitimately be
// processed by a different cashier on a later day (customer returns
// after the original cashier's shift), so instead of restricting WHO
// can refund, this requires a Supervisor/Admin to approve it on the
// spot — same accountability the discount flow already has.
function ensureRefundApprovalColumns($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM sales LIKE 'refund_approved_by_name'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE sales ADD COLUMN refund_approved_by_name VARCHAR(150) NULL AFTER refunded_by");
        }
    } catch (PDOException $e) {
        error_log("ensureRefundApprovalColumns skipped: " . $e->getMessage());
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM sale_refunds LIKE 'approved_by_name'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE sale_refunds ADD COLUMN approved_by_name VARCHAR(150) NULL AFTER refunded_by");
        }
    } catch (PDOException $e) {
        error_log("ensureRefundApprovalColumns (sale_refunds) skipped: " . $e->getMessage());
    }
}

function syncCashierAccountsFromHRMS($pdo, $hrmsPdo)
{
    if (!$hrmsPdo)
        return; // walang HRMS connection, wala tayong magagawa

    try {
        // Lahat ng HRMS employee na "Cashier" ang position, active pa ang
        // pagka-empleyado, may active HRMS user account, AT plain
        // "employee" pa rin ang HRMS system role nila.
        //
        // Bakit parehong kailangan ang position AT ang role: ang
        // "position" ay job title lang (text field, manually edited sa
        // Edit Employee screen), samantalang ang "role" ay ang
        // system-access level (admin/hr/hr_manager/supervisor/employee,
        // binabago sa Manage Users). Puwedeng ma-promote ang isang tao
        // maging Supervisor (role='supervisor') nang hindi na-uupdate
        // ang "position" niya palayo sa "Cashier" — kaya parehong
        // kailangan i-check para hindi tuluy-tuloy na masi-sync bilang
        // valid Cashier ang isang taong Supervisor/HR/Admin na sa role.
        $stmt = $hrmsPdo->query("
            SELECT u.id AS hrms_user_id, u.employee_id, u.username, u.password,
                   u.full_name, u.is_active AS hrms_is_active, e.status AS employee_status
            FROM users u
            JOIN employees e ON e.employee_id = u.employee_id
            WHERE e.position = 'Cashier' AND u.role = 'employee'
        ");
        $hrmsCashiers = $stmt->fetchAll();

        $activeEmployeeIds = [];

        foreach ($hrmsCashiers as $c) {
            $shouldBeActive = ((int) $c['hrms_is_active'] === 1)
                && !in_array($c['employee_status'], ['resigned', 'terminated'], true);

            $existing = $pdo->prepare("SELECT id, username, password FROM users WHERE employee_id = ?");
            $existing->execute([$c['employee_id']]);
            $row = $existing->fetch();

            if ($row) {
                // Update na existing synced account — panatilihing tugma
                // sa HRMS ang username/password/pangalan/status.
                //
                // Kapag nag-reset ng password si HR sa HRMS, iba na ang
                // bagong hash kumpara sa naka-store dito. Ituring itong
                // "password reset" — i-clear din ang POS-side lockout
                // (failed_attempts/is_locked), kaya sa sandaling i-reset
                // ni HR ang password, automatic na ma-unlock ang cashier
                // sa POS din nang hindi na kailangan pang ibang hakbang.
                $passwordChanged = ($row['password'] !== $c['password']);

                $pdo->prepare("
                    UPDATE users
                    SET username = ?, password = ?, full_name = ?, role = 'cashier',
                        is_active = ?, synced_from_hrms = 1
                        " . ($passwordChanged ? ", is_locked = 0, failed_attempts = 0" : "") . "
                        " . ($shouldBeActive ? ", force_logout = 0" : "") . "
                    WHERE id = ?
                ")->execute([
                            $c['username'],
                            $c['password'],
                            $c['full_name'],
                            $shouldBeActive ? 1 : 0,
                            $row['id']
                        ]);
            } elseif ($shouldBeActive) {
                // Bagong cashier account — i-mirror lang mula HRMS
                // (parehong bcrypt hash, kaya iisa lang ang password
                // ng cashier sa dalawang systema).
                $pdo->prepare("
                    INSERT INTO users (username, password, full_name, role, employee_id, is_active, synced_from_hrms, force_logout)
                    VALUES (?, ?, ?, 'cashier', ?, 1, 1, 0)
                ")->execute([
                            $c['username'],
                            $c['password'],
                            $c['full_name'],
                            $c['employee_id']
                        ]);
            }

            if ($shouldBeActive) {
                $activeEmployeeIds[] = (int) $c['employee_id'];
            }
        }

        // I-deactivate ang mga dati nang naka-sync na account pero hindi na
        // dapat active ngayon (na-resign/na-terminate/hindi na Cashier ang
        // position). Manual/legacy accounts (synced_from_hrms = 0) hindi
        // natin ginagalaw dito.
        if (empty($activeEmployeeIds)) {
            $pdo->exec("UPDATE users SET is_active = 0, force_logout = 1 WHERE synced_from_hrms = 1");
        } else {
            $placeholders = implode(',', array_fill(0, count($activeEmployeeIds), '?'));
            $pdo->prepare("
                UPDATE users SET is_active = 0, force_logout = 1
                WHERE synced_from_hrms = 1 AND employee_id NOT IN ($placeholders)
            ")->execute($activeEmployeeIds);
        }
    } catch (PDOException $e) {
        error_log("syncCashierAccountsFromHRMS skipped: " . $e->getMessage());
    }
}

// =====================================================
// REAL-TIME LOGIN GUARD — checked at the exact moment of login,
// on top of syncCashierAccountsFromHRMS() above.
// ------------------------------------------------------
// The sync already deactivates a POS account once its linked HRMS
// employee is no longer a Cashier (promoted to Supervisor, moved
// department, resigned, etc.) — but that only happens on the next
// page load that runs the sync. This function is an extra, immediate
// check so an employee can never slip through and log into the POS
// with a stale "Cashier" account the moment their HRMS position
// changes to something else (e.g. Supervisor), even before a sync
// has had the chance to catch it. Fails OPEN (returns true) if the
// HRMS database can't be reached or the record isn't found, so a
// temporary HRMS outage doesn't lock everyone out of the POS.
// =====================================================
function employeeStillEligibleForPosLogin($hrmsPdo, $employee_id)
{
    if (!$hrmsPdo || !$employee_id)
        return true;
    try {
        $stmt = $hrmsPdo->prepare("
            SELECT e.position, e.status, u.role AS hrms_role
            FROM employees e
            LEFT JOIN users u ON u.employee_id = e.employee_id
            WHERE e.employee_id = ?
        ");
        $stmt->execute([$employee_id]);
        $emp = $stmt->fetch();
        if (!$emp)
            return true;
        if (in_array($emp['status'], ['resigned', 'terminated'], true))
            return false;
        if (!empty($emp['hrms_role']) && $emp['hrms_role'] !== 'employee')
            return false;
        // "Cashier", "Inventory Staff", at "Accountant" (Finance) ang mga
        // position na may valid POS-side login — hindi lang Cashier
        // (dati, bago pa idinagdag ang Inventory Staff/Finance roles,
        // "Cashier" lang ang tinatanggap dito, kaya na-block ang mga
        // valid Inventory Staff / Finance).
        return in_array(trim((string) $emp['position']), ['Cashier', 'Inventory Staff', 'Accountant'], true);
    } catch (PDOException $e) {
        error_log("employeeStillEligibleForPosLogin skipped: " . $e->getMessage());
        return true;
    }
}

// =====================================================
// EMPLOYEE SUSPENSION STATUS (POS side)
// ------------------------------------------------------
// HRMS suspends an employee by setting employees.suspension_start_date
// / suspension_end_date / suspension_reason (see HRMS/employees.php
// 'suspend' action) — those columns live ONLY in the HRMS database,
// so the POS side has to look them up over $hrmsPdo rather than its
// own $pdo. Used in two places: (1) login.php, to block a suspended
// Cashier/Inventory Staff/Finance account from logging in at all, and
// (2) processCashierSelfTimeInOut() below, to block Time In for an
// already-open session too (in case it was opened just before the
// suspension took effect). Entirely date-driven off
// suspension_end_date — nothing here needs to be manually undone once
// the suspension period passes; the very next check just sees
// 'active' => false again on its own.
//
// getEmployeeSuspensionStatus() mirrors the identically-named function
// in HRMS/includes/config.php byte-for-byte, so "N days remaining"
// always means the same thing on both sides.
// =====================================================
function getEmployeeSuspensionStatus($suspensionStartDate, $suspensionEndDate)
{
    if (empty($suspensionEndDate)) {
        return ['active' => false, 'days_remaining' => 0, 'total_days' => 0];
    }

    $today = new DateTime('today');
    $end = new DateTime($suspensionEndDate);
    $start = !empty($suspensionStartDate) ? new DateTime($suspensionStartDate) : $today;

    if ($today > $end) {
        return ['active' => false, 'days_remaining' => 0, 'total_days' => (int) $start->diff($end)->days + 1];
    }

    $daysRemaining = (int) $today->diff($end)->days + 1; // inclusive of today
    $totalDays = (int) $start->diff($end)->days + 1;

    return ['active' => true, 'days_remaining' => $daysRemaining, 'total_days' => $totalDays];
}

// Looks up the suspension columns for one employee over $hrmsPdo and
// runs them through getEmployeeSuspensionStatus() above. Fails OPEN
// (not suspended) if $hrmsPdo is unreachable or the employee has no
// suspension on file — same fail-open philosophy as
// employeeStillEligibleForPosLogin() right above, so a temporary HRMS
// outage never locks out an otherwise-fine account.
function getCashierSuspensionStatus($hrmsPdo, $employee_id)
{
    $none = ['active' => false, 'days_remaining' => 0, 'total_days' => 0, 'reason' => null, 'end_date' => null];
    if (!$hrmsPdo || !$employee_id)
        return $none;
    try {
        $stmt = $hrmsPdo->prepare("
            SELECT suspension_start_date, suspension_end_date, suspension_reason
            FROM employees WHERE employee_id = ?
        ");
        $stmt->execute([$employee_id]);
        $row = $stmt->fetch();
        if (!$row)
            return $none;

        $susp = getEmployeeSuspensionStatus($row['suspension_start_date'], $row['suspension_end_date']);
        $susp['reason'] = $row['suspension_reason'];
        $susp['end_date'] = $row['suspension_end_date'];
        return $susp;
    } catch (PDOException $e) {
        error_log("getCashierSuspensionStatus skipped: " . $e->getMessage());
        return $none;
    }
}

// =====================================================
// LOGIN LOCKOUT (brute-force protection for staff login)
// ------------------------------------------------------
// Pagkatapos ng MAX_LOGIN_ATTEMPTS na maling password sa login.php,
// naka-lock na ang account hanggang mag-reset ng password si HR/Admin
// (ngayon ay ginagawa na sa HRMS > User Accounts — see
// syncCashierAccountsFromHRMS() below). Hindi ito time-based/auto
// unlock — kailangan talaga ng Admin/HR action.
// =====================================================
define('MAX_LOGIN_ATTEMPTS', 5);

function ensureLoginLockoutColumns($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'failed_attempts'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN failed_attempts INT NOT NULL DEFAULT 0");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_locked'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (PDOException $e) {
        error_log("ensureLoginLockoutColumns skipped: " . $e->getMessage());
    }
}

// =====================================================
// TWO-FACTOR AUTHENTICATION (Email OTP) — Finance accounts
// ------------------------------------------------------
// Admin's 2FA lives on the HRMS side (HRMS/includes/config.php +
// HRMS/login.php) since Admin logs in there. Finance logs in HERE
// (this file's login.php), so Finance's 2FA columns/logic live on
// this side instead. Same column names/shape on both sides so the
// shared includes/twofactor.php helper (see that file) works
// identically no matter which users table it's given.
//   two_factor_enabled       — 1 once the account has finished setup
//   two_factor_email         — where OTP codes get sent (can differ
//                               from a login-username-as-email setup;
//                               kept separate from any "email" field
//                               elsewhere so this can't accidentally
//                               be blanked out by an unrelated form)
//   two_factor_backup_codes  — JSON array of hashed one-time backup
//                               codes (password_hash'ed, never stored
//                               in plain text), consumed one at a time
// =====================================================
function ensureTwoFactorColumns($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'two_factor_enabled'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'two_factor_email'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN two_factor_email VARCHAR(150) NULL DEFAULT NULL");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'two_factor_backup_codes'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN two_factor_backup_codes TEXT NULL DEFAULT NULL");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'two_factor_failed_attempts'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN two_factor_failed_attempts INT NOT NULL DEFAULT 0");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'two_factor_locked_until'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN two_factor_locked_until DATETIME NULL DEFAULT NULL");
        }
    } catch (PDOException $e) {
        error_log("ensureTwoFactorColumns skipped: " . $e->getMessage());
    }
}
// Run before the HRMS sync below, since a password reset synced from
// HRMS also clears is_locked/failed_attempts — those columns must
// already exist by then.
if ($__migrationsNeeded) {
    ensureLoginLockoutColumns($pdo);
}

if ($__migrationsNeeded) {
    ensureCashierSyncColumns($pdo);
}
if ($__migrationsNeeded) {
    ensureProfilePictureColumn($pdo);
}
if ($__migrationsNeeded) {
    ensureInventoryStaffRoleEnum($pdo);
}
if ($__migrationsNeeded) {
    ensureFinanceRoleEnum($pdo);
}
if ($__migrationsNeeded) {
    ensureIssuanceChangeTypeEnum($pdo);
}
if ($__migrationsNeeded) {
    ensureStockLogsValidationColumns($pdo);
}
if ($__migrationsNeeded) {
    ensureStockLogSaleReferenceColumn($pdo);
}
if ($__migrationsNeeded) {
    ensureStockLogsAuditProtection($pdo);
}
if ($__migrationsNeeded) {
    ensurePartialRefundedStatusEnum($pdo);
}
if ($__migrationsNeeded) {
    ensureSaleRefundsTable($pdo);
}
if ($__migrationsNeeded) {
    ensureDiscountIdColumns($pdo);
}
if ($__migrationsNeeded) {
    ensureRefundApprovalColumns($pdo);
}
if ($__migrationsNeeded) {
    ensureProductDiscountColumns($pdo);
}
if ($__migrationsNeeded) {
    ensureCapitalTransactionsTable($pdo);
}
if ($__migrationsNeeded) {
    ensureSuppliersTable($pdo);
}
if ($__migrationsNeeded) {
    ensureSupplierPaymentTermsColumn($pdo);
}
if ($__migrationsNeeded) {
    ensurePurchaseOrdersTables($pdo);
}
if ($__migrationsNeeded) {
    ensureProcurementWorkflowColumns($pdo);
}
if ($__migrationsNeeded) {
    ensureInventoryRequestSupplierColumn($pdo);
}
if ($__migrationsNeeded) {
    ensureAccountsPayableTables($pdo);
}
if ($__migrationsNeeded) {
    ensureTwoFactorColumns($pdo);
}
syncCashierAccountsFromHRMS($pdo, $hrmsPdo);

function recordFailedLogin($pdo, $userId)
{
    $stmt = $pdo->prepare("SELECT failed_attempts FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $attempts = (int) $stmt->fetchColumn() + 1;

    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        $pdo->prepare("UPDATE users SET failed_attempts = ?, is_locked = 1 WHERE id = ?")
            ->execute([$attempts, $userId]);
    } else {
        $pdo->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?")
            ->execute([$attempts, $userId]);
    }

    return $attempts;
}

function clearFailedLogins($pdo, $userId)
{
    $pdo->prepare("UPDATE users SET failed_attempts = 0, is_locked = 0 WHERE id = ?")
        ->execute([$userId]);
}

// =====================================================
// LOGIN IP THROTTLE (Item 4 — Phase 1 security audit)
// ------------------------------------------------------
// The account lockout above only protects ONE known username: it never
// triggers if an attacker cycles through many different usernames from
// the same IP (recordFailedLogin() above is only ever called when a
// matching user row was found), or simply tries usernames that don't
// exist at all. This adds a lightweight, time-windowed throttle keyed
// on IP address, on top of — not instead of — the existing per-account
// lockout. It never permanently blocks an IP; the window just expires.
// =====================================================
function ensureLoginIpThrottleTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS login_ip_throttle (
                id int(11) NOT NULL AUTO_INCREMENT,
                ip_address varchar(45) NOT NULL,
                attempted_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (id),
                KEY idx_ip_time (ip_address, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureLoginIpThrottleTable skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureLoginIpThrottleTable($pdo);
}

define('IP_THROTTLE_MAX_ATTEMPTS', 20);    // failed login attempts from one IP...
define('IP_THROTTLE_WINDOW_SECONDS', 900); // ...within this many seconds (15 min) trips the throttle

// Uses REMOTE_ADDR only — NOT X-Forwarded-For / X-Real-IP. Those headers
// are client-supplied and trivially spoofable unless a trusted reverse
// proxy is guaranteed to strip and re-set them, which isn't something
// this app can assume about its deployment. Trusting them here would
// let an attacker present a fresh "IP" on every request and bypass the
// throttle completely.
function getClientIp()
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function isLoginIpThrottled($pdo, $ip)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM login_ip_throttle
        WHERE ip_address = ? AND attempted_at > (NOW() - INTERVAL " . IP_THROTTLE_WINDOW_SECONDS . " SECOND)
    ");
    $stmt->execute([$ip]);
    return ((int) $stmt->fetchColumn()) >= IP_THROTTLE_MAX_ATTEMPTS;
}

function recordFailedLoginIp($pdo, $ip)
{
    $pdo->prepare("INSERT INTO login_ip_throttle (ip_address) VALUES (?)")->execute([$ip]);

    // Opportunistic cleanup (~2% of calls) so this table doesn't grow
    // forever — it's low-value data, so a cron/scheduled job isn't
    // warranted just for this.
    if (mt_rand(1, 50) === 1) {
        try {
            $pdo->exec("DELETE FROM login_ip_throttle WHERE attempted_at < (NOW() - INTERVAL " . (IP_THROTTLE_WINDOW_SECONDS * 4) . " SECOND)");
        } catch (PDOException $e) {
            error_log("login_ip_throttle cleanup skipped: " . $e->getMessage());
        }
    }
}

// =====================================================
// INVENTORY STAFF <-> HRMS SYNC
// ------------------------------------------------------
// Same idea as the Cashier sync above, pero para sa mga empleyado
// na ang HRMS position ay "Inventory Staff". Dati, ang mga taong
// ito ay ginagawan na lang ng manual na account sa ilalim ng
// hrs/admin/supervisor login (HRMS/login.php) kaya nalilito kung
// saan talaga sila dapat mag-login. Ngayon, may sarili na silang
// role dito sa POS "users" table ('inventory_staff') — mag-lo-login
// sila dito sa POS Login (login.php), hindi na sa HR & Payroll
// Login, at diretso sila sa Inventory module.
// =====================================================
function syncInventoryStaffAccountsFromHRMS($pdo, $hrmsPdo)
{
    if (!$hrmsPdo)
        return; // walang HRMS connection, wala tayong magagawa

    try {
        $stmt = $hrmsPdo->query("
            SELECT u.id AS hrms_user_id, u.employee_id, u.username, u.password,
                   u.full_name, u.is_active AS hrms_is_active, e.status AS employee_status
            FROM users u
            JOIN employees e ON e.employee_id = u.employee_id
            WHERE e.position = 'Inventory Staff'
        ");
        $hrmsInventoryStaff = $stmt->fetchAll();

        $activeEmployeeIds = [];

        foreach ($hrmsInventoryStaff as $c) {
            $shouldBeActive = ((int) $c['hrms_is_active'] === 1)
                && !in_array($c['employee_status'], ['resigned', 'terminated'], true);

            $existing = $pdo->prepare("SELECT id, username FROM users WHERE employee_id = ?");
            $existing->execute([$c['employee_id']]);
            $row = $existing->fetch();

            if ($row) {
                $pdo->prepare("
                    UPDATE users
                    SET username = ?, password = ?, full_name = ?, role = 'inventory_staff',
                        is_active = ?, synced_from_hrms = 1
                        " . ($shouldBeActive ? ", force_logout = 0" : "") . "
                    WHERE id = ?
                ")->execute([
                            $c['username'],
                            $c['password'],
                            $c['full_name'],
                            $shouldBeActive ? 1 : 0,
                            $row['id']
                        ]);
            } elseif ($shouldBeActive) {
                $pdo->prepare("
                    INSERT INTO users (username, password, full_name, role, employee_id, is_active, synced_from_hrms, force_logout)
                    VALUES (?, ?, ?, 'inventory_staff', ?, 1, 1, 0)
                ")->execute([
                            $c['username'],
                            $c['password'],
                            $c['full_name'],
                            $c['employee_id']
                        ]);
            }

            if ($shouldBeActive) {
                $activeEmployeeIds[] = (int) $c['employee_id'];
            }
        }

        // I-deactivate ang mga dati nang naka-sync na Inventory Staff account
        // na hindi na dapat active (na-resign/na-terminate/hindi na Inventory
        // Staff ang position). Hindi natin ginagalaw ang mga cashier o
        // ibang synced account dito — kaya may role = 'inventory_staff' filter.
        if (empty($activeEmployeeIds)) {
            $pdo->exec("UPDATE users SET is_active = 0, force_logout = 1 WHERE synced_from_hrms = 1 AND role = 'inventory_staff'");
        } else {
            $placeholders = implode(',', array_fill(0, count($activeEmployeeIds), '?'));
            $pdo->prepare("
                UPDATE users SET is_active = 0, force_logout = 1
                WHERE synced_from_hrms = 1 AND role = 'inventory_staff' AND employee_id NOT IN ($placeholders)
            ")->execute($activeEmployeeIds);
        }
    } catch (PDOException $e) {
        error_log("syncInventoryStaffAccountsFromHRMS skipped: " . $e->getMessage());
    }
}

syncInventoryStaffAccountsFromHRMS($pdo, $hrmsPdo);

// =====================================================
// FINANCE <-> HRMS SYNC
// ------------------------------------------------------
// Same pattern as syncInventoryStaffAccountsFromHRMS() above, but for
// employees whose HRMS position is "Accountant" (the existing HRMS
// position used for the Finance role — see COMMON_POSITIONS in
// HRMS/employees.php). They're the ones assigned to review the
// Business Cash Position, Capital transactions, and Restock Requests
// once Inventory Staff has validated them (see finance.php and the
// 'pending_finance' status in inventory_requests). Like Inventory
// Staff, they log in here via POS Login (login.php), not HR & Payroll
// Login.
// =====================================================
function syncFinanceAccountsFromHRMS($pdo, $hrmsPdo)
{
    if (!$hrmsPdo)
        return;

    try {
        $stmt = $hrmsPdo->query("
            SELECT u.id AS hrms_user_id, u.employee_id, u.username, u.password,
                   u.full_name, u.is_active AS hrms_is_active, e.status AS employee_status
            FROM users u
            JOIN employees e ON e.employee_id = u.employee_id
            WHERE e.position = 'Accountant'
        ");
        $hrmsFinanceStaff = $stmt->fetchAll();

        $activeEmployeeIds = [];

        foreach ($hrmsFinanceStaff as $c) {
            $shouldBeActive = ((int) $c['hrms_is_active'] === 1)
                && !in_array($c['employee_status'], ['resigned', 'terminated'], true);

            $existing = $pdo->prepare("SELECT id, username FROM users WHERE employee_id = ?");
            $existing->execute([$c['employee_id']]);
            $row = $existing->fetch();

            if ($row) {
                $pdo->prepare("
                    UPDATE users
                    SET username = ?, password = ?, full_name = ?, role = 'finance',
                        is_active = ?, synced_from_hrms = 1
                        " . ($shouldBeActive ? ", force_logout = 0" : "") . "
                    WHERE id = ?
                ")->execute([
                            $c['username'],
                            $c['password'],
                            $c['full_name'],
                            $shouldBeActive ? 1 : 0,
                            $row['id']
                        ]);
            } elseif ($shouldBeActive) {
                $pdo->prepare("
                    INSERT INTO users (username, password, full_name, role, employee_id, is_active, synced_from_hrms, force_logout)
                    VALUES (?, ?, ?, 'finance', ?, 1, 1, 0)
                ")->execute([
                            $c['username'],
                            $c['password'],
                            $c['full_name'],
                            $c['employee_id']
                        ]);
            }

            if ($shouldBeActive) {
                $activeEmployeeIds[] = (int) $c['employee_id'];
            }
        }

        // I-deactivate ang mga dati nang naka-sync na Finance account na
        // hindi na dapat active — gaya ng ginagawa sa Inventory Staff sync.
        if (empty($activeEmployeeIds)) {
            $pdo->exec("UPDATE users SET is_active = 0, force_logout = 1 WHERE synced_from_hrms = 1 AND role = 'finance'");
        } else {
            $placeholders = implode(',', array_fill(0, count($activeEmployeeIds), '?'));
            $pdo->prepare("
                UPDATE users SET is_active = 0, force_logout = 1
                WHERE synced_from_hrms = 1 AND role = 'finance' AND employee_id NOT IN ($placeholders)
            ")->execute($activeEmployeeIds);
        }
    } catch (PDOException $e) {
        error_log("syncFinanceAccountsFromHRMS skipped: " . $e->getMessage());
    }
}

syncFinanceAccountsFromHRMS($pdo, $hrmsPdo);

function requireInventoryStaff()
{
    requireLogin();
    if (!in_array($_SESSION['role'], ['inventory_staff', 'admin'], true)) {
        redirectToRoleHome();
    }
}

// AI configuration changes global product-enrichment behavior and usage
// limits, so only the system Admin may change it. AI product intake itself
// remains available to Admin and Inventory Staff through requireInventoryStaff().
function requireAiAdmin()
{
    requireLogin();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        redirectToRoleHome();
    }
}

function requireShiftReportAccess()
{
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', ['admin', 'cashier'], true)) {
        redirectToRoleHome();
    }
}

// Finance role (and Admin, who can access every module) — used to
// gate finance.php and the Finance-side approve/reject actions on
// restock requests (status 'pending_finance').
function requireFinance()
{
    requireLogin();
    if (!in_array($_SESSION['role'], ['finance', 'admin'], true)) {
        redirectToRoleHome();
    }
}

// notifications.php carries global inventory/HR alerts for Admin and
// Inventory Staff, plus user-targeted workflow alerts for Finance and
// Cashier accounts. The page applies the matching per-role data scope.
function requireNotificationsAccess()
{
    requireLogin();
    if (!in_array($_SESSION['role'], ['admin', 'inventory_staff', 'finance', 'cashier'], true)) {
        redirectToRoleHome();
    }
}

function getVisibleUnreadNotificationCount($pdo, $role, $userId)
{
    $userId = (int) $userId;
    if (!$pdo || $userId <= 0)
        return 0;

    $scope = in_array($role, ['admin', 'inventory_staff'], true)
        ? '(user_id = :uid OR user_id IS NULL)'
        : 'user_id = :uid';
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM notifications
            WHERE is_read = 0 AND is_archived = 0 AND {$scope}
        ");
        $stmt->execute([':uid' => $userId]);
        $notificationCount = (int) $stmt->fetchColumn();
        return $notificationCount + getUnreadCompanyAnnouncementCount($pdo, $userId);
    } catch (PDOException $e) {
        error_log("getVisibleUnreadNotificationCount error: " . $e->getMessage());
        return 0;
    }
}

function getUnreadCompanyAnnouncementCount($pdo, $userId)
{
    global $hrmsPdo;
    $userId = (int) $userId;
    if (!$pdo || !$hrmsPdo || $userId <= 0 || !function_exists('getCompanyWideAnnouncementsForPos'))
        return 0;

    $announcements = getCompanyWideAnnouncementsForPos($hrmsPdo, 100);
    if (!$announcements)
        return 0;
    try {
        $stmt = $pdo->prepare("SELECT announcement_id FROM announcement_reads WHERE user_id = ?");
        $stmt->execute([$userId]);
        $readIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return count(array_filter($announcements, fn($announcement) => !in_array((int) $announcement['id'], $readIds, true)));
    } catch (PDOException $e) {
        error_log("getUnreadCompanyAnnouncementCount error: " . $e->getMessage());
        return 0;
    }
}

// notifications.php's "Delete Read" action used to hard-DELETE rows.
// That's permanent data loss with no undo, unlike every other removal
// flow in this app (products, users, etc. all use an is_active-style
// soft delete). This adds the same soft-delete column here so
// "Delete Read" can archive instead of destroying the notification.
function ensureNotificationsArchiveColumn($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'is_archived'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (PDOException $e) {
        error_log("ensureNotificationsArchiveColumn skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureNotificationsArchiveColumn($pdo);
}

// Shares the notifications table with HRMS Merit/Demerit records (see
// notifyPerformanceRecord() in HRMS/includes/config.php) — 'stock' for
// the existing low-stock alerts below, 'performance' for those.
function ensureNotificationsCategoryColumn($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'category'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'stock' AFTER product_id");
        }
        $pdo->exec("ALTER TABLE notifications MODIFY product_id INT NULL");
    } catch (PDOException $e) {
        error_log("ensureNotificationsCategoryColumn skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureNotificationsCategoryColumn($pdo);
}

// =====================================================
// BATCH / EXPIRY TRACKING
// ------------------------------------------------------
// Bawat restock ay maaaring may ibang expiry date kaysa sa dati nang
// stock (bagong delivery, bagong batch). Kaya sa halip na iisang
// `expiry_date` column lang sa products, may hiwalay tayong
// `product_batches` table — isang row bawat restock/batch, may sarili
// itong quantity_remaining at expiry_date. Ang `products.stock` ay
// laging ang TOTAL ng lahat ng batch (kept in sync sa bawat pagbago).
// Pag-benta: FEFO (First-Expire, First-Out) — sa pinaka-malapit
// mag-expire na batch muna kukunin ang stock.
// =====================================================

function getExpiryStatus($expiry_date)
{
    if (empty($expiry_date))
        return null;
    $today = strtotime('today');
    $exp = strtotime($expiry_date);
    $diff = ($exp - $today) / 86400;
    if ($diff < 0)
        return ['label' => 'Expired', 'color' => 'var(--red-alert)', 'icon' => '🚫'];
    if ($diff <= 7)
        return ['label' => 'Expires in ' . (int) $diff . 'd', 'color' => 'var(--red-alert)', 'icon' => '⚠️'];
    if ($diff <= 30)
        return ['label' => 'Expires in ' . (int) $diff . 'd', 'color' => 'var(--mustard)', 'icon' => '⚠️'];
    return ['label' => date('M d, Y', $exp), 'color' => 'var(--ink-soft)', 'icon' => ''];
}

// True kapag ang pinaka-malapit-mag-expire na batch (na may natitirang
// stock) ng isang product ay expired na — ibig sabihin, "not for sale"
// na ang product na 'to sa POS side (see FEFO note above: ang batch na
// 'to ang unang kukunin sa bawat benta). Ginagamit para i-block ang
// pag-set/pag-approve ng discount dito — walang saysay mag-discount
// ng item na hindi naman pwedeng ibenta.
function isProductExpired($pdo, $product_id)
{
    $stmt = $pdo->prepare("
        SELECT MIN(expiry_date) AS nearest_expiry
        FROM product_batches
        WHERE product_id = ? AND quantity_remaining > 0
    ");
    $stmt->execute([$product_id]);
    $nearest = $stmt->fetchColumn();
    if (empty($nearest))
        return false; // walang batch/expiry pa — hindi natin masasabing expired
    return strtotime($nearest) < strtotime('today');
}

// Gumagawa ng bagong batch row (bagong delivery/restock, o initial
// stock ng bagong product). Ang bawat batch ay may sariling expiry
// date — kaya kahit magkaiba ang expiry ng bagong dating stock sa
// luma, hindi na sila magkakahalo.
function addProductBatch($pdo, $product_id, $qty, $cost_price, $expiry_date, $user_id = null, $notes = null)
{
    if ($qty <= 0)
        return null;
    $stmt = $pdo->prepare("
        INSERT INTO product_batches (product_id, quantity_received, quantity_remaining, cost_price, expiry_date, added_by, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$product_id, $qty, $qty, $cost_price, $expiry_date ?: null, $user_id, $notes]);
    return $pdo->lastInsertId();
}

// Available stock na talagang puedeng ibenta — hindi kasama ang mga
// batch na expired na. Kung may batch record ang product (ibig
// sabihin batch-tracked na ito), ito ang gagamitin sa halip ng
// products.stock (na total pa rin kahit may laman pang expired batch).
// Kung walang batch record pa ang product (legacy/unbatched), ang
// products.stock na lang ang gagamitin — hindi ito naaapektuhan ng
// expiry dahil wala namang naka-batch na expiry date dito.
function getSellableStock($pdo, $product_id)
{
    $stmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM product_batches WHERE product_id = ?) AS batch_count,
            (SELECT COALESCE(SUM(quantity_remaining), 0) FROM product_batches
                WHERE product_id = ? AND quantity_remaining > 0
                  AND (expiry_date IS NULL OR expiry_date >= CURDATE())) AS sellable_qty,
            (SELECT stock FROM products WHERE id = ?) AS raw_stock
    ");
    $stmt->execute([$product_id, $product_id, $product_id]);
    $row = $stmt->fetch();
    if (!$row)
        return 0;
    return ((int) $row['batch_count'] > 0) ? (int) $row['sellable_qty'] : (int) $row['raw_stock'];
}

// Bawasan ang stock ng isang product gamit ang FEFO — kukunin muna
// sa batch na pinaka-malapit nang mag-expire. Ibinabalik ang listahan
// ng ginamit na batches (batch_id + quantity) para ma-record at
// maibalik nang tama kapag na-void o na-refund ang benta.
function deductStockFEFO($pdo, $product_id, $qty_needed)
{
    $consumed = [];
    if ($qty_needed <= 0)
        return $consumed;

    $stmt = $pdo->prepare("
        SELECT id, quantity_remaining
        FROM product_batches
        WHERE product_id = :pid AND quantity_remaining > 0
          AND (expiry_date IS NULL OR expiry_date >= CURDATE())
        ORDER BY (expiry_date IS NULL) ASC, expiry_date ASC, date_received ASC
        FOR UPDATE
    ");
    $stmt->execute([':pid' => $product_id]);
    $batches = $stmt->fetchAll();

    $remaining_to_take = $qty_needed;
    foreach ($batches as $batch) {
        if ($remaining_to_take <= 0)
            break;
        $take = min((int) $batch['quantity_remaining'], $remaining_to_take);
        if ($take <= 0)
            continue;

        $pdo->prepare("UPDATE product_batches SET quantity_remaining = quantity_remaining - ? WHERE id = ?")
            ->execute([$take, $batch['id']]);

        $consumed[] = ['batch_id' => $batch['id'], 'quantity' => $take];
        $remaining_to_take -= $take;
    }

    // Fallback para sa "unbatched" na stock (hal. luma nang data bago
    // pa ma-enable ang batch tracking) — huwag paalisin ang benta,
    // pero walang partikular na batch na mababawasan.
    if ($remaining_to_take > 0) {
        $consumed[] = ['batch_id' => null, 'quantity' => $remaining_to_take];
    }

    return $consumed;
}

// Kabaligtaran ng deductStockFEFO — ibinabalik ang dami papunta sa
// mismong batch kung saan ito nanggaling (ginagamit ng void/refund).
function restoreStockToBatches($pdo, $consumption_rows)
{
    foreach ($consumption_rows as $row) {
        if (!empty($row['batch_id'])) {
            $pdo->prepare("UPDATE product_batches SET quantity_remaining = quantity_remaining + ? WHERE id = ?")
                ->execute([$row['quantity'], $row['batch_id']]);
        }
        // Kung batch_id null (unbatched legacy stock), products.stock
        // na lang ang binabalik ng caller — walang partikular na batch.
    }
}

// Gaya ng restoreStockToBatches, pero para sa partial refund kung
// saan hindi lahat ng na-deduct na quantity sa isang sales_detail ang
// ibinabalik — halimbawa 5 ang binili pero 2 lang ang ire-refund.
// Kinukuha lang dito ang unang $qty_to_restore mula sa listahan ng
// batches na ginamit noong binili (order sila base sa FEFO deduction
// noon), kaya sapat pa rin ang natitirang $consumption_rows kung sakaling
// may susunod pang partial refund sa parehong linya.
function restoreStockToBatchesPartial($pdo, $consumption_rows, $qty_to_restore)
{
    $remaining = $qty_to_restore;
    foreach ($consumption_rows as $row) {
        if ($remaining <= 0)
            break;
        $take = min((int) $row['quantity'], $remaining);
        if ($take <= 0)
            continue;

        if (!empty($row['batch_id'])) {
            $pdo->prepare("UPDATE product_batches SET quantity_remaining = quantity_remaining + ? WHERE id = ?")
                ->execute([$take, $row['batch_id']]);
        }
        // Kung batch_id null (unbatched legacy stock), products.stock
        // na lang ang binabalik ng caller — walang partikular na batch.

        $remaining -= $take;
    }
}

function checkLowStock($pdo)
{
    $existing = $pdo->query("
        SELECT n.id, n.product_id, p.stock
        FROM notifications n
        LEFT JOIN products p ON p.id = n.product_id
        WHERE n.category = 'stock'
          AND n.is_archived = 0
          AND n.message LIKE 'Low stock alert:%'
        ORDER BY n.product_id ASC, n.id DESC
    ")->fetchAll();
    $keptByProduct = [];
    $archiveIds = [];
    foreach ($existing as $notification) {
        $productId = (int) $notification['product_id'];
        $stock = $notification['stock'] === null ? null : (int) $notification['stock'];
        $stillLow = $productId > 0 && $stock !== null && $stock > 0 && $stock <= LOW_STOCK;
        if (!$stillLow || isset($keptByProduct[$productId])) {
            $archiveIds[] = (int) $notification['id'];
            continue;
        }
        $keptByProduct[$productId] = true;
    }
    foreach (array_chunk($archiveIds, 200) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $pdo->prepare("UPDATE notifications SET is_archived = 1 WHERE is_archived = 0 AND id IN ({$placeholders})")
            ->execute($chunk);
    }

    $stmt = $pdo->prepare("
        SELECT p.id, p.product_name, p.stock
        FROM products p
        WHERE p.stock <= :low_stock
          AND p.stock > 0
          AND NOT EXISTS (
              SELECT 1
              FROM notifications n
              WHERE n.product_id = p.id
                AND n.category = 'stock'
                AND n.is_archived = 0
                AND n.message LIKE 'Low stock alert:%'
          )
    ");
    $stmt->execute([':low_stock' => LOW_STOCK]);
    $products = $stmt->fetchAll();

    foreach ($products as $product) {
        $message = "Low stock alert: " . $product['product_name'] .
            " — only " . $product['stock'] . " pieces left.";
        $insert = $pdo->prepare("
            INSERT INTO notifications (product_id, message)
            VALUES (:product_id, :message)
        ");
        $insert->execute([
            ':product_id' => $product['id'],
            ':message' => $message
        ]);
    }
}

// =====================================================
// CROSS-ROLE / USER-TARGETED NOTIFICATIONS
// ------------------------------------------------------
// Ang `notifications` table sa itaas ay stock/refund-alert lang
// dating gamit (walang recipient — nakikita ng lahat na pumapasok sa
// notifications.php). Kailangan ng Procurement (Section 8, item 10)
// ng totoong per-user na notification para sa bawat cross-role
// handoff: encode -> verify -> approve -> pay. Dito idinaragdag
// ang mga columns (user_id, link_url, source_type, source_id) sa
// parehong table nang hindi sinisira ang lumang stock-alert behavior
// (kung saan `user_id` ay NULL pa rin), gamit ang parehong
// SHOW-COLUMNS-then-ALTER pattern na ginagamit na sa buong file na ito.
function ensureNotificationRoutingColumns($pdo)
{
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('user_id', $cols, true)) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN user_id INT DEFAULT NULL AFTER product_id");
        }
        if (!in_array('link_url', $cols, true)) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN link_url VARCHAR(255) DEFAULT NULL AFTER message");
        }
        if (!in_array('source_type', $cols, true)) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN source_type VARCHAR(30) DEFAULT NULL AFTER link_url");
        }
        if (!in_array('source_id', $cols, true)) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN source_id INT DEFAULT NULL AFTER source_type");
        }

        // `type` started as ENUM('low_stock','refund') — widen it to a
        // plain VARCHAR so new workflow event types (oe_verification_needed,
        // oe_approval_needed, oe_returned, oe_approved, oe_paid, atbp.)
        // never need another schema edit later.
        $typeCol = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'type'")->fetch();
        if ($typeCol && stripos($typeCol['Type'], 'enum') === 0) {
            $pdo->exec("ALTER TABLE notifications MODIFY type VARCHAR(40) NOT NULL DEFAULT 'low_stock'");
        }

        $idx = $pdo->query("SHOW INDEX FROM notifications WHERE Key_name = 'idx_notif_user'")->fetchAll();
        if (!$idx) {
            $pdo->exec("ALTER TABLE notifications ADD INDEX idx_notif_user (user_id, is_archived, is_read)");
        }
    } catch (PDOException $e) {
        error_log("ensureNotificationRoutingColumns skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureNotificationRoutingColumns($pdo);
}

// Push one notification to exactly one user. This is the building
// block cross-role handoffs are made of — every call below funnels
// through here so there is exactly one insert path to audit/debug.
function pushUserNotification($pdo, $userId, $type, $message, $linkUrl = null, $sourceType = null, $sourceId = null)
{
    $userId = (int) $userId;
    if ($userId <= 0)
        return false;
    ensureNotificationRoutingColumns($pdo);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, category, type, message, link_url, source_type, source_id)
            VALUES (?, 'workflow', ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$userId, $type, $message, $linkUrl, $sourceType, $sourceId]);
    } catch (PDOException $e) {
        error_log("pushUserNotification error: " . $e->getMessage());
        return false;
    }
}

// Push the same event to every active user holding any of the given
// role(s) — one row PER USER (not one shared row), so each person's
// read/archived state stays independent. $excludeUserId lets whoever
// just performed the action skip notifying themselves.
function pushRoleNotification($pdo, $roles, $type, $message, $linkUrl = null, $sourceType = null, $sourceId = null, $excludeUserId = null)
{
    ensureNotificationRoutingColumns($pdo);
    $roles = array_values((array) $roles);
    if (empty($roles))
        return;
    try {
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role IN ($placeholders) AND is_active = 1");
        $stmt->execute($roles);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            if ($excludeUserId !== null && (int) $uid === (int) $excludeUserId)
                continue;
            pushUserNotification($pdo, $uid, $type, $message, $linkUrl, $sourceType, $sourceId);
        }
    } catch (PDOException $e) {
        error_log("pushRoleNotification error: " . $e->getMessage());
    }
}

// =====================================================
// PROFIT & BUSINESS CAPITAL (Owner Transactions)
// ------------------------------------------------------
// Ito ang koneksyon ng "profit" dito sa POS admin side papunta sa
// HRMS Payroll — dito nanggagaling ang net profit na pinagbabatayan
// kung sapat ba ang kinita ng grocery para sa sahod ng lahat ng
// empleyado (cashier, inventory staff, HR staff/manager, supervisor,
// atbp). Kapag kulang, dito rin naka-record ang "Add Capital / Owner
// Transaction" — yung sariling pera ni Admin/Owner na idinagdag sa
// negosyo para masahod ang mga empleyado.
// =====================================================

// Idempotent — gaya ng ibang ensureXTable() dito, safe itong tawagin
// paulit-ulit (hal. sa bawat request) kahit existing na ang table.
// OSCA/PWD ID number + cardholder name for Senior Citizen / PWD
// discounts (RA 9994 / RA 10754 + BIR RR 7-2010 require capturing the
// ID number). Stored on the sales row itself since a sale can only
// carry one discount at a time (see computeDiscount()/apply_discount
// in pos.php).
function ensureDiscountIdColumns($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM sales LIKE 'discount_id_number'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE sales ADD COLUMN discount_id_number VARCHAR(50) NULL AFTER discount_amount");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM sales LIKE 'discount_holder_name'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE sales ADD COLUMN discount_holder_name VARCHAR(150) NULL AFTER discount_id_number");
        }

        // Which Supervisor/Admin approved this Senior/PWD discount by
        // entering their username/password at the POS — see
        // verifyCashierSupervisorDiscountApproval() above.
        $stmt = $pdo->query("SHOW COLUMNS FROM sales LIKE 'discount_approved_by_name'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE sales ADD COLUMN discount_approved_by_name VARCHAR(150) NULL AFTER discount_holder_name");
        }
    } catch (PDOException $e) {
        error_log("ensureDiscountIdColumns skipped: " . $e->getMessage());
    }
}

// Barcode/UPC field for keyboard-wedge scanner support (Item 5 —
// Phase 2 POS audit). Nullable + UNIQUE so products without a printed
// barcode still work fine (MySQL/MariaDB allow multiple NULLs under a
// UNIQUE index), while two products can never accidentally share the
// same scanned code.
function ensureProductBarcodeColumn($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'barcode'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE products ADD COLUMN barcode VARCHAR(64) NULL DEFAULT NULL AFTER product_name");
        }

        $stmt = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_products_barcode'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE products ADD UNIQUE KEY idx_products_barcode (barcode)");
        }
    } catch (PDOException $e) {
        error_log("ensureProductBarcodeColumn skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureProductBarcodeColumn($pdo);
}

function generateInternalProductBarcode(int $productId): string
{
    if ($productId < 1 || $productId > 9999999999) {
        throw new InvalidArgumentException('Product ID is outside the supported range for an internal barcode.');
    }

    $payload = '20' . str_pad((string) $productId, 10, '0', STR_PAD_LEFT);
    $sum = 0;
    for ($index = 0; $index < strlen($payload); $index++) {
        $sum += (int) $payload[$index] * ($index % 2 === 0 ? 1 : 3);
    }
    return $payload . ((10 - ($sum % 10)) % 10);
}

// End of the gated migration block (Item 9) — record that this exact

// Product metadata is kept separate from the transactional products row so
// descriptions and merchandising information can evolve without changing
// stock, price, or sales history. Barcode remains on products because the
// existing unique index and POS lookup already depend on it.
function ensureProductDetailsTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS product_details (
                detail_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id INT NOT NULL,
                description TEXT NULL,
                brand VARCHAR(100) NULL,
                unit_name VARCHAR(50) NULL,
                storage_notes TEXT NULL,
                updated_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (detail_id),
                UNIQUE KEY uq_product_details_product (product_id),
                KEY idx_product_details_updated_by (updated_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS product_detail_audit (
                audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id INT NOT NULL,
                changed_by INT NULL,
                before_json JSON NULL,
                after_json JSON NOT NULL,
                changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (audit_id),
                KEY idx_product_detail_audit_product (product_id),
                KEY idx_product_detail_audit_changed_by (changed_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureProductDetailsTable skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureProductDetailsTable($pdo);
}
// version of config.php has now been verified, so subsequent requests
// skip straight past it until the file changes again.
if ($__migrationsNeeded) {
    @file_put_contents($__migrationMarkerFile, (string) $__configMtime);
}

// Per-product discount — used for promos or markdowns on stock that's
// nearing expiration. discount_type stays 'none' unless a discount is
// actively set from the Products page; discount_reason is just a
// free-text label (e.g. "Nearing expiry", "Holiday promo") shown next
// to the badge, purely informational.
function ensureProductDiscountColumns($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'discount_type'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE products ADD COLUMN discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none' AFTER price");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'discount_value'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE products ADD COLUMN discount_value DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER discount_type");
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'discount_reason'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE products ADD COLUMN discount_reason VARCHAR(100) NULL AFTER discount_value");
        }
    } catch (PDOException $e) {
        error_log("ensureProductDiscountColumns skipped: " . $e->getMessage());
    }
}

// Given a products row (must include price, discount_type, discount_value),
// works out the actual selling price after any active discount. Used on
// the Products page (to show the markdown) and in the POS (to actually
// charge the discounted price).
function getDiscountedPrice($product)
{
    $price = (float) $product['price'];
    $type = $product['discount_type'] ?? 'none';
    $value = (float) ($product['discount_value'] ?? 0);

    if ($type === 'percent' && $value > 0) {
        $final = $price - ($price * min($value, 100) / 100);
    } elseif ($type === 'fixed' && $value > 0) {
        $final = $price - $value;
    } else {
        return ['has_discount' => false, 'original_price' => $price, 'final_price' => $price];
    }

    $final = max(0, round($final, 2));
    return ['has_discount' => true, 'original_price' => $price, 'final_price' => $final];
}

// =====================================================
// SUPPLIER MANAGEMENT MODULE
// ------------------------------------------------------
// Suppliers are a standalone directory (not free-text) so the coming
// Purchase Order module can reference a real supplier_id (FK-safe),
// and so supplier contact details only need to be maintained in one
// place. Deactivating a supplier never deletes the row — it just
// flips status to 'inactive' so it drops out of "active supplier"
// pickers (e.g. when creating a new Purchase Order) while any Purchase
// Orders that already reference it keep working.
// =====================================================
function ensureSuppliersTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS suppliers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_name VARCHAR(150) NOT NULL,
                contact_person VARCHAR(150) DEFAULT NULL,
                contact_number VARCHAR(30) DEFAULT NULL,
                email VARCHAR(150) DEFAULT NULL,
                address VARCHAR(255) DEFAULT NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                notes TEXT DEFAULT NULL,
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_suppliers_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureSuppliersTable skipped: " . $e->getMessage());
    }
}

// suppliers.payment_terms_days — how many days after actual receipt
// (never after PO/Request approval) an unpaid balance for this
// supplier is due. Drives the Accounts Payable due_date computed in
// recordAccountsPayable() below. Defaults to 30 (Net 30) for every
// existing and new supplier unless Admin/Inventory Staff overrides it
// on the supplier record.
function ensureSupplierPaymentTermsColumn($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM suppliers LIKE 'payment_terms_days'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE suppliers ADD COLUMN payment_terms_days INT NOT NULL DEFAULT 30 AFTER status");
        }
    } catch (PDOException $e) {
        error_log("ensureSupplierPaymentTermsColumn skipped: " . $e->getMessage());
    }
}

// Only Admin and Inventory Staff manage the supplier directory —
// mirrors requireInventoryStaff() so it lines up with who already
// manages Products/Inventory (suppliers feed directly into that same
// receiving workflow, and later the Purchase Order module).
function requireSupplierManager()
{
    requireLogin();
    if (!in_array($_SESSION['role'], ['inventory_staff', 'admin'], true)) {
        redirectToRoleHome();
    }
}

// Small helper for other modules (e.g. the Purchase Order module) to
// populate a "select a supplier" dropdown with only active suppliers.
function getActiveSuppliers($pdo)
{
    try {
        return $pdo->query("SELECT id, company_name FROM suppliers WHERE status = 'active' ORDER BY company_name ASC")->fetchAll();
    } catch (PDOException $e) {
        error_log("getActiveSuppliers failed: " . $e->getMessage());
        return [];
    }
}

// Looks up a single supplier by id regardless of active/inactive status —
// used by Products so an item already linked to a since-deactivated
// supplier can still show/keep that link instead of silently losing it.
function getSupplierById($pdo, $id)
{
    if (empty($id) || !ctype_digit((string) $id)) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT id, company_name, status FROM suppliers WHERE id = ?");
        $stmt->execute([(int) $id]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("getSupplierById failed: " . $e->getMessage());
        return null;
    }
}

// =====================================================
// VENDOR PERFORMANCE RATINGS (Section 7, optional item in the
// Procurement build prompt — simple post-delivery/settlement rating so
// recurring late/problem vendors surface on their own instead of only
// living in someone's memory).
// ------------------------------------------------------
// Access (per the Section 2 matrix): Admin and Finance can add AND
// edit any rating. Inventory Staff can add a rating (they're the ones
// physically receiving deliveries) but can never edit one, even their
// own, once it's saved — enforced both by hiding the Edit action in
// the UI and by requireVendorRatingEditor() on the server.
function requireVendorRatingAccess()
{
    requireLogin();
    if (!in_array($_SESSION['role'], ['admin', 'inventory_staff', 'finance'], true)) {
        redirectToRoleHome();
    }
}

function requireVendorRatingEditor()
{
    requireLogin();
    if (!in_array($_SESSION['role'], ['admin', 'finance'], true)) {
        redirectToRoleHome();
    }
}

// vendor_ratings itself is created inside ensureOperatingExpenseTables();
// this only adds the edit-tracking columns a plain "input-only" table
// didn't originally need.
function ensureVendorRatingEditColumns($pdo)
{
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM vendor_ratings")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('updated_by', $cols, true)) {
            $pdo->exec("ALTER TABLE vendor_ratings ADD COLUMN updated_by INT DEFAULT NULL AFTER rated_by");
        }
        if (!in_array('updated_by_name', $cols, true)) {
            $pdo->exec("ALTER TABLE vendor_ratings ADD COLUMN updated_by_name VARCHAR(150) DEFAULT NULL AFTER updated_by");
        }
        if (!in_array('updated_at', $cols, true)) {
            $pdo->exec("ALTER TABLE vendor_ratings ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL AFTER updated_by_name");
        }
    } catch (PDOException $e) {
        error_log("ensureVendorRatingEditColumns skipped: " . $e->getMessage());
    }
}

// One rating = one delivery/settlement. $supplierId XOR a free-text
// $vendorName (for vendors that never go through the Suppliers module,
// e.g. Meralco, PLDT, the landlord) — exactly one of the two is kept.
function addVendorRating($pdo, array $data, $userId, $userName)
{
    ensureOperatingExpenseTables($pdo);
    ensureVendorRatingEditColumns($pdo);

    $supplierId = !empty($data['supplier_id']) ? (int) $data['supplier_id'] : null;
    $vendorName = trim($data['vendor_name'] ?? '');
    $ratingDate = trim($data['rating_date'] ?? '');
    $onTime = ($data['on_time_delivery'] ?? '') === '' ? null : (int) !!$data['on_time_delivery'];
    $qualityIss = ($data['quality_issue'] ?? '') === '' ? null : (int) !!$data['quality_issue'];
    $notes = trim($data['notes'] ?? '');

    if (!$supplierId && $vendorName === '') {
        return ['ok' => false, 'message' => 'Please choose a supplier or type the vendor name.'];
    }
    if ($ratingDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ratingDate)) {
        return ['ok' => false, 'message' => 'Please provide a valid rating date.'];
    }
    if ($onTime === null && $qualityIss === null) {
        return ['ok' => false, 'message' => 'Please answer at least one of the two rating questions.'];
    }

    try {
        $pdo->prepare("
            INSERT INTO vendor_ratings
                (supplier_id, vendor_name, rating_date, on_time_delivery, quality_issue, notes, rated_by, rated_by_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
                    $supplierId,
                    $supplierId ? null : $vendorName,
                    $ratingDate,
                    $onTime,
                    $qualityIss,
                    $notes ?: null,
                    $userId,
                    $userName,
                ]);
        return ['ok' => true];
    } catch (PDOException $e) {
        error_log("addVendorRating error: " . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not save the rating. Please try again.'];
    }
}

// Admin/Finance only (requireVendorRatingEditor() at the call site) —
// Inventory Staff never reaches this even via a crafted request,
// because the page itself gates the POST action by role before calling it.
function updateVendorRating($pdo, $id, array $data, $userId, $userName)
{
    ensureVendorRatingEditColumns($pdo);

    $ratingDate = trim($data['rating_date'] ?? '');
    $onTime = ($data['on_time_delivery'] ?? '') === '' ? null : (int) !!$data['on_time_delivery'];
    $qualityIss = ($data['quality_issue'] ?? '') === '' ? null : (int) !!$data['quality_issue'];
    $notes = trim($data['notes'] ?? '');

    if ($ratingDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ratingDate)) {
        return ['ok' => false, 'message' => 'Please provide a valid rating date.'];
    }
    if ($onTime === null && $qualityIss === null) {
        return ['ok' => false, 'message' => 'Please answer at least one of the two rating questions.'];
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE vendor_ratings
            SET rating_date = ?, on_time_delivery = ?, quality_issue = ?, notes = ?,
                updated_by = ?, updated_by_name = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$ratingDate, $onTime, $qualityIss, $notes ?: null, $userId, $userName, (int) $id]);
        return ['ok' => true];
    } catch (PDOException $e) {
        error_log("updateVendorRating error: " . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not update the rating. Please try again.'];
    }
}

// Admin-only hard delete — for a rating entered by mistake. Ratings
// otherwise have no soft-delete/status field since they're an
// append-only log, not a record with a lifecycle of its own.
function deleteVendorRating($pdo, $id)
{
    try {
        $pdo->prepare("DELETE FROM vendor_ratings WHERE id = ?")->execute([(int) $id]);
        return ['ok' => true];
    } catch (PDOException $e) {
        error_log("deleteVendorRating error: " . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not delete the rating. Please try again.'];
    }
}

// Per-vendor rollup (supplier or free-text vendor name treated the
// same way) — this is the "sino/anong vendor ang paulit-ulit na
// lumalagpas" view the spec asks for, analogous to the Operating
// Expenses "Actual vs Budget" rollup.
function getVendorRatingSummary($pdo, $from = null, $to = null)
{
    ensureOperatingExpenseTables($pdo);
    ensureVendorRatingEditColumns($pdo);

    $where = [];
    $params = [];
    if ($from) {
        $where[] = 'vr.rating_date >= ?';
        $params[] = $from;
    }
    if ($to) {
        $where[] = 'vr.rating_date <= ?';
        $params[] = $to;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(s.company_name, vr.vendor_name, 'Unknown Vendor') AS vendor_label,
                vr.supplier_id,
                COUNT(*) AS total_ratings,
                SUM(CASE WHEN vr.on_time_delivery = 1 THEN 1 ELSE 0 END) AS on_time_count,
                SUM(CASE WHEN vr.on_time_delivery = 0 THEN 1 ELSE 0 END) AS late_count,
                SUM(CASE WHEN vr.quality_issue = 1 THEN 1 ELSE 0 END) AS quality_issue_count,
                MAX(vr.rating_date) AS last_rating_date
            FROM vendor_ratings vr
            LEFT JOIN suppliers s ON s.id = vr.supplier_id
            $whereSql
            GROUP BY vendor_label, vr.supplier_id
            ORDER BY quality_issue_count DESC, late_count DESC, vendor_label ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $onTimeRated = (int) $r['on_time_count'] + (int) $r['late_count'];
            $r['on_time_pct'] = $onTimeRated > 0 ? round(($r['on_time_count'] / $onTimeRated) * 100) : null;
        }
        unset($r);
        return $rows;
    } catch (PDOException $e) {
        error_log("getVendorRatingSummary failed: " . $e->getMessage());
        return [];
    }
}

// Individual rating rows for the table (most recent first), with an
// optional supplier/vendor-name text filter.
function getVendorRatingList($pdo, $search = '')
{
    ensureOperatingExpenseTables($pdo);
    ensureVendorRatingEditColumns($pdo);

    $where = [];
    $params = [];
    if (trim($search) !== '') {
        $where[] = '(s.company_name LIKE ? OR vr.vendor_name LIKE ?)';
        $like = '%' . trim($search) . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    try {
        $stmt = $pdo->prepare("
            SELECT vr.*, s.company_name
            FROM vendor_ratings vr
            LEFT JOIN suppliers s ON s.id = vr.supplier_id
            $whereSql
            ORDER BY vr.rating_date DESC, vr.id DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getVendorRatingList failed: " . $e->getMessage());
        return [];
    }
}

// =====================================================
// PURCHASE ORDER MODULE
// ------------------------------------------------------
// Status flow (enforced in purchase_orders.php / finance.php —
// every transition below is a whitelisted "from status X only"
// check, so a step can never be skipped from the UI):
//
//   draft -> pending_approval -> approved -> ordered
//                                          -> partially_received -> received
//          (rejected)                      (cancelled)
//
// IMPORTANT: creating or approving a PO never touches products.stock
// or product_batches. Stock is only ever updated by the Receiving
// step below (recordPurchaseOrderReceipt()), and only for the exact
// quantity an Inventory Staff confirms was actually received —
// mirrors the same "approval != stock movement" rule already used
// by the Inventory Requests workflow.
// =====================================================
function ensurePurchaseOrdersTables($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS purchase_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                po_number VARCHAR(20) DEFAULT NULL,
                supplier_id INT NOT NULL,
                status ENUM('draft','pending_approval','approved','pending_supplier','rejected','ordered','partially_received','received','cancelled') NOT NULL DEFAULT 'draft',
                notes TEXT DEFAULT NULL,
                created_by INT DEFAULT NULL,
                created_by_name VARCHAR(150) DEFAULT NULL,
                submitted_at TIMESTAMP NULL DEFAULT NULL,
                approved_by INT DEFAULT NULL,
                approved_at TIMESTAMP NULL DEFAULT NULL,
                rejected_by INT DEFAULT NULL,
                rejected_at TIMESTAMP NULL DEFAULT NULL,
                rejection_reason VARCHAR(255) DEFAULT NULL,
                ordered_at TIMESTAMP NULL DEFAULT NULL,
                received_at TIMESTAMP NULL DEFAULT NULL,
                cancelled_by INT DEFAULT NULL,
                cancelled_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_po_status (status),
                KEY idx_po_supplier (supplier_id),
                CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log("ensurePurchaseOrdersTables (purchase_orders) skipped: " . $e->getMessage());
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS purchase_order_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                po_id INT NOT NULL,
                product_id INT DEFAULT NULL,
                item_name VARCHAR(150) NOT NULL,
                quantity_ordered INT NOT NULL,
                quantity_received INT NOT NULL DEFAULT 0,
                unit_cost DECIMAL(10,2) NOT NULL,
                subtotal DECIMAL(12,2) NOT NULL,
                KEY idx_poi_po (po_id),
                KEY idx_poi_product (product_id),
                CONSTRAINT fk_poi_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log("ensurePurchaseOrdersTables (purchase_order_items) skipped: " . $e->getMessage());
    }

}

function ensureProcurementWorkflowColumns($pdo)
{
    try {
        foreach ([
            'purchase_orders' => [
                'department' => "VARCHAR(100) NOT NULL DEFAULT 'Unassigned'",
                'requested_by_hrms_user_id' => 'INT DEFAULT NULL',
                'requester_signature' => 'LONGTEXT DEFAULT NULL',
                'requester_signature_at' => 'DATETIME DEFAULT NULL',
                'finance_signature' => 'LONGTEXT DEFAULT NULL',
                'finance_signature_at' => 'DATETIME DEFAULT NULL',
                'supplier_signature' => 'LONGTEXT DEFAULT NULL',
                'supplier_signature_at' => 'DATETIME DEFAULT NULL',
                'supplier_approval_token_hash' => 'CHAR(64) DEFAULT NULL',
                'supplier_approval_expires_at' => 'DATETIME DEFAULT NULL',
            ],
            'inventory_requests' => [
                'request_group_id' => 'CHAR(36) DEFAULT NULL',
                'request_department' => 'VARCHAR(100) DEFAULT NULL',
                'purchase_order_id' => 'INT DEFAULT NULL',
            ],
            'purchase_order_items' => [
                'inventory_request_id' => 'INT DEFAULT NULL',
            ],
        ] as $table => $definitions) {
            $columns = $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($definitions as $name => $definition) {
                if (!in_array($name, $columns, true)) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
                }
            }
            $statusColumn = $pdo->query("SHOW COLUMNS FROM purchase_orders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
            if (!is_array($statusColumn) || strpos((string) ($statusColumn['Type'] ?? ''), "'pending_supplier'") === false) {
                $pdo->exec("ALTER TABLE purchase_orders MODIFY status ENUM('draft','pending_approval','approved','pending_supplier','rejected','ordered','partially_received','received','cancelled') NOT NULL DEFAULT 'draft'");
            }
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS purchase_order_receipts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                po_id INT NOT NULL,
                receipt_file_path VARCHAR(255) NOT NULL,
                original_file_name VARCHAR(255) DEFAULT NULL,
                note VARCHAR(500) DEFAULT NULL,
                received_by INT NOT NULL,
                received_by_name VARCHAR(150) DEFAULT NULL,
                received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_por_po_received (po_id, received_at),
                CONSTRAINT fk_por_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS purchase_order_receipt_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                receipt_id INT NOT NULL,
                item_name VARCHAR(150) NOT NULL,
                quantity_received INT NOT NULL,
                unit_cost DECIMAL(10,2) NOT NULL,
                line_total DECIMAL(12,2) NOT NULL,
                KEY idx_pori_receipt (receipt_id),
                CONSTRAINT fk_pori_receipt FOREIGN KEY (receipt_id)
                    REFERENCES purchase_order_receipts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $hasLinkIndex = $pdo->query("SHOW INDEX FROM purchase_order_items WHERE Key_name = 'uq_poi_inventory_request'")->fetch();
        if (!$hasLinkIndex) {
            $pdo->exec("ALTER TABLE purchase_order_items ADD UNIQUE KEY uq_poi_inventory_request (inventory_request_id)");
        }
        $constraintStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ?");
        $constraintStmt->execute(['purchase_order_items', 'fk_poi_inventory_request']);
        if (!(int) $constraintStmt->fetchColumn()) {
            $pdo->exec("ALTER TABLE purchase_order_items ADD CONSTRAINT fk_poi_inventory_request FOREIGN KEY (inventory_request_id) REFERENCES inventory_requests(request_id) ON DELETE SET NULL");
        }
        $constraintStmt->execute(['inventory_requests', 'fk_ir_purchase_order']);
        if (!(int) $constraintStmt->fetchColumn()) {
            $pdo->exec("ALTER TABLE inventory_requests ADD CONSTRAINT fk_ir_purchase_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL");
        }
    } catch (PDOException $e) {
        // Duplicate keys/constraints mean a prior deployment already applied the link.
        error_log("ensureProcurementWorkflowColumns skipped: " . $e->getMessage());
    }
}

// Human-friendly PO number, assigned right after the row is inserted
// (needs the auto-increment id first) — e.g. "PO-000001".
function assignPoNumber($pdo, $po_id)
{
    $number = 'PO-' . str_pad((string) $po_id, 6, '0', STR_PAD_LEFT);
    $pdo->prepare("UPDATE purchase_orders SET po_number = ? WHERE id = ?")->execute([$number, $po_id]);
    return $number;
}

function createFinanceApprovedRestockPurchaseOrder($pdo, array $requests, $approvedBy, $approvedByName)
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Restock Purchase Orders must be created inside the approval transaction.');
    }
    if (!$requests) {
        throw new RuntimeException('No restock request lines were provided for the Purchase Order.');
    }

    $firstRequest = $requests[0];
    $supplierId = (int) ($firstRequest['supplier_id'] ?? 0);
    $department = trim((string) ($firstRequest['request_department'] ?? ''));
    if ($supplierId <= 0 || $department === '' || $department === 'Unassigned') {
        throw new RuntimeException('A valid supplier and assigned department are required to create the Purchase Order.');
    }
    foreach ($requests as $request) {
        if (
            (int) ($request['supplier_id'] ?? 0) !== $supplierId
            || trim((string) ($request['request_department'] ?? '')) !== $department
            || (int) ($request['quantity'] ?? 0) <= 0
            || !is_numeric($request['estimated_cost'] ?? null)
            || (float) $request['estimated_cost'] < 0
            || !empty($request['purchase_order_id'])
        ) {
            throw new RuntimeException('The approved restock lines do not form one valid, unassigned supplier and department order.');
        }
    }

    $supplierStmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ? AND status = 'active' LIMIT 1");
    $supplierStmt->execute([$supplierId]);
    if (!$supplierStmt->fetchColumn()) {
        throw new RuntimeException('The selected supplier is no longer active.');
    }

    $pdo->prepare("
        INSERT INTO purchase_orders
            (supplier_id, department, status, notes, created_by, created_by_name, submitted_at, approved_by, approved_at)
        VALUES (?, ?, 'approved', ?, ?, ?, NOW(), ?, NOW())
    ")->execute([
        $supplierId,
        $department,
        'Automatically created from Finance-approved inventory restock request(s). Awaiting supplier e-signature.',
        $approvedBy,
        $approvedByName,
        $approvedBy,
    ]);
    $poId = (int) $pdo->lastInsertId();
    $poNumber = assignPoNumber($pdo, $poId);

    $itemStmt = $pdo->prepare("
        INSERT INTO purchase_order_items
            (po_id, product_id, inventory_request_id, item_name, quantity_ordered, unit_cost, subtotal)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $linkStmt = $pdo->prepare("
        UPDATE inventory_requests
        SET purchase_order_id = ?
        WHERE request_id = ? AND status = 'pending_supplier' AND purchase_order_id IS NULL
    ");
    foreach ($requests as $request) {
        $requestId = (int) $request['request_id'];
        $quantity = (int) $request['quantity'];
        $subtotal = round((float) $request['estimated_cost'], 2);
        $unitCost = round($subtotal / $quantity, 2);
        $itemStmt->execute([
            $poId,
            !empty($request['product_id']) ? (int) $request['product_id'] : null,
            $requestId,
            (string) $request['item_name'],
            $quantity,
            $unitCost,
            $subtotal,
        ]);
        $linkStmt->execute([$poId, $requestId]);
        if ($linkStmt->rowCount() !== 1) {
            throw new RuntimeException('A restock request was assigned to a different Purchase Order before approval completed.');
        }
    }

    return ['id' => $poId, 'number' => $poNumber, 'department' => $department];
}

// Confirms actual receipt of PO items and — only at this step —
// updates real inventory (stock_logs + product_batches), exactly
// mirroring the Restock action's own audit trail so a received PO
// shows up in Inventory Reports / Audit Trail the same way a manual
// restock would. Items with no product_id (not yet in the product
// catalog) still record quantity_received on the PO itself, but
// can't move stock automatically — same limitation as an unlinked
// Inventory Request; add the item to Products first, then restock.
//
// $receipts: [ po_item_id => quantity_received_now, ... ]
function recordPurchaseOrderReceipt($pdo, $po_id, array $receipts, $userId, $reason = 'Purchase Order receiving', array $receiptEvidence = [])
{
    $anyReceivedNow = false;
    $fullyReceived = true;
    $receivedItems = [];
    // Sum of unit_cost * qty received THIS call, across every item —
    // whether or not it's linked to a product yet. This becomes the
    // Accounts Payable amount owed for this receiving event (a PO
    // always has a supplier, so this always becomes a payable, never
    // immediate cash — see the AP block below).
    $receiptTotalCost = 0.0;

    $pdo->beginTransaction();
    try {
        // Lock the PO and all of its lines before calculating remaining
        // quantities. This prevents two receiving requests from both
        // accepting the same units and creating duplicate stock/AP entries.
        $poLockStmt = $pdo->prepare("SELECT id, status FROM purchase_orders WHERE id = ? FOR UPDATE");
        $poLockStmt->execute([$po_id]);
        $po = $poLockStmt->fetch();
        if (!$po) {
            throw new RuntimeException('Purchase Order not found.');
        }
        if (!in_array($po['status'], ['ordered', 'partially_received'], true)) {
            throw new RuntimeException('Only an Ordered or Partially Received Purchase Order can be received.');
        }

        $itemsStmt = $pdo->prepare("SELECT * FROM purchase_order_items WHERE po_id = ? FOR UPDATE");
        $itemsStmt->execute([$po_id]);
        $items = $itemsStmt->fetchAll();
        if (!$items) {
            throw new RuntimeException('This Purchase Order has no items to receive.');
        }

        foreach ($items as $item) {
            $qtyNow = isset($receipts[$item['id']]) ? (int) $receipts[$item['id']] : 0;
            $remaining = (int) $item['quantity_ordered'] - (int) $item['quantity_received'];
            if ($qtyNow < 0) {
                $qtyNow = 0;
            }
            if ($qtyNow > $remaining) {
                $qtyNow = $remaining;
            } // never over-receive past what was ordered

            if ($qtyNow > 0) {
                $anyReceivedNow = true;
                $lineTotal = round((float) $item['unit_cost'] * $qtyNow, 2);
                $receiptTotalCost += $lineTotal;
                $receivedItems[] = [
                    'item_name' => (string) $item['item_name'],
                    'quantity_received' => $qtyNow,
                    'unit_cost' => (float) $item['unit_cost'],
                    'line_total' => $lineTotal,
                ];

                $pdo->prepare("UPDATE purchase_order_items SET quantity_received = quantity_received + ? WHERE id = ?")
                    ->execute([$qtyNow, $item['id']]);

                if (!empty($item['product_id'])) {
                    $prodStmt = $pdo->prepare("SELECT stock FROM products WHERE id = ? FOR UPDATE");
                    $prodStmt->execute([$item['product_id']]);
                    $product = $prodStmt->fetch();

                    if ($product) {
                        $previous_stock = (int) $product['stock'];
                        $new_stock = $previous_stock + $qtyNow;
                        $total_cost = (float) $item['unit_cost'] * $qtyNow;

                        $pdo->prepare("UPDATE products SET stock = stock + :qty, cost_price = :cp WHERE id = :id")
                            ->execute([':qty' => $qtyNow, ':cp' => $item['unit_cost'], ':id' => $item['product_id']]);

                        addProductBatch($pdo, $item['product_id'], $qtyNow, $item['unit_cost'], null, $userId, $reason . ' (PO #' . $po_id . ')');

                        $pdo->prepare("
                            INSERT INTO stock_logs (product_id, change_type, quantity_change, previous_stock, new_stock, reason, total_cost, user_id)
                            VALUES (:pid, 'restock', :qc, :ps, :ns, :r, :tc, :uid)
                        ")->execute([
                                    ':pid' => $item['product_id'],
                                    ':qc' => $qtyNow,
                                    ':ps' => $previous_stock,
                                    ':ns' => $new_stock,
                                    ':r' => $reason . ' (PO #' . $po_id . ')',
                                    ':tc' => $total_cost,
                                    ':uid' => $userId,
                                ]);
                    }
                }
            }

            // Re-check remaining AFTER this receipt to know if the PO as a
            // whole is fully received yet.
            $remainingAfter = $remaining - $qtyNow;
            if ($remainingAfter > 0) {
                $fullyReceived = false;
            }
        }

        if (!$anyReceivedNow) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Please enter a received quantity greater than 0 for at least one item.'];
        }

        $receiptStmt = $pdo->prepare("
            INSERT INTO purchase_order_receipts
                (po_id, receipt_file_path, original_file_name, note, received_by, received_by_name)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $receiptStmt->execute([
            $po_id,
            $receiptEvidence['path'] ?? 'AUTO_GENERATED',
            $receiptEvidence['original_name'] ?? 'System-generated receiving receipt',
            $receiptEvidence['note'] ?? null,
            $userId,
            $receiptEvidence['received_by_name'] ?? null,
        ]);
        $receiptId = (int) $pdo->lastInsertId();
        $receiptItemStmt = $pdo->prepare("
            INSERT INTO purchase_order_receipt_items
                (receipt_id, item_name, quantity_received, unit_cost, line_total)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($receivedItems as $receivedItem) {
            $receiptItemStmt->execute([
                $receiptId,
                $receivedItem['item_name'],
                $receivedItem['quantity_received'],
                $receivedItem['unit_cost'],
                $receivedItem['line_total'],
            ]);
        }

        $newStatus = $fullyReceived ? 'received' : 'partially_received';
        if ($fullyReceived) {
            $pdo->prepare("UPDATE purchase_orders SET status = 'received', received_at = NOW() WHERE id = ?")->execute([$po_id]);
            $pdo->prepare("
                UPDATE inventory_requests ir
                JOIN purchase_order_items poi ON poi.inventory_request_id = ir.request_id
                SET ir.status = 'fulfilled', ir.received_by = ?, ir.received_at = NOW()
                WHERE poi.po_id = ? AND ir.status IN ('approved', 'ready_to_receive')
            ")->execute([$userId, $po_id]);
        } else {
            $pdo->prepare("UPDATE purchase_orders SET status = 'partially_received' WHERE id = ?")->execute([$po_id]);
        }

        // Accounts Payable — recorded now, at actual receipt, never at
        // approval. A Purchase Order always has a supplier, so every
        // receiving event becomes a payable (standard accrual — the
        // grocery now owes the supplier for goods it has in hand).
        if ($receiptTotalCost > 0) {
            $poStmt = $pdo->prepare("SELECT supplier_id, po_number FROM purchase_orders WHERE id = ?");
            $poStmt->execute([$po_id]);
            $poRow = $poStmt->fetch();
            if ($poRow) {
                $refLabel = $poRow['po_number'] ? ('PO ' . $poRow['po_number']) : ('PO #' . $po_id);
                $apRecorded = recordAccountsPayable(
                    $pdo,
                    'purchase_order',
                    $po_id,
                    $poRow['supplier_id'],
                    $refLabel,
                    $receiptTotalCost,
                    $userId,
                    null,
                    null,
                    true
                );
                if (!$apRecorded) {
                    throw new RuntimeException('Accounts Payable could not be recorded for this receipt.');
                }
            } else {
                throw new RuntimeException('Purchase Order supplier details could not be loaded.');
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("recordPurchaseOrderReceipt error: " . $e->getMessage());
        return ['ok' => false, 'message' => 'Receiving could not be saved. Please try again.'];
    }

    try {
        pushRoleNotification(
            $pdo,
            ['finance'],
            'supplier_invoice_required',
            'Received items for PO #' . (int) $po_id . ' are awaiting supplier invoice details before payment.',
            'accounts_payable.php?section=invoices',
            'purchase_order',
            (int) $po_id,
            $userId
        );
    } catch (Throwable $e) {
        error_log("recordPurchaseOrderReceipt notification error: " . $e->getMessage());
    }
    return ['ok' => true, 'status' => $newStatus];
}

function handlePurchaseOrderReceiptUpload($file)
{
    if (empty($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => 'Attach a supplier receipt or delivery document before recording receipt.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > OE_RECEIPT_MAX_BYTES) {
        return ['ok' => false, 'message' => 'The receipt upload failed or exceeds the 5MB limit.'];
    }

    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, OE_RECEIPT_ALLOWED_EXT, true)) {
        return ['ok' => false, 'message' => 'Only JPG, PNG, or PDF receipts are allowed.'];
    }
    $allowedMime = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'application/pdf' => ['pdf'],
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $actualMime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo) {
        finfo_close($finfo);
    }
    if ($actualMime === false || !isset($allowedMime[$actualMime]) || !in_array($ext, $allowedMime[$actualMime], true)) {
        return ['ok' => false, 'message' => 'The uploaded file is not a valid JPG, PNG, or PDF.'];
    }

    $relativeDirectory = 'uploads/receipts/purchase-orders/';
    $absoluteDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0755, true) && !is_dir($absoluteDirectory)) {
        return ['ok' => false, 'message' => 'The receipt upload folder could not be created.'];
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $absoluteDirectory . $name)) {
        return ['ok' => false, 'message' => 'The receipt could not be saved. Please retry.'];
    }

    return [
        'ok' => true,
        'path' => $relativeDirectory . $name,
        'original_name' => basename((string) $file['name']),
    ];
}

// inventory_requests.supplier_id — optional. When Inventory Staff (self
// on Submit, or Finance/Inventory Staff on Validate) attaches a
// supplier to a request, its actual receipt/fulfillment cost becomes
// an Accounts Payable liability instead of the old immediate-cash
// behavior. Left NULL keeps a request exactly as it always worked
// (cash-only, no AP entry). Nullable + no FK-required flow (a
// supplier can be deactivated later without breaking old requests
// that already point at it), but a real FK is still added so it can
// never point at a row that never existed.
function ensureInventoryRequestSupplierColumn($pdo)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM inventory_requests LIKE 'supplier_id'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE inventory_requests ADD COLUMN supplier_id INT NULL DEFAULT NULL AFTER product_id");
        }
    } catch (PDOException $e) {
        error_log("ensureInventoryRequestSupplierColumn (column) skipped: " . $e->getMessage());
    }

    try {
        $stmt = $pdo->query("SHOW INDEX FROM inventory_requests WHERE Key_name = 'idx_ir_supplier'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE inventory_requests ADD KEY idx_ir_supplier (supplier_id)");
        }
    } catch (PDOException $e) {
        error_log("ensureInventoryRequestSupplierColumn (index) skipped: " . $e->getMessage());
    }

    try {
        $stmt = $pdo->query("
            SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_requests' AND CONSTRAINT_NAME = 'fk_ir_supplier'
        ");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE inventory_requests ADD CONSTRAINT fk_ir_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)");
        }
    } catch (PDOException $e) {
        error_log("ensureInventoryRequestSupplierColumn (fk) skipped: " . $e->getMessage());
    }
}

// =====================================================
// ACCOUNTS PAYABLE MODULE
// ------------------------------------------------------
// Standard accrual accounting, confirmed Aug 2026: a payable is only
// ever recorded the moment goods are ACTUALLY received/fulfilled
// (recordPurchaseOrderReceipt(), and the 'fulfill' / 'mark_fulfilled_manual'
// actions in inventory_requests.php) — never at PO/Request "approved",
// which is only a budget commitment, not yet a real obligation to a
// supplier. Applies to both Purchase Orders (supplier_id is always
// required there) and Inventory Requests (supplier_id is optional —
// a request with no supplier attached keeps the original immediate-
// cash behavior and never creates a payable at all).
//
// Initial due_date = receipt date + supplier terms; Finance replaces it
// with invoice date + supplier terms when the supplier invoice is recorded.
// =====================================================
function ensureAccountsPayableTables($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS accounts_payable (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source_type ENUM('purchase_order','inventory_request','operating_expense') NOT NULL,
                source_id INT NOT NULL,
                supplier_id INT DEFAULT NULL,
                payee_name VARCHAR(150) DEFAULT NULL,
                reference_label VARCHAR(150) DEFAULT NULL,
                supplier_invoice_number VARCHAR(100) DEFAULT NULL,
                supplier_invoice_date DATE DEFAULT NULL,
                supplier_invoice_amount DECIMAL(12,2) DEFAULT NULL,
                amount_owed DECIMAL(12,2) NOT NULL,
                amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
                due_date DATE NOT NULL,
                status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_ap_supplier (supplier_id),
                KEY idx_ap_status (status),
                KEY idx_ap_source (source_type, source_id),
                CONSTRAINT fk_ap_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureAccountsPayableTables (accounts_payable) skipped: " . $e->getMessage());
    }

    // Existing installs (bago pa man ang Operating Expenses module) ay
    // may lumang schema — walang payee_name, at NOT NULL pa ang
    // supplier_id/ENUM lang sa dalawang source_type. I-patch papunta
    // sa bagong schema sa itaas, isang beses lang (may column-existence
    // check bago mag-ALTER, tulad ng ensureSupplierPaymentTermsColumn()).
    try {
        $col = $pdo->query("SHOW COLUMNS FROM accounts_payable LIKE 'payee_name'");
        if (!$col->fetch()) {
            $pdo->exec("ALTER TABLE accounts_payable ADD COLUMN payee_name VARCHAR(150) DEFAULT NULL AFTER supplier_id");
            $pdo->exec("ALTER TABLE accounts_payable MODIFY supplier_id INT NULL");
        }
    } catch (PDOException $e) {
        error_log("ensureAccountsPayableTables (payee_name patch) skipped: " . $e->getMessage());
    }
    try {
        $payableColumns = [];
        foreach ($pdo->query("SHOW COLUMNS FROM accounts_payable")->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $payableColumns[(string) $column] = true;
        }
        $invoiceColumns = [
            'supplier_invoice_number' => "ALTER TABLE accounts_payable ADD COLUMN supplier_invoice_number VARCHAR(100) DEFAULT NULL AFTER reference_label",
            'supplier_invoice_date' => "ALTER TABLE accounts_payable ADD COLUMN supplier_invoice_date DATE DEFAULT NULL AFTER supplier_invoice_number",
            'supplier_invoice_amount' => "ALTER TABLE accounts_payable ADD COLUMN supplier_invoice_amount DECIMAL(12,2) DEFAULT NULL AFTER supplier_invoice_date",
        ];
        foreach ($invoiceColumns as $column => $alterSql) {
            if (!isset($payableColumns[$column])) {
                $pdo->exec($alterSql);
            }
        }
    } catch (PDOException $e) {
        error_log("ensureAccountsPayableTables (supplier invoice compatibility patch) skipped: " . $e->getMessage());
    }
    try {
        $pdo->exec("ALTER TABLE accounts_payable MODIFY source_type ENUM('purchase_order','inventory_request','operating_expense') NOT NULL");
    } catch (PDOException $e) {
        error_log("ensureAccountsPayableTables (source_type patch) skipped: " . $e->getMessage());
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS supplier_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                payable_id INT NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                payment_method VARCHAR(50) DEFAULT NULL,
                reference_no VARCHAR(100) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                paid_by INT DEFAULT NULL,
                paid_by_name VARCHAR(150) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sp_payable (payable_id),
                CONSTRAINT fk_sp_payable FOREIGN KEY (payable_id) REFERENCES accounts_payable(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureAccountsPayableTables (supplier_payments) skipped: " . $e->getMessage());
    }

    // CREATE TABLE does not repair an existing legacy table. Add columns
    // introduced after the first Accounts Payable release.
    try {
        $paymentColumns = [];
        foreach ($pdo->query("SHOW COLUMNS FROM supplier_payments")->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $paymentColumns[(string) $column] = true;
        }
        $missingColumns = [
            'payment_method' => "ALTER TABLE supplier_payments ADD COLUMN payment_method VARCHAR(50) DEFAULT NULL AFTER amount",
            'reference_no' => "ALTER TABLE supplier_payments ADD COLUMN reference_no VARCHAR(100) DEFAULT NULL AFTER payment_method",
            'notes' => "ALTER TABLE supplier_payments ADD COLUMN notes TEXT DEFAULT NULL AFTER reference_no",
            'paid_by' => "ALTER TABLE supplier_payments ADD COLUMN paid_by INT DEFAULT NULL AFTER notes",
            'paid_by_name' => "ALTER TABLE supplier_payments ADD COLUMN paid_by_name VARCHAR(150) DEFAULT NULL AFTER paid_by",
        ];
        foreach ($missingColumns as $column => $alterSql) {
            if (!isset($paymentColumns[$column])) {
                $pdo->exec($alterSql);
            }
        }
    } catch (PDOException $e) {
        error_log("ensureAccountsPayableTables (supplier_payments compatibility patch) skipped: " . $e->getMessage());
    }
}

// Accounts Payable is a Finance responsibility (recording who's owed
// what and settling payments) — same access as the rest of the
// Finance module, plus Admin who can reach every module.
function requireAccountsPayableManager()
{
    requireLogin();
    if (!in_array($_SESSION['role'], ['finance', 'admin'], true)) {
        redirectToRoleHome();
    }
}

// Creates the Accounts Payable liability for one actual receipt/
// fulfillment event (Purchase Order / Inventory Request), OR for one
// approved Operating Expense (indirect procurement — utilities, rent,
// gov't fees, atbp.). Only ever called at the moment goods are
// physically received / a bill is fully approved — never at an
// earlier approval-only step. Purchase-order payments remain blocked
// until Finance records the supplier's invoice. Silently does nothing (returns false)
// if there's no supplier/payee attached or nothing owed, which is
// exactly the signal callers use to fall back to the old
// immediate-cash behavior instead.
//
// $supplierId: required for 'purchase_order'/'inventory_request' (feeds
//   the supplier's own payment_terms_days). Pass null for
//   'operating_expense' — those pay a $payeeName instead, which may or
//   may not correspond to a row in `suppliers` (e.g. Meralco).
// $payeeName: only used when $supplierId is null.
// $dueDateOverride: when given (operating expenses already carry their
//   own bill due_date), used as-is instead of computing one from
//   supplier payment terms.
function recordAccountsPayable($pdo, $sourceType, $sourceId, $supplierId, $referenceLabel, $amount, $userId, $payeeName = null, $dueDateOverride = null, $schemaReadyInTransaction = false)
{
    if ((empty($supplierId) && empty($payeeName)) || $amount === null || (float) $amount <= 0) {
        return false;
    }
    if (!in_array($sourceType, ['purchase_order', 'inventory_request', 'operating_expense'], true)) {
        return false;
    }

    // Operating-expense approval prepares this schema before its transaction.
    // Keep the existing behavior for all other callers unless they opt in.
    if (!$pdo->inTransaction() || !$schemaReadyInTransaction)
        ensureAccountsPayableTables($pdo);

    if ($dueDateOverride) {
        $dueDate = $dueDateOverride;
    } else {
        $terms = 30;
        if (!empty($supplierId)) {
            $termsStmt = $pdo->prepare("SELECT payment_terms_days FROM suppliers WHERE id = ?");
            $termsStmt->execute([$supplierId]);
            $t = $termsStmt->fetchColumn();
            $terms = ($t !== false && (int) $t > 0) ? (int) $t : 30;
        }
        $dueDate = date('Y-m-d', strtotime('+' . $terms . ' days'));
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO accounts_payable (source_type, source_id, supplier_id, payee_name, reference_label, amount_owed, due_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$sourceType, $sourceId, $supplierId ?: null, $supplierId ? null : $payeeName, $referenceLabel, round((float) $amount, 2), $dueDate, $userId]);
    } catch (PDOException $e) {
        error_log("recordAccountsPayable error: " . $e->getMessage());
        return false;
    }
}

function recordSupplierInvoice($pdo, $payableId, $invoiceNumber, $invoiceDate, $invoiceAmount)
{
    $invoiceNumber = trim((string) $invoiceNumber);
    $invoiceAmount = round((float) $invoiceAmount, 2);
    if (
        $invoiceNumber === ''
        || strlen($invoiceNumber) > 100
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $invoiceDate)
        || !is_finite($invoiceAmount)
        || $invoiceAmount <= 0
        || $invoiceAmount > 9999999999.99
    ) {
        return ['ok' => false, 'message' => 'Enter a valid supplier invoice number, date, and positive amount.'];
    }
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $invoiceDate);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (
        !$parsedDate
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $parsedDate->format('Y-m-d') !== (string) $invoiceDate
    ) {
        return ['ok' => false, 'message' => 'Enter a valid supplier invoice number, date, and positive amount.'];
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            SELECT ap.*, s.payment_terms_days
            FROM accounts_payable ap
            LEFT JOIN suppliers s ON s.id = ap.supplier_id
            WHERE ap.id = ?
            FOR UPDATE
        ");
        $stmt->execute([(int) $payableId]);
        $payable = $stmt->fetch();
        if (!$payable || $payable['source_type'] !== 'purchase_order') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This supplier invoice entry is only available for received purchase orders.'];
        }
        if ($payable['status'] === 'paid' || (!empty($payable['supplier_invoice_number']) && (float) $payable['amount_paid'] > 0)) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'An invoice cannot be added or changed after payment has started.'];
        }
        if ($invoiceAmount < (float) $payable['amount_paid']) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'The invoice amount cannot be lower than payments already recorded.'];
        }

        $duplicateStmt = $pdo->prepare("
            SELECT id FROM accounts_payable
            WHERE supplier_id = ?
              AND LOWER(TRIM(supplier_invoice_number)) = LOWER(TRIM(?))
              AND id <> ?
            LIMIT 1
        ");
        $duplicateStmt->execute([(int) $payable['supplier_id'], $invoiceNumber, (int) $payableId]);
        if ($duplicateStmt->fetchColumn()) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'That supplier invoice number is already recorded for this supplier.'];
        }

        $terms = (int) ($payable['payment_terms_days'] ?? 0);
        $terms = $terms > 0 ? $terms : 30;
        $dueDate = $parsedDate->modify('+' . $terms . ' days')->format('Y-m-d');
        $update = $pdo->prepare("
            UPDATE accounts_payable
            SET supplier_invoice_number = ?, supplier_invoice_date = ?, supplier_invoice_amount = ?,
                amount_owed = ?, due_date = ?
            WHERE id = ?
        ");
        $update->execute([
            $invoiceNumber,
            $parsedDate->format('Y-m-d'),
            $invoiceAmount,
            $invoiceAmount,
            $dueDate,
            (int) $payableId,
        ]);
        $pdo->commit();
        return ['ok' => true, 'due_date' => $dueDate];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("recordSupplierInvoice error: " . $e->getMessage());
        return ['ok' => false, 'message' => 'Supplier invoice details could not be saved. Please try again.'];
    }
}

// Records one payment against an existing payable, updating its
// running amount_paid/status. Never lets a payment push amount_paid
// past amount_owed (overpayment isn't a thing here — split it into a
// separate, correctly-sized payable instead).
function recordSupplierPayment($pdo, $payableId, $amount, $paymentMethod, $referenceNo, $notes, $userId, $userName, $hrmsPdo = null)
{
    $amount = round((float) $amount, 2);
    $allowedMethods = ['cash', 'bank_transfer', 'check', 'gcash', 'other'];
    if (!is_finite($amount) || $amount <= 0) {
        return ['ok' => false, 'message' => 'Payment amount must be greater than 0.'];
    }
    if ($paymentMethod !== null && $paymentMethod !== '' && !in_array($paymentMethod, $allowedMethods, true)) {
        return ['ok' => false, 'message' => 'Please select a valid payment method.'];
    }
    if (strlen((string) $referenceNo) > 100) {
        return ['ok' => false, 'message' => 'Reference number cannot exceed 100 characters.'];
    }
    if (!$hrmsPdo) {
        return ['ok' => false, 'message' => 'Available cash could not be verified because the HRMS payroll records are unavailable. No payment was recorded. Please reconnect HRMS and try again.'];
    }

    $cashPosition = getBusinessCashPosition($pdo, $hrmsPdo);
    if (empty($cashPosition['payroll_data_available'])) {
        return ['ok' => false, 'message' => 'Available cash could not be verified against released payroll. No payment was recorded. Please check the HRMS connection and try again.'];
    }
    $availableCash = (float) $cashPosition['available_cash'];
    if ($amount > max(0, $availableCash) + 0.005) {
        $shortfall = $amount - $availableCash;
        return [
            'ok' => false,
            'message' => 'Not enough Available Business Cash. This payment is ' . peso($amount)
                . ', available cash is ' . peso($availableCash) . ', and the funding shortfall is ' . peso($shortfall)
                . '. No payment was recorded; the unpaid balance remains a liability. Add capital on the Finance dashboard before paying.'
        ];
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM accounts_payable WHERE id = ? FOR UPDATE");
        $stmt->execute([$payableId]);
        $ap = $stmt->fetch();
        if (!$ap) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Payable not found.'];
        }
        if ($ap['source_type'] === 'purchase_order' && empty($ap['supplier_invoice_number'])) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Record and verify the supplier invoice in Accounts Payable before paying this purchase order.'];
        }

        $remaining = round((float) $ap['amount_owed'] - (float) $ap['amount_paid'], 2);
        if ($remaining <= 0) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This payable is already fully paid.'];
        }
        if ($amount > $remaining + 0.001) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Payment cannot exceed the remaining balance of ' . peso($remaining) . '.'];
        }

        $pdo->prepare("
            INSERT INTO supplier_payments (payable_id, amount, payment_method, reference_no, notes, paid_by, paid_by_name)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$payableId, $amount, $paymentMethod ?: null, $referenceNo ?: null, $notes ?: null, $userId, $userName]);

        $newPaid = round((float) $ap['amount_paid'] + $amount, 2);
        $newStatus = ($newPaid >= (float) $ap['amount_owed'] - 0.01) ? 'paid' : 'partial';

        $update = $pdo->prepare("UPDATE accounts_payable SET amount_paid = ?, status = ? WHERE id = ?");
        $update
            ->execute([$newPaid, $newStatus, $payableId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Payable balance was not updated.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        error_log("recordSupplierPayment error: " . $e->getMessage());
        return ['ok' => false, 'message' => 'Payment could not be saved. Please try again.'];
    }

    // Notifications are best-effort and must not turn a committed payment
    // into a reported failure (which could otherwise invite a duplicate retry).
    if (($ap['source_type'] ?? null) === 'operating_expense' && !empty($ap['source_id'])) {
        try {
            $oeStmt = $pdo->prepare("SELECT encoded_by, payee_name FROM operating_expenses WHERE id = ?");
            $oeStmt->execute([$ap['source_id']]);
            $oeRow = $oeStmt->fetch();
            if ($oeRow) {
                $verb = $newStatus === 'paid' ? 'Fully paid' : 'Partially paid';
                pushUserNotification(
                    $pdo,
                    $oeRow['encoded_by'],
                    'oe_paid',
                    $verb . ': bill for ' . ($oeRow['payee_name'] ?? '') . ' — ' . peso($amount) . ' paid today.',
                    'operating_expenses.php?oe=' . enc_id($ap['source_id']),
                    'operating_expense',
                    $ap['source_id']
                );
            }
        } catch (Throwable $e) {
            error_log("recordSupplierPayment notification error: " . $e->getMessage());
        }
    }

    return ['ok' => true, 'status' => $newStatus];
}

// Approved PO lines are tracked as commitments until their goods arrive.
// Only the unreceived quantity is included; receipt creates the actual
// Accounts Payable liability via recordPurchaseOrderReceipt().
function getUnreceivedPurchaseOrderCommitments($pdo)
{
    $stmt = $pdo->query("
        SELECT po.id, po.po_number, po.status,
               s.company_name AS supplier_name,
               COALESCE(SUM(GREATEST(poi.quantity_ordered - poi.quantity_received, 0) * poi.unit_cost), 0) AS unreceived_amount
        FROM purchase_orders po
        JOIN suppliers s ON s.id = po.supplier_id
        JOIN purchase_order_items poi ON poi.po_id = po.id
        WHERE po.status IN ('approved', 'ordered', 'partially_received')
        GROUP BY po.id, po.po_number, po.status, s.company_name
        HAVING unreceived_amount > 0
        ORDER BY po.created_at DESC, po.id DESC
    ");
    $rows = $stmt->fetchAll();
    $total = 0.0;
    foreach ($rows as $row) {
        $total += (float) $row['unreceived_amount'];
    }
    return ['rows' => $rows, 'total' => round($total, 2)];
}

// Every outstanding (unpaid/partial) payable, grouped into standard
// aging buckets off today's date vs. due_date — for accounts_payable.php.
function getAccountsPayableAging($pdo)
{
    $rows = $pdo->query("
        SELECT ap.*, COALESCE(s.company_name, ap.payee_name, 'Unknown Payee') AS supplier_name
        FROM accounts_payable ap
        LEFT JOIN suppliers s ON s.id = ap.supplier_id
        WHERE ap.status IN ('unpaid', 'partial')
        ORDER BY ap.due_date ASC, ap.id ASC
    ")->fetchAll();

    $buckets = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_plus' => 0.0];
    $today = new DateTime('today');

    foreach ($rows as &$r) {
        $balance = round((float) $r['amount_owed'] - (float) $r['amount_paid'], 2);
        $due = new DateTime($r['due_date']);
        $daysOverdue = (int) $today->diff($due)->format('%r%a') * -1; // positive = past due

        if ($daysOverdue <= 0) {
            $bucket = 'current';
        } elseif ($daysOverdue <= 30) {
            $bucket = '1_30';
        } elseif ($daysOverdue <= 60) {
            $bucket = '31_60';
        } else {
            $bucket = '61_plus';
        }

        $r['balance'] = $balance;
        $r['days_overdue'] = $daysOverdue;
        $r['bucket'] = $bucket;
        $buckets[$bucket] += $balance;
    }
    unset($r);

    return [
        'rows' => $rows,
        'buckets' => $buckets,
        'total_outstanding' => array_sum($buckets),
    ];
}

// =====================================================
// OPERATING EXPENSES MODULE (Indirect Procurement)
// ------------------------------------------------------
// Covers bills that are NOT resold merchandise — kuryente, tubig,
// internet, renta, office supplies, gov't fees, atbp. Parallel sa
// Purchase Order flow sa itaas pero may proof-of-bill requirement,
// budget check, at halaga-based approval threshold. Parehong
// "pinto" papunta sa Finance ang ginagamit nito — recordAccountsPayable()
// sa itaas — kaya iisang Accounts Payable/aging view lang ang
// kailangan bantayan, hindi dalawa.
//
// Status flow (bawat transition ay isang whitelisted "from status X
// only" check sa mga function sa ibaba — walang paraang tumalon ang
// UI papunta sa isang status nang hindi dumadaan dito):
//
//   draft -> pending_verification -> verified -> approved
//                                  -> pending_approval -> approved   (kung >= threshold)
//                                  -> returned -> (draft, after edit+resubmit)
//   (cancelled, mula draft o returned lang)
// =====================================================

define('OE_LEVEL2_THRESHOLD_DEFAULT', 10000.00);
define('OE_RECEIPT_UPLOAD_DIR', 'uploads/receipts/');
define('OE_RECEIPT_ALLOWED_EXT', ['jpg', 'jpeg', 'png', 'pdf']);
define('OE_RECEIPT_MAX_BYTES', 5 * 1024 * 1024); // 5MB

function ensureOperatingExpenseTables($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS expense_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                is_recurring TINYINT(1) NOT NULL DEFAULT 0,
                monthly_budget DECIMAL(12,2) DEFAULT NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureOperatingExpenseTables (expense_categories) skipped: " . $e->getMessage());
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS operating_expenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category_id INT NOT NULL,
                payee_name VARCHAR(150) NOT NULL,
                description VARCHAR(255) DEFAULT NULL,
                billing_period_start DATE DEFAULT NULL,
                billing_period_end DATE DEFAULT NULL,
                due_date DATE NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                is_recurring TINYINT(1) NOT NULL DEFAULT 0,
                receipt_file_path VARCHAR(255) NOT NULL,
                status ENUM('draft','pending_verification','verified','pending_approval','approved','returned','cancelled') NOT NULL DEFAULT 'draft',
                requires_level2_approval TINYINT(1) NOT NULL DEFAULT 0,
                over_budget_flag TINYINT(1) NOT NULL DEFAULT 0,
                over_budget_reason VARCHAR(255) DEFAULT NULL,
                encoded_by INT NOT NULL,
                encoded_by_name VARCHAR(150) DEFAULT NULL,
                verified_by INT DEFAULT NULL,
                verified_by_name VARCHAR(150) DEFAULT NULL,
                verified_at TIMESTAMP NULL DEFAULT NULL,
                approved_by INT DEFAULT NULL,
                approved_by_name VARCHAR(150) DEFAULT NULL,
                approved_at TIMESTAMP NULL DEFAULT NULL,
                return_reason VARCHAR(255) DEFAULT NULL,
                returned_at TIMESTAMP NULL DEFAULT NULL,
                cancelled_by INT DEFAULT NULL,
                cancelled_at TIMESTAMP NULL DEFAULT NULL,
                submitted_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_oe_status (status),
                KEY idx_oe_category (category_id),
                CONSTRAINT fk_oe_category FOREIGN KEY (category_id) REFERENCES expense_categories(id),
                CONSTRAINT chk_oe_encoder_verifier CHECK (verified_by IS NULL OR verified_by <> encoded_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureOperatingExpenseTables (operating_expenses) skipped: " . $e->getMessage());
    }

    // Retrofit: CREATE TABLE IF NOT EXISTS above does nothing if an older
    // copy of operating_expenses already exists (e.g. from an earlier
    // schema dump), so add any columns that older copy is missing.
    try {
        $existingOeCols = $pdo->query("SHOW COLUMNS FROM operating_expenses")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('encoded_by_name', $existingOeCols, true)) {
            $pdo->exec("ALTER TABLE operating_expenses ADD COLUMN encoded_by_name VARCHAR(150) DEFAULT NULL AFTER encoded_by");
        }
        if (!in_array('verified_by_name', $existingOeCols, true)) {
            $pdo->exec("ALTER TABLE operating_expenses ADD COLUMN verified_by_name VARCHAR(150) DEFAULT NULL AFTER verified_by");
        }
        if (!in_array('verified_at', $existingOeCols, true)) {
            $pdo->exec("ALTER TABLE operating_expenses ADD COLUMN verified_at TIMESTAMP NULL DEFAULT NULL AFTER verified_by_name");
        }
        if (!in_array('approved_by_name', $existingOeCols, true)) {
            $pdo->exec("ALTER TABLE operating_expenses ADD COLUMN approved_by_name VARCHAR(150) DEFAULT NULL AFTER approved_by");
        }
        if (!in_array('approved_at', $existingOeCols, true)) {
            $pdo->exec("ALTER TABLE operating_expenses ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL AFTER approved_by_name");
        }
        if (!in_array('returned_at', $existingOeCols, true)) {
            $pdo->exec("ALTER TABLE operating_expenses ADD COLUMN returned_at TIMESTAMP NULL DEFAULT NULL AFTER return_reason");
        }
        if (!in_array('cancelled_by', $existingOeCols, true)) {
            $pdo->exec("ALTER TABLE operating_expenses ADD COLUMN cancelled_by INT DEFAULT NULL AFTER returned_at");
        }
        if (!in_array('cancelled_at', $existingOeCols, true)) {
            $pdo->exec("ALTER TABLE operating_expenses ADD COLUMN cancelled_at TIMESTAMP NULL DEFAULT NULL AFTER cancelled_by");
        }
        if (!in_array('submitted_at', $existingOeCols, true)) {
            $pdo->exec("ALTER TABLE operating_expenses ADD COLUMN submitted_at TIMESTAMP NULL DEFAULT NULL AFTER cancelled_at");
        }
    } catch (PDOException $e) {
        error_log("ensureOperatingExpenseTables (operating_expenses retrofit) skipped: " . $e->getMessage());
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS vendor_ratings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT DEFAULT NULL,
                vendor_name VARCHAR(150) DEFAULT NULL,
                rating_date DATE NOT NULL,
                on_time_delivery TINYINT(1) DEFAULT NULL,
                quality_issue TINYINT(1) DEFAULT NULL,
                notes VARCHAR(255) DEFAULT NULL,
                rated_by INT NOT NULL,
                rated_by_name VARCHAR(150) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureOperatingExpenseTables (vendor_ratings) skipped: " . $e->getMessage());
    }

    // accounts_payable itself is patched inside ensureAccountsPayableTables()
    // (source_type ENUM + payee_name column) — called from there too, but
    // call it again here so Operating Expenses works even on a page that
    // never otherwise touches Accounts Payable.
    ensureAccountsPayableTables($pdo);
}

// store_settings already ships with the base schema (see pos.php /
// shift_report.php, which already SELECT from it) — this is just a
// safety net for a fresh database, matching the same shape.
function ensureStoreSettingsTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS store_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT DEFAULT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY setting_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureStoreSettingsTable skipped: " . $e->getMessage());
    }
}

function getStoreSetting($pdo, $key, $default = null)
{
    ensureStoreSettingsTable($pdo);
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM store_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val === false || $val === null || $val === '') ? $default : $val;
    } catch (PDOException $e) {
        error_log("getStoreSetting error: " . $e->getMessage());
        return $default;
    }
}

function setStoreSetting($pdo, $key, $value)
{
    ensureStoreSettingsTable($pdo);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        return $stmt->execute([$key, $value]);
    } catch (PDOException $e) {
        error_log("setStoreSetting error: " . $e->getMessage());
        return false;
    }
}

// Configurable (not hardcoded) Level 2 approval threshold — Admin can
// change this from expense_categories.php without touching source code.
function getOperatingExpenseLevel2Threshold($pdo)
{
    return (float) getStoreSetting($pdo, 'oe_level2_threshold', OE_LEVEL2_THRESHOLD_DEFAULT);
}

function setOperatingExpenseLevel2Threshold($pdo, $amount)
{
    return setStoreSetting($pdo, 'oe_level2_threshold', round((float) $amount, 2));
}

// ---------- Access control ----------

// Admin at Finance ang pwedeng mag-encode ng operating-expense bills
// (indirect procurement) — pareho ring sila ang pwedeng mag-Level-1-
// verify, PERO hindi ang eksaktong parehong tao para sa isang bill
// (segregation of duties, enforced ulit sa verifyOperatingExpense()).
function requireOperatingExpenseManager()
{
    requireLogin();
    if (!in_array($_SESSION['role'], ['admin', 'finance'], true)) {
        redirectToRoleHome();
    }
}

// Level 2 approval (malalaking bill, katumbas o lampas sa threshold)
// ay Admin lang — hindi kahit Finance na siyang gumawa ng Level 1.
function requireOperatingExpenseLevel2Approver()
{
    requireLogin();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        redirectToRoleHome();
    }
}

// Parehong access ng Budgeting — Admin + Finance lang ang namamahala
// ng mga kategorya at kanya-kanyang monthly budget.
function requireExpenseCategoryManager()
{
    requireLogin();
    if (!in_array($_SESSION['role'], ['admin', 'finance'], true)) {
        redirectToRoleHome();
    }
}

// ---------- Category helpers ----------

function getActiveExpenseCategories($pdo)
{
    ensureOperatingExpenseTables($pdo);
    try {
        return $pdo->query("SELECT * FROM expense_categories WHERE status = 'active' ORDER BY name ASC")->fetchAll();
    } catch (PDOException $e) {
        error_log("getActiveExpenseCategories failed: " . $e->getMessage());
        return [];
    }
}

function getAllExpenseCategories($pdo)
{
    ensureOperatingExpenseTables($pdo);
    try {
        return $pdo->query("SELECT * FROM expense_categories ORDER BY status ASC, name ASC")->fetchAll();
    } catch (PDOException $e) {
        error_log("getAllExpenseCategories failed: " . $e->getMessage());
        return [];
    }
}

function getExpenseCategoryById($pdo, $id)
{
    if (empty($id) || !ctype_digit((string) $id))
        return null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM expense_categories WHERE id = ?");
        $stmt->execute([(int) $id]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("getExpenseCategoryById failed: " . $e->getMessage());
        return null;
    }
}

function upsertExpenseCategory($pdo, $id, $name, $isRecurring, $monthlyBudget, $status)
{
    ensureOperatingExpenseTables($pdo);
    $name = trim((string) $name);
    if ($name === '')
        return false;
    if (!in_array($status, ['active', 'inactive'], true))
        return false;
    if ($monthlyBudget === '' || $monthlyBudget === null) {
        $monthlyBudget = null;
    } elseif (!is_numeric($monthlyBudget) || !is_finite((float) $monthlyBudget) || (float) $monthlyBudget < 0) {
        return false;
    } else {
        $monthlyBudget = round((float) $monthlyBudget, 2);
    }

    try {
        $pdo->beginTransaction();
        if (!empty($id)) {
            $exists = $pdo->prepare("SELECT id FROM expense_categories WHERE id = ? FOR UPDATE");
            $exists->execute([(int) $id]);
            if (!$exists->fetchColumn()) {
                $pdo->rollBack();
                return false;
            }
            $stmt = $pdo->prepare("UPDATE expense_categories SET name=?, is_recurring=?, monthly_budget=?, status=? WHERE id=?");
            $stmt->execute([$name, $isRecurring ? 1 : 0, $monthlyBudget, $status, (int) $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO expense_categories (name, is_recurring, monthly_budget, status) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $isRecurring ? 1 : 0, $monthlyBudget, $status]);
        }
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        error_log("upsertExpenseCategory error: " . $e->getMessage());
        return false;
    }
}

// Soft-deactivate lang — hindi pwedeng burahin nang tuluyan ang isang
// category na may naka-link nang operating_expenses record (mirrors
// the same soft-delete rule already used elsewhere in the system).
function deactivateExpenseCategory($pdo, $id)
{
    try {
        if (empty($id) || !ctype_digit((string) $id))
            return false;
        ensureOperatingExpenseTables($pdo);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE expense_categories SET status = 'inactive' WHERE id = ? AND status = 'active'");
        $stmt->execute([(int) $id]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        error_log("deactivateExpenseCategory error: " . $e->getMessage());
        return false;
    }
}

// ---------- Budget check ----------

// Kabuuang halaga ng mga 'approved' na operating expenses para sa
// isang category, sa buwan kung saan babagsak ang due_date ng bill —
// ginagamit bilang batayan kung malalampasan ba ang monthly_budget
// nito. $excludeId: huwag isama ang sarili niyang record (para sa
// muling pagkuwenta bago aprubahan ang record mismong iyon).
function getExpenseCategoryMonthActual($pdo, $categoryId, $referenceDate, $excludeId = null)
{
    $monthStart = date('Y-m-01', strtotime($referenceDate));
    $monthEnd = date('Y-m-t', strtotime($referenceDate));
    $sql = "
        SELECT COALESCE(SUM(amount), 0) FROM operating_expenses
        WHERE category_id = ? AND status = 'approved' AND due_date BETWEEN ? AND ?
    ";
    $params = [$categoryId, $monthStart, $monthEnd];
    if (!empty($excludeId)) {
        $sql .= " AND id <> ?";
        $params[] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

// Para sa budgeting.php "Actual vs Budget" section — bawat active
// category kasama ang naipong 'approved' na gastos para sa hinaing
// buwan (month ng $referenceDate).
function getOperatingExpensesActualVsBudget($pdo, $referenceDate = null)
{
    ensureOperatingExpenseTables($pdo);
    $referenceDate = $referenceDate ?: date('Y-m-d');
    $categories = getActiveExpenseCategories($pdo);
    $out = [];
    foreach ($categories as $cat) {
        $actual = getExpenseCategoryMonthActual($pdo, $cat['id'], $referenceDate);
        $out[] = [
            'category' => $cat,
            'actual' => $actual,
            'budget' => $cat['monthly_budget'],
            'over_budget' => ($cat['monthly_budget'] !== null && $actual > (float) $cat['monthly_budget'] + 0.01),
            'percent_used' => ($cat['monthly_budget'] !== null && (float) $cat['monthly_budget'] > 0)
                ? min(999, round(($actual / (float) $cat['monthly_budget']) * 100))
                : null,
        ];
    }
    return $out;
}

// ---------- Receipt / bill attachment upload ----------

// Bina-validate at ini-store ang naka-attach na resibo/bill (jpg/png/pdf
// lang, may 5MB size limit), gamit ang parehong "random filename, sariling
// upload folder" na pattern ng product image upload (products.php) —
// hindi ang orihinal na filename mula sa user ang ginagamit, para hindi
// ito basta ma-guess o ma-overwrite.
function handleOperatingExpenseReceiptUpload($file)
{
    if (empty($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => 'Please attach a copy of the bill/receipt (required).'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'The file failed to upload. Please try again.'];
    }
    if ($file['size'] > OE_RECEIPT_MAX_BYTES) {
        return ['ok' => false, 'message' => 'File is too large (max 5MB).'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, OE_RECEIPT_ALLOWED_EXT, true)) {
        return ['ok' => false, 'message' => 'Only JPG, PNG, or PDF files are allowed.'];
    }

    // Extension whitelist alone can be spoofed by simply renaming a file
    // (e.g. malware.exe -> malware.jpg) — check the actual file content's
    // MIME type too, not just what the filename claims to be.
    $allowedMime = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'application/pdf' => ['pdf']];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $actualMime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo)
        finfo_close($finfo);
    if ($actualMime === false || !isset($allowedMime[$actualMime]) || !in_array($ext, $allowedMime[$actualMime], true)) {
        return ['ok' => false, 'message' => 'This file does not look like a valid JPG, PNG, or PDF. Please re-save/re-export it and try again.'];
    }

    if (!is_dir(OE_RECEIPT_UPLOAD_DIR)) {
        mkdir(OE_RECEIPT_UPLOAD_DIR, 0755, true);
    }

    $randomName = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = OE_RECEIPT_UPLOAD_DIR . $randomName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        error_log("handleOperatingExpenseReceiptUpload: move_uploaded_file failed for $randomName");
        return ['ok' => false, 'message' => 'Could not save the uploaded file. Please try again.'];
    }

    return ['ok' => true, 'path' => $randomName];
}

// ---------- Workflow: encode / submit / verify / approve / return ----------

// Encode a new bill as Draft. Isang naka-attach na resibo/bill ang
// hard requirement bago pa man ma-save ito — kahit Draft pa lang
// (Section 4 & 8 ng spec).
function createOperatingExpenseDraft($pdo, array $data, $file, $userId, $userName)
{
    ensureOperatingExpenseTables($pdo);

    $upload = handleOperatingExpenseReceiptUpload($file);
    if (!$upload['ok']) {
        return $upload;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO operating_expenses
                (category_id, payee_name, description, billing_period_start, billing_period_end,
                 due_date, amount, is_recurring, receipt_file_path, status, encoded_by, encoded_by_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)
        ");
        $stmt->execute([
            (int) $data['category_id'],
            trim($data['payee_name']),
            trim($data['description']) ?: null,
            $data['billing_period_start'] ?: null,
            $data['billing_period_end'] ?: null,
            $data['due_date'],
            round((float) $data['amount'], 2),
            !empty($data['is_recurring']) ? 1 : 0,
            $upload['path'],
            $userId,
            $userName,
        ]);
        return ['ok' => true, 'id' => $pdo->lastInsertId()];
    } catch (PDOException $e) {
        error_log("createOperatingExpenseDraft error: " . $e->getMessage());
        @unlink(OE_RECEIPT_UPLOAD_DIR . $upload['path']); // huwag mag-iwan ng orphaned file
        return ['ok' => false, 'message' => 'Could not save the bill. Please try again.'];
    }
}

// draft -> pending_verification.
function submitOperatingExpense($pdo, $id, $userId)
{
    $stmt = $pdo->prepare("SELECT * FROM operating_expenses WHERE id = ?");
    $stmt->execute([$id]);
    $oe = $stmt->fetch();
    if (!$oe)
        return ['ok' => false, 'message' => 'Bill not found.'];
    if ($oe['status'] !== 'draft')
        return ['ok' => false, 'message' => 'Only a draft bill can be submitted.'];
    if (empty($oe['receipt_file_path']))
        return ['ok' => false, 'message' => 'Please attach the receipt/bill before submitting.'];

    $pdo->prepare("UPDATE operating_expenses SET status='pending_verification', submitted_at = NOW() WHERE id = ?")->execute([$id]);

    // Handoff #1: encode -> verify. Notify everyone who can Level-1-verify
    // (Finance + Admin) except the encoder — they can't verify their own
    // bill anyway (segregation of duties), so they don't need the ping.
    pushRoleNotification(
        $pdo,
        ['finance', 'admin'],
        'oe_verification_needed',
        'New bill submitted by ' . ($oe['encoded_by_name'] ?: 'a user') . ' — ' . ($oe['payee_name'] ?? '') . ' (' . peso($oe['amount']) . ') — Level 1 verification required.',
        'operating_expenses.php?oe=' . enc_id($id),
        'operating_expense',
        $id,
        (int) $userId
    );

    return ['ok' => true];
}

// Level 1 verification (Finance o Admin, PERO hindi ang eksaktong
// parehong tao na nag-encode — segregation of duties, double-checked
// dito at sa CHECK constraint ng database). Kapag mababa sa
// threshold, dumidiretso agad papunta sa parehong budget-check +
// Accounts-Payable-posting na ginagamit ng Level 2 (finalizeApprovedOperatingExpense());
// kapag katumbas o lampas, nananatiling 'pending_approval' hanggang
// mag-Level-2-approve ang isang Admin.
function verifyOperatingExpense($pdo, $id, $userId, $userName, $overrideReason = null)
{
    ensureOperatingExpenseTables($pdo);
    // Reading this setting ensures its table exists, which may execute DDL;
    // perform it before the transaction to avoid MySQL's implicit commit.
    $threshold = getOperatingExpenseLevel2Threshold($pdo);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM operating_expenses WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $oe = $stmt->fetch();
        if (!$oe) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Bill not found.'];
        }
        if ($oe['status'] !== 'pending_verification') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This bill is not waiting for verification.'];
        }
        if ((int) $oe['encoded_by'] === (int) $userId) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => "You can't verify a bill you encoded yourself — ask another Finance/Admin user to verify it."];
        }

        $needsLevel2 = ((float) $oe['amount'] >= $threshold);
        $pdo->prepare("
            UPDATE operating_expenses
            SET verified_by = ?, verified_by_name = ?, verified_at = NOW(),
                requires_level2_approval = ?, status = ?
            WHERE id = ?
        ")->execute([$userId, $userName, $needsLevel2 ? 1 : 0, $needsLevel2 ? 'pending_approval' : 'verified', $id]);

        if (!$needsLevel2) {
            $result = finalizeApprovedOperatingExpense($pdo, $id, $userId, $userName, $overrideReason);
            if (!$result['ok']) {
                $pdo->rollBack();
                return $result;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        error_log("verifyOperatingExpense error: " . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not verify this bill. Please try again.'];
    }

    try {
        if ($needsLevel2) {
            pushRoleNotification(
                $pdo,
                ['admin'],
                'oe_approval_needed',
                'Bill na ' . peso($oe['amount']) . ' (' . ($oe['payee_name'] ?? '') . ') ay na-verify na ni ' . $userName . ' at kailangan na ng Level 2 approval (lampas sa threshold).',
                'operating_expenses.php?oe=' . enc_id($id),
                'operating_expense',
                $id,
                (int) $userId
            );
        } else {
            pushUserNotification(
                $pdo,
                $oe['encoded_by'],
                'oe_approved',
                'Na-approve na ang bill mo na ' . ($oe['payee_name'] ?? '') . ' (' . peso($oe['amount']) . ') — nasa Accounts Payable na para bayaran.',
                'operating_expenses.php?oe=' . enc_id($id),
                'operating_expense',
                $id
            );
        }
    } catch (Throwable $e) {
        error_log("verifyOperatingExpense notification error: " . $e->getMessage());
    }

    return ['ok' => true, 'needs_level2' => $needsLevel2];
}

// Ibinabalik ang bill sa nag-encode (status -> 'returned') na may
// dahilan — hindi ito tuluyang cancel; puwede pa itong i-edit at
// i-resubmit ulit (resubmitOperatingExpense() sa ibaba).
function returnOperatingExpense($pdo, $id, $userId, $reason)
{
    $reason = trim((string) $reason);
    if ($reason === '')
        return ['ok' => false, 'message' => 'Please provide a reason for returning this bill.'];

    $stmt = $pdo->prepare("SELECT * FROM operating_expenses WHERE id = ?");
    $stmt->execute([$id]);
    $oe = $stmt->fetch();
    if (!$oe)
        return ['ok' => false, 'message' => 'Bill not found.'];
    if (!in_array($oe['status'], ['pending_verification', 'pending_approval'], true)) {
        return ['ok' => false, 'message' => 'Only a bill waiting for verification or approval can be returned.'];
    }

    $pdo->prepare("UPDATE operating_expenses SET status='returned', return_reason=?, returned_at=NOW() WHERE id=?")
        ->execute([$reason, $id]);

    // Handoff: returned -> back to encoder, with the reason so they know
    // exactly what to fix before resubmitting.
    pushUserNotification(
        $pdo,
        $oe['encoded_by'],
        'oe_returned',
        'Na-return ang bill mo na ' . ($oe['payee_name'] ?? '') . ' (' . peso($oe['amount']) . '). Dahilan: ' . $reason,
        'operating_expenses.php?oe=' . enc_id($id),
        'operating_expense',
        $id
    );

    return ['ok' => true];
}

// Muling isumite ang isang 'returned' na bill matapos itong i-edit —
// bumabalik sa 'pending_verification' at nililinis ang mga naunang
// desisyon (level 1/level 2) para hindi na dala ang lumang data.
// Isang bagong file ay optional — kung wala, ginagamit pa rin ang
// dati nang naka-attach na resibo.
function resubmitOperatingExpense($pdo, $id, array $data, $newFile, $userId)
{
    $stmt = $pdo->prepare("SELECT * FROM operating_expenses WHERE id = ?");
    $stmt->execute([$id]);
    $oe = $stmt->fetch();
    if (!$oe)
        return ['ok' => false, 'message' => 'Bill not found.'];
    if ($oe['status'] !== 'returned')
        return ['ok' => false, 'message' => 'Only a returned bill can be edited and resubmitted.'];
    if ((int) $oe['encoded_by'] !== (int) $userId && ($_SESSION['role'] ?? '') !== 'admin') {
        return ['ok' => false, 'message' => "Only the original encoder (or an Admin) can revise this bill."];
    }

    $receiptPath = $oe['receipt_file_path'];
    $oldPathToRemove = null;
    if (!empty($newFile) && ($newFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $upload = handleOperatingExpenseReceiptUpload($newFile);
        if (!$upload['ok'])
            return $upload;
        $oldPathToRemove = $receiptPath;
        $receiptPath = $upload['path'];
    }

    $pdo->prepare("
        UPDATE operating_expenses
        SET category_id=?, payee_name=?, description=?, billing_period_start=?, billing_period_end=?,
            due_date=?, amount=?, is_recurring=?, receipt_file_path=?,
            status='pending_verification', submitted_at = NOW(),
            verified_by=NULL, verified_by_name=NULL, verified_at=NULL,
            requires_level2_approval=0, over_budget_flag=0, over_budget_reason=NULL,
            return_reason=NULL, returned_at=NULL
        WHERE id=?
    ")->execute([
                (int) $data['category_id'],
                trim($data['payee_name']),
                trim($data['description']) ?: null,
                $data['billing_period_start'] ?: null,
                $data['billing_period_end'] ?: null,
                $data['due_date'],
                round((float) $data['amount'], 2),
                !empty($data['is_recurring']) ? 1 : 0,
                $receiptPath,
                $id,
            ]);

    if ($oldPathToRemove) {
        @unlink(OE_RECEIPT_UPLOAD_DIR . $oldPathToRemove);
    }

    return ['ok' => true];
}

// Level 2 approval — Admin lang, para sa mga bill na katumbas/lampas
// sa threshold. Dumadaan din dito ang parehong budget-check +
// AP-posting logic sa ibaba (finalizeApprovedOperatingExpense()), kaya
// iisa lang talaga ang landas papuntang "approved" kahit iba ang ruta.
function level2ApproveOperatingExpense($pdo, $id, $userId, $userName, $overrideReason = null)
{
    ensureOperatingExpenseTables($pdo);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT status FROM operating_expenses WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $status = $stmt->fetchColumn();
        if ($status === false) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Bill not found.'];
        }
        if ($status !== 'pending_approval') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This bill is not waiting for Level 2 approval.'];
        }

        $result = finalizeApprovedOperatingExpense($pdo, $id, $userId, $userName, $overrideReason);
        if (!$result['ok']) {
            $pdo->rollBack();
            return $result;
        }
        $pdo->commit();

        $stmt = $pdo->prepare("SELECT * FROM operating_expenses WHERE id = ?");
        $stmt->execute([$id]);
        $oe = $stmt->fetch();

    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        error_log("level2ApproveOperatingExpense error: " . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not approve this bill. Please try again.'];
    }

    // Handoff #3: Level 2 approved -> tell the encoder and Finance that
    // it is now sitting in Accounts Payable. Delivery is best-effort.
    try {
        if ($oe) {
            pushUserNotification(
                $pdo,
                $oe['encoded_by'],
                'oe_approved',
                'Na-Level-2-approve na ang bill mo na ' . ($oe['payee_name'] ?? '') . ' (' . peso($oe['amount']) . ') — nasa Accounts Payable na para bayaran.',
                'operating_expenses.php?oe=' . enc_id($id),
                'operating_expense',
                $id
            );
            pushRoleNotification(
                $pdo,
                ['finance'],
                'oe_ready_for_payment',
                'Handa nang bayaran sa Accounts Payable: ' . ($oe['payee_name'] ?? '') . ' (' . peso($oe['amount']) . ').',
                'accounts_payable.php',
                'operating_expense',
                $id,
                (int) $userId
            );
        }
    } catch (Throwable $e) {
        error_log("level2ApproveOperatingExpense notification error: " . $e->getMessage());
    }
    return ['ok' => true, 'over_budget' => $result['over_budget'] ?? false];
}

// Iisang punto ng "pagiging approved" — tinatawag pareho ng
// verifyOperatingExpense() (kapag mababa sa threshold, walang
// hihintaying Level 2) at ng level2ApproveOperatingExpense() (kapag
// Admin na ang nag-Level-2). Dito nangyayari ang budget check laban
// sa expense_categories.monthly_budget (Section 5), at ang pag-post
// papunta sa Accounts Payable gamit ang parehong recordAccountsPayable()
// na ginagamit ng Purchase Orders. IMPORTANT: hindi ito nagba-begin/
// commit ng sarili niyang transaction — inaasahang nasa loob na ito ng
// transaction ng tumatawag (verifyOperatingExpense / level2ApproveOperatingExpense).
function finalizeApprovedOperatingExpense($pdo, $id, $userId, $userName, $overrideReason = null)
{
    $stmt = $pdo->prepare("SELECT * FROM operating_expenses WHERE id = ? FOR UPDATE");
    $stmt->execute([$id]);
    $oe = $stmt->fetch();
    if (!$oe)
        return ['ok' => false, 'message' => 'Bill not found.'];

    $categoryStmt = $pdo->prepare("SELECT * FROM expense_categories WHERE id = ? FOR UPDATE");
    $categoryStmt->execute([$oe['category_id']]);
    $category = $categoryStmt->fetch() ?: null;
    $overBudget = false;

    if ($category && $category['monthly_budget'] !== null) {
        $actualSoFar = getExpenseCategoryMonthActual($pdo, $oe['category_id'], $oe['due_date'], $oe['id']);
        $projected = $actualSoFar + (float) $oe['amount'];
        if ($projected > (float) $category['monthly_budget'] + 0.01) {
            $overBudget = true;
            if (trim((string) $overrideReason) === '') {
                // Totoong bayarin pa rin ito kahit lumagpas sa budget —
                // hindi ito basta bina-block; pero kailangan ng
                // paliwanag bago ito tuluyang aprubahan (Section 5).
                return [
                    'ok' => false,
                    'needs_override_reason' => true,
                    'message' => 'This would put "' . $category['name'] . '" over its monthly budget of ' . peso($category['monthly_budget'])
                        . ' (already ' . peso($actualSoFar) . ' approved this month). Please provide an override reason to proceed anyway.',
                ];
            }
        }
    }

    $pdo->prepare("
        UPDATE operating_expenses
        SET status='approved', approved_by=?, approved_by_name=?, approved_at=NOW(),
            over_budget_flag=?, over_budget_reason=?
        WHERE id=?
    ")->execute([$userId, $userName, $overBudget ? 1 : 0, $overBudget ? trim($overrideReason) : null, $id]);

    $reference = 'Operating Expense — ' . $oe['payee_name'] . (!empty($category['name']) ? ' (' . $category['name'] . ')' : '');
    if (
        !recordAccountsPayable(
            $pdo,
            'operating_expense',
            $oe['id'],
            null,
            $reference,
            (float) $oe['amount'],
            $userId,
            $oe['payee_name'],
            $oe['due_date'],
            true
        )
    ) {
        return ['ok' => false, 'message' => 'Could not post the approved bill to Accounts Payable. Please try again.'];
    }

    return ['ok' => true, 'over_budget' => $overBudget];
}

// Draft o Returned lang ang puwedeng i-cancel — kapag nasa
// verification/approval na ito, i-return na lang muna (may audit
// trail ang reason), hindi basta i-cancel.
function cancelOperatingExpense($pdo, $id, $userId)
{
    $stmt = $pdo->prepare("SELECT status FROM operating_expenses WHERE id = ?");
    $stmt->execute([$id]);
    $status = $stmt->fetchColumn();
    if ($status === false)
        return ['ok' => false, 'message' => 'Bill not found.'];
    if (!in_array($status, ['draft', 'returned'], true)) {
        return ['ok' => false, 'message' => 'Only a Draft or Returned bill can be cancelled.'];
    }
    $pdo->prepare("UPDATE operating_expenses SET status='cancelled', cancelled_by=?, cancelled_at=NOW() WHERE id=?")
        ->execute([$userId, $id]);
    return ['ok' => true];
}

// ---------- List / detail fetch helpers ----------

function getOperatingExpenses($pdo, $statusFilter = null, $search = '')
{
    ensureOperatingExpenseTables($pdo);
    $sql = "
        SELECT oe.*, ec.name AS category_name, ec.monthly_budget AS category_budget
        FROM operating_expenses oe
        LEFT JOIN expense_categories ec ON ec.id = oe.category_id
        WHERE 1=1
    ";
    $params = [];
    if (!empty($statusFilter) && $statusFilter !== 'all') {
        $sql .= " AND oe.status = ?";
        $params[] = $statusFilter;
    }
    if ($search !== '') {
        $sql .= " AND (oe.payee_name LIKE ? OR oe.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $sql .= " ORDER BY FIELD(oe.status,'pending_approval','pending_verification','draft','returned','verified','approved','cancelled'), oe.due_date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getOperatingExpenseById($pdo, $id)
{
    if (empty($id) || !ctype_digit((string) $id))
        return null;
    $stmt = $pdo->prepare("
        SELECT oe.*, ec.name AS category_name, ec.monthly_budget AS category_budget
        FROM operating_expenses oe
        LEFT JOIN expense_categories ec ON ec.id = oe.category_id
        WHERE oe.id = ?
    ");
    $stmt->execute([(int) $id]);
    return $stmt->fetch() ?: null;
}

function ensureCapitalTransactionsTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS capital_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type ENUM('capital_in','capital_out') NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                payroll_period_start DATE NULL,
                payroll_period_end DATE NULL,
                created_by INT NULL,
                created_by_name VARCHAR(150) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureCapitalTransactionsTable skipped: " . $e->getMessage());
    }
}

// Revenue, Cost of Goods Sold, at Gross Profit ng grocery para sa
// isang date range (o all-time kung null ang $from/$to). Ang COGS ay
// kinukuha mula sa mismong batch(es) na ginamit sa bawat benta (see
// sale_batch_consumption + product_batches.cost_price sa pos.php
// checkout) — kaya tumpak ito base sa aktwal na presyo ng paninda
// noong pumasok, hindi lang tantiya. Yung mga "unbatched" na benta
// (legacy stock bago pa ma-enable ang batch tracking, batch_id NULL)
// ay hiwalay na tinatally bilang "unknown cost" — hindi na ito basta
// hinuhulaan, para hindi tahimik na sumablay ang profit figure.
function getProfitForPeriod($pdo, $from = null, $to = null)
{
    $dateWhere = '';
    $params = [];
    if ($from && $to) {
        $dateWhere = 'AND DATE(s.transaction_date) BETWEEN :f AND :t';
        $params = [':f' => $from, ':t' => $to];
    }

    // Kasama na ang 'partial_refunded' — pero ang na-refund lang na
    // parte (mula sa sale_refunds) ang ikinakaltas, hindi ang buong
    // sale. Kaya kung 1 lang sa maraming binili ang na-refund, ang
    // ibang items sa parehong resibo ay ina-account pa rin.
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(s.total_amount - COALESCE(sr.refunded_amount, 0)), 0)
        FROM sales s
        LEFT JOIN (
            SELECT sale_id, SUM(amount) AS refunded_amount
            FROM sale_refunds
            GROUP BY sale_id
        ) sr ON sr.sale_id = s.id
        WHERE s.status IN ('completed', 'partial_refunded') $dateWhere
    ");
    $stmt->execute($params);
    $revenue = (float) $stmt->fetchColumn();

    // COGS: kinukuha muna ang na-consume na cost per sales_detail line,
    // tapos ikinakaltas ang proportional cost ng dami na na-refund sa
    // linyang iyon (hal. 5 binili, 2 na-refund sa linyang iyon => 2/5
    // ng cost ng linyang iyon lang ang ikinakaltas, hindi ang buong sale).
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(
                CASE WHEN d.consumed_qty > 0
                    THEN d.known_cogs * (d.consumed_qty - COALESCE(lr.refunded_qty, 0)) / d.consumed_qty
                    ELSE 0 END
            ), 0) AS known_cogs,
            COALESCE(SUM(
                CASE WHEN d.consumed_qty > 0
                    THEN d.unknown_qty * (d.consumed_qty - COALESCE(lr.refunded_qty, 0)) / d.consumed_qty
                    ELSE 0 END
            ), 0) AS unknown_cost_qty
        FROM (
            SELECT sbc.sales_detail_id,
                   SUM(CASE WHEN sbc.batch_id IS NOT NULL THEN sbc.quantity * pb.cost_price ELSE 0 END) AS known_cogs,
                   SUM(CASE WHEN sbc.batch_id IS NULL THEN sbc.quantity ELSE 0 END) AS unknown_qty,
                   SUM(sbc.quantity) AS consumed_qty
            FROM sale_batch_consumption sbc
            JOIN sales_details sd ON sd.id = sbc.sales_detail_id
            JOIN sales s ON s.id = sd.sale_id
            LEFT JOIN product_batches pb ON pb.id = sbc.batch_id
            WHERE s.status IN ('completed', 'partial_refunded') $dateWhere
            GROUP BY sbc.sales_detail_id
        ) d
        LEFT JOIN (
            SELECT sales_detail_id, SUM(quantity) AS refunded_qty
            FROM sale_refunds
            GROUP BY sales_detail_id
        ) lr ON lr.sales_detail_id = d.sales_detail_id
    ");
    $stmt->execute($params);
    $row = $stmt->fetch();
    $cogs = (float) ($row['known_cogs'] ?? 0);
    $unknownCostQty = (int) round($row['unknown_cost_qty'] ?? 0);

    return [
        'revenue' => $revenue,
        'cogs' => $cogs,
        'gross_profit' => $revenue - $cogs,
        'unknown_cost_qty' => $unknownCostQty,
    ];
}

// Builds the exact stock_logs 'reason' strings written by fulfilled
// Inventory Requests that have a supplier attached (see the 'fulfill'
// action in inventory_requests.php) — i.e. the restocks that are now
// Accounts Payable liabilities, not immediate cash. Used to exclude
// exactly those rows from the direct-cash stock_logs total below,
// without any fragile text-pattern guessing.
function __apLinkedInventoryRequestReasons($pdo)
{
    $reasons = [];
    $rows = $pdo->query("SELECT request_id FROM inventory_requests WHERE supplier_id IS NOT NULL AND status = 'fulfilled'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $rid) {
        $reasons[] = 'Inventory Request #' . $rid . ' fulfillment';
    }
    return $reasons;
}

// All-time CASH-BASIS spend on restocking (used for Business Cash
// Position / Balance Sheet 'cash' — actual pesos that have left the
// business, not merely committed or owed). Standard accrual, confirmed
// Aug 2026: an Accounts Payable liability is recorded the moment goods
// are actually received/fulfilled (see recordAccountsPayable() /
// recordPurchaseOrderReceipt()), but it only becomes a CASH outflow
// once a Supplier Payment is actually made against it. Three sources,
// added together with no overlap:
//   1. Direct-cash stock_logs 'restock' rows — the plain Restock action
//      on inventory.php (never AP-linked), PLUS Inventory Requests
//      fulfilled with NO supplier attached (same immediate-cash
//      behavior this app always had). Purchase Order receipts (always
//      AP-linked — a PO always has a supplier) and supplier-linked
//      Inventory Request fulfillments are excluded here; their cash
//      impact is covered by #3 instead, only once actually paid.
//   2. Inventory Requests marked "Fulfilled (Manual)" for a brand-new
//      item (no product_id, so no stock_logs row at all) with NO
//      supplier attached — same immediate-cash bucket as before AP
//      tracking existed.
//   3. Accounts Payable amounts actually PAID so far for Purchase Orders
//      and supplier-linked Inventory Requests — operating bills are
//      accounted separately in getTotalOperatingExpensePaid().
function getTotalRestockCost($pdo)
{
    $apLinkedReasons = __apLinkedInventoryRequestReasons($pdo);

    $sql = "SELECT COALESCE(SUM(total_cost), 0) FROM stock_logs WHERE change_type = 'restock' AND reason NOT LIKE '%(PO #%'";
    $params = [];
    if (!empty($apLinkedReasons)) {
        $placeholders = implode(',', array_fill(0, count($apLinkedReasons), '?'));
        $sql .= " AND reason NOT IN ($placeholders)";
        $params = $apLinkedReasons;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $directCashStockLogs = (float) $stmt->fetchColumn();

    $manualFulfilledNoSupplier = (float) $pdo->query("
        SELECT COALESCE(SUM(estimated_cost), 0) FROM inventory_requests
        WHERE status = 'fulfilled' AND product_id IS NULL AND supplier_id IS NULL
    ")->fetchColumn();

    $apPaid = 0.0;
    try {
        $apPaid = (float) $pdo->query("
            SELECT COALESCE(SUM(amount_paid), 0)
            FROM accounts_payable
            WHERE source_type IN ('purchase_order', 'inventory_request')
        ")->fetchColumn();
    } catch (PDOException $e) {
        error_log("getTotalRestockCost (accounts_payable) skipped: " . $e->getMessage());
    }

    return $directCashStockLogs + $manualFulfilledNoSupplier + $apPaid;
}

function getTotalOperatingExpensePaid($pdo)
{
    try {
        return (float) $pdo->query("
            SELECT COALESCE(SUM(amount_paid), 0)
            FROM accounts_payable
            WHERE source_type = 'operating_expense'
        ")->fetchColumn();
    } catch (PDOException $e) {
        error_log("getTotalOperatingExpensePaid skipped: " . $e->getMessage());
        return 0.0;
    }
}

// Ang running "Business Cash Position" (all-time, hindi date-ranged)
// — ginagamit ito ng HRMS Payroll para malaman kung sapat pa ang
// pera ng negosyo (kinita mula sa POS + capital na idinagdag ni
// Owner, minus payroll na na-release na) para sahurin ang susunod na
// batch ng payroll:
//
//   Available Cash = All-time Sales Revenue
//                     + Capital In (Owner cash idinagdag)
//                     − Capital Out (Owner withdrawal)
//   − Cash restocks / supplier payments already paid
//   − Operating bills already paid
//   − Payroll na na-release na (mula HRMS)
//
// Kapag negative ito (o mas mababa sa kailangan ng susunod na
// payroll), ibig sabihin kulang ang kinita ng grocery at kailangan
// ng "Add Capital" mula kay Admin/Owner.
function getBusinessCashPosition($pdo, $hrmsPdo = null)
{
    $profit = getProfitForPeriod($pdo, null, null);

    $stmt = $pdo->query("
        SELECT
            COALESCE(SUM(CASE WHEN type = 'capital_in'  THEN amount ELSE 0 END), 0) AS capital_in,
            COALESCE(SUM(CASE WHEN type = 'capital_out' THEN amount ELSE 0 END), 0) AS capital_out
        FROM capital_transactions
    ");
    $capRow = $stmt->fetch();
    $capitalIn = (float) ($capRow['capital_in'] ?? 0);
    $capitalOut = (float) ($capRow['capital_out'] ?? 0);

    // See getTotalRestockCost() above — cash-basis only: immediate-cash
    // restocks (no supplier attached) count in full at receipt, but a
    // restock owed to a supplier via Accounts Payable only reduces
    // available cash once it's actually paid off (recordSupplierPayment()),
    // not the moment the goods arrive. Operating bills also reduce cash
    // only when paid, not when approved/accrued.
    $totalRestockCost = getTotalRestockCost($pdo);
    $operatingExpensePaid = getTotalOperatingExpensePaid($pdo);

    $payrollReleased = 0.0;
    $payrollDataAvailable = $hrmsPdo !== null;
    if ($hrmsPdo) {
        try {
            $payrollReleased = (float) $hrmsPdo->query("
                SELECT COALESCE(SUM(net_pay), 0) FROM payroll_records WHERE approval_status = 'approved' AND payment_status = 'paid'
            ")->fetchColumn();
        } catch (PDOException $e) {
            error_log("getBusinessCashPosition (payroll_released) skipped: " . $e->getMessage());
            $payrollDataAvailable = false;
        }
    }

    $availableCash = round($profit['revenue'] - $totalRestockCost - $operatingExpensePaid + $capitalIn - $capitalOut - $payrollReleased, 2);
    return [
        'sales_revenue' => $profit['revenue'],
        'gross_profit' => $profit['gross_profit'],
        'unknown_cost_qty' => $profit['unknown_cost_qty'],
        'capital_in' => $capitalIn,
        'capital_out' => $capitalOut,
        'restock_cost' => $totalRestockCost,
        'operating_expenses_paid' => $operatingExpensePaid,
        'payroll_released' => $payrollReleased,
        'payroll_data_available' => $payrollDataAvailable,
        'available_cash' => $availableCash,
        'cash_shortfall' => round(max(0, -$availableCash), 2),
    ];
}

// I-record ang "Add Capital" (Owner nagdagdag ng sariling pera sa
// negosyo — kadalasan dahil kulang ang kinita para sa sahod) o
// "Owner Withdrawal" (Owner kumuha ng pera/profit mula sa negosyo).
// Ginagamit mula sa POS Reports (Admin) at mula sa HRMS Payroll
// (Admin din) — pareho silang tumutukoy dito sa parehong table sa
// grocery_pos database, kaya laging magkatugma ang makikita sa
// dalawang side.
function recordCapitalTransaction($pdo, $type, $amount, $reason, $userId = null, $userName = null, $periodStart = null, $periodEnd = null)
{
    if (!in_array($type, ['capital_in', 'capital_out'], true) || $amount <= 0) {
        return false;
    }
    ensureCapitalTransactionsTable($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO capital_transactions (type, amount, reason, payroll_period_start, payroll_period_end, created_by, created_by_name)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    return $stmt->execute([$type, $amount, $reason, $periodStart ?: null, $periodEnd ?: null, $userId, $userName]);
}

// =====================================================
// FINANCIAL STATEMENTS — Income Statement, Balance Sheet,
// Cash Flow Statement (Financial Reporting, Finance module).
//
// Accounting model used across all three statements (kept
// consistent with the existing Business Cash Position logic
// in getBusinessCashPosition() above):
//   - Sales revenue/COGS are recognized the same way as
//     getProfitForPeriod() (accrual-ish, based on actual batch
//     cost consumed).
//   - Restock cost recognized as an actual CASH outflow only when
//     it's truly cash — no supplier attached — or, for a restock owed
//     to a supplier, only once a Supplier Payment is actually made
//     against its Accounts Payable balance (see getTotalRestockCost()).
//     The liability itself (accounts_payable) is recorded at actual
//     receipt/fulfillment — standard accrual, never at PO/Request
//     approval — confirmed Aug 2026.
//   - Payroll is only counted as a formal expense/cash-out via
//     HRMS payroll_records (gross_pay for the Income Statement,
//     actual paid net_pay for the Cash Flow Statement). No other
//     operating expense categories (rent, utilities, etc.) are
//     tracked anywhere in the system yet, so they are absent from
//     these statements — noted explicitly on the page itself
//     rather than silently omitted.
//   - Accounts Payable (supplier credit for received/fulfilled
//     Purchase Orders and Inventory Requests) is tracked and appears
//     as a Balance Sheet liability (getBalanceSheet()'s
//     total_liabilities). There is still no Accounts Receivable —
//     the grocery never sells on credit, only buys on credit from
//     suppliers.
// =====================================================

// Income Statement for a date range (pass null/null for all-time,
// same convention as getProfitForPeriod()).
function getIncomeStatement($pdo, $hrmsPdo, $from = null, $to = null)
{
    $profit = getProfitForPeriod($pdo, $from, $to);

    $payrollExpense = 0.0;
    $payrollAvailable = (bool) $hrmsPdo;
    if ($hrmsPdo) {
        try {
            if ($from && $to) {
                $stmt = $hrmsPdo->prepare("
                    SELECT COALESCE(SUM(gross_pay), 0) FROM payroll_records
                    WHERE approval_status IN ('n/a','approved') AND pay_period_end BETWEEN ? AND ?
                ");
                $stmt->execute([$from, $to]);
            } else {
                $stmt = $hrmsPdo->query("
                    SELECT COALESCE(SUM(gross_pay), 0) FROM payroll_records
                    WHERE approval_status IN ('n/a','approved')
                ");
            }
            $payrollExpense = (float) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $payrollAvailable = false;
            error_log("getIncomeStatement (payroll) skipped: " . $e->getMessage());
        }
    }

    $operatingExpense = 0.0;
    $operatingExpenseAvailable = true;
    try {
        ensureOperatingExpenseTables($pdo);
        $sql = "
            SELECT COALESCE(SUM(amount), 0) FROM operating_expenses
            WHERE status = 'approved'
        ";
        $params = [];
        if ($from && $to) {
            $sql .= " AND DATE(COALESCE(approved_at, due_date)) BETWEEN ? AND ?";
            $params = [$from, $to];
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $operatingExpense = (float) $stmt->fetchColumn();
    } catch (PDOException $e) {
        $operatingExpenseAvailable = false;
        error_log("getIncomeStatement (operating expenses) skipped: " . $e->getMessage());
    }

    $operatingIncome = $profit['gross_profit'] - $payrollExpense - $operatingExpense;

    return [
        'revenue' => $profit['revenue'],
        'cogs' => $profit['cogs'],
        'gross_profit' => $profit['gross_profit'],
        'unknown_cost_qty' => $profit['unknown_cost_qty'],
        'payroll_expense' => $payrollExpense,
        'payroll_available' => $payrollAvailable,
        'operating_expense' => $operatingExpense,
        'operating_expense_available' => $operatingExpenseAvailable,
        'operating_income' => $operatingIncome,
        // No interest/tax/other non-operating items tracked, so
        // Net Income = Operating Income for now.
        'net_income' => $operatingIncome,
    ];
}

// Balance Sheet — always "as of today", since the underlying cash
// (getBusinessCashPosition) and inventory (product_batches) figures
// this reuses are only ever tracked as running/current totals, not
// filterable by an arbitrary past date, in the current schema.
function getBalanceSheet($pdo, $hrmsPdo)
{
    $cash_position = getBusinessCashPosition($pdo, $hrmsPdo);
    $cash = $cash_position['available_cash'];

    $inventory_value = (float) $pdo->query("
        SELECT COALESCE(SUM(quantity_remaining * cost_price), 0) FROM product_batches
    ")->fetchColumn();

    $total_assets = $cash + $inventory_value;

    // Accounts Payable — money owed to suppliers for goods already
    // received/fulfilled but not yet paid for (accounts_payable table,
    // status 'unpaid' or 'partial'). Recorded only at actual receipt,
    // never at approval — standard accrual, confirmed Aug 2026. This is
    // exactly the Dr Inventory / Cr Accounts Payable side of a
    // credit-received delivery: inventory_value above already went up
    // by the full received cost even though cash hasn't moved yet, so
    // counting the same amount here as a liability is what keeps the
    // statement balancing instead of silently inflating equity.
    $total_liabilities = 0.0;
    try {
        $total_liabilities = (float) $pdo->query("
            SELECT COALESCE(SUM(amount_owed - amount_paid), 0) FROM accounts_payable WHERE status IN ('unpaid', 'partial')
        ")->fetchColumn();
    } catch (PDOException $e) {
        error_log("getBalanceSheet (accounts_payable) skipped: " . $e->getMessage());
    }

    $capital_net = $cash_position['capital_in'] - $cash_position['capital_out'];
    $income_alltime = getIncomeStatement($pdo, $hrmsPdo, null, null);
    $retained_earnings = $income_alltime['net_income'];
    $total_equity = $capital_net + $retained_earnings;

    // Reconciling difference: Assets should equal Liabilities + Equity
    // by definition. Any remaining gap here comes from timing choices
    // already present elsewhere in the app — e.g. inventory is valued
    // at its current quantity_remaining rather than reconstructed as of
    // a past date. Shown openly instead of hidden so the statement
    // still balances on the page.
    $reconciling = $total_assets - ($total_liabilities + $total_equity);

    return [
        'as_of' => date('Y-m-d'),
        'cash' => $cash,
        'inventory_value' => $inventory_value,
        'total_assets' => $total_assets,
        'total_liabilities' => $total_liabilities,
        'capital_net' => $capital_net,
        'retained_earnings' => $retained_earnings,
        'total_equity' => $total_equity,
        'reconciling' => $reconciling,
    ];
}

// Cash Flow Statement (direct method) for a date range.
function getCashFlowStatement($pdo, $hrmsPdo, $from, $to)
{
    // Operating: cash sales revenue, minus cash actually paid out for
    // restocking during the period, minus payroll actually disbursed in
    // the period (payment_status = 'paid', dated by paid_at — the real
    // disbursement moment, not just when it was approved).
    //
    // cash_paid_restock is date-scoped ACTUAL cash out, not committed/
    // received cost — same cash-basis split as getTotalRestockCost()
    // above (see that function's comment for the full rationale), just
    // bounded to [$from, $to] instead of all-time:
    //   1. Direct-cash stock_logs 'restock' rows (no PO, and excluding
    //      any reason belonging to a supplier-linked, hence AP-linked,
    //      Inventory Request fulfillment) dated within the range.
    //   2. Inventory Requests marked "Fulfilled (Manual)" for a
    //      brand-new item (no product_id, so no stock_logs row) with NO
    //      supplier attached, decided within the range.
    //   3. Supplier Payments actually made within the range — pulled
    //      straight from supplier_payments.created_at, NOT
    //      accounts_payable.amount_paid (which is a cumulative, all-time
    //      running balance and would double-count across periods). This
    //      is what makes an AP-linked Purchase Order receipt or
    //      supplier-linked Inventory Request fulfillment show up here at
    //      all — only in the period the cash actually left, cash-basis.
    $profit = getProfitForPeriod($pdo, $from, $to);
    $cashFromSales = $profit['revenue'];

    $apLinkedReasons = __apLinkedInventoryRequestReasons($pdo);

    $sql = "
        SELECT COALESCE(SUM(total_cost), 0) FROM stock_logs
        WHERE change_type = 'restock' AND reason NOT LIKE '%(PO #%' AND DATE(date_created) BETWEEN ? AND ?
    ";
    $params = [$from, $to];
    if (!empty($apLinkedReasons)) {
        $placeholders = implode(',', array_fill(0, count($apLinkedReasons), '?'));
        $sql .= " AND reason NOT IN ($placeholders)";
        $params = array_merge($params, $apLinkedReasons);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $directCashStockLogs = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(estimated_cost), 0) FROM inventory_requests
        WHERE status = 'fulfilled' AND product_id IS NULL AND supplier_id IS NULL
        AND DATE(decided_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$from, $to]);
    $manualFulfilledNoSupplier = (float) $stmt->fetchColumn();

    $supplierPaidProcurement = 0.0;
    $operatingExpensePaid = 0.0;
    $supplierPaymentsAvailable = true;
    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN ap.source_type IN ('purchase_order', 'inventory_request') THEN sp.amount ELSE 0 END), 0) AS procurement_paid,
                COALESCE(SUM(CASE WHEN ap.source_type = 'operating_expense' THEN sp.amount ELSE 0 END), 0) AS operating_expenses_paid
            FROM supplier_payments sp
            JOIN accounts_payable ap ON ap.id = sp.payable_id
            WHERE DATE(sp.created_at) BETWEEN ? AND ?
        ");
        $stmt->execute([$from, $to]);
        $paymentTotals = $stmt->fetch();
        $supplierPaidProcurement = (float) ($paymentTotals['procurement_paid'] ?? 0);
        $operatingExpensePaid = (float) ($paymentTotals['operating_expenses_paid'] ?? 0);
    } catch (PDOException $e) {
        $supplierPaymentsAvailable = false;
        error_log("getCashFlowStatement (supplier_payments) skipped: " . $e->getMessage());
    }

    $directCashRestock = $directCashStockLogs + $manualFulfilledNoSupplier;
    $cashPaidRestock = $directCashRestock + $supplierPaidProcurement;

    $cashPaidPayroll = 0.0;
    $payrollCashAvailable = (bool) $hrmsPdo;
    if ($hrmsPdo) {
        try {
            $stmt = $hrmsPdo->prepare("
                SELECT COALESCE(SUM(net_pay), 0) FROM payroll_records
                WHERE payment_status = 'paid' AND DATE(paid_at) BETWEEN ? AND ?
            ");
            $stmt->execute([$from, $to]);
            $cashPaidPayroll = (float) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $payrollCashAvailable = false;
            error_log("getCashFlowStatement (payroll) skipped: " . $e->getMessage());
        }
    }

    $netOperating = $cashFromSales - $cashPaidRestock - $cashPaidPayroll - $operatingExpensePaid;

    // Investing: nothing tracked yet (no fixed-asset purchase records
    // anywhere in the system) — kept at 0 rather than left off the
    // statement, so the gap is visible rather than silently skipped.
    $netInvesting = 0.0;

    // Financing: Owner capital contributions / withdrawals for the period.
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type = 'capital_in'  THEN amount ELSE 0 END), 0) AS capital_in,
            COALESCE(SUM(CASE WHEN type = 'capital_out' THEN amount ELSE 0 END), 0) AS capital_out
        FROM capital_transactions
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$from, $to]);
    $capRow = $stmt->fetch();
    $capitalInPeriod = (float) ($capRow['capital_in'] ?? 0);
    $capitalOutPeriod = (float) ($capRow['capital_out'] ?? 0);
    $netFinancing = $capitalInPeriod - $capitalOutPeriod;

    $netChangeInCash = $netOperating + $netInvesting + $netFinancing;

    return [
        'cash_from_sales' => $cashFromSales,
        'cash_paid_direct_restock' => $directCashRestock,
        'cash_paid_supplier_procurement' => $supplierPaidProcurement,
        'cash_paid_restock' => $cashPaidRestock,
        'cash_paid_payroll' => $cashPaidPayroll,
        'cash_paid_operating_expenses' => $operatingExpensePaid,
        'supplier_payments_available' => $supplierPaymentsAvailable,
        'payroll_cash_available' => $payrollCashAvailable,
        'net_operating' => $netOperating,
        'net_investing' => $netInvesting,
        'capital_in' => $capitalInPeriod,
        'capital_out' => $capitalOutPeriod,
        'net_financing' => $netFinancing,
        'net_change_in_cash' => $netChangeInCash,
        // For context only — the app's running all-time cash figure,
        // not a strict beginning+change=ending proof (see getBalanceSheet
        // note on why past-dated cash isn't reconstructable yet).
        'current_cash_position' => getBusinessCashPosition($pdo, $hrmsPdo)['available_cash'],
    ];
}

// =====================================================
// CASH RUNWAY / PROJECTION — forward-looking extension of the Cash
// Flow Statement above. Existing Cash Position + Cash Flow Statement
// are both backward-looking (current balance, or what happened in a
// past range); this answers "kung magpapatuloy ang takbo ngayon,
// gaano katagal pa aabot ang pera namin, at kailan posibleng ma-ubos?"
//
// Method: trailing average DAILY Net Cash from Operating Activities
// (same figure as getCashFlowStatement()['net_operating'] — Cash from
// Sales minus Cash Paid for Restocking minus Cash Paid for Payroll)
// over a lookback window ending today, projected forward in a
// straight line from the current Business Cash Position.
//
// Deliberately EXCLUDES Financing activity (Capital In / Owner
// Withdrawal) from the burn-rate calculation, even though those do
// move actual cash — otherwise a grocery that's actually losing money
// every month, but being kept afloat by repeated Owner "Add Capital"
// injections, would look artificially "safe" here. Financing is still
// shown separately on the page for context, and IS included in
// current_cash (via getBusinessCashPosition) since that's the real
// starting point for the projection.
//
// This is a straight-line projection off recent history, not a
// statistical forecast — it will not see a big one-off restock or a
// seasonal swing coming. Shown explicitly as an assumption on the
// page itself rather than presented as a guarantee.
function getCashRunway($pdo, $hrmsPdo, $lookbackDays = 30, $projectionWeeks = 12)
{
    $lookbackDays = in_array((int) $lookbackDays, [30, 60, 90], true) ? (int) $lookbackDays : 30;

    $today = date('Y-m-d');
    $lookbackFrom = date('Y-m-d', strtotime("-" . ($lookbackDays - 1) . " days"));

    $cf = getCashFlowStatement($pdo, $hrmsPdo, $lookbackFrom, $today);

    $currentCash = getBusinessCashPosition($pdo, $hrmsPdo)['available_cash'];

    $netOperatingInWindow = $cf['net_operating'];
    $dailyBurn = $netOperatingInWindow / $lookbackDays; // negative = losing cash per day, positive = gaining

    $isBurning = $dailyBurn < 0;
    $runwayDays = null;
    $projectedZeroDate = null;
    if ($isBurning) {
        if ($currentCash <= 0) {
            $runwayDays = 0;
            $projectedZeroDate = $today;
        } else {
            $runwayDays = (int) floor($currentCash / abs($dailyBurn));
            $projectedZeroDate = date('Y-m-d', strtotime("+{$runwayDays} days"));
        }
    }

    // Week-by-week straight-line projection of cash position, starting
    // from today (week 0) at the current daily burn/gain rate. Cash is
    // floored at 0 for display once it would have theoretically gone
    // negative — the business would stop being able to spend before that.
    $projection = [];
    $runningCash = $currentCash;
    $hitZeroAlready = $currentCash <= 0;
    for ($w = 0; $w <= $projectionWeeks; $w++) {
        $displayCash = $hitZeroAlready ? 0.0 : max($runningCash, 0.0);
        if ($displayCash <= 0) {
            $hitZeroAlready = true;
        }
        $projection[] = [
            'week' => $w,
            'date' => date('Y-m-d', strtotime("+" . ($w * 7) . " days")),
            'cash' => $displayCash,
        ];
        $runningCash += $dailyBurn * 7;
    }

    return [
        'lookback_days' => $lookbackDays,
        'lookback_from' => $lookbackFrom,
        'lookback_to' => $today,
        'current_cash' => $currentCash,
        'net_operating_window' => $netOperatingInWindow,
        'daily_burn' => $dailyBurn,
        'is_burning' => $isBurning,
        'runway_days' => $runwayDays,
        'projected_zero_date' => $projectedZeroDate,
        'projection' => $projection,
        // For context on the page only — not part of the burn-rate math.
        'capital_in_window' => $cf['capital_in'],
        'capital_out_window' => $cf['capital_out'],
    ];
}

// =====================================================
// FINANCIAL ANALYSIS — margin %, month-over-month trend, and expense
// breakdown, built entirely on top of getIncomeStatement() /
// getCashFlowStatement() above so the numbers always agree with
// Financial Statements instead of re-deriving anything new.
// =====================================================

// Gross Margin % and Net Margin % for an already-computed Income
// Statement array (see getIncomeStatement()). Revenue = 0 is treated
// as "no margin yet" (0.0) instead of a divide-by-zero.
function computeMarginPct($income)
{
    $revenue = (float) ($income['revenue'] ?? 0);
    if ($revenue <= 0) {
        return ['gross_margin_pct' => 0.0, 'net_margin_pct' => 0.0];
    }
    return [
        'gross_margin_pct' => ($income['gross_profit'] / $revenue) * 100,
        'net_margin_pct' => ($income['net_income'] / $revenue) * 100,
    ];
}

function getComparableFinancialPeriod($from, $to)
{
    $periodStart = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $from);
    $periodEnd = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $to);
    if (!$periodStart || !$periodEnd || $periodEnd < $periodStart) {
        throw new InvalidArgumentException('A valid financial date range is required.');
    }

    if ($periodStart->format('d') === '01') {
        $previousStart = $periodStart->modify('-1 month');
        $isFullMonth = $periodEnd->format('Y-m-d') === $periodStart->format('Y-m-t');
        if ($isFullMonth) {
            $previousEnd = $previousStart->modify('last day of this month');
        } else {
            $elapsedDays = (int) $periodStart->diff($periodEnd)->days + 1;
            $previousDays = min($elapsedDays, (int) $previousStart->format('t'));
            $previousEnd = $previousStart->modify('+' . ($previousDays - 1) . ' days');
        }
    } else {
        $periodDays = (int) $periodStart->diff($periodEnd)->days + 1;
        $previousEnd = $periodStart->modify('-1 day');
        $previousStart = $previousEnd->modify('-' . ($periodDays - 1) . ' days');
    }

    return [
        'from' => $previousStart->format('Y-m-d'),
        'to' => $previousEnd->format('Y-m-d'),
    ];
}

// Supplier inventory purchases become reportable when received and an
// Accounts Payable record is created. They remain inventory assets until
// sold, so this figure must not be added to income-statement expenses.
function getSupplierPurchasesReceived($pdo, $from, $to)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount_owed), 0)
            FROM accounts_payable
            WHERE source_type IN ('purchase_order', 'inventory_request')
              AND DATE(created_at) BETWEEN ? AND ?
        ");
        $stmt->execute([$from, $to]);
        return ['amount' => (float) $stmt->fetchColumn(), 'available' => true];
    } catch (PDOException $e) {
        error_log('getSupplierPurchasesReceived skipped: ' . $e->getMessage());
        return ['amount' => 0.0, 'available' => false];
    }
}

// Month-by-month trend (oldest → newest) of Revenue / COGS / Gross
// Profit / Payroll Expense / Net Income / margins, for the trailing
// $months calendar months (including the current, in-progress month).
// Same accrual model as getIncomeStatement() — just called once per
// month instead of once for a single range.
function getMonthlyFinancialTrend($pdo, $hrmsPdo, $months = 6)
{
    $months = in_array((int) $months, [3, 6, 12, 24], true) ? (int) $months : 6;

    $trend = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $monthStart = date('Y-m-01', strtotime("-{$i} months"));
        $monthEnd = date('Y-m-t', strtotime("-{$i} months"));
        // Don't project past today for the current, in-progress month.
        if ($monthEnd > date('Y-m-d')) {
            $monthEnd = date('Y-m-d');
        }

        $income = getIncomeStatement($pdo, $hrmsPdo, $monthStart, $monthEnd);
        $margins = computeMarginPct($income);
        $supplierPurchases = getSupplierPurchasesReceived($pdo, $monthStart, $monthEnd);

        $trend[] = array_merge([
            'label' => date('M Y', strtotime($monthStart)),
            'month_start' => $monthStart,
            'month_end' => $monthEnd,
            'supplier_purchases_received' => $supplierPurchases['amount'],
            'supplier_purchases_available' => $supplierPurchases['available'],
        ], $income, $margins);
    }

    return $trend;
}

// Expense breakdown for a date range — the accrual categories used by
// the Income Statement, with their share of Revenue and Total Expenses.
function getProcurementFinancialSummary($pdo, $hrmsPdo, $from, $to)
{
    $income = getIncomeStatement($pdo, $hrmsPdo, $from, $to);
    $cashFlow = getCashFlowStatement($pdo, $hrmsPdo, $from, $to);
    return composeProcurementFinancialSummary($income, $cashFlow);
}

function getExpenseBreakdown($pdo, $hrmsPdo, $from, $to)
{
    $income = getIncomeStatement($pdo, $hrmsPdo, $from, $to);
    $revenue = (float) $income['revenue'];
    $cogs = (float) $income['cogs'];
    $payroll = (float) $income['payroll_expense'];
    $operatingExpenses = (float) ($income['operating_expense'] ?? 0);
    $total = $cogs + $payroll + $operatingExpenses;
    $cashFlow = getCashFlowStatement($pdo, $hrmsPdo, $from, $to);

    $pctOfRevenue = function ($amount) use ($revenue) {
        return $revenue > 0 ? ($amount / $revenue) * 100 : 0.0;
    };
    $pctOfTotal = function ($amount) use ($total) {
        return $total > 0 ? ($amount / $total) * 100 : 0.0;
    };

    return [
        'revenue' => $revenue,
        'total_expenses' => $total,
        'categories' => [
            [
                'label' => 'Cost of Goods Sold (COGS)',
                'amount' => $cogs,
                'pct_of_revenue' => $pctOfRevenue($cogs),
                'pct_of_total' => $pctOfTotal($cogs),
            ],
            [
                'label' => 'Payroll Expense',
                'amount' => $payroll,
                'pct_of_revenue' => $pctOfRevenue($payroll),
                'pct_of_total' => $pctOfTotal($payroll),
            ],
            [
                'label' => 'Approved Operating Bills',
                'amount' => $operatingExpenses,
                'pct_of_revenue' => $pctOfRevenue($operatingExpenses),
                'pct_of_total' => $pctOfTotal($operatingExpenses),
            ],
        ],
        // For context alongside the accrual breakdown above — actual
        // CASH that left the business in the same range, broken down
        // the same way the Cash Flow Statement already does (inventory
        // payments, payroll disbursements, and paid operating bills). Not
        // merged into 'categories' above since mixing accrual and
        // cash-basis figures in one pie would silently misstate both.
        'cash_outflows' => [
            ['label' => 'Inventory Purchases / Supplier Payments', 'amount' => (float) $cashFlow['cash_paid_restock']],
            ['label' => 'Payroll Paid (Net Pay)', 'amount' => (float) $cashFlow['cash_paid_payroll']],
            ['label' => 'Cash Paid — Operating Bills', 'amount' => (float) ($cashFlow['cash_paid_operating_expenses'] ?? 0)],
        ],
        'cash_outflows_available' => !empty($cashFlow['supplier_payments_available']) && !empty($cashFlow['payroll_cash_available']),
    ];
}

// Per-employee payroll breakdown for a date range — the detail behind
// the single "Payroll Expense" line on the Income Statement / Expense
// Breakdown above. Uses the EXACT SAME filter as
// getIncomeStatement()'s payroll query (approval_status IN
// ('n/a','approved'), pay_period_end BETWEEN $from AND $to) so the
// sum of gross_pay here always foots to the same Payroll Expense
// figure shown elsewhere — no separate/competing definition of
// "payroll for the period".
function getPayrollBreakdown($pdo, $hrmsPdo, $from, $to)
{
    $rows = [];
    if (!$hrmsPdo) {
        return ['rows' => $rows, 'total_gross' => 0.0, 'total_net' => 0.0, 'total_deductions' => 0.0, 'by_department' => [], 'cash_paid_rows' => [], 'cash_paid_by_department' => [], 'cash_paid_total' => 0.0, 'cash_paid_available' => false, 'available' => false];
    }

    try {
        $stmt = $hrmsPdo->prepare("
            SELECT
                e.employee_id, e.employee_code, e.first_name, e.last_name,
                e.department, e.position,
                COALESCE(NULLIF(TRIM(CONCAT(e.first_name, ' ', e.last_name)), ''), CONCAT('Employee #', pr.employee_id)) AS employee_name,
                pr.payroll_id, pr.pay_period_start, pr.pay_period_end,
                pr.basic_pay, pr.overtime_pay, pr.allowances, pr.gross_pay,
                (pr.sss_deduction + pr.philhealth_deduction + pr.pagibig_deduction + pr.tax_deduction + pr.other_deductions) AS total_deductions,
                pr.net_pay, pr.payment_status, pr.paid_at
            FROM payroll_records pr
            LEFT JOIN employees e ON e.employee_id = pr.employee_id
            WHERE pr.approval_status IN ('n/a', 'approved')
              AND pr.pay_period_end BETWEEN ? AND ?
            ORDER BY e.last_name, e.first_name, pr.pay_period_start
        ");
        $stmt->execute([$from, $to]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getPayrollBreakdown skipped: " . $e->getMessage());
        return ['rows' => [], 'total_gross' => 0.0, 'total_net' => 0.0, 'total_deductions' => 0.0, 'by_department' => [], 'cash_paid_rows' => [], 'cash_paid_by_department' => [], 'cash_paid_total' => 0.0, 'cash_paid_available' => false, 'available' => false];
    }

    $totalGross = 0.0;
    $totalNet = 0.0;
    $totalDeductions = 0.0;
    $byDepartment = []; // dept label => gross_pay sum

    foreach ($rows as $r) {
        $totalGross += (float) $r['gross_pay'];
        $totalNet += (float) $r['net_pay'];
        $totalDeductions += (float) $r['total_deductions'];
        $dept = trim((string) $r['department']) !== '' ? $r['department'] : 'Unassigned';
        $byDepartment[$dept] = ($byDepartment[$dept] ?? 0.0) + (float) $r['gross_pay'];
    }
    arsort($byDepartment);

    $cashPaidByDepartment = [];
    $cashPaidTotal = 0.0;
    $cashPaidRows = [];
    $cashPaidAvailable = true;
    try {
        $cashStmt = $hrmsPdo->prepare("
            SELECT
                pr.payroll_id, pr.employee_id, pr.pay_period_start, pr.pay_period_end,
                pr.gross_pay, pr.net_pay, pr.paid_at,
                (pr.sss_deduction + pr.philhealth_deduction + pr.pagibig_deduction + pr.tax_deduction + pr.other_deductions) AS total_deductions,
                COALESCE(NULLIF(TRIM(CONCAT(e.first_name, ' ', e.last_name)), ''), CONCAT('Employee #', pr.employee_id)) AS employee_name,
                COALESCE(NULLIF(TRIM(e.department), ''), 'Unassigned') AS department,
                COALESCE(NULLIF(TRIM(e.position), ''), '—') AS position
            FROM payroll_records pr
            LEFT JOIN employees e ON e.employee_id = pr.employee_id
            WHERE pr.payment_status = 'paid' AND pr.net_pay > 0 AND DATE(pr.paid_at) BETWEEN ? AND ?
            ORDER BY e.department, e.last_name, e.first_name, pr.paid_at DESC
        ");
        $cashStmt->execute([$from, $to]);
        $cashPaidRows = $cashStmt->fetchAll();
        foreach ($cashPaidRows as $row) {
            $netPaid = (float) $row['net_pay'];
            $cashPaidTotal += $netPaid;
            $cashPaidByDepartment[$row['department']] = ($cashPaidByDepartment[$row['department']] ?? 0.0) + $netPaid;
        }
        arsort($cashPaidByDepartment);
    } catch (PDOException $e) {
        $cashPaidAvailable = false;
        error_log("getPayrollBreakdown (cash paid by department) skipped: " . $e->getMessage());
    }

    return [
        'rows' => $rows,
        'total_gross' => $totalGross,
        'total_net' => $totalNet,
        'total_deductions' => $totalDeductions,
        'by_department' => $byDepartment,
        'cash_paid_rows' => $cashPaidRows,
        'cash_paid_by_department' => $cashPaidByDepartment,
        'cash_paid_total' => $cashPaidTotal,
        'cash_paid_available' => $cashPaidAvailable,
        'available' => true,
    ];
}

// =====================================================
// BUDGETING — set a target amount per category per period, then
// compare it against the same "actual" figures already computed by
// getIncomeStatement() / getCashFlowStatement() above, so Budget vs
// Actual always agrees with the Financial Statements page instead of
// re-deriving its own numbers.
//
// Categories kept deliberately small and tied to what's actually
// trackable right now:
//   'revenue' — Sales Revenue target, compared to getIncomeStatement()['revenue']
//   'payroll' — Payroll budget, compared to getIncomeStatement()['payroll_expense']
//   'restock' — Restocking/cash-spend budget, compared to
//               getCashFlowStatement()['cash_paid_restock'] (actual cash paid
//               for stock received in the period — same figure the Cash Flow
//               Statement uses, not the COGS figure, since a restocking
//               budget is about planned cash outlay, not units later sold)
// =====================================================

function ensureBudgetsTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS budgets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                category ENUM('revenue','payroll','restock') NOT NULL,
                budgeted_amount DECIMAL(12,2) NOT NULL,
                notes VARCHAR(255) NULL,
                created_by INT NULL,
                created_by_name VARCHAR(150) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_period_category (period_start, period_end, category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureBudgetsTable skipped: " . $e->getMessage());
    }
}

// Insert a new budget line, or update the amount/notes if one already
// exists for the same exact period+category (so Finance can revise a
// budget instead of creating duplicate rows for the same period).
function upsertBudget($pdo, $periodStart, $periodEnd, $category, $amount, $notes, $userId = null, $userName = null)
{
    if (!in_array($category, ['revenue', 'payroll', 'restock'], true) || $amount < 0) {
        return false;
    }
    ensureBudgetsTable($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO budgets (period_start, period_end, category, budgeted_amount, notes, created_by, created_by_name)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            budgeted_amount = VALUES(budgeted_amount),
            notes = VALUES(notes),
            created_by = VALUES(created_by),
            created_by_name = VALUES(created_by_name)
    ");
    return $stmt->execute([$periodStart, $periodEnd, $category, $amount, $notes ?: null, $userId, $userName]);
}

function deleteBudget($pdo, $budgetId)
{
    ensureBudgetsTable($pdo);
    $stmt = $pdo->prepare("DELETE FROM budgets WHERE id = ?");
    return $stmt->execute([(int) $budgetId]);
}

// Budget vs Actual for one exact period (period_start/period_end must
// match how the budget was saved — same date range used across the
// rest of the Finance module, e.g. a calendar month).
function getBudgetVsActual($pdo, $hrmsPdo, $periodStart, $periodEnd)
{
    ensureBudgetsTable($pdo);

    $stmt = $pdo->prepare("
        SELECT category, budgeted_amount, notes, created_by_name, created_at
        FROM budgets WHERE period_start = ? AND period_end = ?
    ");
    $stmt->execute([$periodStart, $periodEnd]);
    $rows = $stmt->fetchAll();

    $budgeted = ['revenue' => null, 'payroll' => null, 'restock' => null];
    $meta = [];
    foreach ($rows as $r) {
        $budgeted[$r['category']] = (float) $r['budgeted_amount'];
        $meta[$r['category']] = $r;
    }

    $income = getIncomeStatement($pdo, $hrmsPdo, $periodStart, $periodEnd);
    $cashflow = getCashFlowStatement($pdo, $hrmsPdo, $periodStart, $periodEnd);

    $actual = [
        'revenue' => $income['revenue'],
        'payroll' => $income['payroll_expense'],
        'restock' => $cashflow['cash_paid_restock'],
    ];

    $result = [];
    foreach (['revenue', 'payroll', 'restock'] as $cat) {
        $b = $budgeted[$cat];
        $a = $actual[$cat];
        // For revenue, "over budget" is good (exceeded target); for
        // payroll/restock (spending categories), "over budget" is bad
        // (spent more than planned). variance is always actual - budgeted;
        // is_favorable flips meaning depending on category direction.
        $variance = ($b !== null) ? ($a - $b) : null;
        $variancePct = ($b !== null && $b > 0) ? ($variance / $b * 100) : null;
        $isSpendCat = in_array($cat, ['payroll', 'restock'], true);
        $isFavorable = ($variance === null) ? null : ($isSpendCat ? $variance <= 0 : $variance >= 0);

        $result[$cat] = [
            'budgeted' => $b,
            'actual' => $a,
            'variance' => $variance,
            'variance_pct' => $variancePct,
            'is_favorable' => $isFavorable,
            'notes' => $meta[$cat]['notes'] ?? null,
            'set_by' => $meta[$cat]['created_by_name'] ?? null,
        ];
    }

    return $result;
}

// =====================================================
// FINANCIAL AUDIT LOG — unified, chronological view of every
// financial-control decision in the system.
//
// Kailangan ito dahil dati, kada module may sarili-sariling
// decided_at/approved_by trail (purchase_orders.approved_at,
// discount_requests.decided_at, supplier_payments.created_at, atbp.)
// — totoo naman ang datos, pero kailangan pa palaging pumunta sa
// bawat module page nang hiwa-hiwalay para makita KUNG SINO ang
// nag-decide ng ANO at KAILAN, walang isang lugar na makikita lahat
// nang magkakasunod para sa audit/tax coordination.
//
// Hindi ito bagong table — sinasama lang dito (sa PHP, hindi sa SQL,
// dahil magkaibang database ang grocery_pos at hrms) ang lahat ng
// existing decision trail sa iisang listahan, pinagsunod-sunod ayon
// sa petsa:
//   - Purchase Orders        : Approved / Rejected / Cancelled
//   - Inventory Requests     : Validated / Approved / Rejected / Received
//   - POS Discount Requests  : Approved / Rejected
//   - Product Discount Requests : Approved / Rejected
//   - Sale Refunds           : Refund Issued
//   - Supplier Payments      : Payment Recorded (Accounts Payable)
//   - Capital Transactions   : Capital In / Capital Out
//   - Budgets                : Budget Set/Revised
//   - Payroll (HRMS)         : Approved / Released (Paid) / Submitted /
//                              Updated (from payroll_records +
//                              payroll_logs — "generated"/"draft saved"
//                              excluded, too noisy per-employee-per-run
//                              to be a useful control-audit signal)
//
// Read-only aggregation — walang binabago sa underlying tables. Every
// source query is wrapped in try/catch (same convention as the rest
// of this file) so a missing/renamed table on an older install just
// quietly contributes zero rows instead of breaking the whole log.
function getFinancialAuditLog($pdo, $hrmsPdo, $from, $to, $category = 'all', $limit = 300)
{
    $rows = [];

    $push = function ($stmt, $args, $categoryLabel) use (&$rows) {
        try {
            $s = $stmt->execute($args) ? $stmt : null;
            if ($s) {
                foreach ($s->fetchAll() as $r) {
                    $r['category'] = $categoryLabel;
                    $rows[] = $r;
                }
            }
        } catch (PDOException $e) {
            error_log("getFinancialAuditLog ($categoryLabel) skipped: " . $e->getMessage());
        }
    };

    $wantsAll = ($category === 'all' || empty($category));

    // ---------- grocery_pos ($pdo) sources ----------

    if ($wantsAll || $category === 'purchase_orders') {
        $push($pdo->prepare("
            SELECT po.approved_at AS ts, 'Approved' AS event,
                   CONCAT('PO #', COALESCE(po.po_number, po.id), ' — ', COALESCE(s.company_name, 'Unknown Supplier')) AS reference,
                   (SELECT COALESCE(SUM(subtotal), 0) FROM purchase_order_items WHERE po_id = po.id) AS amount,
                   u.full_name AS actor_name, NULL AS notes
            FROM purchase_orders po
            LEFT JOIN suppliers s ON s.id = po.supplier_id
            LEFT JOIN users u ON u.id = po.approved_by
            WHERE po.approved_at IS NOT NULL AND DATE(po.approved_at) BETWEEN ? AND ?
        "), [$from, $to], 'Purchase Orders');

        $push($pdo->prepare("
            SELECT po.rejected_at AS ts, 'Rejected' AS event,
                   CONCAT('PO #', COALESCE(po.po_number, po.id), ' — ', COALESCE(s.company_name, 'Unknown Supplier')) AS reference,
                   (SELECT COALESCE(SUM(subtotal), 0) FROM purchase_order_items WHERE po_id = po.id) AS amount,
                   u.full_name AS actor_name, po.rejection_reason AS notes
            FROM purchase_orders po
            LEFT JOIN suppliers s ON s.id = po.supplier_id
            LEFT JOIN users u ON u.id = po.rejected_by
            WHERE po.rejected_at IS NOT NULL AND DATE(po.rejected_at) BETWEEN ? AND ?
        "), [$from, $to], 'Purchase Orders');

        $push($pdo->prepare("
            SELECT po.cancelled_at AS ts, 'Cancelled' AS event,
                   CONCAT('PO #', COALESCE(po.po_number, po.id), ' — ', COALESCE(s.company_name, 'Unknown Supplier')) AS reference,
                   (SELECT COALESCE(SUM(subtotal), 0) FROM purchase_order_items WHERE po_id = po.id) AS amount,
                   u.full_name AS actor_name, NULL AS notes
            FROM purchase_orders po
            LEFT JOIN suppliers s ON s.id = po.supplier_id
            LEFT JOIN users u ON u.id = po.cancelled_by
            WHERE po.cancelled_at IS NOT NULL AND DATE(po.cancelled_at) BETWEEN ? AND ?
        "), [$from, $to], 'Purchase Orders');
    }

    if ($wantsAll || $category === 'inventory_requests') {
        $push($pdo->prepare("
            SELECT ir.validated_at AS ts, 'Validated' AS event,
                   CONCAT('Request #', ir.request_id, ' — ', ir.item_name) AS reference,
                   ir.estimated_cost AS amount, u.full_name AS actor_name, ir.notes AS notes
            FROM inventory_requests ir
            LEFT JOIN users u ON u.id = ir.validated_by
            WHERE ir.validated_at IS NOT NULL AND DATE(ir.validated_at) BETWEEN ? AND ?
        "), [$from, $to], 'Inventory Requests');

        $push($pdo->prepare("
            SELECT ir.decided_at AS ts,
                   CASE WHEN ir.status = 'approved' THEN 'Approved' WHEN ir.status = 'rejected' THEN 'Rejected' ELSE ir.status END AS event,
                   CONCAT('Request #', ir.request_id, ' — ', ir.item_name) AS reference,
                   ir.estimated_cost AS amount, u.full_name AS actor_name, ir.notes AS notes
            FROM inventory_requests ir
            LEFT JOIN users u ON u.id = ir.decided_by
            WHERE ir.decided_at IS NOT NULL AND DATE(ir.decided_at) BETWEEN ? AND ?
        "), [$from, $to], 'Inventory Requests');

        $push($pdo->prepare("
            SELECT ir.received_at AS ts, 'Received / Fulfilled' AS event,
                   CONCAT('Request #', ir.request_id, ' — ', ir.item_name) AS reference,
                   ir.estimated_cost AS amount, u.full_name AS actor_name, NULL AS notes
            FROM inventory_requests ir
            LEFT JOIN users u ON u.id = ir.received_by
            WHERE ir.received_at IS NOT NULL AND DATE(ir.received_at) BETWEEN ? AND ?
        "), [$from, $to], 'Inventory Requests');
    }

    if ($wantsAll || $category === 'pos_discounts') {
        $push($pdo->prepare("
            SELECT dr.decided_at AS ts,
                   CASE WHEN dr.status = 'approved' THEN 'Approved' WHEN dr.status = 'rejected' THEN 'Rejected' ELSE dr.status END AS event,
                   CONCAT('POS Discount Request #', dr.request_id) AS reference,
                   dr.computed_discount_amount AS amount, u.full_name AS actor_name, dr.reject_reason AS notes
            FROM discount_requests dr
            LEFT JOIN users u ON u.id = dr.decided_by
            WHERE dr.decided_at IS NOT NULL AND DATE(dr.decided_at) BETWEEN ? AND ?
        "), [$from, $to], 'POS Discounts');
    }

    if ($wantsAll || $category === 'product_discounts') {
        $push($pdo->prepare("
            SELECT pdr.decided_at AS ts,
                   CASE WHEN pdr.status = 'approved' THEN 'Approved' WHEN pdr.status = 'rejected' THEN 'Rejected' ELSE pdr.status END AS event,
                   CONCAT('Product Discount — ', p.product_name) AS reference,
                   NULL AS amount, u.full_name AS actor_name, pdr.reject_reason AS notes
            FROM product_discount_requests pdr
            LEFT JOIN products p ON p.id = pdr.product_id
            LEFT JOIN users u ON u.id = pdr.decided_by
            WHERE pdr.decided_at IS NOT NULL AND DATE(pdr.decided_at) BETWEEN ? AND ?
        "), [$from, $to], 'Product Discounts');
    }

    if ($wantsAll || $category === 'refunds') {
        $push($pdo->prepare("
            SELECT sr.refunded_at AS ts, 'Refund Issued' AS event,
                   CONCAT('Sale #', sr.sale_id, ' — ', COALESCE(p.product_name, 'Item')) AS reference,
                   sr.amount AS amount, COALESCE(u.full_name, sr.approved_by_name) AS actor_name, sr.reason AS notes
            FROM sale_refunds sr
            LEFT JOIN products p ON p.id = sr.product_id
            LEFT JOIN users u ON u.id = sr.refunded_by
            WHERE DATE(sr.refunded_at) BETWEEN ? AND ?
        "), [$from, $to], 'Refunds');
    }

    if ($wantsAll || $category === 'supplier_payments') {
        $push($pdo->prepare("
            SELECT sp.created_at AS ts, 'Payment Recorded' AS event,
                   CONCAT('Payable #', sp.payable_id, CASE WHEN sp.reference_no IS NOT NULL AND sp.reference_no != '' THEN CONCAT(' — Ref: ', sp.reference_no) ELSE '' END) AS reference,
                   sp.amount AS amount, COALESCE(u.full_name, sp.paid_by_name) AS actor_name, sp.notes AS notes
            FROM supplier_payments sp
            LEFT JOIN users u ON u.id = sp.paid_by
            WHERE DATE(sp.created_at) BETWEEN ? AND ?
        "), [$from, $to], 'Supplier Payments');
    }

    if ($wantsAll || $category === 'capital') {
        $push($pdo->prepare("
            SELECT ct.created_at AS ts,
                   CASE WHEN ct.type = 'capital_in' THEN 'Capital In' ELSE 'Capital Out (Withdrawal)' END AS event,
                   ct.reason AS reference, ct.amount AS amount,
                   COALESCE(u.full_name, ct.created_by_name) AS actor_name, NULL AS notes
            FROM capital_transactions ct
            LEFT JOIN users u ON u.id = ct.created_by
            WHERE DATE(ct.created_at) BETWEEN ? AND ?
        "), [$from, $to], 'Capital');
    }

    if ($wantsAll || $category === 'budgets') {
        try {
            ensureBudgetsTable($pdo);
        } catch (Throwable $e) {
        }
        $push($pdo->prepare("
            SELECT b.updated_at AS ts, 'Budget Set / Revised' AS event,
                   CONCAT(UPPER(LEFT(b.category, 1)), SUBSTRING(b.category, 2), ' — ', b.period_start, ' to ', b.period_end) AS reference,
                   b.budgeted_amount AS amount, COALESCE(u.full_name, b.created_by_name) AS actor_name, b.notes AS notes
            FROM budgets b
            LEFT JOIN users u ON u.id = b.created_by
            WHERE DATE(b.updated_at) BETWEEN ? AND ?
        "), [$from, $to], 'Budgets');
    }

    if ($wantsAll || $category === 'operating_expenses') {
        try {
            ensureOperatingExpenseTables($pdo);
        } catch (Throwable $e) {
        }

        $push($pdo->prepare("
            SELECT oe.verified_at AS ts,
                   CASE WHEN oe.requires_level2_approval = 1 THEN 'Verified (Level 1)' ELSE 'Verified & Approved' END AS event,
                   CONCAT('Bill #', oe.id, ' — ', oe.payee_name, COALESCE(CONCAT(' (', ec.name, ')'), '')) AS reference,
                   oe.amount AS amount, u.full_name AS actor_name, NULL AS notes
            FROM operating_expenses oe
            LEFT JOIN expense_categories ec ON ec.id = oe.category_id
            LEFT JOIN users u ON u.id = oe.verified_by
            WHERE oe.verified_at IS NOT NULL AND DATE(oe.verified_at) BETWEEN ? AND ?
        "), [$from, $to], 'Operating Expenses');

        $push($pdo->prepare("
            SELECT oe.approved_at AS ts,
                   CASE WHEN oe.over_budget_flag = 1 THEN 'Approved (Over Budget — Overridden)' ELSE 'Approved (Level 2)' END AS event,
                   CONCAT('Bill #', oe.id, ' — ', oe.payee_name, COALESCE(CONCAT(' (', ec.name, ')'), '')) AS reference,
                   oe.amount AS amount, u.full_name AS actor_name, oe.over_budget_reason AS notes
            FROM operating_expenses oe
            LEFT JOIN expense_categories ec ON ec.id = oe.category_id
            LEFT JOIN users u ON u.id = oe.approved_by
            WHERE oe.approved_at IS NOT NULL AND oe.requires_level2_approval = 1 AND DATE(oe.approved_at) BETWEEN ? AND ?
        "), [$from, $to], 'Operating Expenses');

        $push($pdo->prepare("
            SELECT oe.returned_at AS ts, 'Returned' AS event,
                   CONCAT('Bill #', oe.id, ' — ', oe.payee_name, COALESCE(CONCAT(' (', ec.name, ')'), '')) AS reference,
                   oe.amount AS amount, NULL AS actor_name, oe.return_reason AS notes
            FROM operating_expenses oe
            LEFT JOIN expense_categories ec ON ec.id = oe.category_id
            WHERE oe.returned_at IS NOT NULL AND DATE(oe.returned_at) BETWEEN ? AND ?
        "), [$from, $to], 'Operating Expenses');
    }

    // ---------- hrms ($hrmsPdo) sources ----------

    if ($hrmsPdo && ($wantsAll || $category === 'payroll')) {
        $push($hrmsPdo->prepare("
            SELECT pr.approved_at AS ts, 'Approved' AS event,
                   CONCAT('Payroll #', pr.payroll_id, ' — ', pr.pay_period_start, ' to ', pr.pay_period_end) AS reference,
                   pr.gross_pay AS amount, u.full_name AS actor_name, NULL AS notes
            FROM payroll_records pr
            LEFT JOIN users u ON u.id = pr.approved_by
            WHERE pr.approved_at IS NOT NULL AND DATE(pr.approved_at) BETWEEN ? AND ?
        "), [$from, $to], 'Payroll');

        $push($hrmsPdo->prepare("
            SELECT pr.paid_at AS ts, 'Released (Paid)' AS event,
                   CONCAT('Payroll #', pr.payroll_id, ' — ', pr.pay_period_start, ' to ', pr.pay_period_end) AS reference,
                   pr.net_pay AS amount, u.full_name AS actor_name, pr.payment_reference_number AS notes
            FROM payroll_records pr
            LEFT JOIN users u ON u.id = pr.payment_processed_by
            WHERE pr.paid_at IS NOT NULL AND DATE(pr.paid_at) BETWEEN ? AND ?
        "), [$from, $to], 'Payroll');

        $push($hrmsPdo->prepare("
            SELECT pl.logged_at AS ts,
                   CASE WHEN pl.action = 'Payroll submitted for approval' THEN 'Submitted for Approval' ELSE 'Updated' END AS event,
                   CONCAT('Payroll #', pl.payroll_id) AS reference,
                   NULL AS amount, u.full_name AS actor_name, pl.notes AS notes
            FROM payroll_logs pl
            LEFT JOIN users u ON u.id = pl.performed_by
            WHERE pl.action IN ('Payroll submitted for approval', 'Payroll updated')
              AND DATE(pl.logged_at) BETWEEN ? AND ?
        "), [$from, $to], 'Payroll');
    }

    // Merge, sort newest-first, cap.
    usort($rows, function ($a, $b) {
        return strtotime($b['ts']) <=> strtotime($a['ts']);
    });

    if ($limit > 0 && count($rows) > $limit) {
        $rows = array_slice($rows, 0, $limit);
    }

    return $rows;
}

// =====================================================
// CASHIER SELF SERVICE — TIME IN/OUT & LEAVE REQUESTS
// ------------------------------------------------------
// The cashier's HR-related self-service actions (Time In/Out,
// requesting leave) used to only be available on the HRMS side
// (HRMS/employee_dashboard.php), so cashiers had to log into a
// separate system just for that. These are ported here so a
// cashier can do everything from the POS "My Profile" page
// (my_profile.php) instead. They mirror the same logic used in
// HRMS/includes/config.php (processSelfTimeInOut(), leave credits,
// shift lookup) but operate on $hrmsPdo since attendance/leave
// data lives in the `hrms` database, not `grocery_pos`.
// If $hrmsPdo is unavailable, all of these safely no-op/return
// empty results — see the $hrms_unavailable checks in
// my_profile.php.
// =====================================================

define('CASHIER_SHIFT_START_TIME', '08:00'); // fallback only, used when the
// employee has no shift assigned
// for that day (see getEmployeeShiftForDate())
define('CASHIER_LATE_GRACE_MINUTES', 15);    // grace period before being marked "late"
define('CASHIER_EARLY_TIMEIN_WINDOW_MINUTES', 60); // Time In stays CLOSED until this
// many minutes before the shift's
// start_time — e.g. 60 = a 6:00 AM shift
// only opens Time In starting 5:00 AM.

function computeCashierLateMinutes($time_in_hi, $shiftStartTime = null)
{
    $in = DateTime::createFromFormat('H:i', substr($time_in_hi, 0, 5));
    $shift = DateTime::createFromFormat('H:i', substr($shiftStartTime ?: CASHIER_SHIFT_START_TIME, 0, 5));

    if (!$in || !$shift) {
        return 0;
    }
    $diffMinutes = ($in->getTimestamp() - $shift->getTimestamp()) / 60;
    $late = $diffMinutes - CASHIER_LATE_GRACE_MINUTES;
    return $late > 0 ? (int) round($late) : 0;
}

// Kailan bubukas ang Time In bago pa man magsimula ang shift
// (H:i:s) — hal. 6:00 AM ang shift + 60 minutong window = 5:00 AM.
function getCashierTimeInWindowOpenTime($shiftStartTime, $earlyWindowMinutes = null)
{
    $earlyWindowMinutes = $earlyWindowMinutes ?? CASHIER_EARLY_TIMEIN_WINDOW_MINUTES;
    $start = strtotime('1970-01-01 ' . $shiftStartTime);
    if ($start === false)
        return $shiftStartTime;
    return date('H:i:s', $start - ($earlyWindowMinutes * 60));
}

// True kung sarado pa ang Time In dahil masyadong maaga pa.
function isCashierBeforeTimeInWindow($shiftStartTime, $nowTime, $earlyWindowMinutes = null)
{
    $windowOpen = getCashierTimeInWindowOpenTime($shiftStartTime, $earlyWindowMinutes);
    $open = strtotime('1970-01-01 ' . $windowOpen);
    $now = strtotime('1970-01-01 ' . $nowTime);
    if ($open === false || $now === false)
        return false;
    if ($open < 0)
        return false; // shift start - window landed "before midnight" — huwag nang gawing bara
    return $now < $open;
}

// True kung mas maaga pa sa aktwal na simula ng shift — ginagamit
// para hindi puwedeng mag-Time In nang maaga (sa loob ng early
// window) tapos agad-agad mag-Time Out bago pa man talaga sumapit
// ang shift.
function isCashierBeforeShiftStart($shiftStartTime, $nowTime)
{
    $start = strtotime('1970-01-01 ' . $shiftStartTime);
    $now = strtotime('1970-01-01 ' . $nowTime);
    if ($start === false || $now === false)
        return false;
    return $now < $start;
}

// Whether $shiftEndTime (H:i:s) has already passed for $nowTime
// (H:i:s), on the same calendar day as $shiftStartTime. Handles
// shifts that cross midnight (end time earlier than start time).
// Mirrors shiftHasEnded() in HRMS/includes/config.php — was missing
// entirely on this (POS) side, which is why a cashier who never
// Timed In could still see "Time In Now" open even after their whole
// shift had already passed.
function cashierShiftHasEnded($shiftEndTime, $shiftStartTime, $nowTime)
{
    $start = strtotime('1970-01-01 ' . $shiftStartTime);
    $end = strtotime('1970-01-01 ' . $shiftEndTime);
    $now = strtotime('1970-01-01 ' . $nowTime);
    if ($start === false || $end === false || $now === false)
        return false;
    if ($end <= $start) {
        $end += 86400;
    } // overnight shift
    return $now >= $end;
}

define('CASHIER_LEAVE_CREDIT_TYPES', ['sick', 'vacation', 'emergency']);
define('CASHIER_DEFAULT_LEAVE_ALLOCATIONS', [
    'sick' => 15,
    'vacation' => 15,
    'emergency' => 5,
]);

// Leave types where a day-limit is actually enforced when submitting a
// request. Sick and Emergency are still tracked for reporting, but only
// Vacation Leave blocks submission once the balance is used up.
define('CASHIER_LEAVE_TYPES_WITH_LIMIT', ['vacation']);

// Minimum number of working days an employee must be back at work
// before they're allowed to file another Vacation Leave request.
// Prevents scheduling a new vacation leave that starts immediately
// (or overlaps) right after returning from a previous one, while
// still allowing vacation leave to be scheduled far in advance.
// Mirrors VACATION_LEAVE_COOLDOWN_DAYS / checkVacationLeaveCooldown()
// in HRMS/includes/config.php so the rule stays consistent.
define('CASHIER_VACATION_LEAVE_COOLDOWN_DAYS', 1);

// Minimum number of days that must pass since a Cashier/Inventory
// Staff employee's last Vacation Leave REQUEST (the day they FILED
// it, not the leave dates itself) before they're allowed to file
// another one. Mirrors checkVacationLeaveRequestCooldown() in
// HRMS/includes/config.php so the rule stays consistent between
// both sides -- checkCashierVacationLeaveCooldown() above instead
// checks the gap between the leave PERIODS.
define('CASHIER_VACATION_LEAVE_REQUEST_COOLDOWN_DAYS', 30);

function checkCashierVacationLeaveRequestCooldown($hrmsPdo, $employee_id)
{
    try {
        $stmt = $hrmsPdo->prepare("
            SELECT requested_at FROM leave_requests
            WHERE employee_id = ? AND leave_type = 'vacation' AND status IN ('pending', 'approved')
            ORDER BY requested_at DESC LIMIT 1
        ");
        $stmt->execute([$employee_id]);
        $lastRequestedAt = $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("checkCashierVacationLeaveRequestCooldown error: " . $e->getMessage());
        return null; // fail open -- don't block submission over a lookup error
    }

    if (!$lastRequestedAt)
        return null;

    $daysSince = (strtotime('today') - strtotime(date('Y-m-d', strtotime($lastRequestedAt)))) / 86400;

    if ($daysSince < CASHIER_VACATION_LEAVE_REQUEST_COOLDOWN_DAYS) {
        $nextAllowedDate = date('F j, Y', strtotime($lastRequestedAt . ' +' . CASHIER_VACATION_LEAVE_REQUEST_COOLDOWN_DAYS . ' days'));
        return "You already filed a Vacation Leave request on " . date('F j, Y', strtotime($lastRequestedAt))
            . ". You need to wait until " . $nextAllowedDate . " (" . CASHIER_VACATION_LEAVE_REQUEST_COOLDOWN_DAYS . " days after your last request) before filing another Vacation Leave request.";
    }

    return null;
}

function checkCashierVacationLeaveCooldown($hrmsPdo, $employee_id, $date_from, $date_to)
{
    try {
        $stmt = $hrmsPdo->prepare("
            SELECT date_from, date_to FROM leave_requests
            WHERE employee_id = ? AND leave_type = 'vacation' AND status IN ('pending', 'approved')
        ");
        $stmt->execute([$employee_id]);
        $existing = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("checkCashierVacationLeaveCooldown error: " . $e->getMessage());
        return null; // fail open — don't block submission over a lookup error
    }

    $newFrom = strtotime($date_from);
    $newTo = strtotime($date_to);

    foreach ($existing as $ex) {
        $exFrom = strtotime($ex['date_from']);
        $exTo = strtotime($ex['date_to']);

        if ($newFrom <= $exTo && $newTo >= $exFrom) {
            return "This overlaps with another Vacation Leave you already have on file ({$ex['date_from']} to {$ex['date_to']}).";
        }

        if ($newFrom > $exTo) {
            $gapDays = (int) round(($newFrom - $exTo) / 86400) - 1;
        } else {
            $gapDays = (int) round(($exFrom - $newTo) / 86400) - 1;
        }

        if ($gapDays < CASHIER_VACATION_LEAVE_COOLDOWN_DAYS) {
            return "You need at least " . CASHIER_VACATION_LEAVE_COOLDOWN_DAYS . " working day(s) back at work before/after "
                . "your Vacation Leave on {$ex['date_from']} to {$ex['date_to']} before filing another one.";
        }
    }

    return null;
}

// The shift assigned to this employee for a given date (based on
// their weekly schedule set up on the HRMS side). Returns null if
// that day is a Day Off for them, they have no schedule configured
// yet, or the shift tables don't exist yet in the `hrms` database —
// callers fall back to CASHIER_SHIFT_START_TIME in that case.
function getCashierShiftForDate($hrmsPdo, $employee_id, $date)
{
    if (!$hrmsPdo || !$employee_id)
        return null;
    try {
        $dow = (int) date('w', strtotime($date)); // 0=Sunday...6=Saturday
        $stmt = $hrmsPdo->prepare("
            SELECT s.shift_id, s.shift_name, s.start_time, s.end_time
            FROM employee_weekly_shifts ews
            JOIN shifts s ON s.shift_id = ews.shift_id
            WHERE ews.employee_id = ? AND ews.day_of_week = ?
        ");
        $stmt->execute([$employee_id, $dow]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("getCashierShiftForDate skipped: " . $e->getMessage());
        return null;
    }
}

// The APPROVED shift-swap (if any) that lets this cashier/employee
// Time In on $date even though it's their Day Off — set up by HR on
// the HRMS side (Attendance -> Shift Swap Requests). Mirrors
// getApprovedShiftSwapForDate() in HRMS/includes/config.php.
function getCashierApprovedShiftSwap($hrmsPdo, $employee_id, $date)
{
    if (!$hrmsPdo || !$employee_id)
        return null;
    try {
        $stmt = $hrmsPdo->prepare("
            SELECT sw.*, s.shift_name, s.start_time, s.end_time
            FROM shift_swaps sw
            LEFT JOIN shifts s ON s.shift_id = sw.shift_id
            WHERE sw.covering_employee_id = ? AND sw.swap_date = ? AND sw.status = 'approved'
            LIMIT 1
        ");
        $stmt->execute([$employee_id, $date]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("getCashierApprovedShiftSwap skipped: " . $e->getMessage());
        return null;
    }
}

// Returns the most recent PAST attendance record (date < $beforeDate)
// for this cashier/employee where they Timed In but never Timed Out
// — i.e. an "unfinished" record they forgot to close. Mirrors
// getUnfinishedAttendanceRecord() in HRMS/includes/config.php (same
// `attendance` table, shared by both the HRMS and POS Time In/Out
// flows). While this exists, Time In is blocked here too, until a
// Supervisor/HR manually corrects the Time Out on the HRMS side
// (see HRMS/supervisor_attendance.php).
function getCashierUnfinishedAttendanceRecord($hrmsPdo, $employee_id, $beforeDate)
{
    if (!$hrmsPdo || !$employee_id)
        return null;
    try {
        $stmt = $hrmsPdo->prepare("
            SELECT * FROM attendance
            WHERE employee_id = ? AND date < ? AND time_in IS NOT NULL AND time_out IS NULL
            ORDER BY date DESC
            LIMIT 1
        ");
        $stmt->execute([$employee_id, $beforeDate]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("getCashierUnfinishedAttendanceRecord skipped: " . $e->getMessage());
        return null;
    }
}

// Finds YESTERDAY's attendance record if it's still open (Timed In,
// never Timed Out) and it's plausible that it's still the SAME
// overnight shift still in progress — e.g. a 10PM–6AM shift Times In
// under yesterday's date, and the cashier only comes back to Time Out
// after midnight, once "today" has already turned over. Without this,
// processCashierSelfTimeInOut() below would only ever look up
// attendance dated exactly today and miss that row entirely, wrongly
// telling the cashier "You need to Time In first before you can Time
// Out" even though they're already clocked in.
//
// Uses the shift captured on that row (attendance.shift_id, set at
// Time In) to know when it actually ends, wraps it past midnight when
// end_time <= start_time (overnight), and allows a further grace
// window past that so a slightly-late Time Out still self-closes. If
// there's no shift on record, falls back to a flat 12-hour window
// from Time In. Beyond the grace window it returns null on purpose —
// that's a genuinely forgotten Time Out, and stays gated behind
// getCashierUnfinishedAttendanceRecord() for a Supervisor/HR fix,
// same as before.
function getCashierOpenOvernightRecord($hrmsPdo, $employee_id, $today)
{
    if (!$hrmsPdo || !$employee_id)
        return null;
    $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
    try {
        $stmt = $hrmsPdo->prepare("
            SELECT a.*, s.start_time AS shift_start_time, s.end_time AS shift_end_time
            FROM attendance a
            LEFT JOIN shifts s ON s.shift_id = a.shift_id
            WHERE a.employee_id = ? AND a.date = ? AND a.time_in IS NOT NULL AND a.time_out IS NULL
            LIMIT 1
        ");
        $stmt->execute([$employee_id, $yesterday]);
        $row = $stmt->fetch();
        if (!$row)
            return null;

        if (!empty($row['shift_start_time']) && !empty($row['shift_end_time'])) {
            $startTs = strtotime($yesterday . ' ' . $row['shift_start_time']);
            $endTs = strtotime($yesterday . ' ' . $row['shift_end_time']);
            if ($endTs <= $startTs) {
                $endTs += 86400;
            } // overnight wrap
        } else {
            // No shift on record — generous flat window instead of
            // guessing a shift end.
            $endTs = strtotime($yesterday . ' ' . $row['time_in']) + (12 * 3600);
        }

        $graceSeconds = 4 * 3600; // extra leeway past the scheduled/assumed end
        return (time() <= $endTs + $graceSeconds) ? $row : null;
    } catch (PDOException $e) {
        error_log("getCashierOpenOvernightRecord skipped: " . $e->getMessage());
        return null;
    }
}

// Self Time In/Out for the cashier, straight from the POS "My
// Profile" page. Captures GPS location on Time In (required —
// same rule as the HRMS side). Returns ['message'=>, 'error'=>,
// 'todayRow'=>, 'todayShift'=>].
function processCashierSelfTimeInOut($hrmsPdo, $employee_id)
{
    $result = ['message' => '', 'error' => '', 'todayRow' => null, 'todayShift' => null];
    if (!$hrmsPdo) {
        $result['error'] = 'HRMS database is unavailable right now, so Time In/Out cannot be processed.';
        return $result;
    }

    $today = date('Y-m-d');
    // Kept separate from $today because the self_time_out branch below
    // may reassign $today to YESTERDAY's date (overnight shift case) so
    // the todayRow re-fetch picks up the record it just closed. Anything
    // that must stay anchored to the real calendar day (unfinished-record
    // check, today's Time In request) should use $trueToday instead.
    $trueToday = $today;

    if ($employee_id) {
        $stmt = $hrmsPdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND date = ?");
        $stmt->execute([$employee_id, $today]);
        $result['todayRow'] = $stmt->fetch() ?: null;
        $result['todayShift'] = getCashierShiftForDate($hrmsPdo, $employee_id, $today);
        $result['approvedSwap'] = $result['todayShift'] ? null : getCashierApprovedShiftSwap($hrmsPdo, $employee_id, $today);
        // Blocks Time In entirely if a previous day's record was never
        // Timed Out (employee forgot) — see getCashierUnfinishedAttendanceRecord().
        $result['unfinishedRecord'] = getCashierUnfinishedAttendanceRecord($hrmsPdo, $employee_id, $today);
        // Most recent "Request Time In" the cashier submitted for
        // today (pending/approved/rejected) — see
        // getCashierLateTimeInRequestForDate() above. Once a
        // Supervisor approves it (either remotely on the HRMS side,
        // or right here via "Supervisor Time In Now"), Time In
        // re-opens for the rest of today even while outside the
        // normal window, and the late deduction is waived.
        $result['lateRequestToday'] = getCashierLateTimeInRequestForDate($hrmsPdo, $employee_id, $today);
    }

    // Suspension check — see getCashierSuspensionStatus() above. Login
    // is already supposed to block a suspended account (login.php), so
    // this is mainly a safety net for a session that was already open
    // right when the suspension took effect. Purely date-driven off
    // suspension_end_date, so Time In re-opens on its own the day
    // after the suspension ends — no separate "lift" step needed here.
    $result['suspension'] = $employee_id ? getCashierSuspensionStatus($hrmsPdo, $employee_id) : getCashierSuspensionStatus(null, null);

    // Early Time-In window + "Time Out bago pa magsimula ang shift"
    // guard — parehong logic sa HRMS side (see processSelfTimeInOut()
    // sa HRMS/includes/config.php).
    $__effectiveShift = $result['todayShift']
        ?: (($result['approvedSwap'] ?? null) && $result['approvedSwap']['shift_id'] ? $result['approvedSwap'] : null);
    $result['timeInWindowOpen'] = true;
    $result['timeInOpensAt'] = null;
    $result['shiftEnded'] = false;
    if ($employee_id && $__effectiveShift && empty($result['todayRow']['time_in'])) {
        $result['timeInWindowOpen'] = !isCashierBeforeTimeInWindow($__effectiveShift['start_time'], date('H:i:s'));
        $result['timeInOpensAt'] = getCashierTimeInWindowOpenTime($__effectiveShift['start_time']);
        // Kung natapos na ang buong shift at hindi pa nag-Time In,
        // hindi na ito puedeng "Time In Now" na lang basta-basta —
        // dapat dumaan na sa Request Time In / Supervisor override.
        $result['shiftEnded'] = cashierShiftHasEnded($__effectiveShift['end_time'], $__effectiveShift['start_time'], date('H:i:s'));
    }
    $result['shiftNotStartedYet'] = false;
    if ($employee_id && $__effectiveShift && !empty($result['todayRow']['time_in']) && empty($result['todayRow']['time_out'])) {
        $result['shiftNotStartedYet'] = isCashierBeforeShiftStart($__effectiveShift['start_time'], date('H:i:s'));
    }
    // A Supervisor already approved a Time In request for today (or
    // just did the "Supervisor Time In Now" override) — Time In stays
    // open for the cashier even though it's outside their normal window.
    $result['canTimeInLate'] = !empty($result['lateRequestToday']) && $result['lateRequestToday']['status'] === 'approved';

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && in_array($_POST['action'] ?? '', ['self_time_in', 'self_time_out', 'request_late_timein', 'supervisor_timein_now'], true)
    ) {

        requireCSRF();

        if (!$employee_id) {
            $result['error'] = "Your account doesn't have a linked employee record.";
        } elseif (
            $result['suspension']['active']
            && in_array($_POST['action'] ?? '', ['self_time_in', 'request_late_timein', 'supervisor_timein_now'], true)
        ) {
            // Blocks Time In (and requesting/overriding one) for the
            // exact duration set by HR/Admin's Suspend action — Time
            // Out stays allowed (see the "else" self_time_out branch
            // below) so a shift already in progress when the
            // suspension took effect can still be closed out properly.
            $daysLabel = $result['suspension']['days_remaining'] . ' day' . ($result['suspension']['days_remaining'] == 1 ? '' : 's');
            $reasonLabel = !empty($result['suspension']['reason']) ? $result['suspension']['reason'] : 'No reason specified.';
            $result['error'] = "You are currently suspended (" . $daysLabel . " remaining, until "
                . date('M d, Y', strtotime($result['suspension']['end_date'])) . "). Reason: " . $reasonLabel
                . ". Time In is disabled for the duration of the suspension.";
        } elseif (($_POST['action'] ?? '') === 'request_late_timein') {

            $lateReq = $result['lateRequestToday'];

            if ($result['todayRow'] && !empty($result['todayRow']['time_in'])) {
                $result['error'] = "You have already Timed In today.";
            } elseif ($result['unfinishedRecord']) {
                $result['error'] = "You have an unfinished attendance record. Please contact your supervisor or HR to correct your previous Time Out.";
            } elseif ($result['timeInWindowOpen'] && !$result['shiftEnded']) {
                // Allowed to request in TWO situations: (a) too early —
                // before the normal Time In window opens, e.g. an
                // overtime start earlier than the scheduled shift; or
                // (b) the whole shift already ended and they never
                // Timed In. Only block the request when neither is
                // true, i.e. they're comfortably inside a still-valid
                // window and can just Time In normally.
                $result['error'] = "It's already within your Time In window — you can Time In normally, no request needed.";
            } elseif ($lateReq && $lateReq['status'] === 'pending') {
                $result['error'] = "You already have a Time In request pending approval from your Supervisor.";
            } elseif ($lateReq && $lateReq['status'] === 'approved') {
                $result['error'] = "Your Time In request was already approved — you can Time In now.";
            } else {
                $reqTime = trim($_POST['requested_time'] ?? date('H:i'));
                $reason = trim($_POST['late_reason'] ?? '');

                if (!preg_match('/^\d{1,2}:\d{2}$/', $reqTime)) {
                    $result['error'] = "Please enter a valid time you'd like to Time In.";
                } elseif (mb_strlen($reason) < 3) {
                    $result['error'] = "Please enter a reason for your Time In request.";
                } else {
                    try {
                        $stmt = $hrmsPdo->prepare("
                            INSERT INTO late_timein_requests (employee_id, request_date, requested_time, reason, status, requested_at)
                            VALUES (?, ?, ?, ?, 'pending', NOW())
                        ");
                        $stmt->execute([$employee_id, $today, $reqTime, $reason]);
                        $result['message'] = "Your Time In request has been sent to your Supervisor. Time In will re-open once it's approved — either remotely, or right here if your Supervisor uses \"Supervisor Time In Now\".";
                    } catch (PDOException $e) {
                        error_log("processCashierSelfTimeInOut (request_late_timein) error: " . $e->getMessage());
                        $result['error'] = "Could not submit your Time In request. Please try again.";
                    }
                }
            }
        } elseif (($_POST['action'] ?? '') === 'supervisor_timein_now') {

            // A Supervisor enters their OWN HRMS username/password right
            // here on the cashier's POS screen to Time In the cashier on
            // the spot — no need to wait for a remote approval. Counts
            // the cashier as present but WITHOUT a late deduction, same
            // as a remotely-approved Time In request.
            $todayRow = $result['todayRow'];
            $approvedSwap = (!$result['todayShift']) ? getCashierApprovedShiftSwap($hrmsPdo, $employee_id, $today) : null;
            $result['approvedSwap'] = $approvedSwap;

            $supUsername = trim($_POST['supervisor_username'] ?? '');
            $supPassword = (string) ($_POST['supervisor_password'] ?? '');
            $reason = trim($_POST['late_reason'] ?? 'Supervisor Time In Now (on-the-spot override)');

            if ($todayRow && !empty($todayRow['time_in'])) {
                $result['error'] = "You have already Timed In today.";
            } elseif ($result['unfinishedRecord']) {
                $result['error'] = "You have an unfinished attendance record. Please contact your supervisor or HR to correct your previous Time Out.";
            } elseif (!$result['todayShift'] && !$approvedSwap) {
                $result['error'] = "You're not scheduled to work today (Day Off) — ask HR to process a Shift Swap first.";
            } else {
                $supervisor = verifyCashierSupervisorLogin($hrmsPdo, $supUsername, $supPassword, $employee_id);
                if (!$supervisor) {
                    $result['error'] = "Supervisor login could not be verified. Check the username/password, and make sure this Supervisor manages this cashier's team.";
                } else {
                    $lat = $_POST['latitude'] ?? '';
                    $lng = $_POST['longitude'] ?? '';
                    $addr = trim($_POST['location_address'] ?? '');
                    $latValid = is_numeric($lat) && (float) $lat >= -90 && (float) $lat <= 90;
                    $lngValid = is_numeric($lng) && (float) $lng >= -180 && (float) $lng <= 180;
                    $accuracy = $_POST['location_accuracy'] ?? '';
                    $accuracyValid = is_numeric($accuracy) && (float) $accuracy >= 0 && (float) $accuracy <= ATTENDANCE_MAX_ACCURACY_METERS;
                    $locationPolicy = ($latValid && $lngValid && $accuracyValid)
                        ? validateAttendanceLocation($lat, $lng, $accuracy)
                        : ['valid' => false, 'error' => ''];
                    if (!$latValid || !$lngValid || !$accuracyValid || !$locationPolicy['valid']) {
                        $result['error'] = ATTENDANCE_LOCATION_MODE === 'approximate'
                            ? "A location reading is required for Supervisor Time In. Please allow location access and try again."
                            : ($locationPolicy['error'] !== '' ? $locationPolicy['error'] : "A precise GPS fix is required for Supervisor Time In too. Use a GPS-enabled device and try again.");
                        return $result;
                    }
                    $addrToSave = $addr !== '' ? mb_substr($addr, 0, 255) : null;

                    $now = date('H:i:s');
                    $todayShift = $result['todayShift'] ?: ($approvedSwap && $approvedSwap['shift_id'] ? $approvedSwap : null);
                    $shiftId = $todayShift ? $todayShift['shift_id'] : null;

                    try {
                        $hrmsPdo->beginTransaction();

                        // Log the override for the audit trail — same
                        // late_timein_requests table the Supervisor's
                        // remote "Time In Requests" page reads, already
                        // recorded as approved by them.
                        $stmt = $hrmsPdo->prepare("
                            INSERT INTO late_timein_requests (employee_id, request_date, requested_time, reason, status, approved_by, decided_at, requested_at)
                            VALUES (?, ?, ?, ?, 'approved', ?, NOW(), NOW())
                        ");
                        $stmt->execute([$employee_id, $today, $now, $reason, $supervisor['id']]);

                        // late_minutes = 0 — a Supervisor-approved late
                        // Time In is never deducted, regardless of the
                        // actual clock.
                        $stmt = $hrmsPdo->prepare("
                            INSERT INTO attendance (employee_id, date, time_in, status, late_minutes, shift_id, time_in_latitude, time_in_longitude, time_in_accuracy_meters, time_in_address)
                            VALUES (?, ?, ?, 'present', 0, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                                time_in = VALUES(time_in),
                                status = VALUES(status),
                                late_minutes = 0,
                                shift_id = VALUES(shift_id),
                                time_in_latitude = VALUES(time_in_latitude),
                                time_in_longitude = VALUES(time_in_longitude),
                                time_in_accuracy_meters = VALUES(time_in_accuracy_meters),
                                time_in_address = VALUES(time_in_address)
                        ");
                        $stmt->execute([$employee_id, $today, $now, $shiftId, $lat, $lng, (float) $accuracy, $addrToSave]);

                        $hrmsPdo->commit();
                        $result['message'] = "Timed in at " . date('h:i A', strtotime($now)) . " by Supervisor " . $supervisor['full_name'] . " — counted as present, no late deduction.";
                    } catch (PDOException $e) {
                        $hrmsPdo->rollBack();
                        error_log("processCashierSelfTimeInOut (supervisor_timein_now) error: " . $e->getMessage());
                        $result['error'] = "Time In could not be recorded. Please try again.";
                    }
                }
            }
        } elseif (($_POST['action'] ?? '') === 'self_time_in') {

            $todayRow = $result['todayRow'];
            $approvedSwap = (!$result['todayShift']) ? getCashierApprovedShiftSwap($hrmsPdo, $employee_id, $today) : null;
            $result['approvedSwap'] = $approvedSwap;

            if ($todayRow && !empty($todayRow['time_in'])) {
                $result['error'] = "You have already Timed In today.";
            } elseif ($result['unfinishedRecord']) {
                $result['error'] = "You have an unfinished attendance record. Please contact your supervisor or HR to correct your previous Time Out.";
            } elseif (!$result['todayShift'] && !$approvedSwap) {
                $result['error'] = "You're not scheduled to work today (Day Off) — Time In is disabled. If you're covering for another employee's shift today, ask HR to process a Shift Swap for you first.";
            } elseif ($result['shiftEnded'] && !$result['canTimeInLate']) {
                $result['error'] = "Your shift has already ended for today, so Time In is closed. Submit a Time In request for your Supervisor's approval instead.";
            } elseif (!$result['timeInWindowOpen'] && !$result['canTimeInLate']) {
                $result['error'] = "It's too early to Time In. Time In opens at " . date('h:i A', strtotime($result['timeInOpensAt'])) . ". If you need to Time In sooner, submit a Time In request for your Supervisor's approval.";
            } else {
                $lat = $_POST['latitude'] ?? '';
                $lng = $_POST['longitude'] ?? '';
                $addr = trim($_POST['location_address'] ?? '');
                $latValid = is_numeric($lat) && (float) $lat >= -90 && (float) $lat <= 90;
                $lngValid = is_numeric($lng) && (float) $lng >= -180 && (float) $lng <= 180;

                $accuracy = $_POST['location_accuracy'] ?? '';
                $accuracyValid = is_numeric($accuracy) && (float) $accuracy >= 0 && (float) $accuracy <= ATTENDANCE_MAX_ACCURACY_METERS;
                $locationPolicy = ($latValid && $lngValid && $accuracyValid)
                    ? validateAttendanceLocation($lat, $lng, $accuracy)
                    : ['valid' => false, 'error' => ''];

                if (!$latValid || !$lngValid || !$accuracyValid || !$locationPolicy['valid']) {
                    $result['error'] = ATTENDANCE_LOCATION_MODE === 'approximate'
                        ? "A location reading is required before you can Time In. Please allow location access and try again."
                        : ($locationPolicy['error'] !== '' ? $locationPolicy['error'] : "A precise GPS fix is required before you can Time In. Use a GPS-enabled phone/device, turn on device Location, and try again.");
                } else {
                    $now = date('H:i:s');
                    $todayShift = $result['todayShift'] ?: ($approvedSwap && $approvedSwap['shift_id'] ? $approvedSwap : null);
                    $shiftStart = $todayShift ? $todayShift['start_time'] : null;
                    $shiftId = $todayShift ? $todayShift['shift_id'] : null;
                    // Supervisor-approved late Time In requests are never
                    // deducted, regardless of how late the actual clock is.
                    $late_minutes = $result['canTimeInLate'] ? 0 : computeCashierLateMinutes($now, $shiftStart);
                    $addrToSave = $addr !== '' ? mb_substr($addr, 0, 255) : null;

                    try {
                        $stmt = $hrmsPdo->prepare("
                            INSERT INTO attendance (employee_id, date, time_in, status, late_minutes, shift_id, time_in_latitude, time_in_longitude, time_in_accuracy_meters, time_in_address)
                            VALUES (?, ?, ?, 'present', ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                                time_in = VALUES(time_in),
                                status = VALUES(status),
                                late_minutes = VALUES(late_minutes),
                                shift_id = VALUES(shift_id),
                                time_in_latitude = VALUES(time_in_latitude),
                                time_in_longitude = VALUES(time_in_longitude),
                                time_in_accuracy_meters = VALUES(time_in_accuracy_meters),
                                time_in_address = VALUES(time_in_address)
                        ");
                        $stmt->execute([$employee_id, $today, $now, $late_minutes, $shiftId, $lat, $lng, $accuracy === null ? null : (float) $accuracy, $addrToSave]);
                        $result['message'] = "You timed in at " . date('h:i A', strtotime($now)) . ".";
                    } catch (PDOException $e) {
                        error_log("processCashierSelfTimeInOut (time in) error: " . $e->getMessage());
                        $result['error'] = "Time In could not be recorded. Please try again.";
                    }
                }
            }
        } else { // self_time_out
            // Overnight shift (e.g. 10PM–6AM): Time In got recorded
            // under YESTERDAY's date, and it's now past midnight, so
            // $today alone won't find that row. Fall back to
            // yesterday's still-open record if it plausibly belongs
            // to tonight's shift — see getCashierOpenOvernightRecord().
            $openOvernightRow = empty($result['todayRow']) ? getCashierOpenOvernightRecord($hrmsPdo, $employee_id, $today) : null;
            $outRow = $result['todayRow'] ?: $openOvernightRow;
            $outDate = $result['todayRow'] ? $today : ($openOvernightRow['date'] ?? $today);

            if (!$outRow || empty($outRow['time_in'])) {
                $result['error'] = "You need to Time In first before you can Time Out.";
            } elseif (!empty($outRow['time_out'])) {
                $result['error'] = "You have already Timed Out today.";
            } elseif (!$openOvernightRow && $__effectiveShift && isCashierBeforeShiftStart($__effectiveShift['start_time'], date('H:i:s'))) {
                $result['error'] = "You Timed In early — Time Out isn't available yet. It opens once your shift actually starts at " . date('h:i A', strtotime($__effectiveShift['start_time'])) . ".";
            } else {
                try {
                    $now = date('H:i:s');
                    $hrmsPdo->prepare("UPDATE attendance SET time_out = ? WHERE employee_id = ? AND date = ?")
                        ->execute([$now, $employee_id, $outDate]);
                    // So the re-fetch right below picks up the record we
                    // just closed, even if it was dated yesterday.
                    $today = $outDate;
                    $result['message'] = "You timed out at " . date('h:i A', strtotime($now)) . ".";
                } catch (PDOException $e) {
                    error_log("processCashierSelfTimeInOut (time out) error: " . $e->getMessage());
                    $result['error'] = "Time Out could not be recorded. Please try again.";
                }
            }
        }

        if ($employee_id) {
            $stmt = $hrmsPdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND date = ?");
            $stmt->execute([$employee_id, $today]);
            $result['todayRow'] = $stmt->fetch() ?: null;
            $result['unfinishedRecord'] = getCashierUnfinishedAttendanceRecord($hrmsPdo, $employee_id, $trueToday);
            $result['lateRequestToday'] = getCashierLateTimeInRequestForDate($hrmsPdo, $employee_id, $trueToday);
            $result['canTimeInLate'] = !empty($result['lateRequestToday']) && $result['lateRequestToday']['status'] === 'approved';

            if (empty($result['todayRow']['time_in']) && $__effectiveShift) {
                $result['timeInWindowOpen'] = !isCashierBeforeTimeInWindow($__effectiveShift['start_time'], date('H:i:s'));
                $result['timeInOpensAt'] = getCashierTimeInWindowOpenTime($__effectiveShift['start_time']);
                $result['shiftEnded'] = cashierShiftHasEnded($__effectiveShift['end_time'], $__effectiveShift['start_time'], date('H:i:s'));
            } else {
                $result['timeInWindowOpen'] = true;
                $result['shiftEnded'] = false;
            }
            $result['shiftNotStartedYet'] = false;
            if ($__effectiveShift && !empty($result['todayRow']['time_in']) && empty($result['todayRow']['time_out'])) {
                $result['shiftNotStartedYet'] = isCashierBeforeShiftStart($__effectiveShift['start_time'], date('H:i:s'));
            }
        }
    }

    return $result;
}

// =====================================================
// CASHIER CLOCK-IN GATE — controls whether a cashier may sell
// ------------------------------------------------------
// Used by pos.php to enforce that a cashier can only sell/process
// orders while properly on the clock:
//   - NOT on their Day Off (unless HR approved a Shift Swap today)
//   - HAS Timed In today
//   - has NOT already Timed Out today
// Reuses the same rules as processCashierSelfTimeInOut() above so
// "Day Off" and "shift" always mean the same thing across the POS.
//
// Fail-OPEN (allow selling) if $hrmsPdo is unavailable or this
// cashier has no linked employee record — same philosophy as the
// rest of this file: HRMS being unreachable shouldn't take down
// core POS selling, and there is no attendance data to check anyway
// in that case.
// =====================================================
function getCashierClockGate($hrmsPdo, $employee_id)
{
    // 'code' lets callers (like pos.php) tell apart WHY selling is
    // blocked: '' when allowed, otherwise one of:
    //   'day_off'           — no shift/swap today, nothing to fix by Timing In
    //   'unfinished_record' — a previous day was never Timed Out; only a
    //                         Supervisor/HR correction fixes this, not the
    //                         cashier themself, so it's NOT fixable via the
    //                         Time In popup on this page
    //   'not_timed_in'      — has a shift but hasn't Timed In yet (fixable
    //                         right on the POS page via a Time In popup)
    //   'timed_out'         — already Timed Out today, nothing to fix
    $gate = ['allowed' => true, 'reason' => '', 'code' => ''];

    if (!$hrmsPdo || !$employee_id) {
        return $gate;
    }

    $today = date('Y-m-d');
    try {
        $todayShift = getCashierShiftForDate($hrmsPdo, $employee_id, $today);
        $approvedSwap = $todayShift ? null : getCashierApprovedShiftSwap($hrmsPdo, $employee_id, $today);

        if (!$todayShift && !$approvedSwap) {
            $gate['allowed'] = false;
            $gate['code'] = 'day_off';
            $gate['reason'] = "It's your Day Off today — you cannot sell or process orders. If you're covering another employee's shift today, please get a Shift Swap approved by HR first.";
            return $gate;
        }

        if (getCashierUnfinishedAttendanceRecord($hrmsPdo, $employee_id, $today)) {
            $gate['allowed'] = false;
            $gate['code'] = 'unfinished_record';
            $gate['reason'] = "You have an unfinished attendance record. Please contact your supervisor or HR to correct your previous Time Out.";
            return $gate;
        }

        $stmt = $hrmsPdo->prepare("SELECT time_in, time_out FROM attendance WHERE employee_id = ? AND date = ?");
        $stmt->execute([$employee_id, $today]);
        $row = $stmt->fetch();

        if (!$row || empty($row['time_in'])) {
            $gate['allowed'] = false;
            $gate['code'] = 'not_timed_in';
            $gate['reason'] = "You need to Time In first before you can sell or process orders.";
        } elseif (!empty($row['time_out'])) {
            $gate['allowed'] = false;
            $gate['code'] = 'timed_out';
            $gate['reason'] = "You've already Timed Out for today, so you can no longer sell or process orders. If your Time Out was a mistake, please inform HR/Admin.";
        }
    } catch (PDOException $e) {
        error_log("getCashierClockGate skipped: " . $e->getMessage());
        // Fail open on unexpected DB errors — don't block sales over
        // an unrelated query hiccup.
    }

    return $gate;
}

// Gets (or creates, with the default allocation) an employee's
// leave_credits row for a given leave type/year. Returns null for
// 'others' (not credit-tracked).
function getCashierLeaveCredit($hrmsPdo, $employee_id, $leave_type, $year)
{
    if (!$hrmsPdo || !in_array($leave_type, CASHIER_LEAVE_CREDIT_TYPES, true)) {
        return null;
    }
    try {
        $stmt = $hrmsPdo->prepare("SELECT * FROM leave_credits WHERE employee_id = ? AND leave_type = ? AND year = ?");
        $stmt->execute([$employee_id, $leave_type, $year]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
        $allocated = CASHIER_DEFAULT_LEAVE_ALLOCATIONS[$leave_type] ?? 0;
        $hrmsPdo->prepare("
            INSERT INTO leave_credits (employee_id, leave_type, year, allocated_days, used_days)
            VALUES (?, ?, ?, ?, 0)
        ")->execute([$employee_id, $leave_type, $year, $allocated]);

        $stmt = $hrmsPdo->prepare("SELECT * FROM leave_credits WHERE employee_id = ? AND leave_type = ? AND year = ?");
        $stmt->execute([$employee_id, $leave_type, $year]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("getCashierLeaveCredit skipped: " . $e->getMessage());
        return null;
    }
}

// All leave balances (sick/vacation/emergency) for an employee for
// a given year. Returns [] if $hrmsPdo is unavailable.
function getCashierLeaveBalancesForYear($hrmsPdo, $employee_id, $year)
{
    $balances = [];
    if (!$hrmsPdo || !$employee_id)
        return $balances;
    foreach (CASHIER_LEAVE_CREDIT_TYPES as $type) {
        $row = getCashierLeaveCredit($hrmsPdo, $employee_id, $type, $year);
        if (!$row)
            continue;
        $balances[$type] = [
            'allocated' => (float) $row['allocated_days'],
            'used' => (float) $row['used_days'],
            'remaining' => (float) $row['allocated_days'] - (float) $row['used_days'],
        ];
    }
    return $balances;
}

function cashierLeaveTypeLabel($type)
{
    $map = ['sick' => 'Sick Leave', 'vacation' => 'Vacation Leave', 'emergency' => 'Emergency Leave', 'others' => 'Others'];
    return $map[$type] ?? ucfirst($type);
}

// Handles the "Request Overtime" form submission from my_profile.php.
// Mirrors the validation used in HRMS/employee_dashboard.php
// (request_overtime action) so the rules stay consistent between both
// sides. The request lands in overtime_requests as 'pending' and is
// picked up by the cashier's Supervisor on HRMS/supervisor_overtime.php
// (approve_ot / reject_ot), same as OT logged from the HRMS side.
function processCashierOvertimeRequest($hrmsPdo, $employee_id)
{
    $result = ['message' => '', 'error' => ''];
    if (($_POST['action'] ?? '') !== 'request_overtime' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $result;
    }

    requireCSRF();

    if (!$hrmsPdo) {
        $result['error'] = "HRMS database is unavailable right now, so the overtime request could not be submitted.";
        return $result;
    }

    $ot_date = trim($_POST['ot_date'] ?? '');
    $hours = trim($_POST['hours_requested'] ?? '');
    $reason = trim($_POST['ot_reason'] ?? '');
    $today = date('Y-m-d');

    if (!$employee_id) {
        $result['error'] = "No employee record is linked to your account.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ot_date)) {
        $result['error'] = "Please enter a valid date.";
    } elseif ($ot_date > $today) {
        $result['error'] = "Overtime date cannot be in the future — request it once the shift has happened, or on the day itself.";
    } elseif (!is_numeric($hours) || (float) $hours <= 0 || (float) $hours > 12) {
        $result['error'] = "Please enter a valid number of hours (up to 12).";
    } elseif (mb_strlen($reason) < 3) {
        $result['error'] = "Please enter a reason for the overtime.";
    } else {
        try {
            $stmt = $hrmsPdo->prepare("
                INSERT INTO overtime_requests (employee_id, ot_date, hours_requested, reason, status, requested_at)
                VALUES (?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([$employee_id, $ot_date, (float) $hours, $reason]);
            $result['message'] = "Your overtime request has been submitted. Just wait for approval from your Supervisor.";
        } catch (PDOException $e) {
            error_log("processCashierOvertimeRequest error: " . $e->getMessage());
            $result['error'] = "The overtime request could not be submitted. Please try again.";
        }
    }

    return $result;
}

// Handles the "Request Leave" form submission from my_profile.php.
// Returns ['message'=>, 'error'=>]. Mirrors the validation used in
// HRMS/employee_dashboard.php (request_leave action) so the rules
// stay consistent between both sides.
function processCashierLeaveRequest($hrmsPdo, $employee_id)
{
    $result = ['message' => '', 'error' => ''];
    if (($_POST['action'] ?? '') !== 'request_leave' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $result;
    }

    requireCSRF();

    if (!$hrmsPdo) {
        $result['error'] = "HRMS database is unavailable right now, so the leave request could not be submitted.";
        return $result;
    }

    $leave_type = trim($_POST['leave_type'] ?? '');
    $date_from = trim($_POST['date_from'] ?? '');
    $date_to = trim($_POST['date_to'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $today = date('Y-m-d');
    $valid_types = ['sick', 'vacation', 'emergency', 'others'];

    if (!$employee_id) {
        $result['error'] = "No employee record is linked to your account.";
    } elseif (!in_array($leave_type, $valid_types, true)) {
        $result['error'] = "Please select a valid leave type.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $result['error'] = "Please enter a valid date.";
    } elseif ($date_from < $today) {
        $result['error'] = "You cannot request leave for a past date.";
    } elseif ($date_to < $date_from) {
        $result['error'] = "The 'To' date must come after or be the same as the 'From' date.";
    } elseif (mb_strlen($reason) < 3) {
        $result['error'] = "Please enter a reason for your leave.";
    } else {
        $requested_days = (strtotime($date_to) - strtotime($date_from)) / 86400 + 1;
        $leave_year = (int) date('Y', strtotime($date_from));
        $remaining_days = null;

        if (in_array($leave_type, CASHIER_LEAVE_TYPES_WITH_LIMIT, true)) {
            $credit = getCashierLeaveCredit($hrmsPdo, $employee_id, $leave_type, $leave_year);
            if ($credit) {
                $remaining_days = (float) $credit['allocated_days'] - (float) $credit['used_days'];
            }
        }

        $cooldown_error = ($leave_type === 'vacation')
            ? checkCashierVacationLeaveCooldown($hrmsPdo, $employee_id, $date_from, $date_to)
            : null;

        if ($cooldown_error === null && $leave_type === 'vacation') {
            $cooldown_error = checkCashierVacationLeaveRequestCooldown($hrmsPdo, $employee_id);
        }

        if ($remaining_days !== null && $requested_days > $remaining_days) {
            $result['error'] = "Cannot be submitted: you're requesting {$requested_days} day(s) of "
                . cashierLeaveTypeLabel($leave_type) . ", but you only have {$remaining_days} day(s) remaining in your balance for "
                . $leave_year . ".";
        } elseif ($cooldown_error !== null) {
            $result['error'] = $cooldown_error;
        } else {
            try {
                $stmt = $hrmsPdo->prepare("
                    INSERT INTO leave_requests (employee_id, leave_type, date_from, date_to, reason, status, requested_at)
                    VALUES (?, ?, ?, ?, ?, 'pending', NOW())
                ");
                $stmt->execute([$employee_id, $leave_type, $date_from, $date_to, $reason]);
                $result['message'] = "Your leave request has been submitted. Just wait for approval from HR/Admin.";
            } catch (PDOException $e) {
                error_log("processCashierLeaveRequest error: " . $e->getMessage());
                $result['error'] = "The leave request could not be submitted. Please try again.";
            }
        }
    }

    return $result;
}
