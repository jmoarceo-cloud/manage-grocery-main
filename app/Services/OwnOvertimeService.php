<?php

namespace App\Services;

use App\Http\Requests\OwnOvertimeRequest;
use App\Models\StaffUser;
use App\Support\IdentityTransaction;
use App\Support\OvertimeEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OwnOvertimeService
{
    private const EMPLOYEE_FIELDS = ['employee_id', 'status', 'position', 'suspension_start_date', 'suspension_end_date', 'schedule_type'];

    private function checkDomain(StaffUser $staff, string $domain): void
    {
        abort_unless(in_array($domain, ['pos', 'hrms'], true) && $staff->getConnectionName() === $domain, 403);
    }

    public function overview(StaffUser $staff, string $domain, int $year, ?string $status): ?array
    {
        $this->checkDomain($staff, $domain);
        if (! $staff->employee_id) {
            return null;
        }
        $db = DB::connection('hrms');
        $employee = $db->table('employees')->where('employee_id', $staff->employee_id)->first(self::EMPLOYEE_FIELDS);
        abort_unless(StaffAuthentication::employeeEligible($staff, $domain, $employee), 403);
        $query = $db->table('overtime_requests')->where('employee_id', $employee->employee_id)->whereBetween('ot_date', [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]);
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        $history = $query->orderByDesc('requested_at')->orderByDesc('ot_id')->paginate(20, ['ot_id', 'ot_date', 'hours_requested', 'reason', 'status', 'requested_at', 'decided_at'])->appends(['year' => $year, 'status' => $status]);
        $dates = $history->getCollection()->pluck('ot_date')->unique()->values();
        $attendance = $dates->isNotEmpty() ? $db->table('attendance')->where('employee_id', $employee->employee_id)->whereIn('date', $dates)->get(['date', 'time_in', 'time_out'])->keyBy('date') : collect();
        $weekly = $dates->isNotEmpty() ? $db->table('employee_weekly_shifts as w')->join('shifts as s', 's.shift_id', '=', 'w.shift_id')->where('w.employee_id', $employee->employee_id)->whereBetween('w.day_of_week', [0, 6])->get(['w.day_of_week', 's.start_time', 's.end_time'])->keyBy('day_of_week') : collect();
        foreach ($history as $row) {
            if (! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $row->ot_date, $parts) || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                $row->evidence = ['state' => 'invalid'];

                continue;
            }
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $row->ot_date, 'Asia/Manila');
            $row->evidence = OvertimeEvidence::describe($attendance->get($row->ot_date), $weekly->get($day->dayOfWeek), $employee->schedule_type);
        }

        return ['history' => $history, 'hasSupervisor' => $db->table('supervisor_teams')->where('member_employee_id', $employee->employee_id)->exists()];
    }

    public function submit(StaffUser $staff, string $domain, array $data): array
    {
        $this->checkDomain($staff, $domain);
        abort_unless($staff->employee_id, 403);
        if (isset($data['reason']) && is_string($data['reason'])) {
            $data['reason'] = trim($data['reason']);
        }
        $data = Validator::make($data, OwnOvertimeRequest::constraints())->validate();
        $hours = number_format((float) $data['hours_requested'], 2, '.', '');

        return IdentityTransaction::run(function ($db, $users, $schemas) use ($staff, $domain, $data, $hours): array {
            $engines = $db->table('information_schema.TABLES')->where('TABLE_SCHEMA', $schemas['hrms'])->whereIn('TABLE_NAME', ['employees', 'overtime_requests'])->pluck('ENGINE', 'TABLE_NAME');
            foreach (['employees', 'overtime_requests'] as $table) {
                if (strtolower($engines[$table] ?? '') !== 'innodb') {
                    throw ValidationException::withMessages(['overtime' => 'Overtime requests are unavailable. Contact HR.']);
                }
            }
            $actor = $db->table($users[$domain])->where('id', $staff->id)->lockForUpdate()->first(['id', 'employee_id', 'role', 'is_active', 'force_logout', 'two_factor_locked_until', $domain === 'pos' ? 'is_locked' : 'locked_until']);
            abort_unless($actor && $actor->is_active && ! ($actor->is_locked ?? false) && ! $actor->force_logout && (int) $actor->employee_id === (int) $staff->employee_id, 403);
            foreach (['locked_until', 'two_factor_locked_until'] as $field) {
                abort_if(! empty($actor->$field) && now()->lt($actor->$field), 403);
            }
            abort_unless(in_array($actor->role, $domain === 'pos' ? ['admin', 'cashier', 'inventory_staff', 'finance'] : ['admin', 'hr', 'hr_manager', 'supervisor', 'accountant', 'employee'], true), 403);
            $employee = $db->table($schemas['hrms'].'.employees')->where('employee_id', $actor->employee_id)->lockForUpdate()->first(self::EMPLOYEE_FIELDS);
            abort_unless(StaffAuthentication::employeeEligible($actor, $domain, $employee), 403);
            $table = $schemas['hrms'].'.overtime_requests';
            $same = $db->table($table)->where('employee_id', $employee->employee_id)->where('status', 'pending')->where('ot_date', $data['ot_date'])->where('hours_requested', $hours)->whereRaw('BINARY reason = ?', [$data['reason']])->value('ot_id');
            if ($same) {
                return ['id' => (int) $same, 'replayed' => true];
            }
            $id = $db->table($table)->insertGetId(['employee_id' => $employee->employee_id, 'ot_date' => $data['ot_date'], 'hours_requested' => $hours, 'reason' => $data['reason'], 'status' => 'pending', 'requested_at' => now('Asia/Manila')], 'ot_id');
            DomainAudit::record('overtime_requests:hrms', $id, 'requested', ['ot_date', 'hours_requested', 'reason', 'status'], (int) $actor->id, $domain);

            return ['id' => $id, 'replayed' => false];
        }, 'overtime', 'Overtime requests are unavailable. Contact HR.');
    }
}
