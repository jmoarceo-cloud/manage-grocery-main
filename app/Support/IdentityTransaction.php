<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IdentityTransaction
{
    public static function run(Closure $callback, string $errorField = 'password', string $unavailable = 'Password changes are unavailable. Contact your administrator.'): mixed
    {
        $pos = config('database.connections.pos');
        $hr = config('database.connections.hrms');
        $unsupported = ($pos['driver'] ?? '') !== 'mysql' || ($hr['driver'] ?? '') !== 'mysql';
        foreach (['host', 'port', 'username', 'password', 'unix_socket'] as $key) {
            if (($pos[$key] ?? null) !== ($hr[$key] ?? null)) {
                $unsupported = true;
            }
        }
        $schemas = ['pos' => $pos['database'] ?? '', 'hrms' => $hr['database'] ?? ''];
        foreach ($schemas as $schema) {
            if (! is_string($schema) || ! preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $schema)) {
                $unsupported = true;
            }
        }
        if ($schemas['pos'] === $schemas['hrms']) {
            $unsupported = true;
        }
        if ($unsupported) {
            throw ValidationException::withMessages([$errorField => $unavailable]);
        }
        $db = DB::connection('pos');
        $engines = $db->table('information_schema.TABLES')->whereIn('TABLE_SCHEMA', array_values($schemas))->where('TABLE_NAME', 'users')->pluck('ENGINE', 'TABLE_SCHEMA');
        foreach ($schemas as $schema) {
            if (strtolower($engines[$schema] ?? '') !== 'innodb') {
                throw ValidationException::withMessages([$errorField => $unavailable]);
            }
        }

        // Qualified identity operations use one PDO/InnoDB transaction, not two commits.
        return $db->transaction(fn () => $callback($db, array_map(fn ($schema) => $schema.'.users', $schemas), $schemas), 3);
    }
}
