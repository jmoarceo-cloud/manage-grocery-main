<?php

namespace App\Support;

class RecoveryCodes
{
    // Returns the remaining hashes; callers persist this only under a locked user row.
    public static function consume(?string $encoded, string $code): ?string
    {
        $hashes = json_decode($encoded ?? '[]', true);
        if (! is_array($hashes)) {
            return null;
        }
        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && password_verify(strtoupper(trim($code)), $hash)) {
                unset($hashes[$index]);

                return json_encode(array_values($hashes), JSON_THROW_ON_ERROR);
            }
        }

        return null;
    }
}
