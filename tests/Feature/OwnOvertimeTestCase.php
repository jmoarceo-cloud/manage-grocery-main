<?php

namespace Tests\Feature;

use App\Support\IsolatedDatabase;
use Illuminate\Support\Facades\DB;

abstract class OwnOvertimeTestCase extends OwnProfileTestCase
{
    protected function overtimeData(array $extra = []): array
    {
        return array_replace(['ot_date' => now('Asia/Manila')->toDateString(), 'hours_requested' => '2.00', 'reason' => 'Synthetic overtime request'], $extra);
    }

    protected function existingOvertime(int $employee, array $extra = []): int
    {
        return DB::connection('hrms')->table('overtime_requests')->insertGetId(array_replace($this->overtimeData(), ['employee_id' => $employee, 'status' => 'pending', 'requested_at' => now('Asia/Manila')], $extra), 'ot_id');
    }

    protected function tearDown(): void
    {
        IsolatedDatabase::verify();
        $ids = DB::connection('hrms')->table('overtime_requests')->whereIn('employee_id', $this->employeeIds)->pluck('ot_id');
        DB::connection('pos')->table('application_audit_events')->where('entity', 'overtime_requests:hrms')->whereIn('entity_id', $ids)->delete();
        DB::connection('hrms')->table('overtime_requests')->whereIn('ot_id', $ids)->delete();
        DB::connection('hrms')->table('attendance')->whereIn('employee_id', $this->employeeIds)->delete();
        DB::connection('hrms')->table('employee_weekly_shifts')->whereIn('employee_id', $this->employeeIds)->delete();
        parent::tearDown();
    }
}
