<?php

namespace App\Support;

class ProfilePhone
{
    public static function parse(?string $stored): array
    {
        if ($stored === null || trim($stored) === '') {
            return ['PH', ''];
        }
        $countries = config('profile.countries');
        if (preg_match('/\A[0-9]{10}\z/', $stored)) {
            return ['PH', $stored];
        }
        if (preg_match('/\A\+[0-9 ().-]+\z/', $stored)) {
            $digits = preg_replace('/[^0-9]/', '', $stored);
            uasort($countries, fn ($a, $b) => strlen($b['dial']) <=> strlen($a['dial']));
            foreach ($countries as $iso => $country) {
                if (str_starts_with($digits, $country['dial']) && strlen($digits) === strlen($country['dial']) + $country['length']) {
                    return [$iso, substr($digits, strlen($country['dial']))];
                }
            }
        }

        return ['KEEP', $stored];
    }

    public static function format(string $iso, ?string $number): ?string
    {
        if ($iso === 'KEEP') {
            return $number;
        }
        $digits = preg_replace('/[^0-9]/', '', $number ?? '');

        return $digits === '' ? null : '+'.config('profile.countries.'.$iso.'.dial').' '.$digits;
    }
}
