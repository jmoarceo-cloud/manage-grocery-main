<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

// Autoload
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = ROOT_PATH.'/app/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $file = $baseDir.str_replace('\\', '/', substr($class, $len)).'.php';
    if (file_exists($file)) {
        require $file;
    }
});

use App\Core\Migrator;

try {
    $migrator = new Migrator;
    $migrator->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: '.$e->getMessage().PHP_EOL);
    exit(1);
}
