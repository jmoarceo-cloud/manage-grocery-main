<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class StaffSessionVersion
{
    private static function key(string $domain, int $id): string
    {
        return 'staff-session-version:'.$domain.':'.$id;
    }

    public static function current(string $domain, int $id): string
    {
        return (string) Cache::rememberForever(self::key($domain, $id), fn () => (string) Str::uuid());
    }

    public static function revoke(string $domain, int $id): void
    {
        Cache::forever(self::key($domain, $id), (string) Str::uuid());
    }
}
