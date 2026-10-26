<?php

namespace Tests\Feature;

use App\Models\PosUser;
use App\Services\OwnProfileService;
use App\Support\ProfilePhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

class OwnProfileTest extends OwnProfileTestCase
{
    public function test_profile_requires_mfa_and_reads_only_the_linked_employee_not_a_matching_user_id(): void
    {
        $this->get('/account/profile')->assertRedirect(route('login'));
        $actor = $this->profileActor(extra: ['id' => 400001]);
        $other = $this->employee(['employee_id' => $actor->id, 'first_name' => 'Synthetic Other', 'email' => 'other.private@example.invalid']);
        $this->assertNotSame((int) $actor->id, (int) $actor->employee_id);
        $this->actingAs($actor, 'pos')->get('/account/profile')->assertRedirect(route('login'));
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->profileSession($actor)->get('/account/profile?employee_id='.$other)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('other.private@example.invalid')->assertDontSee('SYN-HIDDEN-SSS')->assertDontSee('SYN-HIDDEN-BANK')->assertDontSee('1234.56');
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('basic_salary', $sql);
            $this->assertStringNotContainsString('sss_number', $sql);
            $this->assertStringNotContainsString('bank_account_number', $sql);
        }
    }

    public function test_pos_employee_can_update_owned_fields_with_password_and_minimal_atomic_audit(): void
    {
        $actor = $this->profileActor();
        $other = $this->employee(['email' => 'unchanged.other@example.invalid']);
        Http::preventStrayRequests();
        $data = $this->profileData($actor, ['email' => 'new.contact@example.invalid', 'contact_number_country' => 'PH', 'contact_number' => '917 123 4567', 'home_address' => '2 Synthetic Lane']);
        $this->profileSession($actor)->postJson('/account/profile', $data)->assertOk();
        $this->assertDatabaseHas('employees', ['employee_id' => $actor->employee_id, 'email' => 'new.contact@example.invalid', 'contact_number' => '+63 9171234567', 'address' => '2 Synthetic Lane', 'basic_salary' => '1234.56', 'bank_account_number' => 'SYN-HIDDEN-BANK'], 'hrms');
        $this->assertDatabaseHas('employees', ['employee_id' => $other, 'email' => 'unchanged.other@example.invalid'], 'hrms');
        $this->assertDatabaseHas('users', ['id' => $actor->id, 'two_factor_email' => 'mfa.profile@example.invalid', 'force_logout' => 0], 'pos');
        $audit = DB::connection('pos')->table('application_audit_events')->where('entity', 'employees:hrms')->where('entity_id', $actor->employee_id)->first();
        $this->assertSame('pos', $audit->actor_domain);
        $this->assertSame((int) $actor->id, (int) $audit->actor_id);
        $this->assertEqualsCanonicalizing(['email', 'contact_number', 'address'], json_decode($audit->changed_fields, true));
        $this->assertStringNotContainsString('new.contact@example.invalid', $audit->changed_fields);
        $this->get('/account/profile')->assertOk();
        Http::assertNothingSent();
    }

    public function test_all_native_hr_roles_and_remaining_pos_roles_update_only_their_own_linked_record(): void
    {
        foreach (['admin', 'hr', 'hr_manager', 'supervisor', 'accountant', 'employee'] as $role) {
            $actor = $this->profileActor('hrms', $role);
            $this->profileSession($actor)->postJson('/account/profile', $this->profileData($actor, ['home_address' => 'Synthetic '.$role.' address']))->assertOk();
            $this->assertDatabaseHas('employees', ['employee_id' => $actor->employee_id, 'address' => 'Synthetic '.$role.' address'], 'hrms');
            $this->assertDatabaseHas('application_audit_events', ['entity' => 'employees:hrms', 'entity_id' => $actor->employee_id, 'actor_domain' => 'hrms', 'actor_id' => $actor->id], 'pos');
        }
        foreach (['inventory_staff', 'finance', 'admin'] as $role) {
            $actor = $this->profileActor('pos', $role);
            $this->profileSession($actor)->postJson('/account/profile', $this->profileData($actor, ['home_address' => 'Synthetic POS '.$role.' address']))->assertOk();
            $this->assertDatabaseHas('employees', ['employee_id' => $actor->employee_id, 'address' => 'Synthetic POS '.$role.' address'], 'hrms');
        }
    }

    public function test_target_employment_and_financial_field_tampering_is_rejected(): void
    {
        $actor = $this->profileActor();
        $data = $this->profileData($actor) + ['employee_id' => 1, 'user_id' => 1, 'role' => 'admin', 'status' => 'terminated', 'basic_salary' => '0.01', 'bank_account_number' => 'Synthetic tamper', 'department' => 'Synthetic tamper'];
        $this->profileSession($actor)->postJson('/account/profile', $data)->assertUnprocessable();
        $this->assertDatabaseHas('employees', ['employee_id' => $actor->employee_id, 'basic_salary' => '1234.56', 'department' => 'Synthetic Department', 'status' => 'active'], 'hrms');
    }

    public function test_invalid_password_proofs_are_throttled_without_profile_changes(): void
    {
        $actor = $this->profileActor();
        $data = $this->profileData($actor, ['current_password' => 'Synthetic wrong password', 'home_address' => 'Synthetic attempted change']);
        $this->profileSession($actor);
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/account/profile', $data)->assertUnprocessable()->assertJsonValidationErrors('current_password');
        }
        $this->assertSame(5, RateLimiter::attempts('profile-proof:pos:'.$actor->id));
        $this->assertDatabaseHas('employees', ['employee_id' => $actor->employee_id, 'address' => '1 Synthetic Lane'], 'hrms');
    }

    public function test_duplicate_email_and_confirmation_mismatch_do_not_change_correspondence(): void
    {
        $actor = $this->profileActor();
        $this->employee(['email' => 'existing.contact@example.invalid']);
        $this->profileSession($actor)->postJson('/account/profile', $this->profileData($actor, ['email' => 'new.contact@example.invalid', 'email_confirmation' => 'different.contact@example.invalid']))->assertUnprocessable()->assertJsonValidationErrors('email_confirmation');
        $this->postJson('/account/profile', $this->profileData($actor, ['email' => 'EXISTING.contact@example.invalid']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseMissing('employees', ['employee_id' => $actor->employee_id, 'email' => 'new.contact@example.invalid'], 'hrms');
    }

    public function test_stale_revision_and_reassigned_employee_link_cannot_overwrite_a_record(): void
    {
        $actor = $this->profileActor();
        $data = $this->profileData($actor, ['home_address' => 'Synthetic stale overwrite']);
        DB::connection('hrms')->table('employees')->where('employee_id', $actor->employee_id)->update(['address' => 'Synthetic HR correction']);
        $this->profileSession($actor)->postJson('/account/profile', $data)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $data = $this->profileData($actor, ['home_address' => 'Synthetic relink overwrite']);
        $other = $this->employee();
        DB::connection('pos')->table('users')->where('id', $actor->id)->update(['employee_id' => $other]);
        $actor->refresh();
        $this->profileSession($actor)->postJson('/account/profile', $data)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertDatabaseHas('employees', ['employee_id' => $other, 'address' => '1 Synthetic Lane'], 'hrms');
    }

    public function test_personal_values_and_current_password_are_not_flashed_on_html_failures(): void
    {
        $actor = $this->profileActor();
        $this->profileSession($actor)->post('/account/profile', $this->profileData($actor, ['email' => 'Synthetic invalid email', 'home_address' => 'Synthetic private unsaved address']))->assertRedirect(route('profile.edit'))->assertSessionHasErrors('email')->assertSessionMissing('_old_input');
        $this->get('/account/profile')->assertOk()->assertDontSee('Synthetic private unsaved address');
        $this->post('/account/profile', $this->profileData($actor, ['current_password' => 'Synthetic wrong password', 'home_address' => 'Synthetic proof unsaved address']))->assertRedirect(route('profile.edit'))->assertSessionHasErrors('current_password')->assertSessionMissing('_old_input');
        $this->get('/account/profile')->assertDontSee('Synthetic proof unsaved address')->assertDontSee('Synthetic wrong password');
    }

    public function test_audit_failure_rolls_back_hr_fields_and_releases_the_email_lock(): void
    {
        $actor = $this->profileActor();
        $originalEmail = $this->profileData($actor)['email'];
        $data = $this->profileData($actor, ['email' => 'rollback.profile@example.invalid']);
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'insert into `application_audit_events`')) {
                throw new \RuntimeException('Synthetic profile audit failure');
            }
        });
        try {
            app(OwnProfileService::class)->change($actor, 'pos', $data);
            $this->fail('Unaudited personal information committed.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic profile audit failure', $error->getMessage());
        }
        $this->assertDatabaseHas('employees', ['employee_id' => $actor->employee_id, 'email' => $originalEmail], 'hrms');
        $this->assertDatabaseMissing('employees', ['employee_id' => $actor->employee_id, 'email' => 'rollback.profile@example.invalid'], 'hrms');
        $this->assertSame(1, (int) DB::connection('pos')->selectOne('SELECT IS_FREE_LOCK(?) AS available', [OwnProfileService::emailLock($data['email'])])->available);
    }

    public function test_known_phone_formats_and_unknown_saved_values_are_preserved_without_truncation(): void
    {
        $actor = $this->profileActor();
        $this->assertSame(['US', '2025550100'], ProfilePhone::parse('+1 2025550100'));
        $this->assertSame(['PH', '9171234567'], ProfilePhone::parse('9171234567'));
        $this->assertSame(['GB', '7911123456'], ProfilePhone::parse('+44 7911123456'));
        DB::connection('hrms')->table('employees')->where('employee_id', $actor->employee_id)->update(['contact_number' => '+999 12345678901', 'sex' => 'Saved value']);
        $this->profileSession($actor)->postJson('/account/profile', $this->profileData($actor, ['home_address' => 'Synthetic unchanged phone']))->assertOk();
        $this->assertDatabaseHas('employees', ['employee_id' => $actor->employee_id, 'contact_number' => '+999 12345678901', 'sex' => 'Saved value'], 'hrms');
        $this->postJson('/account/profile', $this->profileData($actor, ['contact_number' => '+999 00000000000']))->assertUnprocessable()->assertJsonValidationErrors('contact_number');
        $this->postJson('/account/profile', $this->profileData($actor, ['sex' => 'Synthetic new value']))->assertUnprocessable()->assertJsonValidationErrors('sex');
    }

    public function test_invalid_personal_field_shapes_and_calendar_dates_are_rejected(): void
    {
        $actor = $this->profileActor();
        $this->profileSession($actor);
        foreach ([['contact_number' => 'abcdefghij'], ['contact_number' => '123'], ['contact_number_country' => 'INVALID'], ['date_of_birth' => '2025-02-30'], ['date_of_birth' => now()->addDay()->toDateString()], ['place_of_birth' => str_repeat('A', 151)], ['year_graduated' => '1949'], ['year_graduated' => (string) (now()->year + 1)], ['home_address' => ['Synthetic array']], ['email' => str_repeat('a', 90).'@example.invalid']] as $invalid) {
            $this->postJson('/account/profile', $this->profileData($actor, $invalid))->assertUnprocessable();
        }
    }

    public function test_unlinked_and_terminated_staff_cannot_write_a_personal_record(): void
    {
        $unlinked = PosUser::where('username', 'demo_admin')->firstOrFail();
        $this->profileSession($unlinked)->get('/account/profile')->assertOk()->assertSee('Your employee profile needs to be linked.');
        $actor = $this->profileActor();
        $data = $this->profileData($actor);
        $this->profileSession($unlinked)->postJson('/account/profile', $data)->assertForbidden();
        DB::connection('hrms')->table('employees')->where('employee_id', $actor->employee_id)->update(['status' => 'terminated']);
        $this->profileSession($actor)->postJson('/account/profile', $data)->assertUnauthorized();
    }

    public function test_unchanged_profile_does_not_create_an_empty_audit_event(): void
    {
        $actor = $this->profileActor();
        $this->profileSession($actor)->postJson('/account/profile', $this->profileData($actor))->assertOk();
        $this->assertSame(0, DB::connection('pos')->table('application_audit_events')->where('entity', 'employees:hrms')->where('entity_id', $actor->employee_id)->count());
    }

    public function test_different_server_configuration_refuses_update_and_releases_its_lock(): void
    {
        $actor = $this->profileActor();
        $data = $this->profileData($actor, ['email' => 'unsupported.profile@example.invalid']);
        $original = config('database.connections.hrms.host');
        try {
            config(['database.connections.hrms.host' => 'synthetic-unavailable.invalid']);
            $this->profileSession($actor)->postJson('/account/profile', $data)->assertUnprocessable()->assertJsonValidationErrors('profile');
        } finally {
            config(['database.connections.hrms.host' => $original]);
        }
        $this->assertDatabaseMissing('employees', ['employee_id' => $actor->employee_id, 'email' => $data['email']], 'hrms');
        $this->assertSame(1, (int) DB::connection('pos')->selectOne('SELECT IS_FREE_LOCK(?) AS available', [OwnProfileService::emailLock($data['email'])])->available);
    }
}
