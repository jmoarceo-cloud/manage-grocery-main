<?php
// =====================================================
// GENERATE DISCOUNT APPROVAL CODE
// ------------------------------------------------------
// Called via fetch() from supervisor_dashboard.php. A Supervisor
// (or Admin) taps "Generate Code" and gets back a one-time, 6-digit
// numeric code that's valid for a few minutes. They read it out to
// the cashier, who types it into the Senior/PWD discount popup on
// pos.php. See ensureDiscountApprovalCodesTable() and
// validateAndConsumeDiscountApprovalCode() in includes/config.php
// (both HRMS and POS copies) for the rest of the flow.
// =====================================================

require_once 'includes/config.php';
requireSupervisorRole(); // Admin + Supervisor only
requireCSRFJson();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request.');
}

$employeeId = (int) ($_SESSION['employee_id'] ?? 0);
$fullName   = trim($_SESSION['full_name'] ?? '');

if (!$employeeId || $fullName === '') {
    jsonResponse(false, 'Your Supervisor session is missing employee info — please log out and back in.');
}

// A generic minutes-valid window — long enough to read the code out
// loud and have the cashier type it, short enough that a code from
// an earlier customer can't be reused later in the shift.
const DISCOUNT_CODE_VALID_MINUTES = 5;

try {
    // Invalidate this Supervisor's own still-active codes first, so
    // only their most recent code is usable — avoids a pile of old
    // active codes quietly working if someone jots one down.
    $pdo->prepare("UPDATE discount_approval_codes SET status = 'cancelled' WHERE generated_by_employee_id = ? AND status = 'active'")
        ->execute([$employeeId]);

    // Generate a 6-digit numeric code that isn't currently active for
    // ANYONE (extremely unlikely to collide, but check anyway).
    do {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT 1 FROM discount_approval_codes WHERE code = ? AND status = 'active' AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn());

    $expiresAt = date('Y-m-d H:i:s', time() + (DISCOUNT_CODE_VALID_MINUTES * 60));

    $stmt = $pdo->prepare("
        INSERT INTO discount_approval_codes (code, generated_by_employee_id, generated_by_name, status, expires_at)
        VALUES (?, ?, ?, 'active', ?)
    ");
    $stmt->execute([$code, $employeeId, $fullName, $expiresAt]);

    jsonResponse(true, 'Code generated.', [
        'code'               => $code,
        'expires_at'         => $expiresAt,
        'expires_in_seconds' => DISCOUNT_CODE_VALID_MINUTES * 60,
    ]);
} catch (PDOException $e) {
    error_log("generate_discount_code error: " . $e->getMessage());
    jsonResponse(false, 'Could not generate a code right now. Please try again.');
}