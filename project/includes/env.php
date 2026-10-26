<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// =====================================================
// LIGHTWEIGHT .env LOADER
// ------------------------------------------------------
// This project has no Composer/vendor setup (PHPMailer, for example, is
// vendored by hand under HRMS/includes/PHPMailer/), so rather than pull
// in vlucas/phpdotenv this is a small dependency-free loader that does
// just what config.php needs: read KEY=VALUE lines from a .env file and
// expose them through getenv()/env().
//
// Real environment variables (e.g. ones set by the actual web server /
// hosting panel) always win over the .env file — this only fills in
// values that aren't already set.
// =====================================================

if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        static $loaded = [];

        $key = $path;
        if (isset($loaded[$key])) {
            return;
        }
        $loaded[$key] = true;

        if (!is_file($path) || !is_readable($path)) {
            // No .env present (e.g. fresh checkout before it's been set
            // up) — config.php falls back to its own hardcoded defaults,
            // so this is not an error.
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }

            list($envKey, $value) = explode('=', $line, 2);
            $envKey = trim($envKey);
            $value  = trim($value);

            if ($envKey === '') {
                continue;
            }

            // Strip one layer of matching surrounding quotes, e.g.
            // DB_PASSWORD="some value" or DB_PASSWORD='some value'.
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last  = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            // Don't clobber a value already present in the real
            // environment (server-level env vars should win).
            $existingValue = getenv($envKey);
            if ($existingValue !== false && $existingValue !== '') {
                continue;
            }

            putenv("$envKey=$value");
            $_ENV[$envKey] = $value;
        }
    }
}

if (!function_exists('env')) {
    function env($key, $default = null) {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}
