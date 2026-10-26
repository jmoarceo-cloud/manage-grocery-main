<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use App\Services\OvertimeApprovalService;
use App\Support\IsolatedDatabase;
use Illuminate\Support\Facades\DB;

abstract class OvertimeApprovalTestCase extends OwnOvertimeTestCase
{
    protected array $shiftIds = [];

    protected function assignOvertimeTeam(int $supervisor, int $employee): void
    {
        DB::connection('hrms')->table('supervisor_teams')->updateOrInsert(['member_employee_id' => $employee], ['supervisor_employee_id' => $supervisor]);
    }

    protected function overtimeDecisionData(StaffUser $actor, int $id, array $extra = []): array
    {
        $row = app(OvertimeApprovalService::class)->listing($actor, 'hrms', 'pending')->getCollection()->firstWhere('ot_id', $id);

        return array_replace(['decision' => 'approved', 'revision' => $row?->revision ?? str_repeat('a', 64)], $extra);
    }

    protected function overtimePunch(int $employee, array $extra = []): void
    {
        DB::connection('hrms')->table('attendance')->updateOrInsert(['employee_id' => $employee, 'date' => now('Asia/Manila')->toDateString()], array_replace(['time_in' => '07:00:00', 'time_out' => '18:00:00', 'status' => 'present', 'time_in_latitude' => '14.1234567', 'time_in_address' => 'SYN-OT-REVIEW-PRIVATE-GPS'], $extra));
    }

    protected function overtimeShift(int $employee, array $extra = []): int
    {
        $id = DB::connection('hrms')->table('shifts')->insertGetId(array_replace(['shift_name' => 'Synthetic overtime shift', 'start_time' => '08:00:00', 'end_time' => '16:00:00'], $extra), 'shift_id');
        $this->shiftIds[] = $id;
        DB::connection('hrms')->table('employee_weekly_shifts')->updateOrInsert(['employee_id' => $employee, 'day_of_week' => now('Asia/Manila')->dayOfWeek], ['shift_id' => $id]);

        return $id;
    }

    protected function tearDown(): void
    {
        IsolatedDatabase::verify();
        DB::connection('hrms')->table('supervisor_teams')->whereIn('member_employee_id', $this->employeeIds)->orWhereIn('supervisor_employee_id', $this->employeeIds)->delete();
        DB::connection('hrms')->table('employee_weekly_shifts')->whereIn('employee_id', $this->employeeIds)->delete();
        DB::connection('hrms')->table('shifts')->whereIn('shift_id', $this->shiftIds)->delete();
        parent::tearDown();
    }
}
