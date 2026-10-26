<?php
require_once __DIR__ . '/includes/config.php';

// ========================================================================
// PROTECTED SETUP/RECOVERY SCRIPT — no longer casually accessible
// via the browser.
//
// Its old weakness: anyone who could guess this URL was able to reset any
// user's password (there was no login check and no confirmation).
// Now:
//   1. It only runs on the command line (php reset_password.php), NOT in the browser.
//   2. It must STILL be deleted or moved outside the web root after use.
// ========================================================================
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Access denied. This can only be run from the command line: php reset_password.php");
}

// ================================
// EDIT THIS BEFORE RUNNING:
// ================================
$target_username = 'replace_with_username';
$new_password = 'admin123'; // Public placeholder; the guard below refuses it.
// ================================

if ($new_password === "admin123") {
    echo "WARNING: the password is still the default 'admin123'. Change it at the top of this file" . PHP_EOL;
    echo "before resetting a password for a production/live server." . PHP_EOL;
    exit(1);
}

$hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = ?");
$stmt->execute([$hashed_password, $target_username]);

if ($stmt->rowCount() > 0) {
    echo "Password reset successfully!" . PHP_EOL;
    echo "Username: {$target_username}" . PHP_EOL;
} else {
    echo "No user found with the username '{$target_username}'." . PHP_EOL;
}
echo PHP_EOL;
echo "Delete this file or move it outside the web root after use!" . PHP_EOL;
