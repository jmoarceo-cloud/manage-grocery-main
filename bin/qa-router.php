<?php

// The PHP development server ignores .htaccess. Expose only native build files.
if (PHP_SAPI !== 'cli-server' || getenv('APP_ENV') !== 'testing') {
    http_response_code(404);
    exit;
}
$public = dirname(__DIR__).'/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && (preg_match('~\A/build/assets/[A-Za-z0-9_-]+\.(?:css|js|ttf|woff2)\z~', $path) || in_array($path, ['/favicon.ico', '/robots.txt'], true))) {
    $file = realpath($public.$path);
    if ($file && str_starts_with(strtolower($file), strtolower(realpath($public).DIRECTORY_SEPARATOR)) && is_file($file)) {
        return false;
    }
}
$_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require $public.'/index.php';
