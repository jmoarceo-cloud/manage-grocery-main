<?php

namespace App\Services;

use App\Http\Requests\ChangeOwnPasswordRequest;
use App\Models\StaffUser;
use App\Support\IdentityTransaction;
use App\Support\RecoveryCodes;
use App\Support\StaffSessionVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AccountSecurityService
{
    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['current_password' => $message]);
    }

    public function change(string $domain, StaffUser $staff, array $data): void
    {
        abort_unless(in_array($domain, ['pos', 'hrms'], true), 403);
        abort_unless($staff->getConnectionName() === $domain, 403);
        $data = Validator::make($data, ChangeOwnPasswordRequest::constraints())->validate();
        $key = 'account-proof:'.$domain.':'.$staff->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->invalid('Please wait before trying another password change.');
        }
        $changed = IdentityTransaction::run(function ($db, $tables, $schemas) use ($domain, $staff, $data, $key): array {
            $users = [];
            // Fixed POS then HR lock order; indexed equality plus binary case matching.
            foreach ($tables as $name => $table) {
                $users[$name] = $db->table($table)->where('username', $staff->username)->whereRaw('BINARY username = ?', [$staff->username])->lockForUpdate()->first();
            }
            $actor = $users[$domain];
            abort_unless($actor && (int) $actor->id === (int) $staff->id && $actor->is_active && ! ($actor->is_locked ?? false) && ! $actor->force_logout, 403);
            foreach (['locked_until', 'two_factor_locked_until'] as $field) {
                if (! empty($actor->$field) && Carbon::parse($actor->$field)->isFuture()) {
                    abort(403);
                }
            }
            abort_unless(StaffAuthentication::canonicalDomain($users['pos'], $users['hrms']) === $domain, 403);
            if ($actor->employee_id) {
                $employee = $db->table($schemas['hrms'].'.employees')->where('employee_id', $actor->employee_id)->lockForUpdate()->first(['status', 'position', 'suspension_start_date', 'suspension_end_date']);
                abort_unless(StaffAuthentication::employeeEligible($actor, $domain, $employee), 403);
            }
            if ($users['pos'] && $users['hrms']) {
                $pos = $users['pos'];
                $hr = $users['hrms'];
                $roles = ($pos->role === 'admin' && $hr->role === 'admin') || (in_array($pos->role, ['cashier', 'inventory_staff'], true) && $hr->role === 'employee') || ($pos->role === 'finance' && in_array($hr->role, ['employee', 'accountant'], true));
                $linked = ($pos->employee_id && $hr->employee_id && (int) $pos->employee_id === (int) $hr->employee_id) || (! $pos->employee_id && ! $hr->employee_id && $pos->role === 'admin' && $hr->role === 'admin');
                if (! $roles || ! $linked) {
                    $this->invalid('Linked accounts need administrator review before this password can be changed.');
                }
            }
            $remaining = null;
            $valid = $data['credential_type'] === 'password' ? Hash::check($data['current_password'], $actor->password) : ($remaining = RecoveryCodes::consume($actor->two_factor_backup_codes, $data['backup_code'])) !== null;
            if (! $valid) {
                RateLimiter::hit($key, 900);
                $this->invalid('The current password or recovery code could not be verified.');
            }
            if (Hash::check($data['password'], $actor->password)) {
                $this->invalid('Choose a password different from the current password.');
            }
            $hash = Hash::make($data['password']);
            $changed = [];
            foreach ($users as $name => $user) {
                if (! $user) {
                    continue;
                }
                $values = ['password' => $hash, 'force_logout' => 1];
                if ($name === $domain && $remaining !== null) {
                    $values['two_factor_backup_codes'] = $remaining;
                }
                $db->table($tables[$name])->where('id', $user->id)->update($values);
                DomainAudit::record('users:'.$name, (int) $user->id, 'password_changed', array_keys($values), (int) $actor->id, $domain);
                $changed[$name] = (int) $user->id;
            }

            return $changed;
        });
        foreach ($changed as $name => $id) {
            StaffSessionVersion::revoke($name, $id);
        }
        RateLimiter::clear($key);
    }
}
