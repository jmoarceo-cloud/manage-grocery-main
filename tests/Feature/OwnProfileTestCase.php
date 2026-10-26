<?php

namespace Tests\Feature;

use App\Models\HrUser;
use App\Models\PosUser;
use App\Models\StaffUser;
use App\Services\OwnProfileService;
use App\Support\IsolatedDatabase;
use App\Support\ProfilePhone;
use App\Support\StaffSessionVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

abstract class OwnProfileTestCase extends TestCase
{
    protected array $employeeIds = [];

    protected array $actorIds = ['pos' => [], 'hrms' => []];

    protected function employee(array $extra = []): int
    {
        IsolatedDatabase::verify();
        $suffix = bin2hex(random_bytes(4));
        $id = DB::connection('hrms')->table('employees')->insertGetId(array_replace(['employee_code' => 'SYN-PROFILE-'.$suffix, 'first_name' => 'Synthetic', 'last_name' => 'Profile', 'department' => 'Synthetic Department', 'position' => 'Cashier', 'date_hired' => '2020-01-01', 'basic_salary' => '1234.56', 'status' => 'active', 'email' => 'profile-'.$suffix.'@example.invalid', 'contact_number' => '+1 2025550100', 'birthdate' => '1995-01-01', 'place_of_birth' => 'Synthetic City', 'sex' => 'Female', 'civil_status' => 'Single', 'address' => '1 Synthetic Lane', 'highest_educational_attainment' => 'College Graduate', 'year_graduated' => '2018', 'sss_number' => 'SYN-HIDDEN-SSS', 'bank_account_number' => 'SYN-HIDDEN-BANK'], $extra), 'employee_id');
        $this->employeeIds[] = $id;

        return $id;
    }

    protected function profileActor(string $domain = 'pos', string $role = 'cashier', ?int $employee = null, array $extra = []): StaffUser
    {
        if ($employee === null) {
            $position = $domain === 'pos' ? match ($role) {
                'inventory_staff' => 'Inventory Staff', 'finance' => 'Accountant', default => 'Cashier'
            } : ($role === 'employee' ? 'Service Crew' : 'HR Staff');
            $employee = $this->employee(['position' => $position]);
        }
        $id = DB::connection($domain)->table('users')->insertGetId(array_replace(['employee_id' => $employee, 'username' => 'synthetic_profile_'.bin2hex(random_bytes(5)), 'full_name' => 'Synthetic Profile User', 'password' => Hash::make('SyntheticProfile!Pass123'), 'role' => $role, 'is_active' => 1, 'two_factor_enabled' => 1, 'two_factor_email' => 'mfa.profile@example.invalid'], $extra));
        $this->actorIds[$domain][] = $id;

        return ($domain === 'pos' ? PosUser::class : HrUser::class)::findOrFail($id);
    }

    protected function profileSession(StaffUser $user): static
    {
        $domain = $user->getConnectionName();

        return $this->actingAs($user, $domain)->withSession(['staff_domain' => $domain, 'staff_mfa' => true, 'staff_password_signature' => hash('sha256', $user->password), 'staff_activity' => time(), 'staff_session_version' => StaffSessionVersion::current($domain, (int) $user->id)]);
    }

    protected function profileData(StaffUser $actor, array $extra = []): array
    {
        $domain = $actor->getConnectionName();
        $employee = app(OwnProfileService::class)->read($actor, $domain);
        [$country, $number] = ProfilePhone::parse($employee->contact_number);
        $data = array_replace(['revision' => OwnProfileService::revision($actor, $domain, $employee), 'current_password' => 'SyntheticProfile!Pass123', 'email' => $employee->email, 'email_confirmation' => $employee->email, 'contact_number_country' => $country, 'contact_number' => $number, 'date_of_birth' => $employee->birthdate, 'place_of_birth' => $employee->place_of_birth, 'sex' => $employee->sex, 'civil_status' => $employee->civil_status, 'home_address' => $employee->address, 'highest_educational_attainment' => $employee->highest_educational_attainment, 'year_graduated' => $employee->year_graduated], $extra);
        if (array_key_exists('email', $extra) && ! array_key_exists('email_confirmation', $extra)) {
            $data['email_confirmation'] = $extra['email'];
        }

        return $data;
    }

    protected function tearDown(): void
    {
        // These fixtures must be committed so the two real PDO connections see them.
        IsolatedDatabase::verify();
        DB::connection('pos')->table('application_audit_events')->where('entity', 'employees:hrms')->whereIn('entity_id', $this->employeeIds)->delete();
        foreach ($this->actorIds as $domain => $ids) {
            DB::connection($domain)->table('users')->whereIn('id', $ids)->delete();
        }
        DB::connection('hrms')->table('employees')->whereIn('employee_id', $this->employeeIds)->delete();
        parent::tearDown();
    }
}
