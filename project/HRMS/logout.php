<?php
require_once 'includes/config.php';

session_unset();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

// Return every staff member to the single unified login screen after logout.
// The HRMS-only login remains available for direct legacy links, but logout
// should always land on the shared POS/HRMS entry point.
$systemRoot = dirname(__DIR__);
$documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
$loginPath = '/login.php';
if ($documentRoot && $systemRoot && strpos(realpath($systemRoot), $documentRoot) === 0) {
    $relativeRoot = str_replace('\\', '/', substr(realpath($systemRoot), strlen($documentRoot)));
    $loginPath = rtrim($relativeRoot, '/') . '/login.php';
}
redirect($loginPath);
