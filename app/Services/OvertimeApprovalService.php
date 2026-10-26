<?php

namespace App\Services;

use App\Http\Requests\OvertimeDecisionRequest;
use App\Models\StaffUser;
use App\Support\IdentityTransaction;
use App\Support\OvertimeEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OvertimeApprovalService
{
    public const FIELDS = ['ot_id', 'employee_id', 'ot_date', 'hours_requested', 'reason', 'status', 'requested_at', 'decided_at'];

    public function authorize(StaffUser $actor, string $domain): void
    {
        abort_unless($domain === 'hrms' && $actor->getConnectionName() === 'hrms' && in_array($actor->role, ['admin', 'supervisor'], true), 403);
    }

    private function scope($query, string $teams, object $actor)
    {
        if ($actor->role === 'supervisor' && ! $actor->employee_id) {
            return $query->whereRaw('1 = 0');
        }
        if ($actor->role !== 'admin') {
            $query->where('ot.employee_id', '<>', $actor->employee_id);
        }

        return $query->whereExists(function ($team) use ($teams, $actor) {
            $team->selectRaw('1')->from($teams.' as tm')->whereColumn('tm.member_employee_id', 'ot.employee_id');
            if ($actor->role === 'supervisor') {
                $team->where('tm.supervisor_employee_id', $actor->employee_id);
            }
        });
    }

    private static function validDate(string $date): bool
    {
        return preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    public static function revision(StaffUser $actor, object $row, array $source): string
    {
        return hash_hmac('sha256', json_encode([(int) $actor->id, $actor->role, array_map(fn ($field) => $row->$field, self::FIELDS), $source], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function listing(StaffUser $actor, string $domain, string $status)
    {
        $this->authorize($actor, $domain);
        $db = DB::connection('hrms');
        $query = $this->scope($db->table('overtime_requests as ot'), 'supervisor_teams', $actor)->join('employees as e', 'e.employee_id', '=', 'ot.employee_id')->where('ot.status', $status);
        $rows = $query->orderByDesc('ot.requested_at')->orderByDesc('ot.ot_id')->paginate(20, [...array_map(fn ($field) => 'ot.'.$field, self::FIELDS), 'e.first_name', 'e.last_name', 'e.employee_code', 'e.schedule_type'])->appends(['status' => $status]);
        $pairs = $rows->getCollection()->map(fn ($row) => [(int) $row->employee_id, $row->ot_date])->unique()->values();
        $attendance = $pairs->isEmpty() ? collect() : $db->table('attendance')->where(function ($query) use ($pairs) {
            foreach ($pairs as [$id, $date]) {
                $query->orWhere(fn ($pair) => $pair->where('employee_id', $id)->where('date', $date));
            }
        })->get(['employee_id', 'date', 'time_in', 'time_out'])->keyBy(fn ($punch) => $punch->employee_id.'|'.$punch->date);
        $weekly = $pairs->isEmpty() ? collect() : $db->table('employee_weekly_shifts as w')->join('shifts as s', 's.shift_id', '=', 'w.shift_id')->whereIn('w.employee_id', $pairs->pluck(0)->unique())->whereBetween('w.day_of_week', [0, 6])->get(['w.employee_id', 'w.day_of_week', 's.start_time', 's.end_time'])->keyBy(fn ($shift) => $shift->employee_id.'|'.$shift->day_of_week);
        foreach ($rows as $row) {
            $punch = $attendance->get($row->employee_id.'|'.$row->ot_date);
            $day = self::validDate($row->ot_date) ? CarbonImmutable::createFromFormat('!Y-m-d', $row->ot_date, 'Asia/Manila')->dayOfWeek : null;
            $shift = $day === null ? null : $weekly->get($row->employee_id.'|'.$day);
            $source = self::source($row->schedule_type, $punch, $shift);
            $row->evidence = $day === null ? ['state' => 'invalid'] : OvertimeEvidence::describe($punch, $shift, $row->schedule_type);
            $row->revision = self::revision($actor, $row, $source);
        }

        return $rows;
    }

    private static function source(?string $schedule, ?object $punch, ?object $shift): array
    {
        return ['schedule_type' => $schedule, 'punch' => $punch ? [$punch->time_in, $punch->time_out] : null, 'shift' => $shift ? [$shift->start_time, $shift->end_time] : null];
    }

    public function decide(StaffUser $staff, string $domain, int $id, array $data): void
    {
        $this->authorize($staff, $domain);
        $data = Validator::make($data, OvertimeDecisionRequest::constraints())->validate();
        IdentityTransaction::run(function ($db, $users, $schemas) use ($staff, $id, $data): void {
            $prefix = $schemas['hrms'].'.';
            $tables = ['employees', 'overtime_requests', 'supervisor_teams', 'attendance', 'employee_weekly_shifts', 'shifts'];
            $engines = $db->table('information_schema.TABLES')->where('TABLE_SCHEMA', $schemas['hrms'])->whereIn('TABLE_NAME', $tables)->pluck('ENGINE', 'TABLE_NAME');
            foreach ($tables as $table) {
                if (strtolower($engines[$table] ?? '') !== 'innodb') {
                    $this->invalid('Overtime decisions are unavailable. Contact HR.');
                }
            }
            $actor = $db->table($users['hrms'])->where('id', $staff->id)->lockForUpdate()->first(['id', 'role', 'employee_id', 'is_active', 'force_logout', 'locked_until', 'two_factor_locked_until']);
            abort_unless($actor && $actor->is_active && ! $actor->force_logout && $actor->role === $staff->role && (int) $actor->employee_id === (int) $staff->employee_id, 403);
            foreach (['locked_until', 'two_factor_locked_until'] as $field) {
                abort_if(! empty($actor->$field) && now()->lt($actor->$field), 403);
            }
            $target = $this->scope($db->table($prefix.'overtime_requests as ot'), $prefix.'supervisor_teams', $actor)->where('ot.ot_id', $id)->value('ot.employee_id');
            abort_unless($target, 404);
            $employees = $db->table($prefix.'employees')->whereIn('employee_id', array_filter([(int) $target, (int) $actor->employee_id]))->orderBy('employee_id')->lockForUpdate()->get(['employee_id', 'status', 'position', 'suspension_start_date', 'suspension_end_date', 'schedule_type'])->keyBy('employee_id');
            if ($actor->employee_id) {
                abort_unless(StaffAuthentication::employeeEligible($actor, 'hrms', $employees->get($actor->employee_id)), 403);
            }
            $employee = $employees->get($target);
            abort_unless($employee, 404);
            $supervisor = $db->table($prefix.'supervisor_teams')->where('member_employee_id', $target)->lockForUpdate()->value('supervisor_employee_id');
            abort_unless($supervisor !== null && ($actor->role === 'admin' || ((int) $supervisor === (int) $actor->employee_id && (int) $target !== (int) $actor->employee_id)), 404);
            $row = $db->table($prefix.'overtime_requests')->where('ot_id', $id)->lockForUpdate()->first(self::FIELDS);
            abort_unless($row && (int) $row->employee_id === (int) $target, 404);
            if ($row->status !== 'pending') {
                $this->invalid('This request has already been decided.');
            }
            $punch = $db->table($prefix.'attendance')->where('employee_id', $target)->where('date', $row->ot_date)->lockForUpdate()->first(['time_in', 'time_out']);
            $day = self::validDate($row->ot_date) ? CarbonImmutable::createFromFormat('!Y-m-d', $row->ot_date, 'Asia/Manila')->dayOfWeek : null;
            $shift = $day === null ? null : $db->table($prefix.'employee_weekly_shifts as w')->join($prefix.'shifts as s', 's.shift_id', '=', 'w.shift_id')->where('w.employee_id', $target)->where('w.day_of_week', $day)->lockForUpdate()->first(['s.start_time', 's.end_time']);
            if (! hash_equals(self::revision($staff, $row, self::source($employee->schedule_type, $punch, $shift)), $data['revision'])) {
                $this->invalid('The request or recorded work changed. Review the latest details before deciding.', 'revision');
            }
            if ($data['decision'] === 'approved') {
                if (! StaffAuthentication::employeeEligible((object) ['role' => 'admin'], 'hrms', $employee)) {
                    $this->invalid('This employee is no longer eligible for approval. You can reject the pending request.');
                }
                if (! self::validDate($row->ot_date) || $row->ot_date > now('Asia/Manila')->toDateString() || (float) $row->hours_requested <= 0 || (float) $row->hours_requested > 12) {
                    $this->invalid('The request date or hours need correction before approval. You can reject this pending request.');
                }
            }
            $db->table($prefix.'overtime_requests')->where('ot_id', $id)->update(['status' => $data['decision'], 'approved_by' => $actor->id, 'decided_at' => now('Asia/Manila')]);
            DomainAudit::record('overtime_requests:hrms', $id, $data['decision'], ['status', 'approved_by', 'decided_at'], (int) $actor->id, 'hrms');
        }, 'overtime', 'Overtime decisions are unavailable. Contact HR.');
    }

    private function invalid(string $message, string $field = 'overtime'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
