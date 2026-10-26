<?php
declare(strict_types=1);

// A loopback socket accepts the connection but deliberately sends no MySQL
// greeting. Only synthetic credentials reach it; no database server is used.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!extension_loaded('pdo_mysql') || !function_exists('proc_open')) {
    fwrite(STDERR, "pdo_mysql and proc_open are required.\n");
    exit(1);
}

$bootstrap = dirname(__DIR__).'/includes/public_bootstrap.php';
$child = <<<'PHP'
$function = $argv[1];
if (!in_array($function, ['public_pdo', 'public_write_pdo', 'public_hrms_write_pdo'], true)) {
    exit(2);
}
foreach ([
    'DB_HOST' => '127.0.0.1', 'DB_PORT' => $argv[2],
    'DB_USERNAME' => 'synthetic_timeout_probe', 'DB_PASSWORD' => 'synthetic-only',
    'DB_NAME_POS' => 'synthetic_timeout_pos', 'DB_NAME_HRMS' => 'synthetic_timeout_hrms',
    'PUBLIC_DB_USERNAME' => 'synthetic_timeout_probe', 'PUBLIC_DB_PASSWORD' => 'synthetic-only',
    'PUBLIC_DB_NAME' => 'synthetic_timeout_pos',
    'ID_CRYPT_KEY' => 'synthetic-timeout-probe-key-with-no-real-account',
] as $key => $value) {
    putenv($key.'='.$value);
}
register_shutdown_function(static function (): void {
    echo PHP_EOL.'status='.(http_response_code() ?: 0);
});
require $argv[3];
try {
    $function();
    echo 'UNEXPECTED_CONNECTION';
    exit(2);
} catch (PDOException $error) {
    echo 'connection_timeout';
}
PHP;

foreach (['public_pdo', 'public_write_pdo', 'public_hrms_write_pdo'] as $function) {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if (!$listener) {
        throw new RuntimeException('Cannot create the synthetic loopback listener.');
    }
    $address = stream_socket_get_name($listener, false);
    $port = (int) substr(strrchr($address, ':'), 1);
    $process = null;
    $peer = false;
    $pipes = [];
    $started = microtime(true);
    try {
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', $child, $function, (string) $port, $bootstrap],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the isolated PHP probe.');
        }
        fclose($pipes[0]);
        $peer = stream_socket_accept($listener, 5);
        if (!$peer) {
            throw new RuntimeException('The application did not use the synthetic connection target.');
        }
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) - $started > 6) {
                throw new RuntimeException('Database handshake exceeded the connection timeout.');
            }
            usleep(50000);
        } while (true);
        $elapsed = microtime(true) - $started;
        $output = stream_get_contents($pipes[1]);
        $expected = $function === 'public_pdo'
            ? str_contains($output, 'The product catalog is temporarily unavailable.') && str_contains($output, 'status=503')
            : str_contains($output, 'connection_timeout');
        if ($status['exitcode'] !== 0 || !$expected || $elapsed < 2 || $elapsed > 5.5) {
            throw new RuntimeException('The stalled connection did not produce the expected bounded failure.');
        }
        printf("%s: stalled handshake stopped in %.2f seconds%s\n", $function, $elapsed, $function === 'public_pdo' ? ' (HTTP 503)' : '');
    } finally {
        if (is_resource($process)) {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
            }
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($process)) {
            proc_close($process);
        }
        if (is_resource($peer)) {
            fclose($peer);
        }
        fclose($listener);
    }
}
echo "database_connection_timeout: OK (3 synthetic handshake probes)\n";
