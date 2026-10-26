<?php

namespace App\Services;

use App\Models\StaffUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OwnScheduleService
{
    public function forStaff(StaffUser $staff, string $domain): ?array
    {
        abort_unless(in_array($domain, ['pos', 'hrms'], true) && $staff->getConnectionName() === $domain, 403);
        if (! $staff->employee_id) {
            return null;
        }
        $db = DB::connection('hrms');
        $employee = $db->table('employees')->where('employee_id', $staff->employee_id)->first(['employee_id', 'status', 'position', 'suspension_start_date', 'suspension_end_date']);
        abort_unless(StaffAuthentication::employeeEligible($staff, $domain, $employee), 403);
        $today = CarbonImmutable::today('Asia/Manila');
        // Existing uniq_emp_day and uniq_covering_date bound both own-record reads.
        $rows = $db->table('employee_weekly_shifts as w')->leftJoin('shifts as s', 's.shift_id', '=', 'w.shift_id')
            ->where('w.employee_id', $employee->employee_id)->whereBetween('w.day_of_week', [0, 6])
            ->orderBy('w.day_of_week')->get(['w.day_of_week', 's.shift_id', 's.shift_name', 's.start_time', 's.end_time', 's.is_active'])->keyBy('day_of_week');
        $swaps = $db->table('shift_swaps as sw')->leftJoin('shifts as s', 's.shift_id', '=', 'sw.shift_id')
            ->where('sw.covering_employee_id', $employee->employee_id)->where('sw.status', 'approved')
            ->whereBetween('sw.swap_date', [$today->toDateString(), $today->addDays(6)->toDateString()])
            ->orderBy('sw.swap_date')->get(['sw.swap_date', 's.shift_id', 's.shift_name', 's.start_time', 's.end_time', 's.is_active'])->keyBy('swap_date');
        $configured = $rows->isNotEmpty();
        $weekly = [];
        foreach ([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'] as $day => $label) {
            $row = $rows->get($day);
            $weekly[] = ['day' => $label, 'today' => $day === $today->dayOfWeek] + $this->present($row?->shift_id ? $row : null, $configured);
        }
        $upcoming = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $date = $today->addDays($offset);
            $row = $rows->get($date->dayOfWeek);
            $swap = $swaps->get($date->toDateString());
            // Match the legacy self-attendance resolver: weekly shift takes precedence.
            $cover = ! $row?->shift_id && $swap !== null;
            $shift = $row?->shift_id ? $row : ($cover ? $swap : null);
            $upcoming[] = ['date' => $date->toDateString(), 'label' => $date->format('D, M j'), 'today' => $offset === 0, 'cover' => $cover]
                + $this->present($shift, $configured);
        }

        return ['weekly' => $weekly, 'upcoming' => $upcoming, 'configured' => $configured];
    }

    private function present(?object $shift, bool $configured): array
    {
        if ($shift === null) {
            return ['name' => $configured ? 'Day off' : 'Not set', 'time' => null, 'overnight' => false, 'inactive' => false, 'needs_review' => false, 'assigned' => false];
        }
        $valid = $shift->shift_id && $this->validTime($shift->start_time) && $this->validTime($shift->end_time);
        $overnight = $valid && $shift->end_time <= $shift->start_time;

        return ['name' => $shift->shift_name ?: 'Approved cover', 'time' => $valid ? $this->clock($shift->start_time).' – '.$this->clock($shift->end_time) : null,
            'overnight' => (bool) $overnight, 'inactive' => $shift->shift_id && ! $shift->is_active, 'needs_review' => ! $valid, 'assigned' => true];
    }

    private function validTime(?string $time): bool
    {
        return is_string($time) && preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?\z/', $time) === 1;
    }

    private function clock(string $time): string
    {
        $hour = (int) substr($time, 0, 2);

        return ($hour % 12 ?: 12).substr($time, 2, 3).($hour < 12 ? ' AM' : ' PM');
    }
}
