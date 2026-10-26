<?php

require_once 'includes/config.php';

if (($_GET['return'] ?? '') === 'catalog' || ($_POST['return'] ?? '') === 'catalog') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCSRF();
    }
    redirect('catalog_supervisor_login.php?exit=1');
}

unset($_SESSION['cart']);

session_unset();


session_destroy();



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

$return = ($_GET['return'] ?? '') === 'catalog' ? 'catalog.php' : 'login.php';
redirect($return);