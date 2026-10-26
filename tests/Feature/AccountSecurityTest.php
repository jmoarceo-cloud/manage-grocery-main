<?php

namespace Tests\Feature;

use App\Models\HrUser;
use App\Services\AccountSecurityService;
use App\Support\StaffSessionVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AccountSecurityTest extends IsolatedFeatureTestCase
{
    private function data(string $new = 'New-Synthetic!Pass456'): array
    {
        return ['credential_type' => 'password', 'current_password' => 'Synthetic!Pass123', 'password' => $new, 'password_confirmation' => $new];
    }

    private function hrTable(): string
    {
        return config('database.connections.hrms.database').'.users';
    }

    private function mirror(string $posRole = 'admin', string $hrRole = 'admin'): array
    {
        $pos = $this->cashier();
        $db = DB::connection('pos');
        $db->table('users')->where('id', $pos->id)->update(['role' => $posRole]);
        $pos->refresh();
        $id = $db->table($this->hrTable())->insertGetId(['id' => 700001, 'username' => $pos->username, 'full_name' => 'Synthetic HR Admin', 'password' => $pos->password, 'role' => $hrRole, 'is_active' => 1, 'two_factor_enabled' => 1, 'two_factor_email' => 'admin@example.invalid']);
        $hr = new HrUser;
        $hr->setRawAttributes((array) $db->table($this->hrTable())->where('id', $id)->first(), true);
        $hr->exists = true;

        return [$pos, $hr];
    }

    private function hrSession(HrUser $user): void
    {
        $this->actingAs($user, 'hrms')->withSession(['staff_domain' => 'hrms', 'staff_mfa' => true, 'staff_password_signature' => hash('sha256', $user->password), 'staff_activity' => time(), 'staff_session_version' => StaffSessionVersion::current('hrms', (int) $user->id)]);
    }

    public function test_solo_cashier_changes_password_and_loses_the_old_session(): void
    {
        $user = $this->cashier();
        $oldVersion = StaffSessionVersion::current('pos', $user->id);
        $this->staffSession($user)->postJson('/account/password', $this->data())->assertOk()->assertJsonPath('message', 'Password changed. Sign in again.');
        $row = DB::connection('pos')->table('users')->where('id', $user->id)->first();
        $this->assertTrue(Hash::check('New-Synthetic!Pass456', $row->password));
        $this->assertSame(1, (int) $row->force_logout);
        $this->assertNotSame($oldVersion, StaffSessionVersion::current('pos', $user->id));
        $this->assertGuest('pos');
        $this->get('/account/security')->assertRedirect(route('login'));
    }

    public function test_hr_admin_password_update_is_atomic_across_distinct_pos_and_hr_ids(): void
    {
        [$pos,$hr] = $this->mirror();
        $this->assertNotSame((int) $pos->id, (int) $hr->id);
        $this->hrSession($hr);
        $this->postJson('/account/password', $this->data())->assertOk();
        $db = DB::connection('pos');
        $posHash = $db->table('users')->where('id', $pos->id)->value('password');
        $hrHash = $db->table($this->hrTable())->where('id', $hr->id)->value('password');
        $this->assertSame($posHash, $hrHash);
        $this->assertTrue(Hash::check('New-Synthetic!Pass456', $hrHash));
        $this->assertSame(1, (int) $db->table('users')->where('id', $pos->id)->value('force_logout'));
        $this->assertSame(1, (int) $db->table($this->hrTable())->where('id', $hr->id)->value('force_logout'));
        $this->assertDatabaseHas('application_audit_events', ['actor_domain' => 'hrms', 'actor_id' => $hr->id, 'entity' => 'users:pos', 'entity_id' => $pos->id, 'action' => 'password_changed'], 'pos');
        $this->assertGuest('hrms');
    }

    public function test_each_native_hr_role_can_change_its_own_unlinked_password_without_changing_role(): void
    {
        $db = DB::connection('pos');
        foreach (['admin', 'hr', 'hr_manager', 'supervisor', 'accountant', 'employee'] as $index => $role) {
            $id = 800000 + $index;
            $db->table($this->hrTable())->insert(['id' => $id, 'username' => 'synthetic_own_'.$role, 'full_name' => 'Synthetic HR User', 'password' => Hash::make('Synthetic!Pass123'), 'role' => $role, 'is_active' => 1, 'two_factor_enabled' => 1]);
            $user = new HrUser;
            $user->setRawAttributes((array) $db->table($this->hrTable())->where('id', $id)->first(), true);
            $user->exists = true;
            $this->hrSession($user);
            $this->postJson('/account/password', $this->data())->assertOk();
            $row = $db->table($this->hrTable())->where('id', $id)->first();
            $this->assertTrue(Hash::check('New-Synthetic!Pass456', $row->password));
            $this->assertSame($role, $row->role);
            $this->assertGuest('hrms');
        }
    }

    public function test_second_domain_failure_rolls_back_both_passwords_recovery_consumption_and_audit(): void
    {
        [$pos,$hr] = $this->mirror();
        $db = DB::connection('pos');
        $backup = json_encode([Hash::make('SYNTHETIC-ATOMIC-RECOVERY')]);
        $db->table($this->hrTable())->where('id', $hr->id)->update(['two_factor_backup_codes' => $backup]);
        $hr->two_factor_backup_codes = $backup;
        $hrSchema = config('database.connections.hrms.database');
        DB::listen(function ($event) use ($hrSchema): void {
            if (str_starts_with($event->sql, 'update `'.$hrSchema.'`.`users`')) {
                throw new \RuntimeException('Synthetic second-domain failure');
            }
        });
        $data = $this->data();
        $data['credential_type'] = 'recovery';
        $data['backup_code'] = 'SYNTHETIC-ATOMIC-RECOVERY';
        unset($data['current_password']);
        try {
            app(AccountSecurityService::class)->change('hrms', $hr, $data);
            $this->fail('Partial identity update was committed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic second-domain failure', $exception->getMessage());
        }
        $this->assertSame($pos->password, $db->table('users')->where('id', $pos->id)->value('password'));
        $this->assertSame($hr->password, $db->table($this->hrTable())->where('id', $hr->id)->value('password'));
        $this->assertSame($backup, $db->table($this->hrTable())->where('id', $hr->id)->value('two_factor_backup_codes'));
        $this->assertDatabaseCount('application_audit_events', 0, 'pos');
    }

    public function test_recovery_proof_is_consumed_once_for_password_change(): void
    {
        $user = $this->cashier();
        $user->two_factor_backup_codes = json_encode([Hash::make('SYNTHETIC-PASSWORD-RECOVERY')]);
        $user->save();
        $data = $this->data();
        $data['credential_type'] = 'recovery';
        $data['backup_code'] = 'SYNTHETIC-PASSWORD-RECOVERY';
        unset($data['current_password']);
        $this->staffSession($user)->postJson('/account/password', $data)->assertOk();
        $db = DB::connection('pos');
        $db->table('users')->where('id', $user->id)->update(['force_logout' => 0]);
        $user->refresh();
        $this->assertSame('[]', $user->two_factor_backup_codes);
        $data['password'] = $data['password_confirmation'] = 'Another-Synthetic!Pass789';
        $this->staffSession($user)->postJson('/account/password', $data)->assertUnprocessable();
        $this->assertTrue(Hash::check('New-Synthetic!Pass456', $user->fresh()->password));
    }

    public function test_failed_current_credentials_are_throttled_without_changes(): void
    {
        $user = $this->cashier();
        $data = $this->data();
        $data['current_password'] = 'wrong-synthetic-password';
        $this->staffSession($user);
        for ($index = 0; $index < 6; $index++) {
            $this->postJson('/account/password', $data)->assertUnprocessable();
        }
        $this->assertSame(5, RateLimiter::attempts('account-proof:pos:'.$user->id));
        $this->assertSame($user->password, $user->fresh()->password);
        $this->assertDatabaseCount('application_audit_events', 0, 'pos');
    }

    public function test_ambiguous_or_noncanonical_domain_links_cannot_change_credentials(): void
    {
        [$pos,$hr] = $this->mirror('cashier', 'admin');
        $this->staffSession($pos)->postJson('/account/password', $this->data())->assertUnprocessable();
        $this->assertSame($pos->password, $pos->fresh()->password);
        DB::connection('pos')->table('users')->where('id', $pos->id)->update(['role' => 'admin']);
        $pos->refresh();
        $this->staffSession($pos)->postJson('/account/password', $this->data())->assertForbidden();
        $this->assertSame($hr->password, DB::connection('pos')->table($this->hrTable())->where('id', $hr->id)->value('password'));
    }

    public function test_target_tampering_and_invalid_password_values_are_rejected_and_secrets_not_flashed(): void
    {
        $user = $this->cashier();
        $this->staffSession($user);
        $this->postJson('/account/password', $this->data() + ['user_id' => 1, 'domain' => 'hrms', 'role' => 'admin'])->assertUnprocessable();
        foreach (['weak', 'New-Synthetic!Pass456'."\0", str_repeat('A', 73).'a1!'] as $value) {
            $this->postJson('/account/password', $this->data($value))->assertUnprocessable();
        }
        $data = $this->data('weak');
        $data['credential_type'] = 'recovery';
        $data['backup_code'] = 'SYNTHETIC-NOT-FLASHED';
        unset($data['current_password']);
        $this->from('/account/security')->post('/account/password', $data)->assertRedirect('/account/security')->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation')->assertSessionMissing('_old_input.backup_code');
        $this->assertSame($user->password, $user->fresh()->password);
    }

    public function test_account_page_requires_mfa_and_masks_private_credential_details(): void
    {
        $this->get('/account/security')->assertRedirect(route('login'));
        $user = $this->cashier();
        $this->actingAs($user, 'pos')->get('/account/security')->assertRedirect(route('login'));
        $this->staffSession($user)->get('/account/security')->assertOk()->assertDontSee($user->password)->assertDontSee('cashier@example.invalid')->assertSee('c•••@example.invalid');
    }

    public function test_different_server_configuration_is_refused_before_any_password_update(): void
    {
        $user = $this->cashier();
        config(['database.connections.hrms.host' => 'synthetic-unavailable.invalid']);
        $this->staffSession($user)->postJson('/account/password', $this->data())->assertUnprocessable();
        $this->assertSame($user->password, $user->fresh()->password);
    }

    public function test_matching_employee_link_updates_both_credentials_only_while_employee_is_eligible(): void
    {
        [$pos, $hr] = $this->mirror('cashier', 'employee');
        $db = DB::connection('pos');
        $employeeTable = config('database.connections.hrms.database').'.employees';
        $employeeId = $db->table($employeeTable)->insertGetId(['employee_code' => 'SYNTHETIC-LINKED', 'first_name' => 'Synthetic', 'last_name' => 'Linked', 'position' => 'Cashier', 'date_hired' => now()->toDateString(), 'basic_salary' => 0, 'status' => 'terminated'], 'employee_id');
        $db->table('users')->where('id', $pos->id)->update(['employee_id' => $employeeId]);
        $db->table($this->hrTable())->where('id', $hr->id)->update(['employee_id' => $employeeId]);
        $pos->refresh();
        try {
            app(AccountSecurityService::class)->change('pos', $pos, $this->data());
            $this->fail('Terminated employee changed credentials.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($pos->password, $pos->fresh()->password);
        $db->table($employeeTable)->where('employee_id', $employeeId)->update(['status' => 'active']);
        app(AccountSecurityService::class)->change('pos', $pos, $this->data());
        $hash = $db->table('users')->where('id', $pos->id)->value('password');
        $this->assertSame($hash, $db->table($this->hrTable())->where('id', $hr->id)->value('password'));
        $this->assertTrue(Hash::check('New-Synthetic!Pass456', $hash));
    }
}
