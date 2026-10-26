<?php

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit;
}
if (!defined('APP_LOADED')) {
    define('APP_LOADED', true);
}

// ============================================================
// APPLICANT PORTAL LINK (used inside emails to applicants)
// ------------------------------------------------------------
// 'localhost' only works on THIS computer, so a link built from it
// is useless (and often spam-flagged) once it's inside an email
// opened on someone else's phone/PC. To fix:
//   1. While testing: download ngrok (ngrok.com/download), run
//      "ngrok http 80" in a terminal (leave it running), then copy
//      the https://xxxx.ngrok-free.app link it shows you.
//   2. Once really deployed: use your real domain instead.
//   3. Paste that link below (inside the quotes) and remove the
//      "//" at the start of the define() line so it's no longer a
//      comment. Save this file.
//   4. Open/use the system through THAT link from then on (not
//      localhost), so applicant emails carry a working address.
// ------------------------------------------------------------
// define('APP_PUBLIC_BASE_URL', 'http://192.168.18.57');
// ============================================================

// ========================================================
// config.php — HRMS & Payroll module
// ========================================================

// Set the timezone before any date()/time() function is called.
// Without this, PHP defaults to UTC (or the server's timezone),
// which would show/record the wrong time (e.g. Time In / Time Out
// on the Quick Action buttons).
date_default_timezone_set('Asia/Manila');

// Shared "Contact Number" country-selector + exact-digit-length
// validation (renderPhoneFieldInputs / validatePhoneNumber /
// formatPhoneForStorage) — the SAME file the POS side uses, so
// suppliers, employees, and job applicants are all validated the
// same way. See ../../includes/phone_countries.php.
require_once __DIR__ . '/../../includes/phone_countries.php';

// Load DB credentials + APP_ENV from the same project-root .env the POS
// side uses (see includes/env.php and .env.example). Falls back to the
// previous root/no-password XAMPP defaults if no .env file exists yet.
require_once __DIR__ . '/../../includes/env.php';
loadEnv(__DIR__ . '/../../.env');

// Attendance location policy. Keep exact mode for production; approximate
// mode is an explicit desktop fallback and preserves the reported accuracy.
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

// ID encryption/obfuscation helper (enc_id() / dec_id()) — the SAME
// file the POS side uses (require_once'd from one shared location so
// both apps always encrypt/decrypt IDs with the same key). See
// ../../includes/id_crypt.php for the full explanation.
require_once __DIR__ . '/../../includes/id_crypt.php';

// =====================================================
// PHP ERROR DISPLAY (Item 3 — Phase 1 security audit)
// ------------------------------------------------------
// Same reasoning as the POS side's includes/config.php: previously
// nothing here explicitly controlled display_errors, so it depended on
// the machine's php.ini default. APP_ENV=local (see .env) keeps errors
// visible for development; anything else (including it being unset)
// hides them from the browser, which is the safer default.
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
header('Permissions-Policy: camera=(), microphone=(), geolocation=(self)');

$host = env('DB_HOST', 'localhost');
$username = env('DB_USERNAME', '');
$password = env('DB_PASSWORD', '');
$database = env('DB_NAME_HRMS', 'hrms');

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
    // Was `catch (PDOException $e)` — too narrow. A missing/disabled
    // `pdo_mysql` extension throws an \Error (not a PDOException), which
    // slipped past this catch and crashed as a blank HTTP 500 instead of
    // showing this message. Widening to \Throwable catches both cases.
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

// Keep 'absent' auto-marking caught up on every page load (see
// autoMarkPastAbsences() below — it was defined but never actually
// called anywhere, so past days with no Time In were never being
// written to the `attendance` table as 'absent'). This is cheap to
// call repeatedly: it tracks the last date it already processed via
// app_settings and returns immediately once caught up, so it only
// does real work once per day across all page loads.
autoMarkPastAbsences($pdo);

// =====================================================
// POS DATABASE CONNECTION (for POS <-> HRMS integration)
// ------------------------------------------------------
// Used to read a handful of POS figures (today's sales, today's
// transaction count, low stock alerts) for the Admin Dashboard's
// "POS Snapshot" panel — Admin is the only role that needs this,
// since Admin already oversees both systems. HR Staff/HR Manager
// dashboards don't use this (their work doesn't involve sales or
// inventory). If the `grocery_pos` database can't be reached
// (e.g. POS isn't set up on this machine), $posPdo stays null and
// the Admin Dashboard just hides the POS Snapshot panel — nothing
// else in HRMS depends on this connection.
// =====================================================
$posPdo = null;
try {
    $posPdo = new PDO(
        "mysql:host=$host;port=" . (int) env('DB_PORT', 3306) . ";dbname=" . env('DB_NAME_POS', 'grocery_pos') . ";charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_TIMEOUT => 3]
    );
    $posPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $posPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Widened from `catch (PDOException $e)` — see the matching note on
    // the main $pdo connection above. Kept fail-open (null, no die()).
    error_log("POS database connection failed (POS Snapshot panel will be unavailable): " . $e->getMessage());
    $posPdo = null;
}

// =====================================================
// SESSION COOKIE HARDENING
// =====================================================
// Previously: there was no explicit setting here, so the session
// cookie's HttpOnly/Secure/SameSite depended entirely on the
// server's php.ini default — not always reliable, especially on
// shared/unknown hosting. This is now set explicitly here so that:
//   - HttpOnly: JavaScript can't reach the cookie (reduces the
//     impact of XSS if any slips through)
//   - Secure: the cookie flag is only set when HTTPS is active (so
//     the session ID isn't broadcast over plain HTTP)
//   - SameSite=Lax: extra protection against some CSRF patterns
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

// Buffer all output from this point on. Some functions further down
// (e.g. requireLogin()'s session-watcher <script>) echo HTML directly
// as a side effect of an auth check, even on AJAX/JSON endpoints. If
// that HTML lands on the wire before jsonResponse() sends its JSON,
// the response body becomes "<script>...</script>{...json...}" —
// invalid JSON that breaks fetch()'s r.json() on the frontend (shows
// up as a false "Network error"). Buffering lets jsonResponse() wipe
// out any such stray output right before it sends the real response.
ob_start();

// =====================================================
// Make sure the `payroll_records` table has a `payout_date` column
// (idempotent, same as what's done in attendance.php for the
// `encoded_by` column — if the table is older than when this column
// was added, CREATE TABLE won't add it, so we handle it here instead
// so no one needs to go into phpMyAdmin).
// =====================================================
function ensurePayoutDateColumn($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'payroll_records'
              AND column_name = 'payout_date'
        ");
        $stmt->execute();
        if (((int) $stmt->fetchColumn()) === 0) {
            $pdo->exec("ALTER TABLE payroll_records ADD COLUMN payout_date DATE NULL AFTER pay_period_end");
        }
    } catch (PDOException $e) {
        // No payroll_records table yet (fresh install) — nothing to do,
        // this will just be handled by another query's error_log if needed.
        error_log("ensurePayoutDateColumn check skipped: " . $e->getMessage());
    }
}

