<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class IsolatedDatabase
{
    public static function verify(): void
    {
        $nonce = config('grocery.test_nonce');
        if (! app()->environment('testing') || ! is_string($nonce) || ! preg_match('/\A[a-f0-9]{10}\z/', $nonce)) {
            throw new RuntimeException('A verified synthetic test environment is required.');
        }
        foreach (['pos', 'hrms'] as $domain) {
            $connection = DB::connection($domain);
            if ($connection->getDatabaseName() !== 'groceryone_test_'.$nonce.'_'.$domain) {
                throw new RuntimeException('Test database name/marker mismatch; refusing database work.');
            }
            if ($connection->table('groceryone_test_target')->value('nonce') !== $nonce) {
                throw new RuntimeException('Disposable target marker not established.');
            }
        }
    }
}
