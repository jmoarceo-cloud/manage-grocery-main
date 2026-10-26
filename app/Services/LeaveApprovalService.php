<?php

namespace App\Services;

use App\Http\Requests\LeaveDecisionRequest;
use App\Models\StaffUser;
use App\Support\IdentityTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LeaveApprovalService
{
    public const REVISION_FIELDS = ['leave_id', 'employee_id', 'leave_type', 'date_from', 'date_to', 'reason', 'status', 'requested_at', 'decided_at'];

    public function authorize(StaffUser $actor, string $domain): void
    {
        abort_unless($domain === 'hrms' && $actor->getConnectionName() === 'hrms' && in_array($actor->role, ['admin', 'hr', 'hr_manager', 'supervisor'], true), 403);
    }

    public static function revision(StaffUser $actor, object $leave): string
    {
        return hash_hmac('sha256', json_encode([(int) $actor->id, $actor->role, array_map(fn ($field) => $leave->$field, self::REVISION_FIELDS)], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function query($db, string $prefix, object $actor)
    {
        $tiers = $db->table($prefix.'users')->whereNotNull('employee_id')->select('employee_id')->selectRaw(LeaveApprovalPolicy::rankSql().' as tier')->groupBy('employee_id');
        $query = $db->table($prefix.'leave_requests as lr')->leftJoinSub($tiers, 'requester_tiers', 'requester_tiers.employee_id', '=', 'lr.employee_id');

        return LeaveApprovalPolicy::scope($query, $actor, $prefix.'supervisor_teams');
    }

    public function listing(StaffUser $actor, string $domain, string $status)
    {
        $this->authorize($actor, $domain);

        return $this->query(DB::connection('hrms'), '', $actor)->join('employees as e', 'e.employee_id', '=', 'lr.employee_id')->where('lr.status', $status)->orderByDesc('lr.requested_at')->orderByDesc('lr.leave_id')
            ->paginate(20, [...array_map(fn ($field) => 'lr.'.$field, self::REVISION_FIELDS), 'e.first_name', 'e.last_name', 'e.employee_code'])->appends(['status' => $status]);
    }

    public function decide(StaffUser $staff, string $domain, int $id, array $data): void
    {
        $this->authorize($staff, $domain);
        $data = Validator::make($data, LeaveDecisionRequest::constraints())->validate();
        IdentityTransaction::run(function ($db, $users, $schemas) use ($staff, $id, $data): void {
            $prefix = $schemas['hrms'].'.';
            $required = ['employees', 'leave_requests', 'leave_credits', 'leave_request_logs', 'supervisor_teams'];
            $engines = $db->table('information_schema.TABLES')->where('TABLE_SCHEMA', $schemas['hrms'])->whereIn('TABLE_NAME', $required)->pluck('ENGINE', 'TABLE_NAME');
            foreach ($required as $table) {
                if (strtolower($engines[$table] ?? '') !== 'innodb') {
                    $this->invalid('Leave decisions are unavailable. Contact your administrator.');
                }
            }
            $actor = $db->table($users['hrms'])->where('id', $staff->id)->lockForUpdate()->first(['id', 'employee_id', 'role', 'is_active', 'force_logout', 'locked_until', 'two_factor_locked_until']);
            abort_unless($actor && $actor->is_active && ! $actor->force_logout && $actor->role === $staff->role && (int) $actor->employee_id === (int) $staff->employee_id, 403);
            foreach (['locked_until', 'two_factor_locked_until'] as $field) {
                abort_if(! empty($actor->$field) && now()->lt($actor->$field), 403);
            }
            $target = $this->query($db, $prefix, $actor)->where('lr.leave_id', $id)->value('lr.employee_id');
            abort_unless($target, 404);
            $employees = $db->table($prefix.'employees')->whereIn('employee_id', array_filter([(int) $target, (int) $actor->employee_id]))->orderBy('employee_id')->lockForUpdate()->get(['employee_id', 'status', 'position', 'suspension_start_date', 'suspension_end_date'])->keyBy('employee_id');
            if ($actor->employee_id) {
                abort_unless(StaffAuthentication::employeeEligible($actor, 'hrms', $employees->get($actor->employee_id)), 403);
            }
            abort_unless($employees->has($target), 404);
            $roles = $db->table($users['hrms'])->where('employee_id', $target)->orderBy('id')->lockForUpdate()->pluck('role')->all();
            $team = $db->table($prefix.'supervisor_teams')->where('member_employee_id', $target)->lockForUpdate()->value('supervisor_employee_id');
            abort_unless(LeaveApprovalPolicy::allows($actor, (int) $target, $roles, $team === null ? null : (int) $team), 404);
            $leave = $db->table($prefix.'leave_requests')->where('leave_id', $id)->lockForUpdate()->first(self::REVISION_FIELDS);
            abort_unless($leave && (int) $leave->employee_id === (int) $target, 404);
            if ($leave->status !== 'pending') {
                $this->invalid('This request has already been decided.');
            }
            if (! hash_equals(self::revision($staff, $leave), $data['revision'])) {
                $this->invalid('This request changed after you opened it. Review the latest details before deciding.', 'revision');
            }
            if ($data['decision'] === 'approved') {
                if (! StaffAuthentication::employeeEligible((object) ['role' => 'admin'], 'hrms', $employees->get($target))) {
                    $this->invalid('This employee is no longer eligible for leave approval. You can reject the pending request.');
                }
                $dates = Validator::make((array) $leave, ['date_from' => ['required', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', 'date_format:Y-m-d'], 'date_to' => ['required', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', 'date_format:Y-m-d', 'after_or_equal:date_from']]);
                if ($dates->fails()) {
                    $this->invalid('This request has invalid dates. Ask HR to review it or reject the pending request.');
                }
                $from = CarbonImmutable::createFromFormat('!Y-m-d', $leave->date_from, 'Asia/Manila');
                $to = CarbonImmutable::createFromFormat('!Y-m-d', $leave->date_to, 'Asia/Manila');
                $days = (int) $from->diffInDays($to) + 1;
                if (array_key_exists($leave->leave_type, config('leave.allocations'))) {
                    $creditQuery = $db->table($prefix.'leave_credits')->where('employee_id', $target)->where('leave_type', $leave->leave_type)->where('year', $from->year);
                    $credit = (clone $creditQuery)->lockForUpdate()->first(['credit_id', 'allocated_days', 'used_days']);
                    $allocated = $credit ? (int) round((float) $credit->allocated_days * 100) : config('leave.allocations.'.$leave->leave_type) * 100;
                    $used = $credit ? (int) round((float) $credit->used_days * 100) : 0;
                    if ($leave->leave_type === 'vacation' && $days * 100 > $allocated - $used) {
                        $this->invalid('The recorded vacation balance no longer covers this request.');
                    }
                    $next = $used + $days * 100;
                    if ($next > 99999 || $next < -99999) {
                        $this->invalid('This approval exceeds the supported credit tracking range. Ask HR to review it.');
                    }
                    if ($credit) {
                        $creditQuery->update(['used_days' => number_format($next / 100, 2, '.', '')]);
                    } else {
                        $db->table($prefix.'leave_credits')->insert(['employee_id' => $target, 'leave_type' => $leave->leave_type, 'year' => $from->year, 'allocated_days' => number_format($allocated / 100, 2, '.', ''), 'used_days' => number_format($next / 100, 2, '.', '')]);
                    }
                }
            }
            $db->table($prefix.'leave_requests')->where('leave_id', $id)->update(['status' => $data['decision'], 'approved_by' => $actor->id, 'decided_at' => now('Asia/Manila')]);
            $db->table($prefix.'leave_request_logs')->insert(['leave_id' => $id, 'action' => $data['decision'], 'performed_by' => $actor->id, 'notes' => 'Native leave decision.']);
            $fields = ['status', 'approved_by', 'decided_at'];
            if ($data['decision'] === 'approved' && array_key_exists($leave->leave_type, config('leave.allocations'))) {
                $fields[] = 'leave_credits.used_days';
            }
            DomainAudit::record('leave_requests:hrms', $id, $data['decision'], $fields, (int) $actor->id, 'hrms');
        }, 'leave', 'Leave decisions are unavailable. Contact your administrator.');
    }

    private function invalid(string $message, string $field = 'leave'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