// =====================================================
// MIGRATION GATE (Item 9 — Phase 3 reliability audit)
// ------------------------------------------------------
// Every ensureXTable()/ensureXColumn() call below is individually
// idempotent (CREATE TABLE IF NOT EXISTS, or SHOW COLUMNS/information_
// schema check then ALTER), so running them is always *safe* — but
// running 40+ of them on every single page load (each a round-trip
// query) adds real per-request overhead as traffic grows, and a few of
// the ALTER TABLE ones carry a small risk of colliding if two requests
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
// SUPERVISOR ROLE — SCHEMA (auto-migrated, same idempotent
// pattern as ensurePayoutDateColumn() above, so nobody needs to
// touch phpMyAdmin to get this feature).
//
// Adds:
//   - 'supervisor' to users.role
//   - supervisor_teams        (which employees each Supervisor manages)
//   - overtime_requests       (employee OT requests + Supervisor decision)
//   - performance_records     (Supervisor performance notes/ratings for their team)
// The Inventory Request table lives on the POS side (grocery_pos db)
// since that's where the rest of inventory data already lives — see
// ensureInventoryRequestsTable() further below, run against $posPdo.
// =====================================================
function ensureSupervisorRoleEnum($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COLUMN_TYPE FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'role'
        ");
        $stmt->execute();
        $columnType = (string) $stmt->fetchColumn();
        if ($columnType !== '' && (strpos($columnType, "'supervisor'") === false || strpos($columnType, "'accountant'") === false)) {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','hr','hr_manager','supervisor','accountant','employee') NOT NULL DEFAULT 'employee'");
        }
    } catch (PDOException $e) {
        error_log("ensureSupervisorRoleEnum check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureSupervisorRoleEnum($pdo);
}

function ensureAccountantRoleEnum($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COLUMN_TYPE FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'role'
        ");
        $stmt->execute();
        $columnType = (string) $stmt->fetchColumn();
        if ($columnType !== '' && strpos($columnType, "'accountant'") === false) {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','hr','hr_manager','supervisor','accountant','employee') NOT NULL DEFAULT 'employee'");
        }
    } catch (PDOException $e) {
        error_log("ensureAccountantRoleEnum check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureAccountantRoleEnum($pdo);
}

function ensureSupervisorTeamsTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS supervisor_teams (
                id int(11) NOT NULL AUTO_INCREMENT,
                supervisor_employee_id int(11) NOT NULL,
                member_employee_id int(11) NOT NULL,
                assigned_by int(11) DEFAULT NULL,
                assigned_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (id),
                UNIQUE KEY uniq_member (member_employee_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureSupervisorTeamsTable skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureSupervisorTeamsTable($pdo);
}

function ensureOvertimeRequestsTable($pdo)
{
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
        error_log("ensureOvertimeRequestsTable skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureOvertimeRequestsTable($pdo);
}

// =====================================================
// OFFICIAL BUSINESS (OB) REQUESTS — Item 12 (Phase 4 HRMS audit)
// =====================================================
// Mirrors the overtime_requests table above (same idempotent
// CREATE TABLE IF NOT EXISTS pattern, same pending/approved/rejected
// status flow, same approved_by/decided_at audit fields).
//
// OB is for an employee sent out on company business during work
// hours (e.g. bank errand, supplier pickup, meeting off-site) — NOT
// a leave (they're still working, just not on-site) and NOT
// automatically overtime (that's still requested separately via
// overtime_requests if they end up working past their shift because
// of it). Once approved, an OB day counts as PRESENT for payroll
// (see computePayrollAttendanceSummary()'s $paid union below) without
// requiring an actual attendance time_in/time_out punch that day.
//
// ob_latitude/ob_longitude/proof_file are schema-ready (nullable) for
// optional GPS tagging or an uploaded supporting document, matching
// the "optional GPS data, optional proof document" scope in the
// audit brief — no upload/GPS-capture UI is wired to them yet, since
// that wasn't part of what was confirmed with the business. Flagged
// separately below.
function ensureOfficialBusinessRequestsTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS official_business_requests (
                ob_id int(11) NOT NULL AUTO_INCREMENT,
                employee_id int(11) NOT NULL,
                ob_date date NOT NULL,
                time_from time DEFAULT NULL,
                time_to time DEFAULT NULL,
                purpose varchar(255) NOT NULL,
                destination varchar(255) DEFAULT NULL,
                status enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                approved_by int(11) DEFAULT NULL,
                decided_at timestamp NULL DEFAULT NULL,
                requested_at timestamp NOT NULL DEFAULT current_timestamp(),
                ob_latitude decimal(10,7) DEFAULT NULL,
                ob_longitude decimal(10,7) DEFAULT NULL,
                proof_file varchar(255) DEFAULT NULL,
                PRIMARY KEY (ob_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureOfficialBusinessRequestsTable skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureOfficialBusinessRequestsTable($pdo);
}

// =====================================================
// LATE TIME IN REQUESTS
// =====================================================
// Once an employee's shift for today has already ended and they
// never Timed In, Time In closes automatically (see
// processSelfTimeInOut()). To still be able to Time In (e.g. they
// were running late and want to come in anyway), they submit a
// request here with the time they'd like to Time In and a reason.
// It shows up on their Supervisor's "Time In Requests" page
// (supervisor_late_timein.php) — only once approved does Time In
// re-open for them for that day.
function ensureLateTimeInRequestsTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS late_timein_requests (
                request_id int(11) NOT NULL AUTO_INCREMENT,
                employee_id int(11) NOT NULL,
                request_date date NOT NULL,
                requested_time time NOT NULL,
                requested_shift_id int(11) DEFAULT NULL,
                reason text DEFAULT NULL,
                status enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                approved_by int(11) DEFAULT NULL,
                decided_at timestamp NULL DEFAULT NULL,
                requested_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (request_id),
                KEY idx_employee_date (employee_id, request_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        // Column may not exist yet on installs created before this
        // migration — requested_time now always mirrors an actual
        // defined shift's start_time (employees pick a shift, not a
        // free-typed time), so we keep which shift they picked too.
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'late_timein_requests' AND column_name = 'requested_shift_id'
        ");
        if (((int) $stmt->fetchColumn()) === 0) {
            $pdo->exec("ALTER TABLE late_timein_requests ADD COLUMN requested_shift_id int(11) DEFAULT NULL AFTER requested_time");
        }
    } catch (PDOException $e) {
        error_log("ensureLateTimeInRequestsTable skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureLateTimeInRequestsTable($pdo);
}

// =====================================================
// DISCOUNT APPROVAL CODES (Supervisor -> Cashier, for Senior/PWD
// discounts in the POS)
// ------------------------------------------------------
// A Supervisor generates a one-time, short-lived numeric code from
// their own device (this dashboard, or the POS-side mirror of this
// table via $hrmsPdo) and reads it out loud to the cashier. The
// cashier types it into the Senior/PWD discount popup on pos.php;
// the code is consumed (single use) and tied to whichever Supervisor
// generated it, giving an audit trail of who actually approved the
// discount — without requiring the Supervisor to physically log in
// on the cashier's terminal every time (unlike "Supervisor Time In
// Now"). Any ACTIVE Supervisor's code works for ANY cashier — this
// is a discount-floor approval, not a per-team assignment like
// supervisor_teams.
// =====================================================
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

        // SELF-HEALING: if this table already existed from an earlier
        // / partial version of the feature, CREATE TABLE IF NOT EXISTS
        // above is a no-op — any columns that older version didn't
        // have yet would still be missing, which makes every INSERT
        // in generate_discount_code.php fail (shows up to the
        // Supervisor as "Could not generate a code right now.").
        // Check the table's actual columns and add whatever's
        // missing, so an old/partial table gets brought up to the
        // current shape automatically instead of silently staying broken.
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

        // Also make sure `status` actually allows 'cancelled' — an
        // earlier version of this table may have only had
        // ('active','used'), which would make the "invalidate my old
        // codes" UPDATE (status = 'cancelled') fail under strict SQL mode.
        $statusCol = $pdo->query("SHOW COLUMNS FROM discount_approval_codes LIKE 'status'")->fetch();
        if ($statusCol && strpos($statusCol['Type'], "'cancelled'") === false) {
            $pdo->exec("ALTER TABLE discount_approval_codes MODIFY status ENUM('active','used','cancelled') NOT NULL DEFAULT 'active'");
        }
    } catch (PDOException $e) {
        error_log("ensureDiscountApprovalCodesTable skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureDiscountApprovalCodesTable($pdo);
}

// Most recent Time In request (any status) an employee has made for a
// given date — used to decide what the "My Attendance Today" card
// should show once the shift has ended (pending / approved /
// rejected / none yet).
function getLateTimeInRequestForDate($pdo, $employee_id, $date)
{
    if (!$employee_id)
        return null;
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, s.shift_name AS requested_shift_name
            FROM late_timein_requests r
            LEFT JOIN shifts s ON s.shift_id = r.requested_shift_id
            WHERE r.employee_id = ? AND r.request_date = ?
            ORDER BY r.requested_at DESC LIMIT 1
        ");
        $stmt->execute([$employee_id, $date]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("getLateTimeInRequestForDate error: " . $e->getMessage());
        return null;
    }
}

// Whether $shiftEndTime (H:i:s) has already passed for $nowTime
// (H:i:s), on the same calendar day as $shiftStartTime. Handles
// shifts that cross midnight (end time earlier than start time).
function shiftHasEnded($shiftEndTime, $shiftStartTime, $nowTime)
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

// Kabaligtaran naman ito ng shiftHasEnded — kailan BUBUKAS ang Time
// In bago pa man magsimula ang shift (H:i:s). Halimbawa, 6:00 AM ang
// simula ng shift at EARLY_TIMEIN_WINDOW_MINUTES = 60, 5:00 AM pa
// lang bubukas ang Time In — hindi dapat puwedeng mag-Time In nang,
// say, 2:00 AM pa lamang.
function getTimeInWindowOpenTime($shiftStartTime, $earlyWindowMinutes = null)
{
    $earlyWindowMinutes = $earlyWindowMinutes ?? EARLY_TIMEIN_WINDOW_MINUTES;
    $start = strtotime('1970-01-01 ' . $shiftStartTime);
    if ($start === false)
        return $shiftStartTime;
    return date('H:i:s', $start - ($earlyWindowMinutes * 60));
}

// True kung SARADO pa ang Time In dahil masyadong maaga pa
// ($nowTime bago pa man sumapit ang getTimeInWindowOpenTime()).
function isBeforeTimeInWindow($shiftStartTime, $nowTime, $earlyWindowMinutes = null)
{
    $windowOpen = getTimeInWindowOpenTime($shiftStartTime, $earlyWindowMinutes);
    $open = strtotime('1970-01-01 ' . $windowOpen);
    $now = strtotime('1970-01-01 ' . $nowTime);
    if ($open === false || $now === false)
        return false;
    // Kung "negative" ang window open time (hal. shift start 12:30 AM
    // minus 60 minutes), ituring nasa nakaraang araw ito — huwag nang
    // gawing bara, dahil malabong may Time In na ganoon kaaga talaga.
    if ($open < 0)
        return false;
    return $now < $open;
}

// True kung masyado pang maaga para mag-Time Out — bago pa man
// talaga magsimula ang shift mismo (hindi yung early window, kundi
// yung actual start_time). Ginagamit para hindi puwedeng mag-Time In
// nang maaga (sa loob ng early window) tapos agad-agad mag-Time Out
// bago pa man talaga sumapit ang shift.
function isBeforeShiftStart($shiftStartTime, $nowTime)
{
    $start = strtotime('1970-01-01 ' . $shiftStartTime);
    $now = strtotime('1970-01-01 ' . $nowTime);
    if ($start === false || $now === false)
        return false;
    return $now < $start;
}

function ensurePerformanceRecordsTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS performance_records (
                perf_id int(11) NOT NULL AUTO_INCREMENT,
                employee_id int(11) NOT NULL,
                rated_by int(11) NOT NULL,
                period_label varchar(40) NOT NULL,
                record_type ENUM('merit','demerit') NOT NULL DEFAULT 'merit',
                rating tinyint(1) NOT NULL,
                comments text DEFAULT NULL,
                created_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (perf_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensurePerformanceRecordsTable skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensurePerformanceRecordsTable($pdo);
}

// Adds the record_type column (merit/demerit) to an existing
// performance_records table from an older install that doesn't have it yet.
function ensurePerformanceRecordTypeColumn($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'performance_records' AND column_name = 'record_type'
        ");
        $stmt->execute();
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE performance_records ADD COLUMN record_type ENUM('merit','demerit') NOT NULL DEFAULT 'merit' AFTER rated_by");
        }
    } catch (PDOException $e) {
        error_log("ensurePerformanceRecordTypeColumn skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensurePerformanceRecordTypeColumn($pdo);
}

// The POS side's `notifications` table (grocery_pos db) was stock-alerts
// only. Adds a `category` column so Merit/Demerit records raised here in
// HRMS can share that same table — 'stock' for the existing low-stock
// alerts, 'performance' for these. Runs against $posPdo since that's
// where the table lives; a no-op if POS isn't set up on this machine.
function ensurePosNotificationsCategoryColumn($posPdo)
{
    if (!$posPdo)
        return;
    try {
        $stmt = $posPdo->query("SHOW COLUMNS FROM notifications LIKE 'category'");
        if (!$stmt->fetch()) {
            $posPdo->exec("ALTER TABLE notifications ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'stock' AFTER product_id");
        }
        // product_id is only meaningful for stock alerts — make sure it
        // can be left NULL for performance notifications.
        $posPdo->exec("ALTER TABLE notifications MODIFY product_id INT NULL");
    } catch (PDOException $e) {
        error_log("ensurePosNotificationsCategoryColumn skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensurePosNotificationsCategoryColumn($posPdo);
}

// Raises a shared notification (grocery_pos.notifications, category
// 'performance') whenever a Supervisor adds a Merit/Demerit record, so
// Inventory Staff and Admin see it on the POS Notifications page, and
// HR Staff/HR Manager/Admin see it via hr_notifications.php on this side.
// Silently does nothing if the POS database isn't reachable.
function notifyPerformanceRecord($posPdo, $employeeName, $recordType, $points, $supervisorName)
{
    if (!$posPdo)
        return;
    try {
        $verb = $recordType === 'demerit' ? 'Demerit' : 'Merit';
        $message = "{$verb} recorded for {$employeeName}: {$points} pt" . ($points > 1 ? 's' : '') . " (by {$supervisorName}).";
        $posPdo->prepare("INSERT INTO notifications (product_id, category, message) VALUES (NULL, 'performance', ?)")
            ->execute([$message]);
    } catch (PDOException $e) {
        error_log("notifyPerformanceRecord skipped: " . $e->getMessage());
    }
}

// Raises a shared notification (grocery_pos.notifications, category
// 'applicant') whenever an applicant submits a requirement through
// their self-service portal link (requirements_submit.php), so HR
// Staff/HR Manager/Admin see it on hr_notifications.php without
// having to keep re-checking applicant_review.php manually.
// Silently does nothing if the POS database isn't reachable.
function notifyApplicantRequirementSubmitted($posPdo, $applicantName, $position, $labels)
{
    if (!$posPdo)
        return;
    try {
        $what = (count($labels) === 1)
            ? $labels[0]
            : count($labels) . ' document(s) (' . implode(', ', $labels) . ')';
        $message = "{$applicantName} ({$position}) submitted {$what} for review.";
        $posPdo->prepare("INSERT INTO notifications (product_id, category, message) VALUES (NULL, 'applicant', ?)")
            ->execute([$message]);
    } catch (PDOException $e) {
        error_log("notifyApplicantRequirementSubmitted skipped: " . $e->getMessage());
    }
}

// Inventory Requests live in the POS (grocery_pos) database — a
// Supervisor's request is fulfilled by whoever runs Inventory there,
// so it belongs next to `products`/`product_batches`, not in HRMS.
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
                status enum('pending_validation','pending_finance','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending_validation',
                decided_by int(11) DEFAULT NULL,
                decided_at timestamp NULL DEFAULT NULL,
                received_by int(11) DEFAULT NULL,
                received_at timestamp NULL DEFAULT NULL,
                requested_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureInventoryRequestsTable skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureInventoryRequestsTable($posPdo);
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
        error_log("ensurePasswordResetRequestsTable (HRMS) skipped: " . $e->getMessage());
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
    ensurePasswordResetRequestsTable($posPdo);
}

// Mirrors ensureInventoryRequestFinanceStatusEnum() in the POS side's
// includes/config.php — widens the status enum on databases created
// before the Finance approval gate existed. See that copy for the
// full explanation.
function ensureInventoryRequestFinanceStatusEnum($posPdo)
{
    if (!$posPdo)
        return;
    try {
        $stmt = $posPdo->query("SHOW COLUMNS FROM inventory_requests LIKE 'status'");
        $col = $stmt->fetch();
        if ($col && stripos($col['Type'], 'pending_finance') === false) {
            $posPdo->exec("ALTER TABLE inventory_requests MODIFY status ENUM('pending_finance','pending','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending_finance'");
        }
    } catch (PDOException $e) {
        error_log("ensureInventoryRequestFinanceStatusEnum skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureInventoryRequestFinanceStatusEnum($posPdo);
}

// Mirrors ensureInventoryRequestValidationWorkflow() in the POS side's
// includes/config.php — adds the Inventory-Staff-validates-before-
// Finance workflow (restock workflow v2, confirmed Aug 2026). See that
// copy for the full explanation.
function ensureInventoryRequestValidationWorkflow($posPdo)
{
    if (!$posPdo)
        return;
    try {
        $stmt = $posPdo->query("SHOW COLUMNS FROM inventory_requests LIKE 'status'");
        $col = $stmt->fetch();
        if ($col && stripos($col['Type'], 'pending_validation') === false) {
            $posPdo->exec("ALTER TABLE inventory_requests MODIFY status ENUM('pending_finance','pending','pending_validation','pending_supplier','ready_to_receive','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending_validation'");
            $posPdo->exec("UPDATE inventory_requests SET status = 'pending_validation' WHERE status = 'pending_finance'");
            $posPdo->exec("UPDATE inventory_requests SET status = 'approved' WHERE status = 'pending'");
            $posPdo->exec("ALTER TABLE inventory_requests MODIFY status ENUM('pending_validation','pending_finance','pending_supplier','ready_to_receive','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending_validation'");
        }

        $existingCols = $posPdo->query("SHOW COLUMNS FROM inventory_requests")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('estimated_cost', $existingCols, true)) {
            $posPdo->exec("ALTER TABLE inventory_requests ADD COLUMN estimated_cost decimal(10,2) DEFAULT NULL AFTER quantity");
        }
        if (!in_array('validated_by', $existingCols, true)) {
            $posPdo->exec("ALTER TABLE inventory_requests ADD COLUMN validated_by int(11) DEFAULT NULL AFTER estimated_cost");
        }
        if (!in_array('validated_at', $existingCols, true)) {
            $posPdo->exec("ALTER TABLE inventory_requests ADD COLUMN validated_at timestamp NULL DEFAULT NULL AFTER validated_by");
        }
        if (!in_array('received_by', $existingCols, true)) {
            $posPdo->exec("ALTER TABLE inventory_requests ADD COLUMN received_by int(11) DEFAULT NULL AFTER decided_at");
        }
        if (!in_array('received_at', $existingCols, true)) {
            $posPdo->exec("ALTER TABLE inventory_requests ADD COLUMN received_at timestamp NULL DEFAULT NULL AFTER received_by");
        }
        if (!in_array('finance_signature', $existingCols, true)) {
            $posPdo->exec("ALTER TABLE inventory_requests ADD COLUMN finance_signature LONGTEXT NULL AFTER decided_at");
        }
        if (!in_array('finance_signature_at', $existingCols, true)) {
            $posPdo->exec("ALTER TABLE inventory_requests ADD COLUMN finance_signature_at DATETIME NULL AFTER finance_signature");
        }
        foreach ([
            'requester_full_name' => "VARCHAR(150) NULL",
            'requester_signature' => "LONGTEXT NULL",
            'requester_signature_at' => "DATETIME NULL",
            'procurement_letter' => "LONGTEXT NULL",
            'supplier_approval_token_hash' => "CHAR(64) NULL",
            'supplier_approval_expires_at' => "DATETIME NULL",
            'supplier_signature' => "LONGTEXT NULL",
            'supplier_signature_at' => "DATETIME NULL",
        ] as $name => $definition) {
            if (!in_array($name, $existingCols, true))
                $posPdo->exec("ALTER TABLE inventory_requests ADD COLUMN {$name} {$definition}");
        }
        $posPdo->exec("ALTER TABLE inventory_requests MODIFY status ENUM('pending_validation','pending_finance','pending_supplier','ready_to_receive','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending_validation'");
    } catch (PDOException $e) {
        error_log("ensureInventoryRequestValidationWorkflow skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureInventoryRequestValidationWorkflow($posPdo);
}
if ($__migrationsNeeded) {
    ensurePayoutDateColumn($pdo);
}

// Mirrors ensureStockLogsValidationColumns() in the POS side's
// includes/config.php — adds stock_logs.validated_by / validated_at
// so a Supervisor can mark restock/adjustment/issuance entries as
// reviewed from the Inventory Monitoring page. See that copy for the
// full explanation.
function ensureStockLogsValidationColumns($posPdo)
{
    if (!$posPdo)
        return;
    try {
        $stmt = $posPdo->query("SHOW COLUMNS FROM stock_logs LIKE 'validated_by'");
        if (!$stmt->fetch()) {
            $posPdo->exec("ALTER TABLE stock_logs ADD COLUMN validated_by INT NULL DEFAULT NULL, ADD COLUMN validated_at TIMESTAMP NULL DEFAULT NULL");
        }
    } catch (PDOException $e) {
        error_log("ensureStockLogsValidationColumns skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureStockLogsValidationColumns($posPdo);
}

// =====================================================
// PAYROLL APPROVAL WORKFLOW (HR Staff/HR Manager -> Accountant -> Finance)
// =====================================================
// Before: only Admin could process payroll for their fellow HR
// Staff/HR Manager, and the normal payroll for rank-and-file
// Employees made by HR Staff/HR Manager was released directly with
// no further review. HR-submitted batches now require Accountant
// approval; the separate Finance release records payment and triggers
// the employee notification. Admin-generated batches are approved by
// Admin but still require Finance release. So 3 columns are added here to
// `payroll_records` (idempotent, same as ensurePayoutDateColumn
// above):
//   - approval_status : 'draft'   (just an auto-save while HR
//                        Staff/Manager is still typing — not yet
//                        hidden until submitted to Finance/Admin),
//                        'n/a'    (legacy no-approval state, migrated
//                        to 'approved' for Finance release),
//                        'pending' (HR Staff/Manager already clicked
//                        "Submit for Approval" — waiting on Accountant's
//                        decision — see
//                        payroll/submit_for_approval.php),
//                        'approved', or 'rejected'
//   - approved_by      : user id of the Admin/Accountant who approved
//   - approved_at      : when it was approved/rejected
function ensurePayrollApprovalColumns($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'payroll_records'
              AND column_name = 'approval_status'
        ");
        $stmt->execute();
        if (((int) $stmt->fetchColumn()) === 0) {
            $pdo->exec("
                ALTER TABLE payroll_records
                ADD COLUMN approval_status ENUM('draft','n/a','pending','approved','rejected') NOT NULL DEFAULT 'n/a' AFTER net_pay,
                ADD COLUMN approved_by INT NULL DEFAULT NULL AFTER approval_status,
                ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL AFTER approved_by
            ");
        } else {
            // Column already exists (an older install from before the
            // 'draft' state was added) — make sure 'draft' is included
            // in the ENUM; this is idempotent too (no effect if it's
            // already there).
            $colStmt = $pdo->prepare("
                SELECT COLUMN_TYPE FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'payroll_records'
                  AND column_name = 'approval_status'
            ");
            $colStmt->execute();
            $colType = (string) $colStmt->fetchColumn();
            if ($colType !== '' && strpos($colType, "'draft'") === false) {
                $pdo->exec("
                    ALTER TABLE payroll_records
                    MODIFY COLUMN approval_status ENUM('draft','n/a','pending','approved','rejected') NOT NULL DEFAULT 'n/a'
                ");
            }
        }
    } catch (PDOException $e) {
        error_log("ensurePayrollApprovalColumns check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensurePayrollApprovalColumns($pdo);
}

// =====================================================
// CASH (WALK-IN) SALARY RELEASE — acknowledgment columns
// =====================================================
// For employees paid in cash (payment_method = 'cash' on `employees`
// — see ensureEmployeePaymentMethodColumn below), there's no bank
// transfer to point to as proof the salary was actually given out.
// So instead, HR/Admin can upload a scanned signed receipt/acknowledgment
// once the payout date arrives, per payroll_records row (idempotent,
// same pattern as ensurePayoutDateColumn above):
//   - cash_ack_file          : stored filename on disk (uploads/cash_ack/)
//   - cash_ack_original_name : original filename, for display/download
//   - cash_ack_note          : optional short note from whoever uploaded it
//   - cash_ack_uploaded_by   : user id who uploaded it
//   - cash_ack_uploaded_at   : when it was uploaded
function ensurePayrollCashAckColumns($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'payroll_records'
              AND column_name = 'cash_ack_file'
        ");
        $stmt->execute();
        if (((int) $stmt->fetchColumn()) === 0) {
            $pdo->exec("
                ALTER TABLE payroll_records
                ADD COLUMN cash_ack_file VARCHAR(255) NULL DEFAULT NULL AFTER approved_at,
                ADD COLUMN cash_ack_original_name VARCHAR(255) NULL DEFAULT NULL AFTER cash_ack_file,
                ADD COLUMN cash_ack_note VARCHAR(255) NULL DEFAULT NULL AFTER cash_ack_original_name,
                ADD COLUMN cash_ack_uploaded_by INT NULL DEFAULT NULL AFTER cash_ack_note,
                ADD COLUMN cash_ack_uploaded_at TIMESTAMP NULL DEFAULT NULL AFTER cash_ack_uploaded_by
            ");
        }
    } catch (PDOException $e) {
        error_log("ensurePayrollCashAckColumns check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensurePayrollCashAckColumns($pdo);
}

// `payroll_logs` — this should already exist (save_record.php already
// uses it), but an idempotent CREATE TABLE IF NOT EXISTS is added here
// so the new approve/reject flow doesn't break if this table doesn't
// actually exist yet on an older install.
function ensurePayrollLogsTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS payroll_logs (
                log_id INT(11) NOT NULL AUTO_INCREMENT,
                payroll_id INT(11) NOT NULL,
                action VARCHAR(50) NOT NULL,
                performed_by INT(11) NOT NULL,
                notes TEXT DEFAULT NULL,
                logged_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (log_id),
                KEY payroll_id (payroll_id),
                KEY performed_by (performed_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensurePayrollLogsTable check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensurePayrollLogsTable($pdo);
}

// =====================================================
// LEAVE MODULE: decided_at column, audit log table, and
// leave credits/balance table (idempotent, same as
// ensurePayoutDateColumn above — so no one needs to go into
// phpMyAdmin if the database is older).
// =====================================================
function ensureLeaveRequestDecidedAtColumn($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'leave_requests'
              AND column_name = 'decided_at'
        ");
        $stmt->execute();
        if (((int) $stmt->fetchColumn()) === 0) {
            $pdo->exec("ALTER TABLE leave_requests ADD COLUMN decided_at TIMESTAMP NULL DEFAULT NULL AFTER approved_by");
        }
    } catch (PDOException $e) {
        error_log("ensureLeaveRequestDecidedAtColumn check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureLeaveRequestDecidedAtColumn($pdo);
}

function ensureLeaveRequestLogsTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS leave_request_logs (
                log_id INT(11) NOT NULL AUTO_INCREMENT,
                leave_id INT(11) NOT NULL,
                action VARCHAR(50) NOT NULL,
                performed_by INT(11) NOT NULL,
                notes TEXT DEFAULT NULL,
                logged_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (log_id),
                KEY leave_id (leave_id),
                KEY performed_by (performed_by),
                CONSTRAINT leave_request_logs_ibfk_1 FOREIGN KEY (leave_id) REFERENCES leave_requests (leave_id),
                CONSTRAINT leave_request_logs_ibfk_2 FOREIGN KEY (performed_by) REFERENCES users (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureLeaveRequestLogsTable check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureLeaveRequestLogsTable($pdo);
}

// Leave types that have credit/balance tracking. 'others' is not
// included here — it stays manual/case-to-case, with no fixed
// allocation, so it's still up to HR discretion.
// =====================================================
// LOGIN LOCKOUT (brute-force protection)
// =====================================================
// Before: there was no limit on login attempts, leaving login.php
// open to brute-force/automated password guessing. failed_attempts/
// locked_until columns are added here to the users table
// (idempotent, same as the other ensureXColumn() functions here) so
// repeated failed logins can be tracked per account.
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SECONDS', 300); // 5 minutes

function ensureLoginLockoutColumns($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'users'
              AND column_name = 'failed_attempts'
        ");
        $stmt->execute();
        if (((int) $stmt->fetchColumn()) === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN failed_attempts INT NOT NULL DEFAULT 0 AFTER password");
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'users'
              AND column_name = 'locked_until'
        ");
        $stmt->execute();
        if (((int) $stmt->fetchColumn()) === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN locked_until DATETIME NULL DEFAULT NULL AFTER failed_attempts");
        }
    } catch (PDOException $e) {
        error_log("ensureLoginLockoutColumns check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureLoginLockoutColumns($pdo);
}

// =====================================================
// PROFILE PICTURE — HRMS side of the same feature as the POS side's
// processProfilePictureUpload() (includes/config.php). See the
// comment there for the full explanation of why a successful upload
// here also gets mirrored (file copy + DB update) back to the POS
// side's own uploads/avatars/ folder and `users` table.
// =====================================================
function ensureProfilePictureColumn($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'users'
              AND column_name = 'profile_picture'
        ");
        $stmt->execute();
        if (((int) $stmt->fetchColumn()) === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL DEFAULT NULL AFTER full_name");
        }
    } catch (PDOException $e) {
        error_log("ensureProfilePictureColumn (HRMS side) skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureProfilePictureColumn($pdo);
}

// =====================================================
// FILE SIGNATURE (MAGIC BYTES) CHECK — Item 13 (Phase 5 compliance
// audit) follow-up.
// ------------------------------------------------------
// The resume/document upload flows below (apply.php, employee_documents.php,
// applicant_actions.php) only ever checked the file's EXTENSION against
// an allow-list (['pdf']) — never its actual content. A file renamed
// to end in ".pdf" would pass that check even if it isn't really a
// PDF. This is the same class of gap that getimagesize() already
// closes for the avatar upload above (an image that doesn't decode as
// an image is rejected, not just one with the wrong extension).
//
// A real PDF starts with "%PDF-" and ends with a cross-reference pointer
// and %%EOF marker. Confirm the detected MIME type as well, so a renamed
// or merely prefixed non-PDF file cannot pass the upload check.
// =====================================================
function isValidPdfSignature($tmpPath)
{
    if (!is_file($tmpPath) || !is_readable($tmpPath)) {
        return false;
    }
    $fileSize = filesize($tmpPath);
    if ($fileSize === false || $fileSize < 16) {
        return false;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if ($finfo->file($tmpPath) !== 'application/pdf') {
        return false;
    }
    $handle = @fopen($tmpPath, 'rb');
    if ($handle === false) {
        return false;
    }
    $header = fread($handle, 5);
    fseek($handle, max(0, $fileSize - 2048));
    $trailer = fread($handle, 2048);
    fclose($handle);
    return $header === '%PDF-'
        && is_string($trailer)
        && preg_match('/startxref\s+\d+\s+%%EOF\s*$/s', $trailer) === 1;
}

// Same idea as isValidPdfSignature(), for the applicant requirements
// (Valid Government ID, 2x2 ID Picture) that must be a real JPEG photo
// rather than a scanned PDF — a renamed .jpg with different content
// (or a PDF/PNG just relabeled to pass the extension check) still gets
// caught here. JPEG files always start with the 2-byte marker FF D8
// (SOI — Start Of Image, ITU-T T.81 §B.1.1.3); exif_imagetype() then
// double-checks it actually decodes as a JPEG (not just "starts right").
function isValidJpegSignature($tmpPath)
{
    if (!is_file($tmpPath) || !is_readable($tmpPath)) {
        return false;
    }
    $handle = @fopen($tmpPath, 'rb');
    if ($handle === false) {
        return false;
    }
    $header = fread($handle, 2);
    fclose($handle);
    if ($header !== "\xFF\xD8") {
        return false;
    }
    return @exif_imagetype($tmpPath) === IMAGETYPE_JPEG;
}

function processProfilePictureUpload($pdo, $posPdo)
{
    $res = ['message' => '', 'error' => '', 'filename' => null];

    requireCSRF();

    $userId = $_SESSION['user_id'] ?? null;
    $employeeId = $_SESSION['employee_id'] ?? null;
    $posUserId = $_SESSION['pos_user_id'] ?? null; // admin's mirrored POS account (see HRMS/login.php)

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
        error_log("Profile picture move_uploaded_file failed (HRMS side): dest=$destination");
        $res['error'] = "Could not save the uploaded image. Please check folder permissions and try again.";
        return $res;
    }

    try {
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

        // ---- Mirror pabalik sa POS side ----
        // employee_id: para sa mga Employee/HR/HR Manager/Supervisor na
        // may kaparehong naka-sync na account sa POS side (Cashier/
        // Inventory Staff/Finance). pos_user_id: para specifically sa
        // Admin, na may hiwalay na mirrored POS account (see HRMS/login.php).
        if ($posPdo && ($employeeId || $posUserId)) {
            try {
                $pos_upload_folder = __DIR__ . '/../../uploads/avatars/';
                if (!is_dir($pos_upload_folder)) {
                    mkdir($pos_upload_folder, 0755, true);
                }
                if (copy($destination, $pos_upload_folder . $newFilename)) {
                    if ($employeeId) {
                        $pOldStmt = $posPdo->prepare("SELECT profile_picture FROM users WHERE employee_id = ?");
                        $pOldStmt->execute([$employeeId]);
                        $pOldFile = $pOldStmt->fetchColumn();

                        $posPdo->prepare("UPDATE users SET profile_picture = ? WHERE employee_id = ?")
                            ->execute([$newFilename, $employeeId]);

                        if ($pOldFile && $pOldFile !== $newFilename && is_file($pos_upload_folder . $pOldFile)) {
                            @unlink($pos_upload_folder . $pOldFile);
                        }
                    }
                    if ($posUserId) {
                        $posPdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?")
                            ->execute([$newFilename, $posUserId]);
                    }
                }
            } catch (PDOException $e) {
                error_log("Profile picture mirror to POS skipped: " . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        error_log("Profile picture DB update error (HRMS side): " . $e->getMessage());
        $res['error'] = "The picture was uploaded but could not be saved to your account. Please try again.";
    }

    return $res;
}

// Clears the current user's profile picture (both HRMS and mirrored POS
// side), reverting the avatar back to the initials placeholder. Mirrors
// the same employee_id / pos_user_id logic as processProfilePictureUpload().
function removeProfilePicture($pdo, $posPdo)
{
    $res = ['message' => '', 'error' => ''];

    requireCSRF();

    $userId = $_SESSION['user_id'] ?? null;
    $employeeId = $_SESSION['employee_id'] ?? null;
    $posUserId = $_SESSION['pos_user_id'] ?? null;

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

        if ($posPdo && ($employeeId || $posUserId)) {
            try {
                $pos_upload_folder = __DIR__ . '/../../uploads/avatars/';
                if ($employeeId) {
                    $pOldStmt = $posPdo->prepare("SELECT profile_picture FROM users WHERE employee_id = ?");
                    $pOldStmt->execute([$employeeId]);
                    $pOldFile = $pOldStmt->fetchColumn();

                    $posPdo->prepare("UPDATE users SET profile_picture = NULL WHERE employee_id = ?")
                        ->execute([$employeeId]);

                    if ($pOldFile && is_file($pos_upload_folder . $pOldFile)) {
                        @unlink($pos_upload_folder . $pOldFile);
                    }
                }
                if ($posUserId) {
                    $posPdo->prepare("UPDATE users SET profile_picture = NULL WHERE id = ?")
                        ->execute([$posUserId]);
                }
            } catch (PDOException $e) {
                error_log("Profile picture removal mirror to POS skipped: " . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        error_log("Profile picture removal DB error (HRMS side): " . $e->getMessage());
        $res['error'] = "Could not remove the picture. Please try again.";
    }

    return $res;
}

define('LEAVE_CREDIT_TYPES', ['sick', 'vacation', 'emergency']);

// Default allocation per year, per leave type (in days).
// This can be adjusted depending on your company policy.
define('DEFAULT_LEAVE_ALLOCATIONS', [
    'sick' => 15,
    'vacation' => 15,
    'emergency' => 5,
]);

// Leave types where a day-limit is actually enforced when submitting a
// request. Sick and Emergency leave are still tracked (see leave_credits /
// getLeaveBalancesForYear) for reporting purposes, but employees are no
// longer blocked from filing beyond their allocated_days for those types —
// only Vacation Leave has a hard cap.
define('LEAVE_TYPES_WITH_LIMIT', ['vacation']);

// Minimum number of working days an employee must be back at work
// before they're allowed to file another Vacation Leave request.
// Prevents scheduling a new vacation leave that starts immediately
// (or overlaps) right after returning from a previous one, while
// still allowing vacation leave to be scheduled far in advance.
define('VACATION_LEAVE_COOLDOWN_DAYS', 1);

// Checks whether a new Vacation Leave request (date_from..date_to)
// is too close to (or overlapping) an existing pending/approved
// Vacation Leave for the same employee, per VACATION_LEAVE_COOLDOWN_DAYS.
// Returns an error message string if it violates the cooldown, or
// null if it's fine to submit.
function checkVacationLeaveCooldown($pdo, $employee_id, $date_from, $date_to)
{
    try {
        $stmt = $pdo->prepare("
            SELECT date_from, date_to FROM leave_requests
            WHERE employee_id = ? AND leave_type = 'vacation' AND status IN ('pending', 'approved')
        ");
        $stmt->execute([$employee_id]);
        $existing = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("checkVacationLeaveCooldown error: " . $e->getMessage());
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

        if ($gapDays < VACATION_LEAVE_COOLDOWN_DAYS) {
            return "You need at least " . VACATION_LEAVE_COOLDOWN_DAYS . " working day(s) back at work before/after "
                . "your Vacation Leave on {$ex['date_from']} to {$ex['date_to']} before filing another one.";
        }
    }

    return null;
}

// Minimum number of days that must pass since an employee's last
// Vacation Leave REQUEST (the day they FILED it, not the leave dates
// itself) before they're allowed to file another one. This is
// separate from checkVacationLeaveCooldown() above, which instead
// checks the gap between the leave PERIODS -- this one stops an
// employee from repeatedly filing Vacation Leave requests close
// together, regardless of which dates each request is for.
define('VACATION_LEAVE_REQUEST_COOLDOWN_DAYS', 30);

// Returns an error message if the employee already filed a Vacation
// Leave request (pending or approved) within the last
// VACATION_LEAVE_REQUEST_COOLDOWN_DAYS days, or null if they're
// clear to file another one.
function checkVacationLeaveRequestCooldown($pdo, $employee_id)
{
    try {
        $stmt = $pdo->prepare("
            SELECT requested_at FROM leave_requests
            WHERE employee_id = ? AND leave_type = 'vacation' AND status IN ('pending', 'approved')
            ORDER BY requested_at DESC LIMIT 1
        ");
        $stmt->execute([$employee_id]);
        $lastRequestedAt = $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("checkVacationLeaveRequestCooldown error: " . $e->getMessage());
        return null; // fail open -- don't block submission over a lookup error
    }

    if (!$lastRequestedAt)
        return null;

    $daysSince = (strtotime('today') - strtotime(date('Y-m-d', strtotime($lastRequestedAt)))) / 86400;

    if ($daysSince < VACATION_LEAVE_REQUEST_COOLDOWN_DAYS) {
        $nextAllowedDate = date('F j, Y', strtotime($lastRequestedAt . ' +' . VACATION_LEAVE_REQUEST_COOLDOWN_DAYS . ' days'));
        return "You already filed a Vacation Leave request on " . date('F j, Y', strtotime($lastRequestedAt))
            . ". You need to wait until " . $nextAllowedDate . " (" . VACATION_LEAVE_REQUEST_COOLDOWN_DAYS . " days after your last request) before filing another Vacation Leave request.";
    }

    return null;
}

function ensureLeaveCreditsTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS leave_credits (
                credit_id INT(11) NOT NULL AUTO_INCREMENT,
                employee_id INT(11) NOT NULL,
                leave_type ENUM('sick','vacation','emergency') NOT NULL,
                year INT(4) NOT NULL,
                allocated_days DECIMAL(5,2) NOT NULL DEFAULT 0,
                used_days DECIMAL(5,2) NOT NULL DEFAULT 0,
                PRIMARY KEY (credit_id),
                UNIQUE KEY uniq_employee_leave_year (employee_id, leave_type, year),
                CONSTRAINT leave_credits_ibfk_1 FOREIGN KEY (employee_id) REFERENCES employees (employee_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureLeaveCreditsTable check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureLeaveCreditsTable($pdo);
}

// Get an employee's leave_credits row (per leave_type, per year) —
// if it doesn't exist yet (e.g. a new employee, or their first time
// requesting this leave type this year), a row is created using
// DEFAULT_LEAVE_ALLOCATIONS before it's returned.
function getOrCreateLeaveCredit($pdo, $employee_id, $leave_type, $year)
{
    if (!in_array($leave_type, LEAVE_CREDIT_TYPES, true)) {
        return null; // 'others' — no credit tracking
    }
    $stmt = $pdo->prepare("SELECT * FROM leave_credits WHERE employee_id = ? AND leave_type = ? AND year = ?");
    $stmt->execute([$employee_id, $leave_type, $year]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    $allocated = DEFAULT_LEAVE_ALLOCATIONS[$leave_type] ?? 0;
    $pdo->prepare("
        INSERT INTO leave_credits (employee_id, leave_type, year, allocated_days, used_days)
        VALUES (?, ?, ?, ?, 0)
    ")->execute([$employee_id, $leave_type, $year, $allocated]);

    $stmt = $pdo->prepare("SELECT * FROM leave_credits WHERE employee_id = ? AND leave_type = ? AND year = ?");
    $stmt->execute([$employee_id, $leave_type, $year]);
    return $stmt->fetch();
}

// Returns all leave balances (sick/vacation/emergency) for an
// employee for the current year — used in the employee dashboard
// to show remaining credits.
function getLeaveBalancesForYear($pdo, $employee_id, $year)
{
    $balances = [];
    foreach (LEAVE_CREDIT_TYPES as $type) {
        $row = getOrCreateLeaveCredit($pdo, $employee_id, $type, $year);
        $balances[$type] = [
            'allocated' => (float) $row['allocated_days'],
            'used' => (float) $row['used_days'],
            'remaining' => (float) $row['allocated_days'] - (float) $row['used_days'],
        ];
    }
    return $balances;
}

// =====================================================
// EMPLOYEE DOCUMENTS (201 File)
// =====================================================
function ensureEmployeeDocumentsTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS employee_documents (
                document_id INT(11) NOT NULL AUTO_INCREMENT,
                employee_id INT(11) NOT NULL,
                document_type VARCHAR(60) NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                uploaded_by INT(11) NOT NULL,
                uploaded_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
                notes TEXT DEFAULT NULL,
                PRIMARY KEY (document_id),
                KEY employee_id (employee_id),
                KEY uploaded_by (uploaded_by),
                CONSTRAINT employee_documents_ibfk_1 FOREIGN KEY (employee_id) REFERENCES employees (employee_id),
                CONSTRAINT employee_documents_ibfk_2 FOREIGN KEY (uploaded_by) REFERENCES users (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureEmployeeDocumentsTable check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureEmployeeDocumentsTable($pdo);
}

// =====================================================
// ATTENDANCE: Time In GPS LOCATION columns
// =====================================================
// Before: there was no place in the `attendance` table to store
// where an employee clocked in from. 3 columns are added here
// (idempotent, same as the other ensureXColumn() functions above)
// for the GPS data captured whenever an employee self-Time-Ins in
// employee_dashboard.php:
//   - time_in_latitude / time_in_longitude : raw coordinates
//   - time_in_accuracy_meters : browser-reported uncertainty radius
//   - time_in_address : readable address (reverse-geocoded; can be
//     NULL if offline/unresolvable — the coordinates are still used)
// This lives in config.php (not attendance.php) to make sure the
// columns already exist even if employee_dashboard.php (which
// doesn't go through attendance.php) is the first to Time In / INSERT.
function ensureAttendanceLocationColumns($pdo)
{
    try {
        $checks = [
            'time_in_latitude' => "ALTER TABLE attendance ADD COLUMN time_in_latitude DECIMAL(10,7) NULL AFTER late_minutes",
            'time_in_longitude' => "ALTER TABLE attendance ADD COLUMN time_in_longitude DECIMAL(10,7) NULL AFTER time_in_latitude",
            'time_in_accuracy_meters' => "ALTER TABLE attendance ADD COLUMN time_in_accuracy_meters DECIMAL(8,2) NULL AFTER time_in_longitude",
            'time_in_address' => "ALTER TABLE attendance ADD COLUMN time_in_address VARCHAR(255) NULL AFTER time_in_accuracy_meters",
        ];
        foreach ($checks as $column => $alterSql) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'attendance'
                  AND column_name = ?
            ");
            $stmt->execute([$column]);
            if (((int) $stmt->fetchColumn()) === 0) {
                $pdo->exec($alterSql);
            }
        }
    } catch (PDOException $e) {
        // No attendance table yet (fresh install) — this will just be
        // handled by the CREATE TABLE in attendance.php / other queries.
        error_log("ensureAttendanceLocationColumns check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureAttendanceLocationColumns($pdo);
}

// =====================================================
// ATTENDANCE: unfinished-record correction audit columns
// =====================================================
// When an employee forgets to Time Out, that record stays "open"
// (time_in set, time_out NULL). We deliberately do NOT let them
// Time In again the next day while an open record exists (see the
// unfinishedRecord check in processSelfTimeInOut() below) — instead
// their Supervisor/HR must manually correct the Time Out, and we
// keep an audit trail of who fixed it, when, and why (idempotent —
// same pattern as ensureAttendanceLocationColumns() above).
function ensureAttendanceCorrectionColumns($pdo)
{
    try {
        $checks = [
            'corrected_by' => "ALTER TABLE attendance ADD COLUMN corrected_by INT NULL AFTER time_in_address",
            'correction_reason' => "ALTER TABLE attendance ADD COLUMN correction_reason VARCHAR(255) NULL AFTER corrected_by",
            'corrected_at' => "ALTER TABLE attendance ADD COLUMN corrected_at TIMESTAMP NULL AFTER correction_reason",
        ];
        foreach ($checks as $column => $alterSql) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'attendance'
                  AND column_name = ?
            ");
            $stmt->execute([$column]);
            if (((int) $stmt->fetchColumn()) === 0) {
                $pdo->exec($alterSql);
            }
        }
    } catch (PDOException $e) {
        error_log("ensureAttendanceCorrectionColumns check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureAttendanceCorrectionColumns($pdo);
}

// Returns the most recent PAST attendance record (date < $beforeDate)
// for this employee where they Timed In but never Timed Out — i.e.
// an "unfinished" record they forgot to close. While this exists,
// Time In is blocked for the employee (both HRMS self-service and
// the POS cashier flow, which shares the same `attendance` table)
// until a Supervisor/HR manually corrects the Time Out (see
// supervisor_attendance.php).
function getUnfinishedAttendanceRecord($pdo, $employee_id, $beforeDate)
{
    if (!$employee_id)
        return null;
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM attendance
            WHERE employee_id = ? AND date < ? AND time_in IS NOT NULL AND time_out IS NULL
            ORDER BY date DESC
            LIMIT 1
        ");
        $stmt->execute([$employee_id, $beforeDate]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("getUnfinishedAttendanceRecord skipped: " . $e->getMessage());
        return null;
    }
}

// Finds YESTERDAY's attendance record if it's still open (Timed In,
// never Timed Out) and plausibly still the SAME overnight shift in
// progress — e.g. a 10PM–6AM shift Times In under yesterday's date,
// and the employee only comes back to Time Out after midnight, once
// "today" has already turned over. Without this, processSelfTimeInOut()
// below would only ever look up attendance dated exactly today and
// miss that row entirely, wrongly saying "You need to Time In first
// before you can Time Out" even though they're already clocked in.
// Mirrors getCashierOpenOvernightRecord() in includes/config.php
// (POS side) — same `attendance` table.
function getOpenOvernightAttendanceRecord($pdo, $employee_id, $today)
{
    if (!$employee_id)
        return null;
    $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
    try {
        $stmt = $pdo->prepare("
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
        error_log("getOpenOvernightAttendanceRecord skipped: " . $e->getMessage());
        return null;
    }
}

// =====================================================
// ATTENDANCE: 'day_off' status (Rest Day)
// =====================================================
// Before: an employee's Day Off (a day their Weekly Shift Schedule
// has no shift assigned) had no distinct status of its own — once
// autoMarkPastAbsences() ran, it silently became 'absent' just like
// a real no-show. That's wrong: a Rest Day is not something an
// employee is being penalized for, so it must never be counted as
// Absent (and therefore never deducted from pay). This widens the
// `status` ENUM to add 'day_off' alongside present/absent/leave/
// holiday (idempotent — same pattern as the other ensureX()
// functions here). See autoMarkPastAbsences() below for where it's
// actually applied, and getEmployeeShiftForDate()/
// getEmployeeWeeklySchedule() above for the Rest Day source of truth
// (the employee's Weekly Shift Schedule).
function ensureAttendanceDayOffStatus($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COLUMN_TYPE FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'attendance' AND column_name = 'status'
        ");
        $stmt->execute();
        $colType = $stmt->fetchColumn();
        if ($colType !== false && strpos($colType, "'day_off'") === false) {
            $pdo->exec("ALTER TABLE attendance MODIFY COLUMN status ENUM('present','absent','leave','holiday','day_off') NOT NULL DEFAULT 'absent'");
        }
    } catch (PDOException $e) {
        // No attendance table yet (fresh install) — the CREATE TABLE in
        // attendance.php already includes 'day_off' for new installs.
        error_log("ensureAttendanceDayOffStatus check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureAttendanceDayOffStatus($pdo);
}

// =====================================================
// SHIFT SWAPS ("Palit-Shift") — lets an employee cover another
// employee's shift (or simply work) on what would otherwise be
// their own Day Off, WITHOUT opening up Time In on Day Offs to
// everyone. HR (or Admin) is always the one who processes/approves
// it (see the "Shift Swap Requests" panel in attendance.php) — an
// employee can never approve their own swap. Once approved for
// (employee_id, date), processSelfTimeInOut()/
// processCashierSelfTimeInOut() will allow that employee to Time In
// that day even though it's their Day Off, using the covered
// shift's hours for late/OT computation.
// =====================================================
// =====================================================
// POSITION / DEPARTMENT CHANGE AUDIT TRAIL
// =====================================================
// Records a history row every time an employee's position or
// department changes on the Edit form, so old sales/reports done
// under a previous position are never lost. This never touches the
// POS-side sales/stock_logs tables — those stay linked to the same
// permanent users.id account regardless of position. Shown on the
// employee's "History" modal (position_history table joined with
// users for the changed_by name).
function ensurePositionHistoryTable($pdo)
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'position_history'");
        if (!$stmt->fetch()) {
            $pdo->exec("
                CREATE TABLE position_history (
                    history_id      INT AUTO_INCREMENT PRIMARY KEY,
                    employee_id     INT NOT NULL,
                    old_position    VARCHAR(100) NULL,
                    new_position    VARCHAR(100) NULL,
                    old_department  VARCHAR(100) NULL,
                    new_department  VARCHAR(100) NULL,
                    changed_by      INT NULL,
                    changed_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_position_history_employee FOREIGN KEY (employee_id)
                        REFERENCES employees(employee_id) ON DELETE CASCADE
                )
            ");
        }
    } catch (PDOException $e) {
        error_log("ensurePositionHistoryTable error: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensurePositionHistoryTable($pdo);
}

// Only inserts a row when the position and/or department actually
// changed — call this unconditionally after every employee UPDATE,
// it's a no-op if nothing changed.
function logPositionChange($pdo, $employee_id, $oldPosition, $newPosition, $oldDepartment, $newDepartment, $changedBy)
{
    if ($oldPosition === $newPosition && $oldDepartment === $newDepartment) {
        return;
    }
    try {
        $pdo->prepare("
            INSERT INTO position_history (employee_id, old_position, new_position, old_department, new_department, changed_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$employee_id, $oldPosition, $newPosition, $oldDepartment, $newDepartment, $changedBy]);
    } catch (PDOException $e) {
        error_log("logPositionChange error: " . $e->getMessage());
    }
}

function ensureShiftSwapsTable($pdo)
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'shift_swaps'");
        if (!$stmt->fetch()) {
            $pdo->exec("
                CREATE TABLE shift_swaps (
                    swap_id             INT AUTO_INCREMENT PRIMARY KEY,
                    swap_date           DATE NOT NULL,
                    covering_employee_id INT NOT NULL,
                    original_employee_id INT NULL,
                    shift_id            INT NULL,
                    reason              VARCHAR(255) NULL,
                    status              ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                    requested_by        INT NULL,
                    processed_by        INT NULL,
                    processed_at        TIMESTAMP NULL,
                    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_covering_date (covering_employee_id, swap_date),
                    CONSTRAINT fk_swap_covering FOREIGN KEY (covering_employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
                    CONSTRAINT fk_swap_original FOREIGN KEY (original_employee_id) REFERENCES employees(employee_id) ON DELETE SET NULL,
                    CONSTRAINT fk_swap_shift    FOREIGN KEY (shift_id) REFERENCES shifts(shift_id) ON DELETE SET NULL
                )
            ");
        }
    } catch (PDOException $e) {
        error_log("ensureShiftSwapsTable error: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureShiftSwapsTable($pdo);
}

// The APPROVED shift-swap (if any) that lets $employee_id Time In on
// $date even though it's their Day Off. Returns the swap row (with
// shift_name/start_time/end_time joined in, if a covering shift was
// set) or null.
function getApprovedShiftSwapForDate($pdo, $employee_id, $date)
{
    if (!$employee_id)
        return null;
    try {
        $stmt = $pdo->prepare("
            SELECT sw.*, s.shift_name, s.start_time, s.end_time
            FROM shift_swaps sw
            LEFT JOIN shifts s ON s.shift_id = sw.shift_id
            WHERE sw.covering_employee_id = ? AND sw.swap_date = ? AND sw.status = 'approved'
            LIMIT 1
        ");
        $stmt->execute([$employee_id, $date]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("getApprovedShiftSwapForDate error: " . $e->getMessage());
        return null;
    }
}

// =====================================================
// EMPLOYEES: BANK / CARD INFORMATION columns
// =====================================================
// Before: there was no place in the `employees` table to store
// where the salary should be deposited. 3 columns are added here
// (idempotent, same as the other ensureXColumn() functions above)
// for payroll deposit:
//   - bank_name           : bank name (e.g. BDO, GCash)
//   - bank_account_number : account/card number
//   - bank_account_name   : name on the account
// Used by the Payroll module and shown on the payslip. Can be NULL
// if not yet set by HR.
function ensureEmployeeBankColumns($pdo)
{
    try {
        $checks = [
            'bank_name' => "ALTER TABLE employees ADD COLUMN bank_name VARCHAR(100) NULL AFTER basic_salary",
            'bank_account_number' => "ALTER TABLE employees ADD COLUMN bank_account_number VARCHAR(50) NULL AFTER bank_name",
            'bank_account_name' => "ALTER TABLE employees ADD COLUMN bank_account_name VARCHAR(100) NULL AFTER bank_account_number",
        ];
        foreach ($checks as $column => $alterSql) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'employees'
                  AND column_name = ?
            ");
            $stmt->execute([$column]);
            if (((int) $stmt->fetchColumn()) === 0) {
                $pdo->exec($alterSql);
            }
        }
    } catch (PDOException $e) {
        error_log("ensureEmployeeBankColumns check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureEmployeeBankColumns($pdo);
}

// =====================================================
// EMPLOYEES: PAYMENT METHOD (Bank Deposit / Cash Walk-in)
// =====================================================
// Before: bank/card details were just optional fields — an employee
// with none set was treated as a data-entry gap ("no way to know
// where to deposit the salary", a warning in Payroll Run). But this
// is a normal, expected case for a brand-new hire who doesn't have a
// bank account open yet — their salary just needs to be given to
// them in cash/personally instead, not flagged as a mistake.
// This column makes that an explicit, intentional choice per
// employee instead of an inferred blank:
//   - 'bank' (default) : salary is deposited to bank_name/bank_account_*
//   - 'cash'            : salary is released in cash/personally — no bank
//                          details required. HR switches this back to
//                          'bank' once the employee opens an account.
// New hires (see confirm_hire in includes/applicant_actions.php) are
// created as 'cash' by default, since a first-day hire almost never
// has bank details on file yet.
function ensureEmployeePaymentMethodColumn($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'employees'
              AND column_name = 'payment_method'
        ");
        $stmt->execute();
        if (((int) $stmt->fetchColumn()) === 0) {
            $pdo->exec("
                ALTER TABLE employees
                ADD COLUMN payment_method ENUM('bank','cash') NOT NULL DEFAULT 'bank' AFTER bank_account_name
            ");
        }
    } catch (PDOException $e) {
        error_log("ensureEmployeePaymentMethodColumn check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureEmployeePaymentMethodColumn($pdo);
}

// =====================================================
// EMPLOYEES: SUSPENSION
// =====================================================
// A suspension is a temporary block on an employee's account —
// separate from `status` (active/on-leave/resigned/terminated).
// While suspension_end_date is today or in the future, the employee
// cannot log in (login.php) and any already-open session is force
// logged out (requireLogin() / check_session_status.php).
//   - suspension_start_date : the day the suspension takes effect
//   - suspension_end_date   : the last day the employee is still
//                             suspended (inclusive) — access resumes
//                             the day after this date
//   - suspension_reason     : shown to the employee on the login
//                             page and to HR/Admin on the Employees list
function ensureEmployeeSuspensionColumns($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'employees'
              AND column_name = 'suspension_end_date'
        ");
        $stmt->execute();
        if (((int) $stmt->fetchColumn()) === 0) {
            $pdo->exec("
                ALTER TABLE employees
                ADD COLUMN suspension_start_date DATE NULL DEFAULT NULL,
                ADD COLUMN suspension_end_date DATE NULL DEFAULT NULL,
                ADD COLUMN suspension_reason VARCHAR(255) NULL DEFAULT NULL
            ");
        }
    } catch (PDOException $e) {
        error_log("ensureEmployeeSuspensionColumns check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureEmployeeSuspensionColumns($pdo);
}

// =====================================================
// EMPLOYEE STATUS LOGS — audit trail of who suspended/lifted/
// offboarded/changed the status of an employee, and when.
// Same pattern as job_applicant_logs. Shown in the Position
// History modal on employees.php (renamed there to also cover
// this) so HR/Admin can see who made a given change.
//   - action     : 'status_change' | 'suspend' | 'lift_suspension' | 'offboard'
//   - old_status / new_status : only meaningful for 'status_change' and
//                  'offboard' (the employees.status value before/after)
//   - details    : free text — e.g. the suspension reason + day count,
//                  populated for 'suspend'; NULL for the others
function ensureEmployeeStatusLogsTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS employee_status_logs (
                log_id       INT(11) NOT NULL AUTO_INCREMENT,
                employee_id  INT(11) NOT NULL,
                action       VARCHAR(30) NOT NULL,
                old_status   VARCHAR(30) DEFAULT NULL,
                new_status   VARCHAR(30) DEFAULT NULL,
                details      TEXT DEFAULT NULL,
                performed_by INT(11) NULL,
                logged_at    TIMESTAMP NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (log_id),
                KEY employee_id (employee_id),
                KEY performed_by (performed_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureEmployeeStatusLogsTable check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureEmployeeStatusLogsTable($pdo);
}

// Small helper — insert a row into employee_status_logs. Kept as a
// function since it's called from several branches in employees.php
// (edit/suspend/lift_suspension/offboard).
function logEmployeeStatusChange($pdo, $employee_id, $action, $old_status, $new_status, $details, $performed_by)
{
    try {
        $pdo->prepare("
            INSERT INTO employee_status_logs (employee_id, action, old_status, new_status, details, performed_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$employee_id, $action, $old_status, $new_status, $details, $performed_by]);
    } catch (PDOException $e) {
        error_log("logEmployeeStatusChange failed: " . $e->getMessage());
    }
}

// Returns whether an employee is CURRENTLY suspended (today falls
// within their suspension_start_date..suspension_end_date range,
// inclusive), plus how many days are left including today, and the
// total length of the suspension. Pass null-safe — employees with no
// suspension on file simply get 'active' => false.
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

// =====================================================
// EMPLOYEES: SCHEDULE TYPE (Full-time / Part-time)
// =====================================================
// Adds the `schedule_type` column (idempotent, same as the other
// ensureX() functions above). Defaults to 'full_time' for all
// existing rows — no data is lost, since everyone used the same
// 8-hour shift before this. Part-time is a 6-hour shift only, and
// limited to a few positions (see PART_TIME_ELIGIBLE_POSITIONS in
// employees.php) — cashier/stock clerk who don't need to be on a
// full shift all day, unlike core/office roles.
function ensureEmployeeScheduleTypeColumn($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'employees'
              AND column_name = 'schedule_type'
        ");
        $stmt->execute();
        if (((int) $stmt->fetchColumn()) === 0) {
            $pdo->exec("
                ALTER TABLE employees
                ADD COLUMN schedule_type ENUM('full_time','part_time') NOT NULL DEFAULT 'full_time'
                AFTER employment_type
            ");
        }
    } catch (PDOException $e) {
        error_log("ensureEmployeeScheduleTypeColumn check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureEmployeeScheduleTypeColumn($pdo);
}

// =====================================================
// EMPLOYEES: PERSONAL INFORMATION (carried over from the
// application form when an applicant is hired — see confirm_hire
// in includes/applicant_actions.php — and editable afterwards by
// the employee themself on my_profile.php, "Personal Information".
// Date of Birth and Home Address reuse the existing `birthdate` /
// `address` columns instead of duplicating them; only the fields
// below are genuinely new.
// =====================================================
function ensureEmployeePersonalDetailsColumns($pdo)
{
    try {
        $checks = [
            'place_of_birth' => "ALTER TABLE employees ADD COLUMN place_of_birth VARCHAR(150) NULL AFTER birthdate",
            'sex' => "ALTER TABLE employees ADD COLUMN sex VARCHAR(20) NULL AFTER place_of_birth",
            'civil_status' => "ALTER TABLE employees ADD COLUMN civil_status VARCHAR(20) NULL AFTER sex",
            'highest_educational_attainment' => "ALTER TABLE employees ADD COLUMN highest_educational_attainment VARCHAR(60) NULL AFTER civil_status",
            'year_graduated' => "ALTER TABLE employees ADD COLUMN year_graduated VARCHAR(4) NULL AFTER highest_educational_attainment",
            // Same JSON shape as job_applicants.character_references —
            // [{name, company, position, contact_number}, ...].
            'character_references' => "ALTER TABLE employees ADD COLUMN character_references TEXT NULL AFTER year_graduated",
        ];
        foreach ($checks as $column => $alterSql) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'employees'
                  AND column_name = ?
            ");
            $stmt->execute([$column]);
            if (((int) $stmt->fetchColumn()) === 0) {
                $pdo->exec($alterSql);
            }
        }
    } catch (PDOException $e) {
        error_log("ensureEmployeePersonalDetailsColumns check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureEmployeePersonalDetailsColumns($pdo);
}

// Part-time shift = 6 hours/day (the full-time shift is
// STANDARD_SHIFT_HOURS below, 8 hours). Used in Payroll to compute
// OT/Undertime based on each employee's actual schedule, instead of
// assuming 8 hours for everyone.
define('PART_TIME_SHIFT_HOURS', 6);

// Positions that can be Part-time. Other positions (e.g. HR
// Manager, HR Staff, Accountant) are Full-time only since they're
// core/office roles that need to be staffed all day — only Cashier
// and Inventory Staff can be scheduled part-time (more flexible staffing
// need, e.g. peak-hours-only shifts in a grocery store).
define('PART_TIME_ELIGIBLE_POSITIONS', ['Cashier', 'Inventory Staff']);

// True if an employee can be set to Part-time based on their
// position. Used in Add/Edit Employee validation and in the
// dropdown that limits which options are shown.
function isPositionPartTimeEligible($position)
{
    return in_array(trim((string) $position), PART_TIME_ELIGIBLE_POSITIONS, true);
}

// Positions that must log in through the POS Login page instead of here.
// Their HRMS `users` row still needs to exist (Admin still creates it via
// "Add User Account" — it's the credential source that gets mirrored into
// the POS side's own `users` table by syncCashierAccountsFromHRMS() /
// syncInventoryStaffAccountsFromHRMS() / syncAccountantAccountsFromHRMS()
// in the POS includes/config.php).
// Only the HRMS-side LOGIN is blocked for them — see login.php — since
// profile, time in/out, and payslip are already available on their
// synced POS account, making the old employee_dashboard.php path redundant.
// Keep this in sync with the POS side's own POS-login-eligible position
// list in includes/config.php (search "Cashier", "Inventory Staff", at
// "Accountant") — Accountant was added there but was missing here, which
// let Accountant accounts still log in through HRMS/login.php.
define('NO_HRMS_LOGIN_POSITIONS', ['Cashier', 'Inventory Staff', 'Accountant']);

// =====================================================
// JOB APPLICANTS: expand the status ENUM for a more
// detailed pipeline (Resume -> Initial -> Final
// -> Admin Approval -> Hired).
// =====================================================
// Before: enum('for_interview','for_admin_approval','hired','rejected').
// Adds for_resume / for_initial / for_final (idempotent, same as
// ensureHRManagerRole() which also MODIFYs an ENUM). The old
// 'for_interview' is removed (nothing uses it anymore), and the new
// default is 'for_resume' (first stage: resume screening).
function ensureJobApplicantStatusValues($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COLUMN_TYPE FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'job_applicants'
              AND column_name = 'status'
        ");
        $stmt->execute();
        $colType = $stmt->fetchColumn();

        $hasAll = $colType !== false
            && strpos($colType, "'for_resume'") !== false
            && strpos($colType, "'for_initial'") !== false
            && strpos($colType, "'for_final'") !== false
            && strpos($colType, "'for_requirements'") !== false
            && strpos($colType, "'disqualified'") !== false;

        if ($colType !== false && !$hasAll) {
            // In case there are old 'for_interview' rows still around
            // (new pipeline), treat them as approval-stage first before
            // changing the ENUM, so MODIFY doesn't lose any data.
            $pdo->exec("UPDATE job_applicants SET status = 'for_admin_approval' WHERE status = 'for_interview'");
            $pdo->exec("
                ALTER TABLE job_applicants
                MODIFY COLUMN status
                ENUM('for_resume','for_initial','for_final','for_requirements','for_admin_approval','hired','rejected','disqualified')
                NOT NULL DEFAULT 'for_resume'
            ");
        }
    } catch (PDOException $e) {
        error_log("ensureJobApplicantStatusValues check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureJobApplicantStatusValues($pdo);
}

// =====================================================
// HOLIDAYS (company-wide) — replaces the old manual
// "Holiday" override on the Attendance page.
// =====================================================
// A list of dates that are holidays for the WHOLE company. Only
// Admin can add/remove these. Used by the Attendance display so a
// holiday isn't marked as "Absent".
function ensureHolidaysTable($pdo)
{
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
        error_log("ensureHolidaysTable check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureHolidaysTable($pdo);
}

// =====================================================
// JOB APPLICANT LOGS — audit trail of each decision
// =====================================================
// Records who passed/rejected/hired an applicant, when, and the
// REASON (required for Reject, optional for Pass/Hire). Same
// pattern as the existing leave_request_logs.
function ensureJobApplicantLogsTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS job_applicant_logs (
                log_id       INT(11) NOT NULL AUTO_INCREMENT,
                applicant_id INT(11) NOT NULL,
                action       VARCHAR(40) NOT NULL,
                performed_by INT(11) NULL,
                reason       TEXT DEFAULT NULL,
                logged_at    TIMESTAMP NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (log_id),
                KEY applicant_id (applicant_id),
                KEY performed_by (performed_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureJobApplicantLogsTable check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureJobApplicantLogsTable($pdo);
}

// =====================================================
// JOB APPLICANTS: Initial and Final Interview dates
// =====================================================
// Once an applicant passes resume screening, HR sets an Initial
// Interview date — this is what's sent in the applicant's email.
// Same thing when they pass Initial (Final Interview date).
function ensureApplicantInterviewDateColumns($pdo)
{
    try {
        $checks = [
            'initial_interview_date' => "ALTER TABLE job_applicants ADD COLUMN initial_interview_date DATE NULL AFTER status",
            'final_interview_date' => "ALTER TABLE job_applicants ADD COLUMN final_interview_date DATE NULL AFTER initial_interview_date",
        ];
        foreach ($checks as $column => $alterSql) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'job_applicants'
                  AND column_name = ?
            ");
            $stmt->execute([$column]);
            if (((int) $stmt->fetchColumn()) === 0) {
                $pdo->exec($alterSql);
            }
        }
    } catch (PDOException $e) {
        error_log("ensureApplicantInterviewDateColumns check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureApplicantInterviewDateColumns($pdo);
}

// =====================================================
// APPLICANT REQUIREMENTS (pre-employment documents)
// =====================================================
// After passing the Final Interview, the applicant needs to
// complete these documents before they can be hired. HR uploads
// these on their behalf. If not complete before the deadline ->
// DISQUALIFIED.
define('APPLICANT_REQUIRED_DOCUMENTS', [
    'nbi_clearance' => 'NBI Clearance',
    'barangay_clearance' => 'Barangay Clearance',
    'birth_certificate' => 'Birth Certificate (PSA)',
    'sss' => 'SSS Number / E-1',
    'philhealth' => 'PhilHealth ID / MDR',
    'pagibig' => 'Pag-IBIG MID',
    'tin' => 'TIN ID',
    'valid_id' => 'Valid Government ID',
    'id_picture' => '2x2 ID Picture',
    'medical_cert' => 'Medical Certificate',
]);

// -----------------------------------------------------
// Which requirements must be a photo/scan of an ID (JPEG only) instead
// of the usual PDF document. Valid Government ID and the 2x2 ID
// Picture are the two that applicants realistically only ever have as
// a phone photo, not a scanned PDF — everything else defaults to PDF.
// -----------------------------------------------------
define('APPLICANT_DOC_IMAGE_TYPES', ['valid_id', 'id_picture']);

// Allowed extensions for a given requirement key.
function applicantDocAllowedExtensions($document_type)
{
    return in_array($document_type, APPLICANT_DOC_IMAGE_TYPES, true) ? ['jpg', 'jpeg'] : ['pdf'];
}

// The <input type="file" accept="..."> value for a given requirement key.
function applicantDocAcceptAttr($document_type)
{
    return in_array($document_type, APPLICANT_DOC_IMAGE_TYPES, true) ? '.jpg,.jpeg,image/jpeg' : '.pdf,application/pdf';
}

// Short format hint used next to each requirement
// row/field so applicants and HR both see the expected format at a glance.
function applicantDocFormatHint($document_type)
{
    return in_array($document_type, APPLICANT_DOC_IMAGE_TYPES, true) ? 'JPEG format' : 'PDF format';
}

// -----------------------------------------------------
// Maps an applicant requirement key to the corresponding 201-file
// document type (employee_documents.php's $DOCUMENT_TYPES). Used
// when a hired applicant's requirements are carried over to their
// new employee record (see 'confirm_hire' in
// includes/applicant_actions.php), so the carried-over document
// lands under the SAME type an employee would normally pick from
// the Documents page dropdown (e.g. "SSS Number / E-1" submitted
// during application -> "SSS Registration" in the 201 file), and
// the "already uploaded" duplicate-prevention check on the
// Documents page works correctly for it.
//
// Types with no 1:1 equivalent in the 201 file (Birth Certificate,
// 2x2 ID Picture) are carried over using their original
// applicant-side label — both are also included in
// employee_documents.php's $DOCUMENT_TYPES so they're recognized,
// selectable types there too, not just leftover carry-over labels.
// -----------------------------------------------------
define('APPLICANT_TO_EMPLOYEE_DOC_MAP', [
    'nbi_clearance' => 'NBI Clearance',
    'barangay_clearance' => 'Barangay Clearance',
    'medical_cert' => 'Medical Certificate',
    'sss' => 'SSS Registration',
    'philhealth' => 'PhilHealth Registration',
    'pagibig' => 'Pag-IBIG Registration',
    'tin' => 'TIN / BIR Form',
    'valid_id' => 'Valid ID',
    // No direct 201-file equivalent — kept as their own type:
    'birth_certificate' => 'Birth Certificate (PSA)',
    'id_picture' => '2x2 ID Picture',
]);

// Deadline for when the requirements should be complete.
function ensureApplicantRequirementColumns($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'job_applicants'
              AND column_name = 'requirements_deadline'
        ");
        $stmt->execute();
        if (((int) $stmt->fetchColumn()) === 0) {
            $pdo->exec("ALTER TABLE job_applicants ADD COLUMN requirements_deadline DATE NULL AFTER final_interview_date");
        }
    } catch (PDOException $e) {
        error_log("ensureApplicantRequirementColumns check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureApplicantRequirementColumns($pdo);
}

// =====================================================
// JOB APPLICANTS: full Personal Info / Position /
// Education / Character References fields — makes the
// public application form (apply.php) look like a real
// HR application form instead of just Name + Email + Resume.
// =====================================================
// Dropdown choices shown on the form AND used server-side to validate
// the submitted value actually came from the dropdown (not a tampered
// POST). Keep these in sync with the <select> options in apply.php.
define('APPLICANT_SEX_OPTIONS', ['Male', 'Female']);
define('APPLICANT_CIVIL_STATUS_OPTIONS', ['Single', 'Married', 'Widowed', 'Separated', 'Divorced']);
define('APPLICANT_EMPLOYMENT_TYPE_OPTIONS', ['Full-time', 'Part-time', 'Probationary', 'Contractual']);
define('APPLICANT_EDUCATION_OPTIONS', [
    'Elementary Graduate',
    'High School Graduate',
    'Senior High School Graduate',
    'Vocational / TESDA Graduate',
    'Some College (Undergraduate)',
    'College Graduate',
    'Post Graduate (Master\'s / Doctorate)',
]);
// Minimum working age used to validate Date of Birth (RA 9231 / Labor Code — 18 for most private employment).
define('APPLICANT_MIN_AGE', 18);

function ensureApplicantPersonalDetailsColumns($pdo)
{
    try {
        $checks = [
            'date_of_birth' => "ALTER TABLE job_applicants ADD COLUMN date_of_birth DATE NULL AFTER last_name",
            'place_of_birth' => "ALTER TABLE job_applicants ADD COLUMN place_of_birth VARCHAR(150) NULL AFTER date_of_birth",
            'sex' => "ALTER TABLE job_applicants ADD COLUMN sex VARCHAR(20) NULL AFTER place_of_birth",
            'civil_status' => "ALTER TABLE job_applicants ADD COLUMN civil_status VARCHAR(20) NULL AFTER sex",
            'home_address' => "ALTER TABLE job_applicants ADD COLUMN home_address VARCHAR(255) NULL AFTER civil_status",
            'employment_type' => "ALTER TABLE job_applicants ADD COLUMN employment_type VARCHAR(20) NULL AFTER position_applied",
            'highest_educational_attainment' => "ALTER TABLE job_applicants ADD COLUMN highest_educational_attainment VARCHAR(60) NULL AFTER employment_type",
            'year_graduated' => "ALTER TABLE job_applicants ADD COLUMN year_graduated VARCHAR(4) NULL AFTER highest_educational_attainment",
            // JSON array of {name, company, position, contact_number} — variable
            // number of references (2 required, up to 3), so a single TEXT/JSON
            // column is simpler than a separate table for such a small, form-only list.
            'character_references' => "ALTER TABLE job_applicants ADD COLUMN character_references TEXT NULL AFTER year_graduated",
        ];
        foreach ($checks as $column => $alterSql) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'job_applicants'
                  AND column_name = ?
            ");
            $stmt->execute([$column]);
            if (((int) $stmt->fetchColumn()) === 0) {
                $pdo->exec($alterSql);
            }
        }
    } catch (PDOException $e) {
        error_log("ensureApplicantPersonalDetailsColumns check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureApplicantPersonalDetailsColumns($pdo);
}

// Privacy controls for applicants and data-subject requests.
function ensureApplicantPrivacySchema($pdo)
{
    try {
        $columns = [
            'privacy_consent' => "ALTER TABLE job_applicants ADD COLUMN privacy_consent TINYINT(1) NOT NULL DEFAULT 0 AFTER character_references",
            'privacy_notice_version' => "ALTER TABLE job_applicants ADD COLUMN privacy_notice_version VARCHAR(32) NULL AFTER privacy_consent",
            'privacy_consent_at' => "ALTER TABLE job_applicants ADD COLUMN privacy_consent_at DATETIME NULL AFTER privacy_notice_version",
        ];
        foreach ($columns as $column => $alterSql) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'job_applicants'
                  AND column_name = ?
            ");
            $stmt->execute([$column]);
            if ((int) $stmt->fetchColumn() === 0) {
                $pdo->exec($alterSql);
            }
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS data_subject_requests (
                request_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                requester_name VARCHAR(150) NOT NULL,
                requester_email VARCHAR(254) NOT NULL,
                request_type ENUM('access','correction','deletion','portability','restriction','objection','other') NOT NULL,
                request_details TEXT NOT NULL,
                status ENUM('pending','identity_verification','identity_verified','in_review','on_hold','completed','denied') NOT NULL DEFAULT 'pending',
                admin_notes TEXT DEFAULT NULL,
                reviewed_by INT(11) DEFAULT NULL,
                reviewed_at DATETIME DEFAULT NULL,
                submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (request_id),
                KEY idx_dsr_status_submitted (status, submitted_at),
                KEY idx_dsr_email (requester_email),
                KEY idx_dsr_reviewer (reviewed_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $requestColumns = [
            'identity_verified_at' => "ALTER TABLE data_subject_requests ADD COLUMN identity_verified_at DATETIME NULL AFTER reviewed_at",
            'response_filename' => "ALTER TABLE data_subject_requests ADD COLUMN response_filename VARCHAR(255) NULL AFTER identity_verified_at",
            'response_original_name' => "ALTER TABLE data_subject_requests ADD COLUMN response_original_name VARCHAR(255) NULL AFTER response_filename",
            'response_expires_at' => "ALTER TABLE data_subject_requests ADD COLUMN response_expires_at DATETIME NULL AFTER response_original_name",
        ];
        foreach ($requestColumns as $column => $alterSql) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'data_subject_requests'
                  AND column_name = ?
            ");
            $stmt->execute([$column]);
            if ((int) $stmt->fetchColumn() === 0) {
                $pdo->exec($alterSql);
            }
        }

        $statusType = $pdo->query("
            SELECT column_type
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'data_subject_requests'
              AND column_name = 'status'
        ")->fetchColumn();
        if (is_string($statusType) && strpos($statusType, "'fulfilled'") === false) {
            $pdo->exec("
                ALTER TABLE data_subject_requests
                MODIFY status ENUM(
                    'pending','identity_verification','identity_verified','in_review',
                    'on_hold','completed','fulfilled','denied'
                ) NOT NULL DEFAULT 'pending'
            ");
        }
    } catch (PDOException $e) {
        error_log("ensureApplicantPrivacySchema failed: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureApplicantPrivacySchema($pdo);
}

// One-time data fixup: the "Position Applied For" dropdown (the
// application_form_fields row with field_key = 'position_applied') had
// "Stock Clerk" as a choice (not an actual position in this company) and
// was missing "HR Staff" and "Accountant" (real positions that ARE
// hireable through this form). Runs once — if the options already match,
// nothing changes, so it's safe to leave in place permanently the same
// way the other ensureX() fixups above are.
function ensureApplicantPositionOptions($pdo)
{
    try {
        $stmt = $pdo->prepare("SELECT field_id, options FROM application_form_fields WHERE field_key = 'position_applied'");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row)
            return; // field not installed on this DB yet — nothing to fix

        $choices = array_values(array_filter(array_map('trim', explode(',', (string) $row['options']))));

        // Case-insensitive remove of "Stock Clerk".
        $choices = array_values(array_filter($choices, function ($c) {
            return strcasecmp($c, 'Stock Clerk') !== 0;
        }));

        // Add "HR Staff" / "Accountant" if not already present (case-insensitive).
        foreach (['HR Staff', 'Accountant'] as $mustHave) {
            $already = false;
            foreach ($choices as $c) {
                if (strcasecmp($c, $mustHave) === 0) {
                    $already = true;
                    break;
                }
            }
            if (!$already)
                $choices[] = $mustHave;
        }

        $newOptions = implode(', ', $choices);
        if ($newOptions !== trim((string) $row['options'])) {
            $pdo->prepare("UPDATE application_form_fields SET options = ? WHERE field_id = ?")
                ->execute([$newOptions, $row['field_id']]);
        }
    } catch (PDOException $e) {
        error_log("ensureApplicantPositionOptions check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureApplicantPositionOptions($pdo);
}

// Applicant ID as shown to applicants/HR on forms and printouts, e.g.
// "APP-000042" — wraps the existing auto-increment primary key instead of
// adding a second ID system, so it's always in sync and needs no extra column.
function formatApplicantCode($applicant_id)
{
    return 'APP-' . str_pad((string) (int) $applicant_id, 6, '0', STR_PAD_LEFT);
}

// Whole-years age as of today, computed from a Y-m-d date string.
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
// "Personal Information" self-edit — shared by my_profile.php
// (HR Staff/HR Manager/Supervisor) AND employee_dashboard.php
// (Cashier/other rank-and-file employees), so every employee —
// not just HRMS staff — can fill in / correct the details normally
// captured on the application form (apply.php), plus their own
// Email and Contact Number. Called after requireLogin()/
// requireEmployee() has already confirmed the session, and only
// when $_POST['action'] === 'update_personal_info'.
// Returns ['message' => string, 'error' => string] — exactly one
// of the two will be non-empty (or both blank if nothing to report).
// =====================================================
function processPersonalInfoUpdate($pdo, $employeeId)
{
    $res = ['message' => '', 'error' => ''];

    requireCSRF();

    if (!$employeeId) {
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
            $dupStmt = $pdo->prepare("SELECT employee_id FROM employees WHERE email = ? AND employee_id != ?");
            $dupStmt->execute([$email, $employeeId]);
            if ($dupStmt->fetch()) {
                $res['error'] = "That email address is already used by another employee record.";
                return $res;
            }

            $stmt = $pdo->prepare("
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
            error_log("Update personal info error: " . $e->getMessage());
            $res['error'] = "Your personal information could not be saved. Please try again.";
        }
    }

    return $res;
}

// One file per requirement per applicant (re-upload = replace).
function ensureApplicantDocumentsTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS applicant_documents (
                document_id   INT(11) NOT NULL AUTO_INCREMENT,
                applicant_id  INT(11) NOT NULL,
                document_type VARCHAR(60) NOT NULL,
                file_name     VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                uploaded_by   INT(11) NULL,
                uploaded_at   TIMESTAMP NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (document_id),
                UNIQUE KEY uniq_applicant_doctype (applicant_id, document_type),
                KEY applicant_id (applicant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureApplicantDocumentsTable check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureApplicantDocumentsTable($pdo);
}

// Returns the uploaded documents: [document_type => row]
function getApplicantDocuments($pdo, $applicant_id)
{
    $out = [];
    try {
        $stmt = $pdo->prepare("SELECT * FROM applicant_documents WHERE applicant_id = ?");
        $stmt->execute([$applicant_id]);
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['document_type']] = $row;
        }
    } catch (PDOException $e) {
        error_log("getApplicantDocuments error: " . $e->getMessage());
    }
    return $out;
}

// Returns the requirements that are still MISSING: [key => label]
function applicantMissingDocuments($pdo, $applicant_id)
{
    $have = getApplicantDocuments($pdo, $applicant_id);
    $missing = [];
    foreach (APPLICANT_REQUIRED_DOCUMENTS as $key => $label) {
        if (!isset($have[$key])) {
            $missing[$key] = $label;
        }
    }
    return $missing;
}

function applicantRequirementsComplete($pdo, $applicant_id)
{
    return count(applicantMissingDocuments($pdo, $applicant_id)) === 0;
}

// -----------------------------------------------------
// APPLICANT REQUIREMENTS SELF-SERVICE PORTAL (magic-link token)
// -----------------------------------------------------
// Applicants don't have accounts/logins of their own, so access to
// requirements_submit.php is gated by a long random token stored per
// applicant (job_applicants.requirements_token) instead of a session.
// It's generated once — the first time the applicant reaches the
// requirements stage (pass_final) — and the SAME link keeps working
// afterwards (including after a rejection) until all requirements are
// complete, so HR/the applicant never needs a new link each time.
function ensureApplicantRequirementsToken($pdo, $applicant_id)
{
    $stmt = $pdo->prepare("SELECT requirements_token FROM job_applicants WHERE applicant_id = ?");
    $stmt->execute([$applicant_id]);
    $token = $stmt->fetchColumn();
    if (!empty($token)) {
        return $token;
    }
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("UPDATE job_applicants SET requirements_token = ? WHERE applicant_id = ?")
        ->execute([$token, $applicant_id]);
    return $token;
}

// Full link the applicant clicks (from the requirements email, or the
// "resend this one" rejection email) to submit/resubmit their
// requirements — no login needed, just this applicant's token.
function applicantRequirementsPortalUrl($pdo, $applicant_id)
{
    $token = ensureApplicantRequirementsToken($pdo, $applicant_id);
    return rtrim(appFullBaseUrl(), '/') . '/requirements_submit.php?aid=' . enc_id($applicant_id) . '&token=' . $token;
}

// For a requirement that's currently MISSING, checks whether that's
// because it was rejected by HR (vs. simply never submitted yet), so
// the self-service portal can show the applicant exactly why and
// avoid leaving them guessing. Reads the same audit trail already
// shown to HR in applicant_review.php's Decision History — looks at
// whichever happened LAST for this requirement: a rejection (still
// pending resend) or a fresh upload (already resent, no longer
// "rejected" even if HR hasn't reviewed the new one yet).
function getApplicantDocumentRejectionReason($pdo, $applicant_id, $document_type)
{
    $label = APPLICANT_REQUIRED_DOCUMENTS[$document_type] ?? null;
    if ($label === null) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT action, reason, logged_at
            FROM job_applicant_logs
            WHERE applicant_id = ?
              AND (
                    (action = 'document_uploaded' AND reason = ?)
                 OR (action = 'document_rejected'  AND reason LIKE ?)
              )
            ORDER BY log_id DESC
            LIMIT 1
        ");
        $stmt->execute([$applicant_id, $label, $label . ' — %']);
        $row = $stmt->fetch();
        if (!$row || $row['action'] !== 'document_rejected') {
            return null; // hindi pa na-reject, o na-resubmit na pagkatapos ma-reject
        }
        return [
            'reason' => preg_replace('/^.*? — /', '', $row['reason'], 1),
            'logged_at' => $row['logged_at'],
        ];
    } catch (PDOException $e) {
        error_log("getApplicantDocumentRejectionReason error: " . $e->getMessage());
        return null;
    }
}

// =====================================================
// AUTO-DISQUALIFY: deadline has passed but requirements are still incomplete.
// Called every time applicants are listed (employees.php / applicant_review.php).
// =====================================================
function sweepDisqualifiedApplicants($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM job_applicants
            WHERE status = 'for_requirements'
              AND requirements_deadline IS NOT NULL
              AND requirements_deadline < CURDATE()
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll() as $ap) {
            if (applicantRequirementsComplete($pdo, $ap['applicant_id'])) {
                continue; // already complete, don't disqualify
            }
            $pdo->prepare("UPDATE job_applicants SET status = 'disqualified' WHERE applicant_id = ?")
                ->execute([$ap['applicant_id']]);

            $missing = implode(', ', applicantMissingDocuments($pdo, $ap['applicant_id']));
            logApplicantAction(
                $pdo,
                $ap['applicant_id'],
                'disqualified',
                'Requirements were not complete before the deadline (' . $ap['requirements_deadline'] . '). Missing: ' . $missing
            );

            if (function_exists('sendDisqualifiedEmail')) {
                sendDisqualifiedEmail(
                    $ap['email'],
                    $ap['first_name'] . ' ' . $ap['last_name'],
                    $ap['position_applied'],
                    $ap['requirements_deadline']
                );
            }
        }
    } catch (PDOException $e) {
        error_log("sweepDisqualifiedApplicants skipped: " . $e->getMessage());
    }
}

// Records a decision on an applicant. Safe to call even without a reason.
function logApplicantAction($pdo, $applicant_id, $action, $reason = '')
{
    try {
        $pdo->prepare("
            INSERT INTO job_applicant_logs (applicant_id, action, performed_by, reason)
            VALUES (?, ?, ?, ?)
        ")->execute([
                    $applicant_id,
                    $action,
                    $_SESSION['user_id'] ?? null,
                    ($reason !== '' ? mb_substr($reason, 0, 2000) : null),
                ]);
    } catch (PDOException $e) {
        // Don't fail the whole action just because the audit log failed.
        error_log("logApplicantAction failed: " . $e->getMessage());
    }
}

// =====================================================
// SHARED LEAVE DECISION HANDLER
// (used by hr_dashboard.php and admin_dashboard.php — both had the
//  same logic before, now merged here: updates status/decided_at,
//  creates an audit log entry, and deducts from leave_credits when
//  approved.)
// =====================================================
// AUTHORIZATION: who can decide on a leave request, based on the
// requester's role — not just "are you logged in":
//   - Requester is an Employee  -> Admin or HR Staff can approve/reject
//     (Accountant/Finance and other regular employees fall under this
//     too, since their HRMS `users.role` is 'employee' by default)
//   - Requester is a Supervisor -> Admin or HR Staff can approve/reject
//   - Requester is HR Staff     -> Admin or HR Manager can approve/reject
//     (so HR Staff can't approve their own leave, or a fellow
//     HR Staff member's leave)
//   - Admin: full access — can decide on anyone's leave
function isAllowedToDecideLeave($deciderRole, $requesterRole, $requesterHasSupervisor = false)
{
    if ($deciderRole === 'admin') {
        return true;
    }
    if ($deciderRole === 'hr' && $requesterRole === 'employee') {
        // HR Staff only decides for employees who aren't assigned to any
        // Supervisor yet — once a Supervisor is assigned, approval/rejection
        // for that employee's leave requests moves to the Supervisor
        // (see supervisor_leave.php / processLeaveDecisionForSupervisor()).
        return !$requesterHasSupervisor;
    }
    if ($deciderRole === 'hr' && $requesterRole === 'supervisor') {
        // HR Staff also decides on Supervisors' own leave requests
        // (Supervisors don't have a Supervisor of their own, so this
        // isn't gated by requesterHasSupervisor).
        return true;
    }
    if ($deciderRole === 'hr_manager' && $requesterRole === 'hr') {
        return true;
    }
    return false;
}

// Whether an employee currently has a Supervisor assigned (supervisor_teams
// has a 1-to-1 member-to-supervisor mapping — see ensureSupervisorTeamsTable()).
function employeeHasSupervisor($pdo, $employee_id)
{
    if (!$employee_id)
        return false;
    $stmt = $pdo->prepare("SELECT 1 FROM supervisor_teams WHERE member_employee_id = ? LIMIT 1");
    $stmt->execute([$employee_id]);
    return (bool) $stmt->fetchColumn();
}

function processLeaveDecision($pdo, $leave_id, $new_status, $user_id)
{
    try {
        $stmt = $pdo->prepare("
            SELECT lr.*, COALESCE(u.role, 'employee') AS requester_role
            FROM leave_requests lr
            JOIN employees e ON lr.employee_id = e.employee_id
            LEFT JOIN users u ON u.employee_id = e.employee_id
            WHERE lr.leave_id = ? AND lr.status = 'pending'
        ");
        $stmt->execute([$leave_id]);
        $leave = $stmt->fetch();

        if (!$leave) {
            return ['ok' => false, 'message' => 'Leave request not found or has already been processed.'];
        }

        $deciderRole = $_SESSION['role'] ?? '';
        $requesterHasSupervisor = employeeHasSupervisor($pdo, $leave['employee_id']);
        if (!isAllowedToDecideLeave($deciderRole, $leave['requester_role'], $requesterHasSupervisor)) {
            return ['ok' => false, 'message' => 'You do not have access to decide on this leave request. This employee is assigned to a Supervisor, who now handles their leave approvals.'];
        }

        $pdo->prepare("UPDATE leave_requests SET status = ?, approved_by = ?, decided_at = NOW() WHERE leave_id = ?")
            ->execute([$new_status, $user_id, $leave_id]);

        $pdo->prepare("
            INSERT INTO leave_request_logs (leave_id, action, performed_by, notes)
            VALUES (?, ?, ?, ?)
        ")->execute([
                    $leave_id,
                    $new_status,
                    $user_id,
                    'Leave request ' . ($new_status === 'approved' ? 'approved' : 'rejected') . ' via dashboard.'
                ]);

        if ($new_status === 'approved' && in_array($leave['leave_type'], LEAVE_CREDIT_TYPES, true)) {
            $days = (strtotime($leave['date_to']) - strtotime($leave['date_from'])) / 86400 + 1;
            $year = (int) date('Y', strtotime($leave['date_from']));
            getOrCreateLeaveCredit($pdo, $leave['employee_id'], $leave['leave_type'], $year);
            $pdo->prepare("
                UPDATE leave_credits SET used_days = used_days + ?
                WHERE employee_id = ? AND leave_type = ? AND year = ?
            ")->execute([$days, $leave['employee_id'], $leave['leave_type'], $year]);
        }

        return [
            'ok' => true,
            'message' => $new_status == 'approved' ? 'Leave request approved.' : 'Leave request rejected.'
        ];
    } catch (PDOException $e) {
        error_log("Leave decision error: " . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not process the leave request. Please try again.'];
    }
}

// Leave decision for a Supervisor: same update/log as processLeaveDecision()
// above, but authorization is "is this requester on my team?" (via
// supervisor_teams) instead of the role-tier check in
// isAllowedToDecideLeave() — a Supervisor only ever decides for their
// own assigned team members, whatever their tier.
function processLeaveDecisionForSupervisor($pdo, $leave_id, $new_status, $user_id, $supervisorEmployeeId, $viewingAllTeams = false)
{
    try {
        $stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE leave_id = ? AND status = 'pending'");
        $stmt->execute([$leave_id]);
        $leave = $stmt->fetch();

        if (!$leave) {
            return ['ok' => false, 'message' => 'Leave request not found or has already been processed.'];
        }
        $authorized = $viewingAllTeams
            ? employeeHasAnySupervisor($pdo, $leave['employee_id'])
            : supervisorManagesEmployee($pdo, $supervisorEmployeeId, $leave['employee_id']);
        if (!$authorized) {
            return ['ok' => false, 'message' => 'This employee is not part of your team.'];
        }

        $pdo->prepare("UPDATE leave_requests SET status = ?, approved_by = ?, decided_at = NOW() WHERE leave_id = ?")
            ->execute([$new_status, $user_id, $leave_id]);

        $pdo->prepare("
            INSERT INTO leave_request_logs (leave_id, action, performed_by, notes)
            VALUES (?, ?, ?, ?)
        ")->execute([
                    $leave_id,
                    $new_status,
                    $user_id,
                    'Leave request ' . ($new_status === 'approved' ? 'approved' : 'rejected') . ' by team Supervisor.'
                ]);

        if ($new_status === 'approved' && in_array($leave['leave_type'], LEAVE_CREDIT_TYPES, true)) {
            $days = (strtotime($leave['date_to']) - strtotime($leave['date_from'])) / 86400 + 1;
            $year = (int) date('Y', strtotime($leave['date_from']));
            getOrCreateLeaveCredit($pdo, $leave['employee_id'], $leave['leave_type'], $year);
            $pdo->prepare("
                UPDATE leave_credits SET used_days = used_days + ?
                WHERE employee_id = ? AND leave_type = ? AND year = ?
            ")->execute([$days, $leave['employee_id'], $leave['leave_type'], $year]);
        }

        return [
            'ok' => true,
            'message' => $new_status == 'approved' ? 'Leave request approved.' : 'Leave request rejected.'
        ];
    } catch (PDOException $e) {
        error_log("Supervisor leave decision error: " . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not process the leave request. Please try again.'];
    }
}

// Overtime decision for a Supervisor — same "is this employee on my
// team" authorization as the leave handler above.
function processOvertimeDecisionForSupervisor($pdo, $ot_id, $new_status, $user_id, $supervisorEmployeeId, $viewingAllTeams = false)
{
    try {
        $stmt = $pdo->prepare("SELECT * FROM overtime_requests WHERE ot_id = ? AND status = 'pending'");
        $stmt->execute([$ot_id]);
        $ot = $stmt->fetch();

        if (!$ot) {
            return ['ok' => false, 'message' => 'Overtime request not found or has already been processed.'];
        }
        $authorized = $viewingAllTeams
            ? employeeHasAnySupervisor($pdo, $ot['employee_id'])
            : supervisorManagesEmployee($pdo, $supervisorEmployeeId, $ot['employee_id']);
        if (!$authorized) {
            return ['ok' => false, 'message' => 'This employee is not part of your team.'];
        }

        $pdo->prepare("UPDATE overtime_requests SET status = ?, approved_by = ?, decided_at = NOW() WHERE ot_id = ?")
            ->execute([$new_status, $user_id, $ot_id]);

        return [
            'ok' => true,
            'message' => $new_status == 'approved' ? 'Overtime request approved.' : 'Overtime request rejected.'
        ];
    } catch (PDOException $e) {
        error_log("Supervisor overtime decision error: " . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not process the overtime request. Please try again.'];
    }
}

// Official Business decision for a Supervisor — same "is this employee
// on my team" authorization as the leave/overtime handlers above.
// Approving does NOT touch the attendance table; it's read directly
// by computePayrollAttendanceSummary() (approved OB dates are unioned
// into $paid, same as holidays/leave) so it counts as PRESENT without
// requiring a time_in/time_out punch that day.
function processOfficialBusinessDecisionForSupervisor($pdo, $ob_id, $new_status, $user_id, $supervisorEmployeeId, $viewingAllTeams = false)
{
    try {
        $stmt = $pdo->prepare("SELECT * FROM official_business_requests WHERE ob_id = ? AND status = 'pending'");
        $stmt->execute([$ob_id]);
        $ob = $stmt->fetch();

        if (!$ob) {
            return ['ok' => false, 'message' => 'Official Business request not found or has already been processed.'];
        }
        $authorized = $viewingAllTeams
            ? employeeHasAnySupervisor($pdo, $ob['employee_id'])
            : supervisorManagesEmployee($pdo, $supervisorEmployeeId, $ob['employee_id']);
        if (!$authorized) {
            return ['ok' => false, 'message' => 'This employee is not part of your team.'];
        }

        $pdo->prepare("UPDATE official_business_requests SET status = ?, approved_by = ?, decided_at = NOW() WHERE ob_id = ?")
            ->execute([$new_status, $user_id, $ob_id]);

        return [
            'ok' => true,
            'message' => $new_status == 'approved' ? 'Official Business request approved.' : 'Official Business request rejected.'
        ];
    } catch (PDOException $e) {
        error_log("Supervisor OB decision error: " . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not process the Official Business request. Please try again.'];
    }
}

// Time In request decision for a Supervisor — same "is this employee
// on my team" authorization as the leave/overtime handlers above.
// Approving does NOT time the employee in automatically; it simply
// re-opens the Time In button (see processSelfTimeInOut()) so they
// can Time In themselves, with GPS captured as usual.
function processLateTimeInDecisionForSupervisor($pdo, $request_id, $new_status, $user_id, $supervisorEmployeeId, $viewingAllTeams = false)
{
    try {
        $stmt = $pdo->prepare("SELECT * FROM late_timein_requests WHERE request_id = ? AND status = 'pending'");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();

        if (!$req) {
            return ['ok' => false, 'message' => 'Time In request not found or has already been processed.'];
        }
        $authorized = $viewingAllTeams
            ? employeeHasAnySupervisor($pdo, $req['employee_id'])
            : supervisorManagesEmployee($pdo, $supervisorEmployeeId, $req['employee_id']);
        if (!$authorized) {
            return ['ok' => false, 'message' => 'This employee is not part of your team.'];
        }

        $pdo->prepare("UPDATE late_timein_requests SET status = ?, approved_by = ?, decided_at = NOW() WHERE request_id = ?")
            ->execute([$new_status, $user_id, $request_id]);

        return [
            'ok' => true,
            'message' => $new_status == 'approved' ? 'Time In request approved — Time In is now open for them.' : 'Time In request rejected.'
        ];
    } catch (PDOException $e) {
        error_log("Supervisor late time-in decision error: " . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not process the Time In request. Please try again.'];
    }
}

// =====================================================
// LATE TIME-IN APPROVAL — HR Staff / HR Manager / Admin tiers
// =====================================================
// Mirrors the monitoring chain used across HRMS/POS: Cashier ->
// Supervisor (handled above via supervisor_teams) -> HR Staff ->
// HR Manager -> Admin, with Finance monitored by Admin same as
// HR Manager. Used by late_timein_requests.php.
//
// Returns the employee_ids whose PENDING late-timein requests the
// given $role is responsible for approving:
//   - 'hr'         : Supervisors
//   - 'hr_manager' : HR Staff
//   - 'admin'      : everyone (Admin sees every tier, company-wide)
function getLateTimeInApprovalScopeEmployeeIds($pdo, $role)
{
    if ($role === 'admin') {
        $stmt = $pdo->query("SELECT employee_id FROM employees");
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $targetAcctRole = $role === 'hr' ? 'supervisor' : ($role === 'hr_manager' ? 'hr' : null);
    if ($targetAcctRole === null)
        return [];

    $stmt = $pdo->prepare("SELECT employee_id FROM users WHERE role = ? AND employee_id IS NOT NULL");
    $stmt->execute([$targetAcctRole]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// Generic version of processLateTimeInDecisionForSupervisor() for the
// HR Staff / HR Manager / Admin tiers, authorized against
// getLateTimeInApprovalScopeEmployeeIds() instead of supervisor_teams.
function processLateTimeInDecisionGeneric($pdo, $request_id, $new_status, $user_id, $scopeEmployeeIds)
{
    try {
        $stmt = $pdo->prepare("SELECT * FROM late_timein_requests WHERE request_id = ? AND status = 'pending'");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();

        if (!$req) {
            return ['ok' => false, 'message' => 'Time In request not found or has already been processed.'];
        }
        if (!in_array((int) $req['employee_id'], $scopeEmployeeIds, true)) {
            return ['ok' => false, 'message' => 'This employee is not part of your approval scope.'];
        }

        $pdo->prepare("UPDATE late_timein_requests SET status = ?, approved_by = ?, decided_at = NOW() WHERE request_id = ?")
            ->execute([$new_status, $user_id, $request_id]);

        return [
            'ok' => true,
            'message' => $new_status == 'approved' ? 'Time In request approved — Time In is now open for them.' : 'Time In request rejected.'
        ];
    } catch (PDOException $e) {
        error_log("Generic late time-in decision error: " . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not process the Time In request. Please try again.'];
    }
}

define('APP_NAME', 'HRMS & Payroll System');
define('SESSION_TIMEOUT', 1800);

function redirect($url)
{
    // Flush any buffered output first — a stray warning/notice or
    // whitespace printed earlier in the request can otherwise cause
    // "headers already sent" and make this redirect silently do
    // nothing, leaving the user stuck on the current page.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        header("Location: $url");
        exit();
    }

    // Fallback: headers were already sent for some reason. Force the
    // navigation client-side instead of leaving the user stuck.
    echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
    echo '<script>window.location.replace(' . json_encode($url) . ');</script>';
    exit();
}

// =====================================================
// APP BASE URL HELPER
// =====================================================
// Why this is needed: some pages (e.g. admin_dashboard.php) are in
// the app root, but others (e.g. payroll/attendance.php) are inside
// a subfolder. If just 'login.php' (a relative path) were used in
// redirect() or in JS's fetch(), the result would be wrong when
// called from inside payroll/ — it would resolve to a
// non-existent payroll/login.php instead of the correct login.php
// in the app root (this happened before with the session-timeout/
// deactivation redirect AND with check_session_status.php's
// real-time polling).
//
// This computes the absolute path (from the web root) to the HRMS
// app's own folder, so the path is always correct no matter which
// page/subfolder it's called from.
function appBaseUrl()
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $appRoot = realpath(__DIR__ . '/..'); // the HRMS app's folder (one level up from includes/)
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;

    $base = '';
    if ($appRoot && $docRoot && strpos($appRoot, $docRoot) === 0) {
        $rel = substr($appRoot, strlen($docRoot));
        $rel = str_replace('\\', '/', $rel);

        // Encode each URL path segment once so reserved characters do
        // not become double-encoded later.
        $base = implode('/', array_map('rawurlencode', explode('/', $rel)));
    }
    return $base; // e.g. '/HRMS', or '' if the app is at the web root itself
}

// Absolute, always-correct link to the logout page — use this
// instead of a bare "logout.php" href anywhere in the HRMS app, so
// the link never depends on which folder depth the current page is
// in, and always resolves with the .php extension intact.
function logoutUrl()
{
    return appBaseUrl() . '/logout.php';
}

// Same as appBaseUrl(), but includes the scheme (http/https) and
// host, so the result is a full, absolute URL (e.g. 'https://siaa.com/HRMS').
// Needed for emails (e.g. the "View/Print Payslip" button) since
// the employee will open it on their own browser/device, so a
// relative path won't work (unlike what redirect()/JS fetch use,
// which run inside the app itself).
function appFullBaseUrl()
{
    // Optional override for emails that must be openable by someone
    // OUTSIDE this machine/network (job applicants opening the
    // requirements-submission link on their own phone/PC). While
    // running on XAMPP, $_SERVER['HTTP_HOST'] is 'localhost' (or a
    // LAN IP), which is USELESS to an applicant and gets flagged by
    // Gmail's spam/phishing filter, so the email looks 'sent' but is
    // never actually seen. Define APP_PUBLIC_BASE_URL below (e.g. an
    // ngrok URL while testing, or your real domain once deployed) to
    // force every applicant-facing link to use a real, reachable
    // address instead of whatever host the current request came in on.
    // Example: define('APP_PUBLIC_BASE_URL', 'https://abcd1234.ngrok-free.app');
    $publicBaseUrl = defined('APP_PUBLIC_BASE_URL')
        ? trim((string) APP_PUBLIC_BASE_URL)
        : trim((string) env('APP_PUBLIC_BASE_URL', ''));
    if ($publicBaseUrl !== '' && filter_var($publicBaseUrl, FILTER_VALIDATE_URL)) {
        $publicParts = parse_url($publicBaseUrl);
        if (!empty($publicParts['scheme']) && !empty($publicParts['host'])) {
            $publicOrigin = $publicParts['scheme'] . '://' . $publicParts['host']
                . (isset($publicParts['port']) ? ':' . $publicParts['port'] : '');
            return rtrim($publicOrigin, '/') . appBaseUrl();
        }
    }

    // APP_URL is the configured system URL (for example
    // http://localhost/wala/new%20ui/SYSTEM). Use its origin with the
    // filesystem-derived HRMS path instead of trusting a local Apache
    // virtual-host label such as "wala" from HTTP_HOST.
    $configuredUrl = trim((string) env('APP_URL', ''));
    if ($configuredUrl !== '' && filter_var($configuredUrl, FILTER_VALIDATE_URL)) {
        $configuredParts = parse_url($configuredUrl);
        if (!empty($configuredParts['scheme']) && !empty($configuredParts['host'])) {
            $configuredOrigin = $configuredParts['scheme'] . '://' . $configuredParts['host']
                . (isset($configuredParts['port']) ? ':' . $configuredParts['port'] : '');
            return rtrim($configuredOrigin, '/') . appBaseUrl();
        }
    }

    $scheme = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    ) ? 'https' : 'http';
    $requestHost = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $hostParts = $requestHost !== '' ? parse_url('http://' . $requestHost) : false;
    $host = is_array($hostParts) ? (string) ($hostParts['host'] ?? '') : '';
    $isValidHost = $host === 'localhost'
        || filter_var($host, FILTER_VALIDATE_IP)
        || (strpos($host, '.') !== false && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME));
    if (!$isValidHost) {
        $host = 'localhost';
    }
    $port = is_array($hostParts) && isset($hostParts['port']) ? ':' . (int) $hostParts['port'] : '';
    return $scheme . '://' . $host . $port . appBaseUrl();
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
    // Discard anything that was echoed before this point (e.g. the
    // session-watcher <script> from requireLogin(), stray PHP
    // notices/warnings, etc.) so the response body is ALWAYS clean,
    // valid JSON — never JSON with something accidentally prepended
    // to it. See the ob_start() call near session_start() above.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
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

// Kino-convert ang php.ini shorthand size (hal. "8M", "2G", "512K")
// papuntang bytes — ginagamit para malaman ng JS side ang AKTWAL na
// post_max_size ng server (hindi basta hula), para ma-warn agad ang
// user BAGO pa mag-submit kung sobra na ang kabuuang laki ng mga
// piniling file (see applicant_review.php's batch document upload).
function iniSizeToBytes($val)
{
    $val = trim((string) $val);
    if ($val === '')
        return 0;
    $unit = strtolower(substr($val, -1));
    $num = (float) $val;
    switch ($unit) {
        case 'g':
            return (int) ($num * 1024 * 1024 * 1024);
        case 'm':
            return (int) ($num * 1024 * 1024);
        case 'k':
            return (int) ($num * 1024);
        default:
            return (int) $num;
    }
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

// =====================================================
// LOGIN LOCKOUT HELPERS
// =====================================================
function isAccountLockedOut($pdo, $userId)
{
    $stmt = $pdo->prepare("SELECT locked_until FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $lockedUntil = $stmt->fetchColumn();
    if (!$lockedUntil) {
        return false;
    }
    return strtotime($lockedUntil) > time();
}

function recordFailedLogin($pdo, $userId)
{
    $stmt = $pdo->prepare("SELECT failed_attempts FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $attempts = (int) $stmt->fetchColumn() + 1;

    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        $lockedUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_SECONDS);
        $pdo->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?")
            ->execute([$attempts, $lockedUntil, $userId]);
    } else {
        $pdo->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?")
            ->execute([$attempts, $userId]);
    }
}

// =====================================================
// LOGIN IP THROTTLE (Item 4 — Phase 1 security audit)
// ------------------------------------------------------
// The account lockout above only protects ONE known username: it never
// triggers if an attacker cycles through many different usernames from
// the same IP (recordFailedLogin() above is only ever called when a
// matching user row was found), or tries usernames that don't exist at
// all. This adds a lightweight, time-windowed throttle keyed on IP
// address, on top of — not instead of — the existing per-account
// lockout. It never permanently blocks an IP; the window just expires.
// Mirrors the same feature added to POS's includes/config.php.
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
        error_log("ensureLoginIpThrottleTable (HRMS side) skipped: " . $e->getMessage());
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
// this app can assume about its deployment.
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
            error_log("login_ip_throttle cleanup (HRMS side) skipped: " . $e->getMessage());
        }
    }
}

function clearFailedLogins($pdo, $userId)
{
    $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?")
        ->execute([$userId]);
}

function requireLogin()
{
    $base = appBaseUrl();

    if (empty($_SESSION['user_id'])) {
        redirect($base . '/login.php');
    }

    if (!empty($_SESSION['last_activity'])) {
        $idle_time = time() - $_SESSION['last_activity'];
        if ($idle_time > SESSION_TIMEOUT) {
            session_unset();
            session_destroy();
            redirect($base . '/login.php?expired=1');
        }
    }

    global $pdo;
    $stmt = $pdo->prepare("
        SELECT u.force_logout, u.is_active, e.suspension_start_date, e.suspension_end_date, e.suspension_reason
        FROM users u
        LEFT JOIN employees e ON u.employee_id = e.employee_id
        WHERE u.id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if ($user && (int) $user['is_active'] === 0) {
        session_unset();
        session_destroy();
        redirect($base . '/login.php?deactivated=1');
    }

    if ($user && $user['force_logout'] == 1) {
        $pdo->prepare("UPDATE users SET force_logout = 0 WHERE id = ?")
            ->execute([$_SESSION['user_id']]);
        session_unset();
        session_destroy();
        redirect($base . '/login.php?reset=1');
    }

    if ($user) {
        $susp = getEmployeeSuspensionStatus($user['suspension_start_date'], $user['suspension_end_date']);
        if ($susp['active']) {
            session_unset();
            session_destroy();
            redirect($base . '/login.php?suspended=1');
        }
    }

    $_SESSION['last_activity'] = time();

    // ------------------------------------------------------
    // REAL-TIME STATUS CHECK: while the page stays open (even if
    // the user isn't doing anything/navigating), this repeatedly
    // asks the server whether the account has been deactivated or
    // force-logged-out. If so, it immediately redirects to the
    // login page — no need to wait for the next click.
    //
    // NOTE: 'check_session_status.php' and 'login.php' used to be
    // relative paths only, so this broke when called from inside a
    // subfolder (e.g. payroll/attendance.php) — the polling would
    // 404 and the redirect path would be wrong. An absolute path
    // (base + /file.php) is now used so it works from any page.
    // ------------------------------------------------------
    $statusUrl = $base . '/check_session_status.php';
    $loginUrl = $base . '/login.php';
    echo "<script>
    (function() {
        var statusUrl = " . json_encode($statusUrl) . ";
        var loginUrl   = " . json_encode($loginUrl) . ";
        setInterval(function() {
            fetch(statusUrl, { cache: 'no-store' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.active) {
                        var reason = 'reset=1';
                        if (data.reason === 'deactivated') reason = 'deactivated=1';
                        if (data.reason === 'suspended') reason = 'suspended=1';
                        window.location.href = loginUrl + '?' + reason;
                    }
                })
                .catch(function() { /* ignore transient network errors */ });
        }, 10000);
    })();
    </script>";
}

function requireAdmin()
{
    requireLogin();
    if ($_SESSION['role'] !== 'admin') {
        redirect(appBaseUrl() . '/login.php');
    }
}

function requireHR()
{
    // "Any HR-tier" gate — Admin, HR Staff, and HR Manager can all
    // pass through here. The more detailed scoping (who can edit
    // what) is handled by each individual page/query, not here.
    requireLogin();
    if (!in_array($_SESSION['role'], ['admin', 'hr', 'hr_manager'])) {
        redirect(appBaseUrl() . '/login.php');
    }
}

// HR STAFF only (+ Admin) — for pages/actions that only whoever
// encodes attendance/payroll for rank-and-file Employees and
// screens job applicants should be able to do (e.g. hr_dashboard.php).
function requireHRStaff()
{
    requireLogin();
    if (!in_array($_SESSION['role'], ['admin', 'hr'])) {
        redirect(appBaseUrl() . '/login.php');
    }
}

// HR MANAGER only (+ Admin) — for the oversight dashboard that
// attends (encodes attendance) for HR Staff, and has view-only
// access to what they do.
function requireHRManagerRole()
{
    requireLogin();
    if (!in_array($_SESSION['role'], ['admin', 'hr_manager'])) {
        redirect(appBaseUrl() . '/login.php');
    }
}

function isAdminRole()
{
    return ($_SESSION['role'] ?? '') === 'admin';
}
function isHRStaffRole()
{
    return ($_SESSION['role'] ?? '') === 'hr';
}
function isHRManagerRole()
{
    return ($_SESSION['role'] ?? '') === 'hr_manager';
}
function isSupervisorRole()
{
    return ($_SESSION['role'] ?? '') === 'supervisor';
}

// SUPERVISOR only (+ Admin) — team attendance monitoring, leave/OT
// approval, performance, inventory requests, and POS monitoring for
// whichever employees are assigned to them in supervisor_teams.
function requireSupervisorRole()
{
    requireLogin();
    if (!in_array($_SESSION['role'], ['admin', 'supervisor'], true)) {
        redirect(appBaseUrl() . '/login.php');
    }
}

// The list of employee_ids a Supervisor manages (their "team"),
// based on the supervisor_teams assignment table (see
// manage_supervisor_teams.php, Admin-only). Admin viewing a
// Supervisor page can pass an explicit $supervisorEmployeeId to
// preview that supervisor's team.
function getSupervisorTeamEmployeeIds($pdo, $supervisorEmployeeId)
{
    if (empty($supervisorEmployeeId))
        return [];
    $stmt = $pdo->prepare("SELECT member_employee_id FROM supervisor_teams WHERE supervisor_employee_id = ?");
    $stmt->execute([$supervisorEmployeeId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function getHrmsNotificationBadgeCount($pdo, $posPdo, $role, $employeeId = null)
{
    try {
        if (in_array($role, ['admin', 'hr', 'hr_manager'], true)) {
            if (!$posPdo)
                return 0;
            $stmt = $posPdo->query("
                SELECT COUNT(*) FROM notifications
                WHERE category IN ('performance','applicant','hr_letter')
                  AND is_archived = 0 AND is_read = 0
            ");
            return (int) $stmt->fetchColumn();
        }

        if ($role === 'accountant') {
            return count(getPendingPayrollApprovals($pdo));
        }

        if ($role === 'supervisor') {
            $teamIds = getSupervisorTeamEmployeeIds($pdo, (int) $employeeId);
        } elseif ($role === 'employee') {
            $teamIds = [(int) $employeeId];
        } else {
            return 0;
        }
        if (empty($teamIds) || (int) $teamIds[0] <= 0)
            return 0;

        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $count = 0;
        foreach (['leave_requests', 'overtime_requests', 'official_business_requests', 'late_timein_requests'] as $table) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM {$table}
                WHERE status = 'pending' AND employee_id IN ({$placeholders})
            ");
            $stmt->execute($teamIds);
            $count += (int) $stmt->fetchColumn();
        }
        return $count;
    } catch (PDOException $e) {
        error_log("getHrmsNotificationBadgeCount error for {$role}: " . $e->getMessage());
        return 0;
    }
}

// Every employee assigned to ANY Supervisor, across every team combined.
// Used when Admin opens a supervisor_*.php page without picking one
// specific Supervisor via ?supervisor_id= — instead of an empty page,
// Admin sees/acts on everyone who has a Supervisor, exactly like a
// Supervisor would see their own team, just company-wide.
function getAllSupervisedEmployeeIds($pdo)
{
    $stmt = $pdo->query("SELECT DISTINCT member_employee_id FROM supervisor_teams");
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// Whether $supervisorEmployeeId is allowed to act on $targetEmployeeId
// (i.e. the target is on their team).
function supervisorManagesEmployee($pdo, $supervisorEmployeeId, $targetEmployeeId)
{
    $stmt = $pdo->prepare("SELECT 1 FROM supervisor_teams WHERE supervisor_employee_id = ? AND member_employee_id = ? LIMIT 1");
    $stmt->execute([$supervisorEmployeeId, $targetEmployeeId]);
    return (bool) $stmt->fetchColumn();
}

// Whether $targetEmployeeId belongs to ANY Supervisor's team at all.
// Used to authorize Admin approve/reject actions while browsing the
// "All Teams" view (no specific Supervisor selected).
function employeeHasAnySupervisor($pdo, $targetEmployeeId)
{
    $stmt = $pdo->prepare("SELECT 1 FROM supervisor_teams WHERE member_employee_id = ? LIMIT 1");
    $stmt->execute([$targetEmployeeId]);
    return (bool) $stmt->fetchColumn();
}

// Maps HRMS employee_ids -> their POS `users.id` (cashier/inventory
// account), for pages that need to look up POS activity (sales,
// stock actions) for a Supervisor's team. Returns [employee_id => pos_user_id].
function getPosUserIdsForEmployees($posPdo, array $employeeIds)
{
    if (!$posPdo || empty($employeeIds))
        return [];
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    $stmt = $posPdo->prepare("SELECT id, employee_id FROM users WHERE employee_id IN ($placeholders)");
    $stmt->execute(array_values($employeeIds));
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['employee_id']] = (int) $row['id'];
    }
    return $map;
}

// Human-readable label for a role — used in the topbar/header of
// each dashboard (it used to show the raw role value, e.g. "(hr)"
// or "(admin)"; now it shows "HR Staff", "HR Manager", "Admin", "Employee").
function roleLabel($role)
{
    switch ($role) {
        case 'admin':
            return 'Admin';
        case 'hr':
            return 'HR Staff';
        case 'hr_manager':
            return 'HR Manager';
        case 'accountant':
            return 'Accountant';
        case 'supervisor':
            return 'Supervisor';
        case 'employee':
            return 'Employee';
        default:
            return ucfirst((string) $role);
    }
}

// Like roleLabel(), but for a row from getAttendanceScopedEmployees():
// distinguishes the acct_role='employee' bucket into Cashier /
// Inventory Staff / Finance / plain Employee using employees.position,
// so the Attendance page's Role column/filter reads the way people
// on the org chart actually think about these accounts (position
// values match the sync criteria in the POS side's
// syncCashierAccountsFromHRMS() / syncFinanceAccountsFromHRMS()).
function attendanceRoleLabel($acctRole, $position)
{
    if ($acctRole !== 'employee')
        return roleLabel($acctRole);
    switch ($position) {
        case 'Cashier':
            return 'Cashier';
        case 'Inventory Staff':
            return 'Inventory Staff';
        case 'Accountant':
            return 'Finance';
        default:
            return 'Employee';
    }
}

// Where the "&larr; Dashboard" link/redirect should go, based on the
// logged-in user's role. Answered in one place only, so
// `$_SESSION['role'] == 'admin' ? ... : ...` isn't repeated on every
// page (there used to be only 2 possible dashboards, now there are 3).
function dashboardUrlForRole($role)
{
    switch ($role) {
        case 'admin':
            return 'admin_dashboard.php';
        case 'hr_manager':
            return 'hr_manager_dashboard.php';
        case 'supervisor':
            return 'supervisor_dashboard.php';
        case 'accountant':
            return 'payroll/accountant.php';
        default:
            return 'hr_dashboard.php'; // 'hr' (staff) and fallback
    }
}

function requireEmployee()
{
    requireLogin();

    // Same gate as login.php, applied again here in case an existing
    // session predates this change, or the URL is hit directly. Cashier
    // and Inventory Staff should be on their POS account instead.
    global $pdo;
    $employeeId = $_SESSION['employee_id'] ?? null;
    if ($employeeId) {
        $stmt = $pdo->prepare("SELECT position FROM employees WHERE employee_id = ?");
        $stmt->execute([$employeeId]);
        $position = $stmt->fetchColumn();
        if (in_array($position ?: '', NO_HRMS_LOGIN_POSITIONS, true)) {
            session_unset();
            session_destroy();
            redirect('login.php?wrong_login=1');
        }
    }
}

function requireAdminJson()
{
    if (empty($_SESSION['user_id'])) {
        jsonResponse(false, 'Not logged in. Please log in first.');
    }
    if ($_SESSION['role'] !== 'admin') {
        jsonResponse(false, 'Admin access only.');
    }
    $_SESSION['last_activity'] = time();
}

// =====================================================
// ATTENDANCE SHARED CONFIG/HELPERS
// (used by attendance.php [HR/Admin manual encoding]
//  and employee_dashboard.php [employee self time in/out])
// =====================================================
define('SHIFT_START_TIME', '08:00'); // 8:00 AM — DEFAULT/FALLBACK
// only now, for employees who
// have no shift assigned on
// that day (see SHIFT SCHEDULING
// block below). When a shift
// is assigned, ITS start_time
// is used instead.
define('LATE_GRACE_MINUTES', 15);      // grace period before being marked "late"
define('EARLY_TIMEIN_WINDOW_MINUTES', 60); // Time In stays CLOSED until this many
// minutes before the shift's start_time —
// e.g. 60 = a 6:00 AM shift only opens Time
// In starting 5:00 AM, not any time before
// that. Adjust this single value to widen
// or narrow the early window for everyone.
define('STANDARD_SHIFT_HOURS', 8);     // standard hours of a shift — basis
// for automatic Overtime/Undertime in
// Payroll (payroll/payroll.php), computed
// from the actual time_in/time_out in
// the attendance table. Also the
// FALLBACK when no shift is assigned.

// =====================================================
// SHIFT SCHEDULING (not all employees work the same hours)
// =====================================================
// Previously there was just one shift for ALL employees (8:00 AM start,
// SHIFT_START_TIME above). Now there's a dedicated "shift catalog"
// (e.g. "Opening 8:00 AM–4:00 PM", "Closing 4:00 PM–12:00 AM") that
// Admin/HR/HR Manager manage on the Employees page, and each employee
// has their own WEEKLY schedule (Mon–Sun, can differ per day —
// "rotating") that picks which shift (or Day Off) applies each
// day of the week.
//
//   - `shifts`               : the catalog of shift templates
//                               (shift_name, start_time, end_time)
//   - `employee_weekly_shifts`: per-employee, per-day-of-week (0=Sunday
//                               ... 6=Saturday) linked to a
//                               shift_id, or EMPTY (Day Off/no shift
//                               scheduled that day).
//
// When an employee has no shift assigned for a given day
// (brand-new employee, or not yet configured by HR), it falls back
// to the old default (SHIFT_START_TIME / STANDARD_SHIFT_HOURS /
// PART_TIME_SHIFT_HOURS based on schedule_type) — no existing
// employee records are broken.
function ensureShiftsTables($pdo)
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'shifts'");
        if (!$stmt->fetch()) {
            $pdo->exec("
                CREATE TABLE shifts (
                    shift_id   INT AUTO_INCREMENT PRIMARY KEY,
                    shift_name VARCHAR(60) NOT NULL,
                    start_time TIME NOT NULL,
                    end_time   TIME NOT NULL,
                    is_active  TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            // Two starter examples based on the typical
            // setup (opening/closing shift) — Admin/HR can still
            // edit/add more on the Employees page.
            $pdo->exec("
                INSERT INTO shifts (shift_name, start_time, end_time) VALUES
                ('Opening (8:00 AM–4:00 PM)', '08:00:00', '16:00:00'),
                ('Closing (4:00 PM–12:00 AM)', '16:00:00', '00:00:00')
            ");
        }
    } catch (PDOException $e) {
        error_log("ensureShiftsTables (shifts) error: " . $e->getMessage());
    }

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'employee_weekly_shifts'");
        if (!$stmt->fetch()) {
            $pdo->exec("
                CREATE TABLE employee_weekly_shifts (
                    id          INT AUTO_INCREMENT PRIMARY KEY,
                    employee_id INT NOT NULL,
                    day_of_week TINYINT NOT NULL, -- 0=Sunday ... 6=Saturday (same as PHP date('w'))
                    shift_id    INT NULL,          -- NULL = Day Off / no shift that day
                    UNIQUE KEY uniq_emp_day (employee_id, day_of_week),
                    CONSTRAINT fk_ews_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
                    CONSTRAINT fk_ews_shift FOREIGN KEY (shift_id) REFERENCES shifts(shift_id) ON DELETE SET NULL
                )
            ");
        }
    } catch (PDOException $e) {
        error_log("ensureShiftsTables (employee_weekly_shifts) error: " . $e->getMessage());
    }

    // attendance.shift_id — so we can still tell afterward (e.g. in
    // payroll/reports) which shift was used on that day,
    // even if the employee's weekly schedule changes later.
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'attendance' AND column_name = 'shift_id'
        ");
        $stmt->execute();
        if (((int) $stmt->fetchColumn()) === 0) {
            $pdo->exec("ALTER TABLE attendance ADD COLUMN shift_id INT NULL AFTER status");
        }
    } catch (PDOException $e) {
        error_log("ensureShiftsTables (attendance.shift_id) error: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureShiftsTables($pdo);
}

// All active shifts, earliest start_time first.
function getActiveShifts($pdo)
{
    return $pdo->query("SELECT * FROM shifts WHERE is_active = 1 ORDER BY start_time ASC")->fetchAll();
}

// The full weekly schedule of an employee, indexed by day_of_week
// (0=Sunday...6=Saturday) => shift row (with shift_name/start_time/end_time)
// or null (Day Off / no shift).
function getEmployeeWeeklySchedule($pdo, $employee_id)
{
    $stmt = $pdo->prepare("
        SELECT ews.day_of_week, s.shift_id, s.shift_name, s.start_time, s.end_time
        FROM employee_weekly_shifts ews
        LEFT JOIN shifts s ON s.shift_id = ews.shift_id
        WHERE ews.employee_id = ?
    ");
    $stmt->execute([$employee_id]);
    $out = [0 => null, 1 => null, 2 => null, 3 => null, 4 => null, 5 => null, 6 => null];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['day_of_week']] = $row['shift_id'] ? $row : null;
    }
    return $out;
}

// The shift that should be used for an employee on a particular
// date (based on the date's day-of-week and their weekly schedule).
// Returns null if that day is Day Off for them, or they don't
// have a configured schedule yet (falls back to the old default in
// the callers of this function).
function getEmployeeShiftForDate($pdo, $employee_id, $date)
{
    $dow = (int) date('w', strtotime($date)); // 0=Sunday...6=Saturday
    $stmt = $pdo->prepare("
        SELECT s.shift_id, s.shift_name, s.start_time, s.end_time
        FROM employee_weekly_shifts ews
        JOIN shifts s ON s.shift_id = ews.shift_id
        WHERE ews.employee_id = ? AND ews.day_of_week = ?
    ");
    $stmt->execute([$employee_id, $dow]);
    return $stmt->fetch() ?: null;
}

// Duration (in hours) of a shift, even one that "crosses midnight" (e.g.
// 4:00 PM–12:00 AM, or a true overnight shift like 10:00 PM–6:00 AM). Used
// in Payroll OT/Undertime computation instead of always assuming 8 hours.
function computeShiftDurationHours($start_time, $end_time)
{
    $start = strtotime('1970-01-01 ' . $start_time);
    $end = strtotime('1970-01-01 ' . $end_time);
    if ($start === false || $end === false)
        return STANDARD_SHIFT_HOURS;
    if ($end <= $start) {
        $end += 86400;
    } // crosses midnight
    return round(($end - $start) / 3600, 2);
}

function computeLateMinutes($time_in_hi, $shiftStartTime = null)
{
    $in = DateTime::createFromFormat('H:i', substr($time_in_hi, 0, 5));
    $shift = DateTime::createFromFormat('H:i', substr($shiftStartTime ?: SHIFT_START_TIME, 0, 5));

    if (!$in || !$shift) {
        return 0;
    }
    $diffMinutes = ($in->getTimestamp() - $shift->getTimestamp()) / 60;
    $late = $diffMinutes - LATE_GRACE_MINUTES;
    return $late > 0 ? (int) round($late) : 0;
}

function isValidTime($value)
{
    return preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value) === 1;
}

// =====================================================
// SELF TIME IN / OUT (shared) — for the employee, HR Staff,
// and HR Manager. Captures the GPS location on every Time In
// (from hidden fields filled in by JavaScript in
// includes/self_attendance_card.php). Location is required —
// without valid coordinates, Time In is not allowed.
// Returns: ['message'=>, 'error'=>, 'todayRow'=>].
// =====================================================
function processSelfTimeInOut($pdo, $employee_id)
{
    $result = ['message' => '', 'error' => '', 'todayRow' => null, 'todayShift' => null];
    $today = date('Y-m-d');
    // Kept separate from $today because the self_time_out branch below
    // may reassign $today to YESTERDAY's date (overnight shift case) so
    // the todayRow re-fetch picks up the record it just closed. Anything
    // that must stay anchored to the real calendar day (unfinished-record
    // check, today's Time In request) should use $trueToday instead.
    $trueToday = $today;

    // Get today's record first (if any).
    if ($employee_id) {
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND date = ?");
        $stmt->execute([$employee_id, $today]);
        $result['todayRow'] = $stmt->fetch() ?: null;
        // Whichever shift is assigned to them today (based on
        // their weekly schedule) — null if Day Off or they don't
        // have a configured schedule yet (the default will be used).
        $result['todayShift'] = getEmployeeShiftForDate($pdo, $employee_id, $today);
        $result['approvedSwap'] = $result['todayShift'] ? null : getApprovedShiftSwapForDate($pdo, $employee_id, $today);
        $result['lateRequestToday'] = getLateTimeInRequestForDate($pdo, $employee_id, $today);
        // Blocks Time In entirely if a previous day's record was never
        // Timed Out (employee forgot) — see getUnfinishedAttendanceRecord().
        $result['unfinishedRecord'] = getUnfinishedAttendanceRecord($pdo, $employee_id, $today);
    }
    // Official defined shifts (e.g. Morning 6AM–2PM, Mid 8AM–4PM) —
    // used for the "Time In Request" shift picker below, so a late
    // employee requests one of OUR actual schedules instead of typing
    // in any arbitrary time of their own.
    $result['activeShifts'] = getActiveShifts($pdo);

    // Once the shift for today is over and the employee never Timed
    // In, Time In closes — unless their Supervisor has already
    // approved a Time In request for today (see late_timein_requests
    // above). This runs on every load (not just POST) so the card
    // always reflects the current state.
    $__effectiveShift = $result['todayShift']
        ?: (($result['approvedSwap'] ?? null) && $result['approvedSwap']['shift_id'] ? $result['approvedSwap'] : null);
    $result['shiftEnded'] = false;
    $result['timeInWindowOpen'] = true;  // default open kung walang shift na kokontrahin (e.g. no shift assigned yet — falls to the "Day Off" message anyway)
    $result['timeInOpensAt'] = null;
    if ($employee_id && $__effectiveShift && empty($result['todayRow']['time_in'])) {
        $result['shiftEnded'] = shiftHasEnded($__effectiveShift['end_time'], $__effectiveShift['start_time'], date('H:i:s'));
        $result['timeInWindowOpen'] = !isBeforeTimeInWindow($__effectiveShift['start_time'], date('H:i:s'));
        $result['timeInOpensAt'] = getTimeInWindowOpenTime($__effectiveShift['start_time']);
    }
    // Timed in na pero wala pang Time Out — dapat naka-off muna ang
    // Time Out button kung mas maaga pa sa aktwal na simula ng shift
    // (ibig sabihin, maaga siyang nag-Time In sa loob ng early window).
    $result['shiftNotStartedYet'] = false;
    if ($employee_id && $__effectiveShift && !empty($result['todayRow']['time_in']) && empty($result['todayRow']['time_out'])) {
        $result['shiftNotStartedYet'] = isBeforeShiftStart($__effectiveShift['start_time'], date('H:i:s'));
    }
    $result['canTimeInLate'] = !empty($result['lateRequestToday']) && $result['lateRequestToday']['status'] === 'approved';

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && in_array($_POST['action'] ?? '', ['self_time_in', 'self_time_out', 'request_late_timein'], true)
    ) {

        requireCSRF();

        if (!$employee_id) {
            $result['error'] = "Your account doesn't have a linked employee record.";
        } elseif (($_POST['action'] ?? '') === 'request_late_timein') {

            $lateReq = $result['lateRequestToday'];

            if ($result['todayRow'] && !empty($result['todayRow']['time_in'])) {
                $result['error'] = "You have already Timed In today.";
            } elseif ($result['unfinishedRecord']) {
                $result['error'] = "You have an unfinished attendance record. Please contact your supervisor or HR to correct your previous Time Out.";
            } elseif (!$result['shiftEnded']) {
                $result['error'] = "Your shift hasn't ended yet — you can still Time In normally.";
            } elseif ($lateReq && $lateReq['status'] === 'pending') {
                $result['error'] = "You already have a Time In request pending approval from your Supervisor.";
            } elseif ($lateReq && $lateReq['status'] === 'approved') {
                $result['error'] = "Your Time In request was already approved — you can Time In now.";
            } else {
                // Employee picks one of OUR defined shifts (not a
                // free-typed time) — requested_time is always taken
                // from that shift's actual start_time, so what they
                // ask for always matches a real schedule we have.
                $reqShiftId = (int) ($_POST['requested_shift_id'] ?? 0);
                $reason = trim($_POST['late_reason'] ?? '');
                $reqShift = null;
                foreach ($result['activeShifts'] as $__s) {
                    if ((int) $__s['shift_id'] === $reqShiftId) {
                        $reqShift = $__s;
                        break;
                    }
                }

                if (!$reqShift) {
                    $result['error'] = "Please select which shift you'd like to Time In for.";
                } elseif (mb_strlen($reason) < 3) {
                    $result['error'] = "Please enter a reason for your late Time In request.";
                } else {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO late_timein_requests (employee_id, request_date, requested_time, requested_shift_id, reason, status, requested_at)
                            VALUES (?, ?, ?, ?, ?, 'pending', NOW())
                        ");
                        $stmt->execute([$employee_id, $today, $reqShift['start_time'], $reqShift['shift_id'], $reason]);
                        $result['message'] = "Your Time In request for the " . $reqShift['shift_name'] . " shift has been sent to your Supervisor. Time In will re-open once it's approved.";
                    } catch (PDOException $e) {
                        error_log("Late time-in request error: " . $e->getMessage());
                        $result['error'] = "Could not submit your Time In request. Please try again.";
                    }
                }
            }
        } elseif (($_POST['action'] ?? '') === 'self_time_in') {

            $todayRow = $result['todayRow'];
            $approvedSwap = (!$result['todayShift']) ? getApprovedShiftSwapForDate($pdo, $employee_id, $today) : null;
            $result['approvedSwap'] = $approvedSwap;

            if ($todayRow && !empty($todayRow['time_in'])) {
                $result['error'] = "You have already Timed In today.";
            } elseif ($result['unfinishedRecord']) {
                $result['error'] = "You have an unfinished attendance record. Please contact your supervisor or HR to correct your previous Time Out.";
            } elseif (!$result['todayShift'] && !$approvedSwap) {
                $result['error'] = "You're not scheduled to work today (Day Off) — Time In is disabled. If you're covering for another employee's shift today, ask HR to process a Shift Swap for you first.";
            } elseif ($result['shiftEnded'] && !$result['canTimeInLate']) {
                $result['error'] = "Your shift has already ended for today, so Time In is closed. Submit a Time In request below for your Supervisor's approval.";
            } elseif (!$result['timeInWindowOpen'] && !$result['canTimeInLate']) {
                $result['error'] = "It's too early to Time In. Time In opens at " . date('h:i A', strtotime($result['timeInOpensAt'])) . ".";
            } else {
                // GPS location (required)
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
                    // Normal assigned shift, or (Day Off) the shift HR
                    // approved via Shift Swap for today.
                    $todayShift = $result['todayShift'] ?: ($approvedSwap && $approvedSwap['shift_id'] ? $approvedSwap : null);
                    // If a Supervisor approved a late Time In request,
                    // the employee picked one of OUR defined shifts on
                    // that request — that's the shift they're actually
                    // covering now, so it wins over their originally
                    // assigned shift (which may be a different one).
                    if ($result['canTimeInLate'] && !empty($result['lateRequestToday']['requested_shift_id'])) {
                        $shiftStart = $result['lateRequestToday']['requested_time'];
                        $shiftId = (int) $result['lateRequestToday']['requested_shift_id'];
                    } else {
                        $shiftStart = $todayShift ? $todayShift['start_time'] : null;
                        $shiftId = $todayShift ? $todayShift['shift_id'] : null;
                    }
                    // Supervisor-approved late Time In requests are never
                    // deducted, regardless of how late the actual clock is.
                    $late_minutes = $result['canTimeInLate'] ? 0 : computeLateMinutes($now, $shiftStart);
                    $addrToSave = $addr !== '' ? mb_substr($addr, 0, 255) : null;

                    try {
                        $stmt = $pdo->prepare("
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
                        $stmt->execute([$employee_id, $today, $now, $late_minutes, $shiftId, $lat, $lng, (float) $accuracy, $addrToSave]);
                        $result['message'] = "You timed in at " . date('h:i A', strtotime($now)) . ".";
                    } catch (PDOException $e) {
                        error_log("processSelfTimeInOut (time in) error: " . $e->getMessage());
                        $result['error'] = "Time In could not be recorded. Please try again or contact HR.";
                    }
                }
            }
        } else { // self_time_out
            // Overnight shift (e.g. 10PM–6AM): Time In got recorded
            // under YESTERDAY's date, and it's now past midnight, so
            // $today alone won't find that row. Fall back to
            // yesterday's still-open record if it plausibly belongs
            // to tonight's shift — see getOpenOvernightAttendanceRecord().
            $openOvernightRow = empty($result['todayRow']) ? getOpenOvernightAttendanceRecord($pdo, $employee_id, $today) : null;
            $outRow = $result['todayRow'] ?: $openOvernightRow;
            $outDate = $result['todayRow'] ? $today : ($openOvernightRow['date'] ?? $today);

            if (!$outRow || empty($outRow['time_in'])) {
                $result['error'] = "You need to Time In first before you can Time Out.";
            } elseif (!empty($outRow['time_out'])) {
                $result['error'] = "You have already Timed Out today.";
            } elseif (!$openOvernightRow && $__effectiveShift && isBeforeShiftStart($__effectiveShift['start_time'], date('H:i:s'))) {
                $result['error'] = "You Timed In early — Time Out isn't available yet. It opens once your shift actually starts at " . date('h:i A', strtotime($__effectiveShift['start_time'])) . ".";
            } else {
                $now = date('H:i:s');
                try {
                    $pdo->prepare("UPDATE attendance SET time_out = ? WHERE employee_id = ? AND date = ?")
                        ->execute([$now, $employee_id, $outDate]);
                    // So the re-fetch right below picks up the record we
                    // just closed, even if it was dated yesterday.
                    $today = $outDate;
                    $result['message'] = "You timed out at " . date('h:i A', strtotime($now)) . ".";
                } catch (PDOException $e) {
                    error_log("processSelfTimeInOut (time out) error: " . $e->getMessage());
                    $result['error'] = "Time Out could not be recorded. Please try again or contact HR.";
                }
            }
        }

        // Fetch the updated row/request again so the card shows the right info.
        if ($employee_id) {
            $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND date = ?");
            $stmt->execute([$employee_id, $today]);
            $result['todayRow'] = $stmt->fetch() ?: null;

            $result['lateRequestToday'] = getLateTimeInRequestForDate($pdo, $employee_id, $trueToday);
            $result['canTimeInLate'] = !empty($result['lateRequestToday']) && $result['lateRequestToday']['status'] === 'approved';
            $result['unfinishedRecord'] = getUnfinishedAttendanceRecord($pdo, $employee_id, $trueToday);
            if (empty($result['todayRow']['time_in']) && $__effectiveShift) {
                $result['shiftEnded'] = shiftHasEnded($__effectiveShift['end_time'], $__effectiveShift['start_time'], date('H:i:s'));
                $result['timeInWindowOpen'] = !isBeforeTimeInWindow($__effectiveShift['start_time'], date('H:i:s'));
                $result['timeInOpensAt'] = getTimeInWindowOpenTime($__effectiveShift['start_time']);
            } else {
                $result['shiftEnded'] = false;
                $result['timeInWindowOpen'] = true;
            }
            $result['shiftNotStartedYet'] = false;
            if ($__effectiveShift && !empty($result['todayRow']['time_in']) && empty($result['todayRow']['time_out'])) {
                $result['shiftNotStartedYet'] = isBeforeShiftStart($__effectiveShift['start_time'], date('H:i:s'));
            }
        }
    }

    return $result;
}

// =====================================================
// ROLE SEPARATION: HR Staff vs HR Manager vs Admin
// =====================================================
// There used to be only 3 roles (admin, hr, employee). 'hr_manager'
// is added here as a 4th role (idempotent — same as the other
// ensureXColumn() functions here, so it's automatically applied
// the next time any page loads, no need to go into phpMyAdmin). The
// old 'hr' role becomes "HR Staff" — they encode attendance/run
// payroll for Employees, and screen job applicants. The new
// 'hr_manager' oversees HR Staff: they encode attendance for HR
// Staff, and have view-only access to HR Staff's payroll/attendance
// work for Employees.
function ensureHRManagerRole($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COLUMN_TYPE FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'users'
              AND column_name = 'role'
        ");
        $stmt->execute();
        $colType = $stmt->fetchColumn();

        if ($colType !== false && (strpos($colType, "'hr_manager'") === false || strpos($colType, "'accountant'") === false || strpos($colType, "'supervisor'") === false)) {
            $pdo->exec("
                ALTER TABLE users
                MODIFY COLUMN role ENUM('admin','hr','hr_manager','supervisor','accountant','employee') NOT NULL DEFAULT 'employee'
            ");
        }
    } catch (PDOException $e) {
        error_log("ensureHRManagerRole check skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureHRManagerRole($pdo);
}

// =====================================================
// TWO-FACTOR AUTHENTICATION (Email OTP) — Admin accounts
// ------------------------------------------------------
// Admin logs in on this side (HRMS/login.php), so Admin's 2FA columns
// live here. Finance logs in on the POS side, so Finance's matching
// columns live in the POS includes/config.php instead — same column
// names/shapes on both, so includes/twofactor.php (shared logic, one
// copy per side since each side has its own PDO/session) works the
// same regardless of which users table it's handed.
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
if ($__migrationsNeeded) {
    ensureTwoFactorColumns($pdo);
}

// End of the gated migration block (Item 9) — record that this exact
// version of config.php has now been verified, so subsequent requests
// skip straight past it until the file changes again.
if ($__migrationsNeeded) {
    @file_put_contents($__migrationMarkerFile, (string) $__configMtime);
}

// Returns the "account role" (from the users table, if it exists)
// for each employee — 'employee' if there's no linked user account
// yet. Used to know whether the person behind each `employees` row
// is an Employee, HR Staff, HR Manager, or Admin, since
// attendance/payroll always links to `employees.employee_id`, not
// directly to `users.role`.
function acctRoleCaseSQL($alias = 'u')
{
    return "COALESCE($alias.role, 'employee')";
}

// =====================================================
// ATTENDANCE SCOPING (role-aware)
// =====================================================
// Returns the list of employees that should be visible on the
// Attendance page for the currently logged-in user, along with an
// 'attendance_editable' flag per row. Mirrors the monitoring chain
// used across HRMS/POS: Cashier -> Supervisor (supervisor_teams,
// see supervisor_attendance.php) -> HR Staff -> HR Manager -> Admin:
//   - Admin        : everyone, all editable
//   - HR Staff     : Supervisors, Finance (Accountant), Inventory
//                     Staff, and plain Employees (rank-and-file) —
//                     editable. Cashiers are editable ONLY while they
//                     haven't been placed on a Supervisor's team yet
//                     (supervisor_teams) — once assigned, HR Staff
//                     still SEES them here but Fix Time Out becomes
//                     the Supervisor's job instead (view-only).
//   - HR Manager   : HR Staff — editable; Employees — VIEW-ONLY
//                     (attendance_editable = false). Supervisor,
//                     Finance, and other HR Manager/Admin rows are
//                     not shown.
function getAttendanceScopedEmployees($pdo)
{
    $role = $_SESSION['role'] ?? '';
    $roleExpr = acctRoleCaseSQL('u');

    $stmt = $pdo->prepare("
        SELECT e.employee_id, e.employee_code, e.first_name, e.last_name,
               e.department, e.position, e.status, $roleExpr AS acct_role,
               st.supervisor_employee_id
        FROM employees e
        LEFT JOIN users u ON u.employee_id = e.employee_id
        LEFT JOIN supervisor_teams st ON st.member_employee_id = e.employee_id
        WHERE e.status IN ('active', 'on-leave')
        ORDER BY e.last_name ASC, e.first_name ASC
    ");
    $stmt->execute();

    $employees = [];
    foreach ($stmt->fetchAll() as $row) {
        $acctRole = $row['acct_role'];
        // Finance staff are identified by position, not by an HRMS
        // login role (they log into HRMS, if at all, as a plain
        // 'employee' — see syncFinanceAccountsFromHRMS() in the POS
        // side, which keys off this same position value).
        $isFinance = ($row['position'] ?? '') === 'Accountant';
        $isCashier = ($row['position'] ?? '') === 'Cashier';
        // Has this Cashier already been placed on a Supervisor's team?
        $hasSupervisorTeam = !empty($row['supervisor_employee_id']);

        if ($role === 'admin') {
            $row['attendance_editable'] = true;
        } elseif ($role === 'hr') {
            if ($acctRole === 'supervisor') {
                $row['attendance_editable'] = true;
            } elseif ($acctRole === 'employee' && $isCashier) {
                // Once a Cashier belongs to a Supervisor's team, that
                // Supervisor owns their attendance — HR Staff can still
                // see the row (for visibility/reporting) but Fix Time
                // Out is no longer theirs to do.
                $row['attendance_editable'] = !$hasSupervisorTeam;
            } elseif ($acctRole === 'employee') {
                // Finance (Accountant), Inventory Staff, and any other
                // rank-and-file position — editable by HR Staff.
                $row['attendance_editable'] = true;
            } else {
                continue; // everyone else (HR/HR Manager/Admin accounts) stays hidden from HR Staff
            }
        } elseif ($role === 'hr_manager') {
            if ($acctRole === 'hr') {
                $row['attendance_editable'] = true;
            } elseif ($acctRole === 'employee' && !$isFinance) {
                $row['attendance_editable'] = false; // view-only
            } else {
                continue; // Supervisor, Finance, other admin/hr_manager rows hidden
            }
        } else {
            continue;
        }

        $employees[] = $row;
    }
    return $employees;
}

// =====================================================
// APPROVED LEAVE MAP (for the Attendance display)
// =====================================================
// There's no more manual "Override" on the Attendance page. Instead,
// the "On Leave" status now comes automatically from APPROVED leave
// requests. This returns: $map[employee_id][YYYY-MM-DD] = leave_type
// for every day covered by an approved leave within
// $startDate..$endDate.
function getApprovedLeaveMap($pdo, $employeeIds, $startDate, $endDate)
{
    $map = [];
    if (empty($employeeIds)) {
        return $map;
    }

    $ph = implode(',', array_fill(0, count($employeeIds), '?'));
    $stmt = $pdo->prepare("
        SELECT employee_id, leave_type, date_from, date_to
        FROM leave_requests
        WHERE status = 'approved'
          AND employee_id IN ($ph)
          AND date_from <= ? AND date_to >= ?
    ");
    $stmt->execute(array_merge($employeeIds, [$endDate, $startDate]));

    foreach ($stmt->fetchAll() as $lv) {
        // Clamp the leave range to just the requested range.
        $cursor = max(strtotime($lv['date_from']), strtotime($startDate));
        $stop = min(strtotime($lv['date_to']), strtotime($endDate));
        while ($cursor <= $stop) {
            $map[(int) $lv['employee_id']][date('Y-m-d', $cursor)] = $lv['leave_type'];
            $cursor = strtotime('+1 day', $cursor);
        }
    }
    return $map;
}

// =====================================================
// APPROVED OFFICIAL BUSINESS MAP — Item 12 (Phase 4 HRMS audit)
// =====================================================
// Same shape/pattern as getApprovedLeaveMap() above:
// $map[employee_id][YYYY-MM-DD] = true for every date covered by an
// APPROVED official_business_requests row. Used by
// computePayrollAttendanceSummary() to count approved OB days as
// PRESENT (paid) even without an attendance time_in/time_out punch
// that day — same treatment as a holiday or approved leave, per the
// business rule: approved OB must not be absent, must not be leave,
// and must not automatically become overtime.
function getApprovedOBMap($pdo, $employeeIds, $startDate, $endDate)
{
    $map = [];
    if (empty($employeeIds)) {
        return $map;
    }
    try {
        $ph = implode(',', array_fill(0, count($employeeIds), '?'));
        $stmt = $pdo->prepare("
            SELECT employee_id, ob_date
            FROM official_business_requests
            WHERE status = 'approved'
              AND employee_id IN ($ph)
              AND ob_date BETWEEN ? AND ?
        ");
        $stmt->execute(array_merge($employeeIds, [$startDate, $endDate]));
        foreach ($stmt->fetchAll() as $ob) {
            $map[(int) $ob['employee_id']][$ob['ob_date']] = true;
        }
    } catch (PDOException $e) {
        error_log("getApprovedOBMap skipped: " . $e->getMessage());
    }
    return $map;
}

// =====================================================
// HOLIDAY MAP (company-wide) for the Attendance display.
// Returns: $map['YYYY-MM-DD'] = holiday name
// =====================================================
function getHolidayMap($pdo, $startDate, $endDate)
{
    $map = [];
    try {
        $stmt = $pdo->prepare("
            SELECT holiday_date, name
            FROM holidays
            WHERE holiday_date BETWEEN ? AND ?
        ");
        $stmt->execute([$startDate, $endDate]);
        foreach ($stmt->fetchAll() as $h) {
            $map[$h['holiday_date']] = $h['name'];
        }
    } catch (PDOException $e) {
        error_log("getHolidayMap skipped: " . $e->getMessage());
    }
    return $map;
}

// =====================================================
// PAYROLL SCOPING (role-aware)
// =====================================================
// Returns the list of employees that should appear in the Payroll
// Run (and be the basis for History) for the currently logged-in
// user:
//   - HR Staff   : Employees only (role=employee/no account) —
//                   they run payroll for rank-and-file staff
//   - Admin      : HR Staff + HR Manager (they're the ones Admin
//                   pays); can also view everyone using
//                   $scopeOverride = 'all' or 'employee'
//   - HR Manager : no access to Payroll Run (they're view-only on
//                   History) — this function should not be called
//                   for them in payroll.php
// =====================================================
// AUTO-ABSENT MARKING
// =====================================================
// Previously "Absent" was manual/display-only (computed in memory
// every time the Attendance page was opened, never saved to the DB)
// — so it was missing from Monthly History (which reads straight
// from the `attendance` table only). Now, this is automatically
// written to the database: once a day has passed (it's in the past,
// not "today") and an active employee has no Time In, and they
// aren't on a holiday or an approved leave that day, they're
// automatically marked 'absent' in the `attendance` table — no
// manual action needed, and it shows up immediately in the Daily
// View and Monthly History.
//
// So the entire history isn't re-scanned every time someone opens
// the page, there's an `app_settings` key (`attendance_auto_mark_last_date`)
// that tracks up to which day marking has already been done. Each
// call just continues from there up to "yesterday" — "today" is
// excluded since the employee could still time in today.
function autoMarkPastAbsences($pdo)
{
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_settings (
                setting_key   VARCHAR(100) PRIMARY KEY,
                setting_value VARCHAR(255) NULL
            )
        ");

        $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'attendance_auto_mark_last_date'");
        $stmt->execute();
        $lastMarked = $stmt->fetchColumn();

        // Bootstrap: if nothing has been recorded yet, don't go back
        // too far (avoid a heavy first query) — the last 60 days is
        // enough.
        $earliestAllowed = date('Y-m-d', strtotime('-60 days', strtotime($yesterday)));
        $startScan = $lastMarked ? date('Y-m-d', strtotime('+1 day', strtotime($lastMarked))) : $earliestAllowed;
        if ($startScan < $earliestAllowed) {
            $startScan = $earliestAllowed;
        }
        if ($startScan > $yesterday) {
            return; // already up to date
        }

        $empStmt = $pdo->query("SELECT employee_id FROM employees WHERE status = 'active'");
        $employeeIds = array_map('intval', $empStmt->fetchAll(PDO::FETCH_COLUMN));
        if (empty($employeeIds)) {
            $pdo->prepare("
                INSERT INTO app_settings (setting_key, setting_value) VALUES ('attendance_auto_mark_last_date', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ")->execute([$yesterday]);
            return;
        }

        $holidayMap = getHolidayMap($pdo, $startScan, $yesterday);
        $leaveMap = getApprovedLeaveMap($pdo, $employeeIds, $startScan, $yesterday);

        // Each employee's weekly schedule (day_of_week => shift row or
        // null for Day Off) — preloaded once per employee to avoid an
        // N+1 query per day scanned. This is the source of truth for
        // which dates are a Rest Day and must NOT be marked 'absent'.
        $weeklySchedules = [];
        $hasScheduleSetup = [];
        foreach ($employeeIds as $eid) {
            $sched = getEmployeeWeeklySchedule($pdo, $eid);
            $weeklySchedules[$eid] = $sched;
            // Only trust "no shift this day = Day Off" if HR has
            // actually configured at least one day for this employee —
            // otherwise (brand-new employee, schedule not set up yet)
            // fall back to the old default behavior (mark absent) so
            // real no-shows still get caught.
            $hasScheduleSetup[$eid] = count(array_filter($sched)) > 0;
        }

        // Get all days that ALREADY HAVE a record in the scan range, so
        // we know who has NO record yet (needs to be marked absent /
        // day_off).
        $ph = implode(',', array_fill(0, count($employeeIds), '?'));
        $existStmt = $pdo->prepare("
            SELECT employee_id, date FROM attendance
            WHERE date BETWEEN ? AND ? AND employee_id IN ($ph)
        ");
        $existStmt->execute(array_merge([$startScan, $yesterday], $employeeIds));
        $existing = [];
        foreach ($existStmt->fetchAll() as $r) {
            $existing[$r['employee_id'] . '|' . $r['date']] = true;
        }

        $insertStmt = $pdo->prepare("
            INSERT IGNORE INTO attendance (employee_id, date, status)
            VALUES (?, ?, ?)
        ");

        $cursor = strtotime($startScan);
        $stop = strtotime($yesterday);
        while ($cursor <= $stop) {
            $d = date('Y-m-d', $cursor);
            $dow = (int) date('w', $cursor);
            if (!isset($holidayMap[$d])) {
                foreach ($employeeIds as $eid) {
                    if (isset($existing[$eid . '|' . $d])) {
                        continue; // already has a record (present/leave/etc.)
                    }
                    if (isset($leaveMap[$eid][$d])) {
                        continue; // approved leave, not absent
                    }
                    // Rest Day (no shift assigned that day-of-week, AND
                    // HR has actually configured a weekly schedule for
                    // this employee) — mark 'day_off', never 'absent',
                    // so it's excluded from Absent counts and never
                    // deducted from pay.
                    $isDayOff = $hasScheduleSetup[$eid] && empty($weeklySchedules[$eid][$dow]);
                    $insertStmt->execute([$eid, $d, $isDayOff ? 'day_off' : 'absent']);
                }
            }
            $cursor = strtotime('+1 day', $cursor);
        }

        $pdo->prepare("
            INSERT INTO app_settings (setting_key, setting_value) VALUES ('attendance_auto_mark_last_date', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ")->execute([$yesterday]);
    } catch (PDOException $e) {
        error_log("autoMarkPastAbsences error: " . $e->getMessage());
    }
}

// =====================================================
// GOVERNMENT CONTRIBUTIONS / WITHHOLDING TAX — SERVER-SIDE
// AUTHORITATIVE TABLES (PHP mirror of
// payroll/assets/gov_contributions.js)
// =====================================================
// IMPORTANT: these must be kept in sync with gov_contributions.js.
// The JS copy is what auto-fills the "auto" SSS/PhilHealth/Pag-IBIG/
// Tax fields for HR to *see* while editing; THIS copy is what
// save_record.php actually trusts when it recomputes and validates
// payroll before writing to the database. If the law/circular
// changes, edit BOTH copies together.
//
// Basis (same as gov_contributions.js):
//   SSS        — RA 11199, SSS Circular 2024-006 — 5% employee
//                share, MSC floor P5,000 / ceiling P35,000, P500 step
//   PhilHealth — RA 11223 (UHC Law) — 2.5% employee share,
//                floor P10,000 / ceiling P100,000
//   Pag-IBIG   — HDMF Circular 460 — 1% (<=P1,500) or 2% (>P1,500)
//                employee share, Maximum Fund Salary cap P10,000
//   Tax        — BIR Revised Withholding Tax Table, semi-monthly pay
//                frequency (RR 11-2018 Annex E, RA 10963 / TRAIN Law),
//                effective Jan 1, 2023, still in force for 2026. Item
//                14 (Phase 5 audit) — replaces the earlier flat 12%
//                company-policy rate with the actual BIR bracket table.
define('GOV_SSS_MIN_MSC', 5000);
define('GOV_SSS_MAX_MSC', 35000);
define('GOV_SSS_STEP', 500);
define('GOV_SSS_EMPLOYEE_RATE', 0.05);
define('GOV_PHIC_FLOOR', 10000);
define('GOV_PHIC_CEILING', 100000);
define('GOV_PHIC_EMPLOYEE_RATE', 0.025);
define('GOV_PAGIBIG_LOW_THRESHOLD', 1500);
define('GOV_PAGIBIG_LOW_RATE', 0.01);
define('GOV_PAGIBIG_NORMAL_RATE', 0.02);
define('GOV_PAGIBIG_MFS_CAP', 10000);

// BIR Revised Withholding Tax Table — SEMI-MONTHLY pay frequency
// (RR 11-2018 Annex E, TRAIN Law, RA 10963). Each row is
// [floor, base tax at floor, rate on excess over floor]. These are
// BIR's own printed peso figures — not a division of the annual or
// monthly table (BIR rounds each bracket boundary and accumulates tax
// across those rounded boundaries, so dividing the monthly/annual
// table by 2 or 24 lands a few centavos off).
define('GOV_BIR_SEMI_MONTHLY_TABLE', [
    ['floor' => 0, 'base' => 0.00, 'rate' => 0.00],
    ['floor' => 10417, 'base' => 0.00, 'rate' => 0.15],
    ['floor' => 16667, 'base' => 937.50, 'rate' => 0.20],
    ['floor' => 33333, 'base' => 4270.70, 'rate' => 0.25],
    ['floor' => 83333, 'base' => 16770.70, 'rate' => 0.30],
    ['floor' => 333333, 'base' => 91770.70, 'rate' => 0.35],
]);

// Absolute statutory MAXIMUMS an employee share can legally be — used
// as the "reasonable range" ceiling when HR manually overrides an
// auto field (govAuto.* = false in the payroll save payload). A
// manual value above this can only be a typo or tampering, since no
// real employee's share can exceed it under the tables above.
define('GOV_SSS_MAX_SHARE', GOV_SSS_MAX_MSC * GOV_SSS_EMPLOYEE_RATE);      // 1,750
define('GOV_PHIC_MAX_SHARE', GOV_PHIC_CEILING * GOV_PHIC_EMPLOYEE_RATE);    // 2,500
define('GOV_PAGIBIG_MAX_SHARE', GOV_PAGIBIG_MFS_CAP * GOV_PAGIBIG_NORMAL_RATE); // 200

function computeSSSEmployeeShare($monthlySalary)
{
    $salary = max(0, (float) $monthlySalary);
    $msc = floor($salary / GOV_SSS_STEP) * GOV_SSS_STEP;
    $msc = min(max($msc, GOV_SSS_MIN_MSC), GOV_SSS_MAX_MSC);
    return round($msc * GOV_SSS_EMPLOYEE_RATE, 2);
}

function computePhilHealthEmployeeShare($monthlySalary)
{
    $salary = max(0, (float) $monthlySalary);
    $base = min(max($salary, GOV_PHIC_FLOOR), GOV_PHIC_CEILING);
    return round($base * GOV_PHIC_EMPLOYEE_RATE, 2);
}

function computePagIbigEmployeeShare($monthlySalary)
{
    $salary = max(0, (float) $monthlySalary);
    $base = min($salary, GOV_PAGIBIG_MFS_CAP);
    $rate = $salary <= GOV_PAGIBIG_LOW_THRESHOLD ? GOV_PAGIBIG_LOW_RATE : GOV_PAGIBIG_NORMAL_RATE;
    return round($base * $rate, 2);
}

function computeWithholdingTaxSemiMonthly($taxableCompensation)
{
    $income = max(0, (float) $taxableCompensation);
    // Walk the BIR semi-monthly table (ascending floor order) and keep
    // the last bracket whose floor the income has reached.
    $bracket = GOV_BIR_SEMI_MONTHLY_TABLE[0];
    foreach (GOV_BIR_SEMI_MONTHLY_TABLE as $row) {
        if ($income >= $row['floor']) {
            $bracket = $row;
        } else {
            break;
        }
    }
    $excess = $income - $bracket['floor'];
    return round($bracket['base'] + $excess * $bracket['rate'], 2);
}

// Government contributions are deducted only ONCE per month — on the
// FIRST cutoff (1st–15th), never on the 2nd (16th–end).
function isFirstCutoffOfMonth($periodStartISO)
{
    if (!is_string($periodStartISO) || $periodStartISO === '')
        return true;
    $parts = explode('-', $periodStartISO);
    $day = isset($parts[2]) ? (int) $parts[2] : null;
    return $day !== null ? $day <= 15 : true;
}

function computeGovDeductionsForCutoff($monthlyBasicSalary, $periodStartISO)
{
    if (!isFirstCutoffOfMonth($periodStartISO)) {
        return ['sss' => 0.0, 'phic' => 0.0, 'pagibig' => 0.0];
    }
    return [
        'sss' => computeSSSEmployeeShare($monthlyBasicSalary),
        'phic' => computePhilHealthEmployeeShare($monthlyBasicSalary),
        'pagibig' => computePagIbigEmployeeShare($monthlyBasicSalary),
    ];
}

// =====================================================
// ACTUAL/VERIFIED OT — Item 11 (Phase 4 HRMS audit)
// =====================================================
// An overtime_requests row only ever stores hours_requested — a number
// the employee (or their Supervisor, via "Log an Overtime Request")
// typed in. Nothing shows the Supervisor what the employee's `attendance`
// row for that exact date actually says once it's punched (regular
// time_in/time_out, same as any other day — OT here is always an
// extension of that same shift, not a separate rest-day/split-session
// clock; confirmed with the business before implementing).
//
// This does NOT introduce a second/competing OT-hours source. Payroll
// (computePayrollAttendanceSummary() below) already independently
// derives the real payable OT from `attendance` and caps it at the
// approved hours_requested — that math is untouched. This function is
// purely for DISPLAY, so a Supervisor can see the actual clocked time
// side-by-side with the requested hours BEFORE approving/rejecting,
// instead of approving a number blind. "Requested" vs "Actual/verified"
// must never be shown as the same figure — always label both.
//
// Returns:
//   has_attendance   - false if the employee hasn't punched that date yet
//   time_in/time_out - raw attendance punches for that date (nullable)
//   shift_start/end  - the shift used to compute expected hours (nullable
//                       if the employee has no configured schedule for
//                       that day-of-week)
//   expected_hours   - hours expected for that day's shift
//   worked_hours     - actual hours between time_in and time_out
//   actual_ot_hours  - worked_hours beyond expected_hours (0 floor);
//                       this is the same "actual excess" figure Payroll
//                       uses as its ceiling input, shown here early.
function getActualOvertimeForEmployeeDate($pdo, $employee_id, $date)
{
    $info = [
        'has_attendance' => false,
        'time_in' => null,
        'time_out' => null,
        'shift_start' => null,
        'shift_end' => null,
        'expected_hours' => null,
        'worked_hours' => 0.0,
        'actual_ot_hours' => 0.0,
    ];
    try {
        $stmt = $pdo->prepare("
            SELECT a.time_in, a.time_out, e.schedule_type
            FROM attendance a
            JOIN employees e ON e.employee_id = a.employee_id
            WHERE a.employee_id = ? AND a.date = ?
        ");
        $stmt->execute([$employee_id, $date]);
        $row = $stmt->fetch();
        if (!$row || empty($row['time_in']) || empty($row['time_out'])) {
            return $info; // not punched (or not fully punched) yet — nothing to compare against
        }

        $info['has_attendance'] = true;
        $info['time_in'] = $row['time_in'];
        $info['time_out'] = $row['time_out'];

        $dow = (int) date('w', strtotime($date));
        $shift = (getEmployeeWeeklySchedule($pdo, $employee_id))[$dow] ?? null;
        $expectedHours = $shift
            ? computeShiftDurationHours($shift['start_time'], $shift['end_time'])
            : (($row['schedule_type'] ?? 'full_time') === 'part_time' ? PART_TIME_SHIFT_HOURS : STANDARD_SHIFT_HOURS);
        $shiftStartStr = $shift['start_time'] ?? SHIFT_START_TIME;

        $info['shift_start'] = $shift['start_time'] ?? null;
        $info['shift_end'] = $shift['end_time'] ?? null;
        $info['expected_hours'] = $expectedHours;

        $timeInTs = strtotime($date . ' ' . $row['time_in']);
        $timeOutTs = strtotime($date . ' ' . $row['time_out']);
        $shiftStartTs = strtotime($date . ' ' . $shiftStartStr);
        if ($timeOutTs < $timeInTs) {
            $timeOutTs += 86400;
        }

        $effectiveTimeInTs = max($timeInTs, $shiftStartTs);
        $workedHours = ($timeOutTs - $effectiveTimeInTs) / 3600;
        if ($workedHours < 0) {
            $workedHours = 0;
        }

        $info['worked_hours'] = round($workedHours, 2);
        $info['actual_ot_hours'] = round(max($workedHours - $expectedHours, 0), 2);
    } catch (PDOException $e) {
        error_log("getActualOvertimeForEmployeeDate error: " . $e->getMessage());
    }
    return $info;
}

// =====================================================
// PAYROLL ATTENDANCE SUMMARY (shared: used by both the Payroll Run
// page display AND save_record.php's server-side recompute) — SINGLE
// SOURCE OF TRUTH so the numbers HR sees while typing and the numbers
// actually saved/validated can never drift apart. Returns, per active/
// on-leave employee_id: presentDays (paid days: worked ∪ holiday ∪
// approved leave), workedDays, leaveDays, holidayDays, lateMinutes,
// otHours, undertimeHours — for the given [periodStart, periodEnd].
// =====================================================
function computePayrollAttendanceSummary($pdo, $periodStart, $periodEnd)
{
    $summary = [];
    try {
        $attStmt = $pdo->prepare("
            SELECT a.employee_id, a.date, a.time_in, a.time_out, a.late_minutes, e.schedule_type
            FROM attendance a
            JOIN employees e ON e.employee_id = a.employee_id
            WHERE a.date BETWEEN ? AND ?
        ");
        $attStmt->execute([$periodStart, $periodEnd]);
        $attRows = $attStmt->fetchAll();

        $empIdsInPeriod = array_values(array_unique(array_map(function ($r) {
            return (int) $r['employee_id'];
        }, $attRows)));
        $weeklySchedules = [];
        foreach ($empIdsInPeriod as $eid) {
            $weeklySchedules[$eid] = getEmployeeWeeklySchedule($pdo, $eid);
        }

        // ---- Approved OT ceiling per employee/date (Item 10 — Phase 4
        //      HRMS audit) --------------------------------------------
        // Only hours covered by an APPROVED overtime_requests row for
        // that exact employee+date are payable as OT. Actual clock-out
        // time past the scheduled shift is still tracked separately
        // (total_ot_hours_actual, below) for HR's visibility/audit, but
        // it no longer pays out on its own — an employee who simply
        // stayed late without a supervisor-approved OT request is not
        // auto-paid OT for that time. Multiple approved requests on the
        // same date (rare, but not blocked by the schema) are summed
        // to form that date's approved ceiling.
        $approvedOtMap = [];
        $otStmt = $pdo->prepare("
            SELECT employee_id, ot_date, hours_requested
            FROM overtime_requests
            WHERE status = 'approved' AND ot_date BETWEEN ? AND ?
        ");
        $otStmt->execute([$periodStart, $periodEnd]);
        foreach ($otStmt->fetchAll() as $otRow) {
            $oeid = (int) $otRow['employee_id'];
            $odate = $otRow['ot_date'];
            if (!isset($approvedOtMap[$oeid][$odate])) {
                $approvedOtMap[$oeid][$odate] = 0.0;
            }
            $approvedOtMap[$oeid][$odate] += (float) $otRow['hours_requested'];
        }

        $aggr = [];
        foreach ($attRows as $row) {
            $eid = (int) $row['employee_id'];
            if (!isset($aggr[$eid])) {
                $aggr[$eid] = ['total_late_minutes' => 0, 'total_ot_hours' => 0.0, 'total_ot_hours_actual' => 0.0, 'total_undertime_hours' => 0.0];
            }
            $aggr[$eid]['total_late_minutes'] += (int) ($row['late_minutes'] ?? 0);

            if (!empty($row['time_in']) && !empty($row['time_out'])) {
                $dow = (int) date('w', strtotime($row['date']));
                $shift = $weeklySchedules[$eid][$dow] ?? null;
                $expectedHours = $shift
                    ? computeShiftDurationHours($shift['start_time'], $shift['end_time'])
                    : (($row['schedule_type'] ?? 'full_time') === 'part_time' ? PART_TIME_SHIFT_HOURS : STANDARD_SHIFT_HOURS);

                $shiftStartStr = $shift['start_time'] ?? SHIFT_START_TIME;
                $timeInTs = strtotime($row['date'] . ' ' . $row['time_in']);
                $timeOutTs = strtotime($row['date'] . ' ' . $row['time_out']);
                $shiftStartTs = strtotime($row['date'] . ' ' . $shiftStartStr);
                if ($timeOutTs < $timeInTs) {
                    $timeOutTs += 86400;
                }

                $effectiveTimeInTs = max($timeInTs, $shiftStartTs);
                $workedHours = ($timeOutTs - $effectiveTimeInTs) / 3600;
                if ($workedHours < 0) {
                    $workedHours = 0;
                }

                $dayOtActual = max($workedHours - $expectedHours, 0);
                $dayOtApprovedCeiling = $approvedOtMap[$eid][$row['date']] ?? 0.0;
                // Payable OT can never exceed what was actually worked,
                // even if more was approved than was ultimately clocked.
                $dayOtPayable = min($dayOtActual, $dayOtApprovedCeiling);

                $aggr[$eid]['total_ot_hours'] += $dayOtPayable;
                $aggr[$eid]['total_ot_hours_actual'] += $dayOtActual;
                $aggr[$eid]['total_undertime_hours'] += max($expectedHours - $workedHours, 0);
            }
        }

        $activeEmpIds = array_map('intval', $pdo->query("
            SELECT employee_id FROM employees WHERE status IN ('active', 'on-leave')
        ")->fetchAll(PDO::FETCH_COLUMN));

        $pdStmt = $pdo->prepare("
            SELECT employee_id, date FROM attendance
            WHERE date BETWEEN ? AND ? AND time_in IS NOT NULL AND time_out IS NOT NULL
        ");
        $pdStmt->execute([$periodStart, $periodEnd]);
        $presentDates = [];
        foreach ($pdStmt->fetchAll() as $r) {
            $presentDates[(int) $r['employee_id']][$r['date']] = true;
        }

        $holidayDates = array_keys(getHolidayMap($pdo, $periodStart, $periodEnd));
        $leaveMap = getApprovedLeaveMap($pdo, $activeEmpIds, $periodStart, $periodEnd);
        // Item 12 — approved Official Business counts as PRESENT for
        // payroll, same treatment as a holiday or approved leave.
        $obMap = getApprovedOBMap($pdo, $activeEmpIds, $periodStart, $periodEnd);

        foreach ($activeEmpIds as $eid) {
            $worked = $presentDates[$eid] ?? [];
            $leaveDates = isset($leaveMap[$eid]) ? array_keys($leaveMap[$eid]) : [];
            $obDates = isset($obMap[$eid]) ? array_keys($obMap[$eid]) : [];

            $paid = $worked; // set of dates (key = date), so automatically unique
            foreach ($holidayDates as $hd) {
                $paid[$hd] = true;
            }
            foreach ($leaveDates as $ld) {
                $paid[$ld] = true;
            }
            foreach ($obDates as $od) {
                $paid[$od] = true;
            }

            $a = $aggr[$eid] ?? null;
            $summary[$eid] = [
                'presentDays' => count($paid),
                'workedDays' => count($worked),
                'leaveDays' => count($leaveDates),
                'holidayDays' => count($holidayDates),
                'obDays' => count($obDates),
                'lateMinutes' => $a ? (int) $a['total_late_minutes'] : 0,
                // Approved-OT-capped hours — this is what actually gets paid
                // (see the loop above). otHoursActual is the raw excess-over-
                // schedule time actually clocked, kept for HR's audit/visibility
                // only; it is intentionally NOT used in the pay computation.
                'otHours' => $a ? round((float) $a['total_ot_hours'], 2) : 0,
                'otHoursActual' => $a ? round((float) $a['total_ot_hours_actual'], 2) : 0,
                'undertimeHours' => $a ? round((float) $a['total_undertime_hours'], 2) : 0,
            ];
        }
    } catch (PDOException $e) {
        error_log("computePayrollAttendanceSummary error: " . $e->getMessage());
    }
    return $summary;
}

function getPayrollScopedEmployees($pdo, $scopeOverride = null)
{
    $role = $_SESSION['role'] ?? '';
    $roleExpr = acctRoleCaseSQL('u');

    $stmt = $pdo->prepare("
        SELECT e.employee_id, e.employee_code, e.first_name, e.last_name, e.department, e.basic_salary,
               e.bank_name, e.bank_account_number, e.schedule_type, e.payment_method,
               e.date_hired,
               $roleExpr AS acct_role
        FROM employees e
        LEFT JOIN users u ON u.employee_id = e.employee_id
        WHERE e.status = 'active'
        ORDER BY e.last_name, e.first_name
    ");
    $stmt->execute();
    $all = $stmt->fetchAll();

    // Admin can override the default scope via ?scope=employee|hr|all
    if ($role === 'admin' && in_array($scopeOverride, ['employee', 'hr', 'all'], true)) {
        $wanted = $scopeOverride;
    } elseif ($role === 'admin') {
        // Default to 'all' so Admin always sees the full roster (Employees +
        // HR Staff + HR Manager) on first load — matches History/13th Month.
        // Previously defaulted to 'hr', which showed a blank Payroll Run
        // whenever no HR Staff/HR Manager accounts existed yet.
        $wanted = 'all';
    } elseif ($role === 'hr') {
        $wanted = 'employee';
    } elseif ($role === 'hr_manager') {
        // Default: same scope as HR Staff — the Employees list. But
        // HR Manager can also switch to ?scope=hr to see/process
        // payroll for their fellow HR Staff (subject to PENDING ADMIN
        // APPROVAL before it's released — see payroll/save_record.php).
        $wanted = in_array($scopeOverride, ['employee', 'hr'], true) ? $scopeOverride : 'employee';
    } else {
        $wanted = 'none';
    }

    $out = [];
    foreach ($all as $row) {
        $acctRole = $row['acct_role'];
        $include = false;
        if ($wanted === 'all') {
            $include = true;
        } elseif ($wanted === 'employee') {
            $include = ($acctRole === 'employee');
        } elseif ($wanted === 'hr') {
            $include = in_array($acctRole, ['hr', 'hr_manager'], true);
        }
        if (!$include)
            continue;

        // Bank/card details — shown on the Payroll Run so HR knows
        // where the salary will go. The account number is masked
        // (last 4 digits shown).
        $acctDigits = preg_replace('/\s+/', '', (string) ($row['bank_account_number'] ?? ''));
        $acctMasked = $acctDigits !== ''
            ? ((strlen($acctDigits) > 4 ? str_repeat('•', strlen($acctDigits) - 4) : '') . substr($acctDigits, -4))
            : '';

        $scheduleType = $row['schedule_type'] ?? 'full_time';
        $out[] = [
            'id' => (int) $row['employee_id'],
            'code' => $row['employee_code'] ?: '',
            'name' => $row['last_name'] . ', ' . $row['first_name'],
            'dept' => $row['department'],
            'status' => 'Active',
            'basic' => (float) $row['basic_salary'],
            'bank' => $row['bank_name'] ?: '',
            'bankAcct' => $acctMasked,
            'hasBank' => ($row['bank_name'] ?? '') !== '' || $acctDigits !== '',
            'paymentMethod' => $row['payment_method'] ?? 'bank',
            'scheduleType' => $scheduleType,
            // Date the employee was actually hired/started (from HRMS ->
            // Employees -> "Date Hired"). Payroll Run / History / payslips
            // should never start earlier than this - an employee shouldn't
            // be shown or paid for a pay period that ended before they
            // even started reporting for work. Null-safe (older/legacy
            // rows may not have it set).
            'dateHired' => $row['date_hired'] ?: null,
            // Actual shift hours to use for this employee's OT/Undertime
            // rate — 6 hrs for part-time, 8 hrs (STANDARD_SHIFT_HOURS)
            // for full-time. This is what payroll.js uses instead of a
            // single "Working hours/day" assumption for everyone.
            'shiftHours' => $scheduleType === 'part_time' ? PART_TIME_SHIFT_HOURS : STANDARD_SHIFT_HOURS,
        ];
    }
    return $out;
}

// =====================================================
// PAYROLL HISTORY SCOPING (role-aware, DB-backed)
// =====================================================
// The History tab of the Payroll module used to be based only on
// browser localStorage (per-browser, not shared between users) — so
// HR Manager couldn't see the payroll records HR Staff had saved.
// Here, we pull directly from the `payroll_records` table (the
// actual source of truth) grouped by pay period, and scope it based
// on role, so everyone sees the same real, shared data:
//   - HR Staff   : periods where the salaries belong to Employees
//                   (their own work)
//   - HR Manager : the SAME periods (view-only oversight)
//   - Admin      : periods where the salaries belong to HR
//                   Staff/HR Manager (payroll they ran themselves),
//                   BUT can also see the 'all' scope
function getPayrollHistoryRecords($pdo, $scopeOverride = null)
{
    $role = $_SESSION['role'] ?? '';
    $roleExpr = acctRoleCaseSQL('u');

    if ($role === 'admin') {
        $wanted = in_array($scopeOverride, ['employee', 'hr', 'all'], true) ? $scopeOverride : 'all';
    } elseif ($role === 'hr') {
        $wanted = 'employee';
    } elseif ($role === 'hr_manager') {
        // HR Manager: 'employee' (default — oversight of HR Staff's
        // work) or 'hr' (payroll they ran themselves FOR fellow HR
        // Staff — this only shows up ONCE the Accountant has APPROVED it,
        // because of the WHERE clause below that excludes
        // 'pending'/'rejected').
        $wanted = in_array($scopeOverride, ['employee', 'hr'], true) ? $scopeOverride : 'employee';
    } else {
        return [];
    }

    // While a payroll batch is still 'pending' or 'rejected' (still
    // awaiting Finance/Admin's decision, or was declined), it is hidden from
    // normal History. Employees separately require payment_status='paid'
    // before payslip listings or the print route are available.
    $stmt = $pdo->prepare("
        SELECT pr.*, e.first_name, e.last_name, e.department, $roleExpr AS acct_role,
               gu.full_name AS generated_by_name
        FROM payroll_records pr
        JOIN employees e ON e.employee_id = pr.employee_id
        LEFT JOIN users u ON u.employee_id = e.employee_id
        LEFT JOIN users gu ON gu.id = pr.generated_by
        WHERE pr.approval_status IN ('n/a', 'approved')
        ORDER BY pr.pay_period_end DESC, pr.pay_period_start DESC, e.last_name, e.first_name
    ");
    $stmt->execute();

    $grouped = [];
    foreach ($stmt->fetchAll() as $r) {
        $acctRole = $r['acct_role'];
        $include = false;
        if ($wanted === 'all') {
            $include = true;
        } elseif ($wanted === 'employee') {
            $include = ($acctRole === 'employee');
        } elseif ($wanted === 'hr') {
            $include = in_array($acctRole, ['hr', 'hr_manager'], true);
        }
        if (!$include)
            continue;

        $key = $r['pay_period_start'] . '_' . $r['pay_period_end'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'id' => 'db_' . $key,
                'periodStart' => $r['pay_period_start'],
                'periodEnd' => $r['pay_period_end'],
                'payoutDate' => $r['payout_date'],
                'savedAt' => $r['generated_at'],
                'generatedBy' => $r['generated_by_name'],
                'rows' => [],
                'totals' => ['gross' => 0, 'ded' => 0, 'net' => 0],
            ];
        }
        $totalDed = (float) $r['sss_deduction'] + (float) $r['philhealth_deduction'] + (float) $r['pagibig_deduction']
            + (float) $r['tax_deduction'] + (float) $r['other_deductions'];
        $grouped[$key]['rows'][] = [
            'empId' => (int) $r['employee_id'],
            'name' => $r['last_name'] . ', ' . $r['first_name'],
            'dept' => $r['department'],
            'basicPay' => (float) $r['basic_pay'],
            'otPay' => (float) $r['overtime_pay'],
            'allow' => (float) $r['allowances'],
            'gross' => (float) $r['gross_pay'],
            'sss' => (float) $r['sss_deduction'],
            'phic' => (float) $r['philhealth_deduction'],
            'pagibig' => (float) $r['pagibig_deduction'],
            'tax' => (float) $r['tax_deduction'],
            'other' => (float) $r['other_deductions'],
            'totalDed' => $totalDed,
            'net' => (float) $r['net_pay'],
        ];
        $grouped[$key]['totals']['gross'] += (float) $r['gross_pay'];
        $grouped[$key]['totals']['ded'] += $totalDed;
        $grouped[$key]['totals']['net'] += (float) $r['net_pay'];
    }

    $records = array_values($grouped);
    $records = array_map(function ($rec) {
        $rec['periodLabel'] = date('M d', strtotime($rec['periodStart'])) . '–' . date('M d, Y', strtotime($rec['periodEnd']));
        return $rec;
    }, $records);
    return $records;
}

// =====================================================
// PENDING PAYROLL APPROVALS (Accountant only)
// =====================================================
// These are the payroll batches submitted by HR Staff/Manager
// (approval_status = 'pending') — they shouldn't show up anywhere
// yet (History, My Payslips, payslip.php) until Finance/Admin has decided on
// them. Grouped by pay period + generated_by (who the HR
// submitter actually was), so each "batch" stays separate even if
// the periods overlap.
function getPendingPayrollApprovals($pdo)
{
    if (($_SESSION['role'] ?? '') !== 'accountant') {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT pr.*, e.first_name, e.last_name, e.department, e.status AS emp_status,
               gu.full_name AS generated_by_name
        FROM payroll_records pr
        JOIN employees e ON e.employee_id = pr.employee_id
        LEFT JOIN users gu ON gu.id = pr.generated_by
        WHERE pr.approval_status = 'pending'
        ORDER BY pr.pay_period_end DESC, pr.pay_period_start DESC, e.last_name, e.first_name
    ");
    $stmt->execute();

    $grouped = [];
    foreach ($stmt->fetchAll() as $r) {
        $key = $r['pay_period_start'] . '_' . $r['pay_period_end'] . '_' . $r['generated_by'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'batchKey' => $key,
                'periodStart' => $r['pay_period_start'],
                'periodEnd' => $r['pay_period_end'],
                'payoutDate' => $r['payout_date'],
                'submittedAt' => $r['generated_at'],
                'submittedBy' => $r['generated_by_name'],
                'generatedBy' => (int) $r['generated_by'],
                'rows' => [],
                'totals' => ['gross' => 0, 'ded' => 0, 'net' => 0],
            ];
        }
        $totalDed = (float) $r['sss_deduction'] + (float) $r['philhealth_deduction'] + (float) $r['pagibig_deduction']
            + (float) $r['tax_deduction'] + (float) $r['other_deductions'];
        $grouped[$key]['rows'][] = [
            'empId' => (int) $r['employee_id'],
            'name' => $r['last_name'] . ', ' . $r['first_name'],
            'dept' => $r['department'],
            'gross' => (float) $r['gross_pay'],
            'net' => (float) $r['net_pay'],
            // 'active'/'on-leave' are the only statuses still eligible for
            // release — anything else (resigned/terminated) means this row
            // will be held back by the separate Finance release step even if
            // Accountant approves this batch, so flag it before approval.
            'empStatus' => $r['emp_status'],
            'blockedFromRelease' => !in_array($r['emp_status'], ['active', 'on-leave'], true),
        ];
        $grouped[$key]['totals']['gross'] += (float) $r['gross_pay'];
        $grouped[$key]['totals']['ded'] += $totalDed;
        $grouped[$key]['totals']['net'] += (float) $r['net_pay'];
    }

    $records = array_values($grouped);
    $records = array_map(function ($rec) {
        $rec['periodLabel'] = date('M d', strtotime($rec['periodStart'])) . '–' . date('M d, Y', strtotime($rec['periodEnd']));
        return $rec;
    }, $records);
    return $records;
}
// =====================================================
// POS SNAPSHOT (Admin Dashboard only)
// ------------------------------------------------------
// Today's sales total, today's transaction count, and low stock
// alerts from the POS side — read-only, via $posPdo. Returns null
// if $posPdo is unavailable (caller should hide the panel in that
// case rather than show empty/zeroed figures).
// =====================================================
function getPosSnapshotForAdmin($posPdo)
{
    if (!$posPdo)
        return null;

    try {
        // Completed lang — hindi kasama ang na-void/na-refund na benta
        // (ibinalik na ang pera/stock sa mga iyon sa POS side).
        $today_sales = $posPdo->query("SELECT SUM(total_amount) FROM sales WHERE DATE(transaction_date) = CURDATE() AND status = 'completed'")->fetchColumn() ?? 0;
        $today_transactions = $posPdo->query("SELECT COUNT(*) FROM sales WHERE DATE(transaction_date) = CURDATE() AND status = 'completed'")->fetchColumn();

        $stmt = $posPdo->prepare("SELECT COUNT(*) FROM products WHERE stock <= :low_stock");
        $stmt->execute([':low_stock' => 10]);
        $low_stock_count = $stmt->fetchColumn();

        $stmt = $posPdo->prepare("SELECT product_name, stock FROM products WHERE stock <= :low_stock ORDER BY stock ASC LIMIT 5");
        $stmt->execute([':low_stock' => 10]);
        $low_stock_products = $stmt->fetchAll();

        return [
            'today_sales' => (float) $today_sales,
            'today_transactions' => (int) $today_transactions,
            'low_stock_count' => (int) $low_stock_count,
            'low_stock_products' => $low_stock_products,
        ];
    } catch (PDOException $e) {
        error_log("getPosSnapshotForAdmin skipped: " . $e->getMessage());
        return null;
    }
}

// =====================================================
// BUSINESS CASH POSITION (Payroll <-> POS Profit/Capital)
// ------------------------------------------------------
// Binabasa nito ang parehong `capital_transactions` table sa
// grocery_pos database na ginagamit din ng POS Admin Reports page
// (see getBusinessCashPosition() sa MANAGE GROCERY/includes/config.php)
// — kaya laging magkatugma ang makikita dito sa Payroll at doon sa
// POS side. Ginagamit ito sa Payroll Run para malaman kung sapat pa
// ang kinita ng grocery (+ capital na idinagdag ni Owner, − payroll
// na na-release na) bago mag-release ng bagong batch ng sahod.
// Returns null kung wala/hindi maka-connect ang $posPdo.
// =====================================================
function getBusinessCashPosition($posPdo, $pdo)
{
    if (!$posPdo)
        return null;

    try {
        $revenue = (float) $posPdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE status = 'completed'")->fetchColumn();

        $cogsRow = $posPdo->query("
            SELECT COALESCE(SUM(CASE WHEN sbc.batch_id IS NOT NULL THEN sbc.quantity * pb.cost_price ELSE 0 END), 0) AS known_cogs
            FROM sale_batch_consumption sbc
            JOIN sales_details sd ON sd.id = sbc.sales_detail_id
            JOIN sales s ON s.id = sd.sale_id
            LEFT JOIN product_batches pb ON pb.id = sbc.batch_id
            WHERE s.status = 'completed'
        ")->fetch();
        $cogs = (float) ($cogsRow['known_cogs'] ?? 0);

        // All-time CASH-BASIS spend on restocking — mirrors
        // getTotalRestockCost() on the POS side (see that function's
        // comment in ../../includes/config.php for the full
        // rationale). Standard accrual, confirmed Aug 2026: an Accounts
        // Payable liability is recorded the moment goods are actually
        // received/fulfilled (recordAccountsPayable() /
        // recordPurchaseOrderReceipt(), POS side), but it only becomes a
        // CASH outflow once a Supplier Payment is actually made against
        // it. Three sources, added together with no overlap:
        //   1. Direct-cash stock_logs 'restock' rows — the plain Restock
        //      action on inventory.php (never AP-linked), PLUS Inventory
        //      Requests fulfilled with NO supplier attached (same
        //      immediate-cash behavior this app always had). Purchase
        //      Order receipts (always AP-linked — a PO always has a
        //      supplier) and supplier-linked Inventory Request
        //      fulfillments are excluded here; their cash impact is
        //      covered by #3 instead, only once actually paid.
        //   2. Inventory Requests marked "Fulfilled (Manual)" for a
        //      brand-new item (no product_id, so no stock_logs row at
        //      all) with NO supplier attached — same immediate-cash
        //      bucket as before AP tracking existed.
        //   3. Accounts Payable amounts actually PAID so far
        //      (supplier_payments, summed via accounts_payable.amount_paid)
        //      — covers every Purchase Order receipt and every
        //      supplier-linked Inventory Request fulfillment, cash-basis:
        //      only counted once money actually left.
        $apLinkedReasons = [];
        try {
            $rows = $posPdo->query("
                SELECT request_id FROM inventory_requests WHERE supplier_id IS NOT NULL AND status = 'fulfilled'
            ")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $rid) {
                $apLinkedReasons[] = 'Inventory Request #' . $rid . ' fulfillment';
            }
        } catch (PDOException $e) {
            error_log("getBusinessCashPosition (AP-linked reasons) skipped: " . $e->getMessage());
        }

        $sql = "SELECT COALESCE(SUM(total_cost), 0) FROM stock_logs WHERE change_type = 'restock' AND reason NOT LIKE '%(PO #%'";
        $params = [];
        if (!empty($apLinkedReasons)) {
            $placeholders = implode(',', array_fill(0, count($apLinkedReasons), '?'));
            $sql .= " AND reason NOT IN ($placeholders)";
            $params = $apLinkedReasons;
        }
        $stmt = $posPdo->prepare($sql);
        $stmt->execute($params);
        $directCashStockLogs = (float) $stmt->fetchColumn();

        $manualFulfilledNoSupplier = (float) $posPdo->query("
            SELECT COALESCE(SUM(estimated_cost), 0) FROM inventory_requests
            WHERE status = 'fulfilled' AND product_id IS NULL AND supplier_id IS NULL
        ")->fetchColumn();

        $apPaid = 0.0;
        try {
            $apPaid = (float) $posPdo->query("SELECT COALESCE(SUM(amount_paid), 0) FROM accounts_payable")->fetchColumn();
        } catch (PDOException $e) {
            error_log("getBusinessCashPosition (accounts_payable) skipped: " . $e->getMessage());
        }

        $totalRestockCost = $directCashStockLogs + $manualFulfilledNoSupplier + $apPaid;

        $capRow = $posPdo->query("
            SELECT COALESCE(SUM(CASE WHEN type = 'capital_in'  THEN amount ELSE 0 END), 0) AS capital_in,
                   COALESCE(SUM(CASE WHEN type = 'capital_out' THEN amount ELSE 0 END), 0) AS capital_out
            FROM capital_transactions
        ")->fetch();
        $capitalIn = (float) ($capRow['capital_in'] ?? 0);
        $capitalOut = (float) ($capRow['capital_out'] ?? 0);

        $payrollReleased = (float) $pdo->query("
            SELECT COALESCE(SUM(net_pay), 0) FROM payroll_records WHERE approval_status = 'approved' AND payment_status = 'paid'
        ")->fetchColumn();

        $grossProfit = $revenue - $cogs;

        return [
            'gross_profit' => $grossProfit,
            'capital_in' => $capitalIn,
            'capital_out' => $capitalOut,
            'restock_cost' => $totalRestockCost,
            'payroll_released' => $payrollReleased,
            'available_cash' => $revenue - $totalRestockCost + $capitalIn - $capitalOut - $payrollReleased,
        ];
    } catch (PDOException $e) {
        error_log("getBusinessCashPosition (HRMS side) skipped: " . $e->getMessage());
        return null;
    }
}

// Mula mismo sa HRMS Payroll page, kapag nakita ni Admin na kulang
// ang Business Cash Position para sa susunod na payroll, dito niya
// maitatala ang "Add Capital" (sariling pera niya na idinagdag sa
// negosyo) — pareho itong table na ginagamit ng POS Admin Reports
// (capital_transactions sa grocery_pos), kaya kahit saan ito idagdag,
// magkatugma pareho ang makikita.
function recordPosCapitalTransaction($posPdo, $type, $amount, $reason, $userId = null, $userName = null, $periodStart = null, $periodEnd = null)
{
    if (!$posPdo || !in_array($type, ['capital_in', 'capital_out'], true) || $amount <= 0) {
        return false;
    }
    try {
        $posPdo->exec("
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
        $stmt = $posPdo->prepare("
            INSERT INTO capital_transactions (type, amount, reason, payroll_period_start, payroll_period_end, created_by, created_by_name)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$type, $amount, $reason, $periodStart ?: null, $periodEnd ?: null, $userId, $userName]);
    } catch (PDOException $e) {
        error_log("recordPosCapitalTransaction skipped: " . $e->getMessage());
        return false;
    }
}

// =====================================================
// ANNOUNCEMENTS MODULE
// ------------------------------------------------------
// Central "Announcements" table, lives here in `hrms` (single source
// of truth — grocery_pos never gets its own copy, it only reads this
// table via $hrmsPdo, same reversed pattern as getPosSnapshotForAdmin()
// above reading FROM grocery_pos). See ANNOUNCEMENTS_MODULE_BUILD_PROMPT.md
// for the full spec this was built against.
//
// Who can create, and what happens after:
//   admin / hr_manager -> published immediately, any scope
//   hr (staff)          -> pending_approval first, any scope
//   supervisor           -> published immediately, but FORCED to
//                           scope='department' = their own department
//                           (never company_wide, never someone else's dept)
//   everyone else (employee, and every POS-only role) -> view only
// =====================================================

function ensureAnnouncementsTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS announcements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(150) NOT NULL,
                content TEXT NOT NULL,
                scope ENUM('company_wide','department') NOT NULL DEFAULT 'company_wide',
                department VARCHAR(100) DEFAULT NULL,
                status ENUM('draft','pending_approval','published','rejected','expired') NOT NULL DEFAULT 'draft',
                created_by INT NOT NULL,
                created_by_name VARCHAR(150) DEFAULT NULL,
                approved_by INT DEFAULT NULL,
                approved_by_name VARCHAR(150) DEFAULT NULL,
                reject_reason VARCHAR(255) DEFAULT NULL,
                published_at TIMESTAMP NULL DEFAULT NULL,
                expires_at DATE DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_ann_status (status),
                KEY idx_ann_scope (scope),
                KEY idx_ann_department (department),
                KEY idx_ann_created_by (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureAnnouncementsTable skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureAnnouncementsTable($pdo);
}

// Generic small audit trail (module + record_id + action), so
// "sino gumawa, sino nag-approve/reject, kailan na-publish" is always
// answerable later without digging through updated_at timestamps.
function ensureHrAuditLogTable($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS hr_audit_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                module VARCHAR(50) NOT NULL DEFAULT 'announcements',
                record_id INT DEFAULT NULL,
                action VARCHAR(50) NOT NULL,
                details VARCHAR(255) DEFAULT NULL,
                user_id INT DEFAULT NULL,
                user_name VARCHAR(150) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_hral_module (module),
                KEY idx_hral_record (module, record_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log("ensureHrAuditLogTable skipped: " . $e->getMessage());
    }
}
if ($__migrationsNeeded) {
    ensureHrAuditLogTable($pdo);
}

function logHrAudit($pdo, $module, $recordId, $action, $details, $userId, $userName)
{
    try {
        $pdo->prepare("
            INSERT INTO hr_audit_log (module, record_id, action, details, user_id, user_name)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$module, $recordId, $action, $details, $userId, $userName]);
    } catch (PDOException $e) {
        error_log("logHrAudit skipped: " . $e->getMessage());
    }
}

// Which HRMS roles are allowed anywhere near the "create announcement"
// action at all. Checked on EVERY action below (not just to decide
// whether to show the button) so a typed-in URL can't bypass it.
function canManageAnnouncements($role)
{
    return in_array($role, ['admin', 'hr', 'hr_manager', 'supervisor'], true);
}

// The logged-in user's own department, straight from `employees` —
// used to (a) force a Supervisor's announcement into their own
// department and (b) filter what a non-oversight role gets to see.
function getSessionEmployeeDepartment($pdo, $employeeId)
{
    if (!$employeeId)
        return null;
    $stmt = $pdo->prepare("SELECT department FROM employees WHERE employee_id = ?");
    $stmt->execute([$employeeId]);
    $dept = $stmt->fetchColumn();
    return $dept ?: null;
}

// Every distinct department currently in use — for the "Department"
// dropdown when creating a department-scoped announcement (Admin/HR
// Manager/HR Staff pick from these; Supervisor doesn't get a choice,
// see createAnnouncement() below).
function getAllDepartments($pdo)
{
    $stmt = $pdo->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// $actor = ['user_id' => int, 'name' => string, 'role' => string, 'employee_id' => int|null]
// $input = ['title' => string, 'content' => string, 'scope' => string, 'department' => string|null, 'expires_at' => string|null]
function createAnnouncement($pdo, array $actor, array $input)
{
    $role = $actor['role'] ?? '';
    if (!canManageAnnouncements($role)) {
        return ['success' => false, 'message' => 'You are not allowed to create announcements.'];
    }

    $title = trim((string) ($input['title'] ?? ''));
    $content = trim((string) ($input['content'] ?? ''));
    $scope = ($input['scope'] ?? 'company_wide') === 'department' ? 'department' : 'company_wide';
    $department = trim((string) ($input['department'] ?? '')) ?: null;
    $expiresAt = trim((string) ($input['expires_at'] ?? '')) ?: null;

    if ($title === '' || mb_strlen($title) > 150) {
        return ['success' => false, 'message' => 'Title is required (max 150 characters).'];
    }
    if ($content === '') {
        return ['success' => false, 'message' => 'Content is required.'];
    }
    if ($expiresAt && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
        return ['success' => false, 'message' => 'Invalid expiry date.'];
    }

    // SUPERVISOR: department-level lang, palagi — hindi pinagtitiwalaan
    // ang scope/department na galing sa form, kahit ano pa ang ipasa
    // dito, i-override, server-side, papuntang sariling department.
    if ($role === 'supervisor') {
        $scope = 'department';
        $department = getSessionEmployeeDepartment($pdo, $actor['employee_id'] ?? null);
        if (!$department) {
            return ['success' => false, 'message' => 'Could not determine your department — please contact Admin.'];
        }
    }

    if ($scope === 'department' && !$department) {
        return ['success' => false, 'message' => 'Please select a department for a department-scoped announcement.'];
    }
    if ($scope === 'company_wide') {
        $department = null;
    }

    // HR Staff needs approval first; Admin/HR Manager/Supervisor go
    // straight to published.
    $needsApproval = ($role === 'hr');
    $status = $needsApproval ? 'pending_approval' : 'published';
    $publishedAt = $needsApproval ? null : date('Y-m-d H:i:s');

    try {
        $stmt = $pdo->prepare("
            INSERT INTO announcements (title, content, scope, department, status, created_by, created_by_name, published_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$title, $content, $scope, $department, $status, $actor['user_id'], $actor['name'], $publishedAt, $expiresAt]);
        $newId = (int) $pdo->lastInsertId();

        logHrAudit(
            $pdo,
            'announcements',
            $newId,
            $needsApproval ? 'submitted' : 'published',
            "\"$title\" ($scope" . ($department ? ": $department" : '') . ")",
            $actor['user_id'],
            $actor['name']
        );

        return [
            'success' => true,
            'message' => $needsApproval
                ? 'Announcement submitted for approval. It will be visible once an HR Manager or Admin approves it.'
                : 'Announcement published.',
        ];
    } catch (PDOException $e) {
        error_log("createAnnouncement failed: " . $e->getMessage());
        return ['success' => false, 'message' => 'Could not save the announcement. Please try again.'];
    }
}

// Approve or reject a pending_approval announcement. Admin / HR
// Manager only — checked here again, not just hidden in the UI.
function decideAnnouncement($pdo, $id, array $actor, $decision, $rejectReason = null)
{
    $role = $actor['role'] ?? '';
    if (!in_array($role, ['admin', 'hr_manager'], true)) {
        return ['success' => false, 'message' => 'You are not allowed to approve or reject announcements.'];
    }
    if (!in_array($decision, ['approve', 'reject'], true)) {
        return ['success' => false, 'message' => 'Invalid decision.'];
    }

    $stmt = $pdo->prepare("SELECT * FROM announcements WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['success' => false, 'message' => 'Announcement not found.'];
    }
    if ($row['status'] !== 'pending_approval') {
        return ['success' => false, 'message' => 'This announcement is no longer pending approval.'];
    }

    try {
        if ($decision === 'approve') {
            $pdo->prepare("
                UPDATE announcements
                SET status = 'published', approved_by = ?, approved_by_name = ?, published_at = NOW(), reject_reason = NULL
                WHERE id = ?
            ")->execute([$actor['user_id'], $actor['name'], $id]);
            logHrAudit($pdo, 'announcements', $id, 'approved', "\"{$row['title']}\"", $actor['user_id'], $actor['name']);
            return ['success' => true, 'message' => 'Announcement approved and published.'];
        }

        $reason = trim((string) $rejectReason) ?: 'No reason given.';
        $pdo->prepare("
            UPDATE announcements
            SET status = 'rejected', approved_by = ?, approved_by_name = ?, reject_reason = ?
            WHERE id = ?
        ")->execute([$actor['user_id'], $actor['name'], $reason, $id]);
        logHrAudit($pdo, 'announcements', $id, 'rejected', "\"{$row['title']}\" — $reason", $actor['user_id'], $actor['name']);
        return ['success' => true, 'message' => 'Announcement rejected.'];
    } catch (PDOException $e) {
        error_log("decideAnnouncement failed: " . $e->getMessage());
        return ['success' => false, 'message' => 'Could not save that decision. Please try again.'];
    }
}

// Pull a PUBLISHED announcement back down (mistake, outdated, etc.)
// Admin / HR Manager only, OR the original creator taking down their
// own post. Reuses the 'rejected' status rather than deleting the
// row, so the audit trail stays intact.
function takeDownAnnouncement($pdo, $id, array $actor)
{
    $role = $actor['role'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM announcements WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['success' => false, 'message' => 'Announcement not found.'];
    }

    $isOwner = ((int) $row['created_by'] === (int) $actor['user_id']);
    if (!in_array($role, ['admin', 'hr_manager'], true) && !$isOwner) {
        return ['success' => false, 'message' => 'You are not allowed to take down this announcement.'];
    }
    if (!in_array($row['status'], ['published', 'pending_approval'], true)) {
        return ['success' => false, 'message' => 'This announcement cannot be taken down.'];
    }

    try {
        $pdo->prepare("
            UPDATE announcements
            SET status = 'rejected', reject_reason = ?
            WHERE id = ?
        ")->execute(["Taken down by {$actor['name']}", $id]);
        logHrAudit($pdo, 'announcements', $id, 'taken_down', "\"{$row['title']}\"", $actor['user_id'], $actor['name']);
        return ['success' => true, 'message' => 'Announcement taken down.'];
    } catch (PDOException $e) {
        error_log("takeDownAnnouncement failed: " . $e->getMessage());
        return ['success' => false, 'message' => 'Could not take that announcement down. Please try again.'];
    }
}

// PUBLISHED + not-yet-expired announcements visible to a given HRMS
// user. Admin/HR Manager see every published announcement (company
// oversight); everyone else only sees company_wide ones plus
// department-scoped ones for their OWN department — checked here in
// the query itself, not just hidden in the template.
function getVisibleAnnouncementsForHrmsUser($pdo, $role, $department)
{
    if (in_array($role, ['admin', 'hr_manager'], true)) {
        return $pdo->query("
            SELECT * FROM announcements
            WHERE status = 'published' AND (expires_at IS NULL OR expires_at >= CURDATE())
            ORDER BY published_at DESC
        ")->fetchAll();
    }
    $stmt = $pdo->prepare("
        SELECT * FROM announcements
        WHERE status = 'published' AND (expires_at IS NULL OR expires_at >= CURDATE())
          AND (scope = 'company_wide' OR (scope = 'department' AND department = ?))
        ORDER BY published_at DESC
    ");
    $stmt->execute([$department]);
    return $stmt->fetchAll();
}

// Admin / HR Manager only — the approval queue.
function getPendingAnnouncementsForApproval($pdo)
{
    return $pdo->query("
        SELECT * FROM announcements
        WHERE status = 'pending_approval'
        ORDER BY created_at ASC
    ")->fetchAll();
}

// Everything a given user has created (any status) — their own
// "My Announcements" tab, so an HR Staff member can see where their
// submission stands (pending / published / rejected).
function getMyAnnouncements($pdo, $userId)
{
    $stmt = $pdo->prepare("SELECT * FROM announcements WHERE created_by = ? ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// =====================================================
// POS SIDE READ (via $hrmsPdo) — company_wide + published + not
// expired ONLY. Department-scoped HR announcements never leave HRMS.
// Fail-open: null/empty on any connection problem, never an error —
// caller just hides the panel, exactly like getPosSnapshotForAdmin().
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
