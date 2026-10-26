<?php

namespace Tests\Feature;

use App\Models\HrUser;
use App\Models\PosUser;
use App\Models\StaffUser;
use App\Services\OwnScheduleService;
use App\Support\StaffSessionVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

class OwnScheduleTest extends IsolatedFeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 00:30:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function employee(array $extra = []): int
    {
        return DB::connection('hrms')->table('employees')->insertGetId(array_replace(['employee_code' => 'SYN-SCH-'.bin2hex(random_bytes(3)), 'first_name' => 'Synthetic', 'last_name' => 'Schedule', 'position' => 'Cashier', 'date_hired' => '2020-01-01', 'basic_salary' => '9876.54', 'status' => 'active', 'email' => 'private.schedule@example.invalid', 'bank_account_number' => 'SYN-SCHEDULE-BANK', 'sss_number' => 'SYN-SCHEDULE-SSS'], $extra), 'employee_id');
    }

    private function actor(string $domain = 'pos', string $role = 'cashier', array $extra = []): StaffUser
    {
        $employee = $this->employee(['position' => $domain === 'hrms' ? 'Service Crew' : match ($role) {
            'finance' => 'Accountant', 'inventory_staff' => 'Inventory Staff', default => 'Cashier'
        }]);
        $id = DB::connection($domain)->table('users')->insertGetId(array_replace(['username' => 'synthetic_schedule_'.bin2hex(random_bytes(5)), 'full_name' => 'Synthetic Schedule Staff', 'employee_id' => $employee, 'role' => $role, 'password' => Hash::make('SyntheticSchedule!Pass123'), 'is_active' => 1, 'two_factor_enabled' => 1, 'two_factor_email' => 'schedule@example.invalid'], $extra));

        return ($domain === 'pos' ? PosUser::class : HrUser::class)::findOrFail($id);
    }

    private function scheduleSession(StaffUser $actor): static
    {
        $domain = $actor->getConnectionName();

        return $this->actingAs($actor, $domain)->withSession(['staff_domain' => $domain, 'staff_mfa' => true, 'staff_password_signature' => hash('sha256', $actor->password), 'staff_activity' => time(), 'staff_session_version' => StaffSessionVersion::current($domain, (int) $actor->id)]);
    }

    private function shift(string $name = 'Synthetic opening', array $extra = []): int
    {
        return DB::connection('hrms')->table('shifts')->insertGetId(array_replace(['shift_name' => $name, 'start_time' => '08:00:00', 'end_time' => '16:00:00', 'is_active' => 1], $extra), 'shift_id');
    }

    private function weekly(int $employee, int $day, ?int $shift): void
    {
        DB::connection('hrms')->table('employee_weekly_shifts')->insert(['employee_id' => $employee, 'day_of_week' => $day, 'shift_id' => $shift]);
    }

    private function cover(int $employee, string $date, ?int $shift, string $status = 'approved', array $extra = []): void
    {
        DB::connection('hrms')->table('shift_swaps')->insert(array_replace(['covering_employee_id' => $employee, 'swap_date' => $date, 'shift_id' => $shift, 'status' => $status, 'reason' => 'SYN-PRIVATE-COVER-REASON'], $extra));
    }

    public function test_schedule_requires_full_mfa_and_has_no_write_endpoint(): void
    {
        $this->get('/account/schedule')->assertRedirect(route('login'));
        $actor = $this->actor();
        $this->actingAs($actor, 'pos')->get('/account/schedule')->assertRedirect(route('login'));
        $this->scheduleSession($actor)->get('/account/schedule')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/account/schedule', [])->assertStatus(405);
    }

    public function test_only_explicit_link_is_used_and_private_employee_and_swap_fields_are_not_selected(): void
    {
        $actor = $this->actor(extra: ['id' => 500001]);
        $other = $this->employee(['employee_id' => $actor->id]);
        $this->assertNotSame((int) $actor->id, (int) $actor->employee_id);
        $this->weekly($actor->employee_id, 1, $this->shift('Owned shift'));
        $this->weekly($other, 1, $this->shift('SYN-OTHER-PRIVATE-SHIFT'));
        $this->cover($other, '2026-10-27', $this->shift('SYN-OTHER-PRIVATE-COVER'));
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->scheduleSession($actor)->get('/account/schedule?employee_id='.$other.'&user_id='.$actor->id.'&from=2000-01-01')->assertOk()->assertSee('Owned shift')->assertDontSee('SYN-OTHER-PRIVATE')->assertDontSee('private.schedule@example.invalid')->assertDontSee('9876.54')->assertDontSee('SYN-SCHEDULE-BANK')->assertDontSee('SYN-SCHEDULE-SSS')->assertDontSee('SYN-PRIVATE-COVER-REASON');
        foreach ($queries as $sql) {
            foreach (['basic_salary', 'bank_account_number', 'sss_number', '`email`', '`reason`', 'original_employee_id', 'requested_by', 'processed_by'] as $field) {
                $this->assertStringNotContainsString($field, $sql);
            }
            $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|alter|create)\b/i', $sql);
        }
    }

    public function test_all_native_pos_and_hr_roles_can_read_only_their_own_schedule(): void
    {
        foreach (['pos' => ['admin', 'cashier', 'inventory_staff', 'finance'], 'hrms' => ['admin', 'hr', 'hr_manager', 'supervisor', 'accountant', 'employee']] as $domain => $roles) {
            foreach ($roles as $role) {
                $actor = $this->actor($domain, $role);
                $this->weekly($actor->employee_id, 2, $this->shift('Synthetic '.$domain.' '.$role));
                $this->scheduleSession($actor)->get('/account/schedule')->assertOk()->assertSee('Synthetic '.$domain.' '.$role);
            }
        }
    }

    public function test_unconfigured_and_day_off_states_differ_and_all_seven_weekdays_are_present(): void
    {
        $actor = $this->actor();
        $this->scheduleSession($actor)->get('/account/schedule')->assertOk()->assertSee('No weekly schedule has been set up.')->assertSee('Not set')->assertDontSee('Day off');
        $this->weekly($actor->employee_id, 1, null);
        $data = app(OwnScheduleService::class)->forStaff($actor, 'pos');
        $this->assertTrue($data['configured']);
        $this->assertSame(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], array_column($data['weekly'], 'day'));
        $this->assertSame(array_fill(0, 7, 'Day off'), array_column($data['weekly'], 'name'));
        $this->assertSame('2026-10-26', $data['upcoming'][0]['date']);
        $this->assertSame('2026-11-01', $data['upcoming'][6]['date']);
    }

    public function test_preview_only_uses_approved_own_covers_in_the_next_seven_manila_days_and_weekly_wins(): void
    {
        $actor = $this->actor();
        $weekly = $this->shift('Weekly precedence');
        $cover = $this->shift('Approved covering shift');
        $hidden = $this->shift('SYN-HIDDEN-COVER');
        $this->weekly($actor->employee_id, 1, $weekly);
        $this->cover($actor->employee_id, '2026-10-26', $hidden);
        $this->cover($actor->employee_id, '2026-10-27', $cover);
        $this->cover($actor->employee_id, '2026-10-28', $hidden, 'pending');
        $this->cover($actor->employee_id, '2026-10-29', $hidden, 'rejected');
        $this->cover($actor->employee_id, '2026-10-25', $hidden);
        $this->cover($actor->employee_id, '2026-11-02', $hidden);
        $other = $this->employee();
        $this->cover($other, '2026-10-30', $hidden, extra: ['original_employee_id' => $actor->employee_id]);
        $data = app(OwnScheduleService::class)->forStaff($actor, 'pos');
        $this->assertSame('Weekly precedence', $data['upcoming'][0]['name']);
        $this->assertFalse($data['upcoming'][0]['cover']);
        $this->assertSame('Approved covering shift', $data['upcoming'][1]['name']);
        $this->assertTrue($data['upcoming'][1]['cover']);
        $this->assertSame(array_fill(0, 5, 'Day off'), array_column(array_slice($data['upcoming'], 2), 'name'));
        $this->scheduleSession($actor)->get('/account/schedule')->assertOk()->assertDontSee('SYN-HIDDEN-COVER')->assertDontSee('SYN-PRIVATE-COVER-REASON');
    }

    public function test_overnight_equal_endpoints_inactive_and_invalid_extended_sql_times_are_explicit(): void
    {
        $actor = $this->actor();
        $this->weekly($actor->employee_id, 1, $this->shift('Night', ['start_time' => '22:00:00', 'end_time' => '06:00:00']));
        $this->weekly($actor->employee_id, 2, $this->shift('Equal endpoints', ['start_time' => '08:00:00', 'end_time' => '08:00:00']));
        $this->weekly($actor->employee_id, 3, $this->shift('Inactive kept', ['is_active' => 0]));
        $this->weekly($actor->employee_id, 4, $this->shift('Bad clock', ['start_time' => '25:00:00']));
        $data = app(OwnScheduleService::class)->forStaff($actor, 'pos');
        $this->assertSame('10:00 PM – 6:00 AM', $data['weekly'][0]['time']);
        $this->assertTrue($data['weekly'][0]['overnight']);
        $this->assertTrue($data['weekly'][1]['overnight']);
        $this->assertTrue($data['weekly'][2]['inactive']);
        $this->assertTrue($data['weekly'][3]['needs_review']);
        $this->assertNull($data['weekly'][3]['time']);
        $this->scheduleSession($actor)->get('/account/schedule')->assertOk()->assertSee('Ends the following day.')->assertSee('Inactive kept')->assertSee('Times need confirmation')->assertDontSee('25:00');
    }

    public function test_approved_cover_without_shift_does_not_invent_a_time_or_day_off(): void
    {
        $actor = $this->actor();
        $this->cover($actor->employee_id, '2026-10-26', null);
        $data = app(OwnScheduleService::class)->forStaff($actor, 'pos');
        $this->assertFalse($data['configured']);
        $this->assertTrue($data['upcoming'][0]['assigned']);
        $this->assertTrue($data['upcoming'][0]['cover']);
        $this->assertTrue($data['upcoming'][0]['needs_review']);
        $this->assertNull($data['upcoming'][0]['time']);
        $this->scheduleSession($actor)->get('/account/schedule')->assertOk()->assertSee('Shift times need review.')->assertSee('Approved cover')->assertDontSee('8:00 AM');
    }

    public function test_unlinked_missing_terminated_and_suspended_records_cannot_expose_a_schedule(): void
    {
        $actor = $this->actor(extra: ['employee_id' => null]);
        $this->scheduleSession($actor)->get('/account/schedule')->assertOk()->assertSee('Your employee record needs to be linked.')->assertDontSee('Recurring week');
        $actor->employee_id = 2147483647;
        $this->scheduleSession($actor)->getJson('/account/schedule')->assertUnauthorized();
        foreach ([['status' => 'terminated'], ['suspension_start_date' => date('Y-m-d'), 'suspension_end_date' => null]] as $state) {
            $actor = $this->actor();
            DB::connection('hrms')->table('employees')->where('employee_id', $actor->employee_id)->update($state);
            $this->scheduleSession($actor)->getJson('/account/schedule')->assertUnauthorized();
        }
    }

    public function test_shift_labels_are_escaped_and_reads_do_not_run_attendance_payroll_or_external_calls(): void
    {
        Http::preventStrayRequests();
        $actor = $this->actor();
        $this->weekly($actor->employee_id, 1, $this->shift('<script>syntheticShift()</script>'));
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });
        $this->scheduleSession($actor)->get('/account/schedule')->assertOk()->assertSee('<script>syntheticShift()</script>')->assertDontSee('<script>syntheticShift()</script>', false);
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('attendance', $sql);
            $this->assertStringNotContainsString('payroll', $sql);
            $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|alter|create)\b/i', $sql);
        }
        Http::assertNothingSent();
    }
}
