<?php

return [
    'default' => env('DB_CONNECTION', 'pos'),
    'connections' => collect(['pos' => 'DB_NAME_POS', 'hrms' => 'DB_NAME_HRMS'])->mapWithKeys(fn ($key, $domain) => [$domain => [
        'driver' => 'mysql', 'host' => env('DB_HOST', '127.0.0.1'), 'port' => env('DB_PORT', '3306'),
        'database' => env($key), 'username' => env('DB_USERNAME'), 'password' => env('DB_PASSWORD'),
        'unix_socket' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
        'prefix_indexes' => true, 'strict' => true, 'engine' => 'InnoDB',
        'options' => [PDO::ATTR_TIMEOUT => 3],
    ]])->all(),
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
