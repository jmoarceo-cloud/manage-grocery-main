<?php
// ========================================================
// check_session_status.php — real-time account status check
// ========================================================
// Polled by the JS that requireLogin() echoes out, for as long as a
// dashboard stays open, so a deactivated or force-logged-out account
// is detected right away while the user is still logged in — no need
// to wait for their next click or navigation.
// ========================================================

require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['active' => false, 'reason' => 'no_session']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT u.is_active, u.force_logout, e.suspension_start_date, e.suspension_end_date
    FROM users u
    LEFT JOIN employees e ON u.employee_id = e.employee_id
    WHERE u.id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_unset();
    session_destroy();
    echo json_encode(['active' => false, 'reason' => 'not_found']);
    exit;
}

if ((int) $user['is_active'] === 0) {
    session_unset();
    session_destroy();
    echo json_encode(['active' => false, 'reason' => 'deactivated']);
    exit;
}

if ((int) $user['force_logout'] === 1) {
    $pdo->prepare("UPDATE users SET force_logout = 0 WHERE id = ?")->execute([$_SESSION['user_id']]);
    session_unset();
    session_destroy();
    echo json_encode(['active' => false, 'reason' => 'force_logout']);
    exit;
}

$susp = getEmployeeSuspensionStatus($user['suspension_start_date'], $user['suspension_end_date']);
if ($susp['active']) {
    session_unset();
    session_destroy();
    echo json_encode(['active' => false, 'reason' => 'suspended']);
    exit;
}

echo json_encode(['active' => true]);