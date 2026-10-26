<?php

namespace App\Support;

class OvertimeEvidence
{
    public static function describe(?object $attendance, ?object $shift, ?string $scheduleType): array
    {
        $empty = ['state' => 'missing', 'time_in' => null, 'time_out' => null, 'worked' => null, 'expected' => null, 'excess' => null];
        if (! $attendance || $attendance->time_in === null || $attendance->time_out === null) {
            return $empty;
        }
        $in = self::seconds($attendance->time_in);
        $out = self::seconds($attendance->time_out);
        $start = $shift ? self::seconds($shift->start_time) : 8 * 3600;
        $end = $shift ? self::seconds($shift->end_time) : null;
        if ($in === null || $out === null || $start === null || ($shift && $end === null)) {
            return array_replace($empty, ['state' => 'invalid']);
        }
        $expected = $shift ? ($end > $start ? $end - $start : $end + 86400 - $start) : ($scheduleType === 'part_time' ? 6 : 8) * 3600;
        if ($out < $in) {
            $out += 86400;
        }
        // Match the existing display/payroll rule: early arrival is not overtime.
        $worked = max(0, $out - max($in, $start));
        $expectedHours = round($expected / 3600, 2);

        return ['state' => 'complete', 'time_in' => substr($attendance->time_in, 0, 5), 'time_out' => substr($attendance->time_out, 0, 5),
            'worked' => number_format($worked / 3600, 2, '.', ''), 'expected' => number_format($expectedHours, 2, '.', ''),
            'excess' => number_format(max(0, $worked / 3600 - $expectedHours), 2, '.', '')];
    }

    private static function seconds(?string $time): ?int
    {
        if (! is_string($time) || ! preg_match('/\A([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?\z/', $time, $parts)) {
            return null;
        }

        return (int) $parts[1] * 3600 + (int) $parts[2] * 60 + (int) ($parts[3] ?? 0);
    }
}
