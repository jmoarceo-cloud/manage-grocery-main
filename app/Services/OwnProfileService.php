<?php

namespace App\Services;

use App\Http\Requests\OwnProfileRequest;
use App\Models\StaffUser;
use App\Support\IdentityTransaction;
use App\Support\ProfilePhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OwnProfileService
{
    public const FIELDS = ['employee_id', 'employee_code', 'first_name', 'middle_name', 'last_name', 'department', 'position', 'date_hired', 'email', 'contact_number', 'birthdate', 'place_of_birth', 'sex', 'civil_status', 'address', 'highest_educational_attainment', 'year_graduated', 'status', 'suspension_start_date', 'suspension_end_date'];

    public function read(StaffUser $staff, string $domain): ?object
    {
        abort_unless(in_array($domain, ['pos', 'hrms'], true) && $staff->getConnectionName() === $domain, 403);
        if (! $staff->employee_id) {
            return null;
        }
        $employee = DB::connection('hrms')->table('employees')->where('employee_id', $staff->employee_id)->first(self::FIELDS);
        abort_unless(StaffAuthentication::employeeEligible($staff, $domain, $employee), 403);

        return $employee;
    }

    public static function revision(StaffUser $staff, string $domain, object $employee): string
    {
        return hash_hmac('sha256', json_encode([$domain, (int) $staff->id, $employee], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public static function emailLock(string $email): string
    {
        return 'grocery-pi:'.substr(hash('sha256', config('database.connections.pos.database').'|'.config('database.connections.hrms.database').'|'.strtolower($email)), 0, 48);
    }

    private function invalid(string $message, string $field = 'profile'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function change(StaffUser $staff, string $domain, array $data): void
    {
        abort_unless(in_array($domain, ['pos', 'hrms'], true) && $staff->getConnectionName() === $domain && $staff->employee_id, 403);
        $data = Validator::make($data, OwnProfileRequest::constraints($data))->validate();
        $key = 'profile-proof:'.$domain.':'.$staff->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->invalid('Please wait before trying another profile update.', 'current_password');
        }
        $db = DB::connection('pos');
        $lock = self::emailLock($data['email']);
        if ((int) $db->selectOne('SELECT GET_LOCK(?, 3) AS acquired', [$lock])->acquired !== 1) {
            $this->invalid('Profile updates are busy. Please try again shortly.');
        }
        try {
            IdentityTransaction::run(function ($db, $users, $schemas) use ($staff, $domain, $data, $key, $lock): void {
                if ((int) $db->selectOne('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS held', [$lock])->held !== 1) {
                    $this->invalid('Profile updates are busy. Reload before trying again.');
                }
                $engine = $db->table('information_schema.TABLES')->where('TABLE_SCHEMA', $schemas['hrms'])->where('TABLE_NAME', 'employees')->value('ENGINE');
                if (strtolower($engine ?? '') !== 'innodb') {
                    $this->invalid('Profile updates are unavailable. Contact your administrator.');
                }
                $columns = ['id', 'username', 'password', 'role', 'employee_id', 'is_active', 'force_logout', 'two_factor_locked_until', $domain === 'pos' ? 'is_locked' : 'locked_until'];
                $actor = $db->table($users[$domain])->where('id', $staff->id)->lockForUpdate()->first($columns);
                abort_unless($actor && $actor->is_active && ! ($actor->is_locked ?? false) && ! $actor->force_logout && (int) $actor->employee_id === (int) $staff->employee_id, 403);
                foreach (['locked_until', 'two_factor_locked_until'] as $field) {
                    abort_if(! empty($actor->$field) && now()->lt($actor->$field), 403);
                }
                abort_unless(in_array($actor->role, $domain === 'pos' ? ['admin', 'cashier', 'inventory_staff', 'finance'] : ['admin', 'hr', 'hr_manager', 'supervisor', 'accountant', 'employee'], true), 403);
                $table = $schemas['hrms'].'.employees';
                $employee = $db->table($table)->where('employee_id', $actor->employee_id)->lockForUpdate()->first(self::FIELDS);
                abort_unless(StaffAuthentication::employeeEligible($actor, $domain, $employee), 403);
                if (! hash_equals(self::revision($staff, $domain, $employee), $data['revision'])) {
                    $this->invalid('Your information changed after you opened the form. Review the saved details and try again.', 'revision');
                }
                if (! Hash::check($data['current_password'], $actor->password)) {
                    RateLimiter::hit($key, 900);
                    $this->invalid('Your current password could not be verified.', 'current_password');
                }
                foreach (['sex' => 'sex', 'civil_status' => 'civil_status', 'highest_educational_attainment' => 'education'] as $field => $options) {
                    $value = $data[$field] ?? null;
                    if ($value !== null && $value !== '' && ! in_array($value, config('profile.'.$options), true) && $value !== $employee->$field) {
                        $this->invalid('Choose an available value for this field.', $field);
                    }
                }
                if ($data['contact_number_country'] === 'KEEP' && ($data['contact_number'] ?? null) !== $employee->contact_number) {
                    $this->invalid('Choose a contact country before entering a new number.', 'contact_number');
                }
                if ($db->table($table)->where('email', $data['email'])->where('employee_id', '<>', $employee->employee_id)->exists()) {
                    $this->invalid('This contact email cannot be used. Check it or contact HR.', 'email');
                }
                $values = ['email' => $data['email'], 'contact_number' => ProfilePhone::format($data['contact_number_country'], $data['contact_number'] ?? null), 'birthdate' => $data['date_of_birth'] ?? null, 'place_of_birth' => $data['place_of_birth'] ?? null, 'sex' => $data['sex'] ?? null, 'civil_status' => $data['civil_status'] ?? null, 'address' => $data['home_address'] ?? null, 'highest_educational_attainment' => $data['highest_educational_attainment'] ?? null, 'year_graduated' => $data['year_graduated'] ?? null];
                $values = array_map(fn ($value) => $value === '' ? null : $value, $values);
                $changes = array_filter($values, fn ($value, $field) => $value !== $employee->$field, ARRAY_FILTER_USE_BOTH);
                if ($changes !== []) {
                    $db->table($table)->where('employee_id', $employee->employee_id)->update($changes);
                    DomainAudit::record('employees:hrms', (int) $employee->employee_id, 'profile_updated', array_keys($changes), (int) $actor->id, $domain);
                }
            }, 'profile', 'Profile updates are unavailable. Contact your administrator.');
            RateLimiter::clear($key);
        } finally {
            try {
                $db->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            } catch (\Throwable) {
                logger()->warning('Profile lock cleanup unavailable.');
            }
        }
    }
}
