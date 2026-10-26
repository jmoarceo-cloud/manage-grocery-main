<?php

namespace Tests\Feature;

use App\Support\IsolatedDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

abstract class OwnLeaveTestCase extends OwnProfileTestCase
{
    protected function leaveData(array $extra = []): array
    {
        $date = CarbonImmutable::today('Asia/Manila')->addDays(10)->toDateString();

        return array_replace(['leave_type' => 'sick', 'date_from' => $date, 'date_to' => $date, 'reason' => 'Synthetic leave request'], $extra);
    }

    protected function existingLeave(int $employee, array $extra = []): int
    {
        $data = $this->leaveData();

        return DB::connection('hrms')->table('leave_requests')->insertGetId(array_replace($data, ['employee_id' => $employee, 'status' => 'pending', 'requested_at' => now('Asia/Manila')], $extra), 'leave_id');
    }

    protected function tearDown(): void
    {
        IsolatedDatabase::verify();
        $ids = DB::connection('hrms')->table('leave_requests')->whereIn('employee_id', $this->employeeIds)->pluck('leave_id');
        DB::connection('pos')->table('application_audit_events')->where('entity', 'leave_requests:hrms')->whereIn('entity_id', $ids)->delete();
        DB::connection('hrms')->table('leave_request_logs')->whereIn('leave_id', $ids)->delete();
        DB::connection('hrms')->table('leave_requests')->whereIn('leave_id', $ids)->delete();
        DB::connection('hrms')->table('leave_credits')->whereIn('employee_id', $this->employeeIds)->delete();
        parent::tearDown();
    }
}
