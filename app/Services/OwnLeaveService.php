<?php

namespace App\Services;

use App\Http\Requests\OwnLeaveRequest;
use App\Models\StaffUser;
use App\Support\IdentityTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OwnLeaveService
{
    private const EMPLOYEE_FIELDS = ['employee_id', 'status', 'position', 'suspension_start_date', 'suspension_end_date'];

    public function overview(StaffUser $staff, string $domain, int $year, ?string $status): ?array
    {
        $this->checkDomain($staff, $domain);
        if (! $staff->employee_id) {
            return null;
        }
        $db = DB::connection('hrms');
        $employee = $db->table('employees')->where('employee_id', $staff->employee_id)->first(self::EMPLOYEE_FIELDS);
        abort_unless(StaffAuthentication::employeeEligible($staff, $domain, $employee), 403);
        $credits = $db->table('leave_credits')->where('employee_id', $employee->employee_id)->where('year', $year)->get(['leave_type', 'allocated_days', 'used_days'])->keyBy('leave_type');
        $balances = [];
        foreach (config('leave.allocations') as $type => $default) {
            $row = $credits->get($type);
            $allocated = $row ? self::hundredths($row->allocated_days) : $default * 100;
            $used = $row ? self::hundredths($row->used_days) : 0;
            $balances[$type] = ['allocated' => number_format($allocated / 100, 2, '.', ''), 'used' => number_format($used / 100, 2, '.', ''), 'remaining' => number_format(($allocated - $used) / 100, 2, '.', ''), 'recorded' => $row !== null];
        }
        $history = $db->table('leave_requests')->where('employee_id', $employee->employee_id)
            ->whereBetween('date_from', [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]);
        if ($status !== null && $status !== '') {
            $history->where('status', $status);
        }

        return ['balances' => $balances, 'history' => $history->orderByDesc('requested_at')->orderByDesc('leave_id')->paginate(20, ['leave_id', 'leave_type', 'date_from', 'date_to', 'reason', 'status', 'requested_at', 'decided_at'])->appends(['year' => $year, 'status' => $status])];
    }

    public function submit(StaffUser $staff, string $domain, array $data): array
    {
        $this->checkDomain($staff, $domain);
        abort_unless($staff->employee_id, 403);
        if (isset($data['reason']) && is_string($data['reason'])) {
            $data['reason'] = trim($data['reason']);
        }
        $data = Validator::make($data, OwnLeaveRequest::constraints())->validate();
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $data['date_from'], 'Asia/Manila');
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $data['date_to'], 'Asia/Manila');
        $days = (int) $from->diffInDays($to) + 1;

        return IdentityTransaction::run(function ($db, $users, $schemas) use ($staff, $domain, $data, $from, $to, $days): array {
            $tables = $db->table('information_schema.TABLES')->where('TABLE_SCHEMA', $schemas['hrms'])->whereIn('TABLE_NAME', ['employees', 'leave_credits', 'leave_requests'])->pluck('ENGINE', 'TABLE_NAME');
            foreach (['employees', 'leave_credits', 'leave_requests'] as $table) {
                if (strtolower($tables[$table] ?? '') !== 'innodb') {
                    $this->invalid('Leave requests are unavailable. Contact HR.');
                }
            }
            $actor = $db->table($users[$domain])->where('id', $staff->id)->lockForUpdate()->first(['id', 'role', 'employee_id', 'is_active', 'force_logout', 'two_factor_locked_until', $domain === 'pos' ? 'is_locked' : 'locked_until']);
            abort_unless($actor && $actor->is_active && ! ($actor->is_locked ?? false) && ! $actor->force_logout && (int) $actor->employee_id === (int) $staff->employee_id, 403);
            foreach (['locked_until', 'two_factor_locked_until'] as $field) {
                abort_if(! empty($actor->$field) && now()->lt($actor->$field), 403);
            }
            abort_unless(in_array($actor->role, $domain === 'pos' ? ['admin', 'cashier', 'inventory_staff', 'finance'] : ['admin', 'hr', 'hr_manager', 'supervisor', 'accountant', 'employee'], true), 403);
            $employee = $db->table($schemas['hrms'].'.employees')->where('employee_id', $actor->employee_id)->lockForUpdate()->first(self::EMPLOYEE_FIELDS);
            abort_unless(StaffAuthentication::employeeEligible($actor, $domain, $employee), 403);
            $requests = $schemas['hrms'].'.leave_requests';
            $credits = $schemas['hrms'].'.leave_credits';
            // Native retries with the same pending contents return the existing request.
            $same = $db->table($requests)->where('employee_id', $employee->employee_id)->where('status', 'pending')->where('leave_type', $data['leave_type'])
                ->where('date_from', $data['date_from'])->where('date_to', $data['date_to'])->whereRaw('BINARY reason = ?', [$data['reason']])->value('leave_id');
            if ($same) {
                return ['id' => (int) $same, 'replayed' => true];
            }
            if ($data['leave_type'] === 'vacation') {
                $credit = $db->table($credits)->where('employee_id', $employee->employee_id)->where('leave_type', 'vacation')->where('year', $from->year)->lockForUpdate()->first(['allocated_days', 'used_days']);
                $remaining = $credit ? self::hundredths($credit->allocated_days) - self::hundredths($credit->used_days) : config('leave.allocations.vacation') * 100;
                if ($days * 100 > $remaining) {
                    $this->invalid('The requested vacation exceeds your remaining recorded balance for '.$from->year.'.', 'date_to');
                }
                $existing = $db->table($requests)->where('employee_id', $employee->employee_id)->where('leave_type', 'vacation')->whereIn('status', ['pending', 'approved']);
                $gap = config('leave.vacation_period_gap_days');
                if ((clone $existing)->where('date_from', '<=', $to->addDays($gap)->toDateString())->where('date_to', '>=', $from->subDays($gap)->toDateString())->exists()) {
                    $this->invalid('Leave at least one clear calendar day between vacation periods.', 'date_from');
                }
                $last = (clone $existing)->orderByDesc('requested_at')->value('requested_at');
                if ($last && CarbonImmutable::parse($last, 'Asia/Manila')->startOfDay()->addDays(config('leave.vacation_filing_cooldown_days'))->gt(CarbonImmutable::today('Asia/Manila'))) {
                    $this->invalid('Wait 30 calendar days after your last pending or approved vacation filing before submitting another.', 'leave_type');
                }
                if (! $credit) {
                    $db->table($credits)->insert(['employee_id' => $employee->employee_id, 'leave_type' => 'vacation', 'year' => $from->year, 'allocated_days' => config('leave.allocations.vacation'), 'used_days' => 0]);
                }
            }
            $id = $db->table($requests)->insertGetId(['employee_id' => $employee->employee_id, 'leave_type' => $data['leave_type'], 'date_from' => $data['date_from'], 'date_to' => $data['date_to'], 'reason' => $data['reason'], 'status' => 'pending', 'requested_at' => now('Asia/Manila')], 'leave_id');
            DomainAudit::record('leave_requests:hrms', $id, 'requested', ['leave_type', 'date_from', 'date_to', 'reason', 'status'], (int) $actor->id, $domain);

            return ['id' => $id, 'replayed' => false];
        }, 'leave', 'Leave requests are unavailable. Contact HR.');
    }

    private function checkDomain(StaffUser $staff, string $domain): void
    {
        abort_unless(in_array($domain, ['pos', 'hrms'], true) && $staff->getConnectionName() === $domain, 403);
    }

    private function invalid(string $message, string $field = 'leave'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private static function hundredths(string $value): int
    {
        return (int) round((float) $value * 100);
    }
}
