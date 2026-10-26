<?php
// ============================================================
// payroll/capital_transaction.php
// "Add Capital / Owner Transaction" from the HRMS Payroll side.
//
// When the Business Cash Position (POS profit + capital added by
// Owner, minus payroll already released) isn't enough for the next
// payroll batch, Admin records their own money added to the
// business here (or the reverse — an owner withdrawal). This is
// written to the same `capital_transactions` table in the
// grocery_pos database that the POS Admin Reports page also uses
// (see recordPosCapitalTransaction() in includes/config.php), so
// both sides always stay in sync.
// ============================================================

require_once __DIR__ . '/../includes/config.php';
requireAdmin(); // Admin only — has authority to record business capital

requireCSRF();

$type        = ($_POST['capital_type'] ?? 'capital_in') === 'capital_out' ? 'capital_out' : 'capital_in';
$amount      = (float) ($_POST['amount'] ?? 0);
$reason      = trim($_POST['reason'] ?? '');
$periodStart = $_POST['period_start'] ?? null;
$periodEnd   = $_POST['period_end']   ?? null;
$back        = 'payroll.php';
if (!empty($periodStart) && !empty($periodEnd) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) {
    $back .= '?period_start=' . urlencode($periodStart) . '&period_end=' . urlencode($periodEnd);
} else {
    $periodStart = null;
    $periodEnd   = null;
}

if ($amount <= 0) {
    $_SESSION['flash_error'] = "Please enter a valid amount.";
} elseif (mb_strlen($reason) < 3) {
    $_SESSION['flash_error'] = "Please enter a reason (e.g. \"Payroll shortfall — Jul 1-15\").";
} elseif (!$posPdo) {
    $_SESSION['flash_error'] = "Could not connect to the POS database right now — the transaction could not be saved.";
} else {
    $ok = recordPosCapitalTransaction(
        $posPdo,
        $type,
        $amount,
        $reason,
        $_SESSION['user_id'] ?? null,
        $_SESSION['full_name'] ?? null,
        $periodStart,
        $periodEnd
    );
    $_SESSION[$ok ? 'flash_success' : 'flash_error'] = $ok
        ? ($type === 'capital_in'
            ? "Recorded " . peso($amount) . " added as capital."
            : "Recorded " . peso($amount) . " owner withdrawal.")
        : "The transaction could not be saved. Please try again.";
}

redirect($back);
