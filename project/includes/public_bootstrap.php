<?php

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit;
}
if (!defined('APP_LOADED')) {
    define('APP_LOADED', true);
}

// Public read-only pages intentionally do not load includes/config.php.
// This keeps the public surface independent from authenticated POS/HRMS helpers.
require_once __DIR__ . '/env.php';
loadEnv(__DIR__ . '/../.env');
require_once __DIR__ . '/id_crypt.php';

date_default_timezone_set('Asia/Manila');
// PDO's connect timeout does not bound mysqlnd's initial greeting read.
// Public-page connections also need a short network-read timeout so an
// unresponsive local server produces an error instead of a loading screen.
if (extension_loaded('mysqlnd')) {
    ini_set('mysqlnd.net_read_timeout', '3');
}
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval'; connect-src 'self'; frame-ancestors 'self';");

function public_logout_authenticated_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool) ($params['secure'] ?? false),
            (bool) ($params['httponly'] ?? true)
        );
    }
    session_destroy();
}

function pub_ip_hash(): ?string
{
    $salt = env('IP_HASH_SALT', '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return ($salt !== '' && $ip !== '') ? hash_hmac('sha256', $ip, $salt) : null;
}

function pub_rate_limit(PDO $pdo, string $route, int $limit = 120, int $windowSeconds = 60): bool
{
    $hash = pub_ip_hash();
    if ($hash === null) {
        return true;
    }
    $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM rate_limit_public WHERE ip_hash = ? AND route = ? AND hit_at >= ?');
    $stmt->execute([$hash, $route, $cutoff]);
    if ((int) $stmt->fetchColumn() >= $limit) {
        return false;
    }
    $pdo->prepare('INSERT INTO rate_limit_public (ip_hash, route) VALUES (?, ?)')->execute([$hash, $route]);
    $pdo->prepare('DELETE FROM rate_limit_public WHERE hit_at < ?')->execute([$cutoff]);
    return true;
}

function pub_log_scan(PDO $pdo, ?int $productId, string $source): void
{
    if (!in_array($source, ['kiosk', 'phone', 'catalog_click'], true)) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO product_scan_events (product_id, source, ip_hash) VALUES (?, ?, ?)');
    $stmt->execute([$productId, $source, pub_ip_hash()]);
}

function public_write_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env('DB_HOST', 'localhost');
    $port = (int) env('DB_PORT', 3306);
    $user = env('DB_USERNAME', '');
    $pass = env('DB_PASSWORD', '');
    $name = env('DB_NAME_POS', 'grocery_pos');
    if ($user === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
        throw new RuntimeException('Application database is not configured.');
    }
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 3]
    );
    return $pdo;
}

function public_hrms_write_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env('DB_HOST', 'localhost');
    $port = (int) env('DB_PORT', 3306);
    $user = env('DB_USERNAME', '');
    $pass = env('DB_PASSWORD', '');
    $name = env('DB_NAME_HRMS', 'hrms');
    if ($user === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
        throw new RuntimeException('HRMS account database is not configured.');
    }
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 3]
    );
    return $pdo;
}

function public_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env('DB_HOST', 'localhost');
    $port = (int) env('DB_PORT', 3306);
    $user = env('PUBLIC_DB_USERNAME', '');
    $pass = env('PUBLIC_DB_PASSWORD', '');
    $name = env('PUBLIC_DB_NAME', env('DB_NAME_POS', 'grocery_pos'));
    if ($user === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
        http_response_code(503);
        exit('Public catalog is not configured.');
    }
    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // The mysqlnd read limit above separately bounds the greeting.
                PDO::ATTR_TIMEOUT => 3,
            ]
        );
        return $pdo;
    } catch (Throwable $e) {
        error_log('Public database connection failed: ' . $e->getMessage());
        http_response_code(503);
        exit('The product catalog is temporarily unavailable.');
    }
}

function public_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (!function_exists('clean')) {
    function clean($value): string
    {
        return public_escape($value);
    }
}

if (!function_exists('peso')) {
    function peso($value): string
    {
        return public_peso($value);
    }
}

function public_peso($value): string
{
    return '₱' . number_format((float) $value, 2);
}

function public_asset_url(string $path): string
{
    $path = trim(str_replace('\\', '/', $path), '/');
    if ($path === '') {
        return '';
    }

    // Public pages are served from the SYSTEM directory. Build the absolute
    // application path so a page under /SYSTEM/ does not produce
    // /SYSTEM/SYSTEM/uploads/... for product images.
    static $base = null;
    if ($base === null) {
        $systemRoot = realpath(__DIR__ . '/..');
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        $base = '';
        if ($systemRoot && $documentRoot && strpos($systemRoot, $documentRoot) === 0) {
            $relativeRoot = str_replace('\\', '/', substr($systemRoot, strlen($documentRoot)));
            $base = rtrim($relativeRoot, '/');
        }
    }

    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
    return ($base !== '' ? $base : '.') . '/' . $encodedPath;
}

function public_image_url($image): string
{
    $image = basename((string) $image);
    if ($image === '' || $image === 'default.png') {
        return '';
    }

    return public_asset_url('uploads/products/' . $image);
}
