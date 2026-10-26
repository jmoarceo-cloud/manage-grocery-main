<?php
require_once __DIR__ . '/includes/config.php';

// ========================================================================
// PROTECTED SETUP SCRIPT — no longer casually accessible via the browser.
//
// Its old weakness: anyone who could guess this URL was able to create a
// new admin account (there was no login check).
// Now:
//   1. It only runs on the command line (php create_admin.php), NOT in the browser.
//   2. It refuses to run if an admin account already exists (so it cannot be
//      abused repeatedly should the file somehow still be exposed).
//   3. It must STILL be deleted or moved outside the web root after use — this
//      is not a substitute for deployment discipline, just extra protection.
// ========================================================================
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Access denied. This can only be run from the command line: php create_admin.php");
}

// ================================
// EDIT THIS BEFORE RUNNING:
// ================================
$new_username = 'replace_with_username';
$new_password = 'admin123'; // Public placeholder; the guard below refuses it.
$new_full_name = 'Replace with full name';
$new_role      = "admin";          // admin, hr, or employee
// ================================

$existingAdminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
if ($existingAdminCount > 0 && !in_array('--force', $argv ?? [], true)) {
    echo "There are already existing admin account(s) ({$existingAdminCount}). If you really do" . PHP_EOL;
    echo "intend to create an additional admin, run again with the --force flag:" . PHP_EOL;
    echo "    php create_admin.php --force" . PHP_EOL;
    exit(1);
}

if ($new_password === "admin123") {
    echo "WARNING: the password is still the default 'admin123'. Change it at the top of this file" . PHP_EOL;
    echo "before creating an account for a production/live server." . PHP_EOL;
    exit(1);
}

$hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
$stmt->execute([$new_username, $hashed_password, $new_full_name, $new_role]);

echo "Account created successfully!" . PHP_EOL;
echo "Username: {$new_username}" . PHP_EOL;
echo "Role: {$new_role}" . PHP_EOL;
echo PHP_EOL;
echo "IMPORTANT: Delete this file (create_admin.php) or move it outside the web root" . PHP_EOL;
echo "after use, so that no one else can access it again." . PHP_EOL;
