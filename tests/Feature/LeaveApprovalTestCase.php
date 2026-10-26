<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use App\Services\LeaveApprovalService;
use App\Support\IsolatedDatabase;
use Illuminate\Support\Facades\DB;

abstract class LeaveApprovalTestCase extends OwnLeaveTestCase
{
    protected function decisionData(StaffUser $actor, int $id, array $extra = []): array
    {
        $leave = DB::connection('hrms')->table('leave_requests')->where('leave_id', $id)->first(LeaveApprovalService::REVISION_FIELDS);

        return array_replace(['decision' => 'approved', 'revision' => LeaveApprovalService::revision($actor, $leave)], $extra);
    }

    protected function assignTeam(int $supervisor, int $member): void
    {
        DB::connection('hrms')->table('supervisor_teams')->updateOrInsert(['member_employee_id' => $member], ['supervisor_employee_id' => $supervisor]);
    }

    protected function tearDown(): void
    {
        IsolatedDatabase::verify();
        DB::connection('hrms')->table('supervisor_teams')->whereIn('member_employee_id', $this->employeeIds)->orWhereIn('supervisor_employee_id', $this->employeeIds)->delete();
        parent::tearDown();
    }
}
