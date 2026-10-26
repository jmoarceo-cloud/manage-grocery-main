<?php

namespace Tests\Feature;

use App\Models\PosUser;
use App\Services\OwnOvertimeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class OwnOvertimeTest extends OwnOvertimeTestCase
{
    public function test_requires_mfa_and_explicit_employee_link_and_does_not_select_gps_or_financial_fields(): void
    {
        $this->get('/account/overtime')->assertRedirect(route('login'));
        $actor = $this->profileActor(extra: ['id' => 800001]);
        $other = $this->employee(['employee_id' => $actor->id]);
        $this->existingOvertime($other, ['reason' => 'SYN-OTHER-PRIVATE-OT']);
        $this->actingAs($actor, 'pos')->get('/account/overtime')->assertRedirect(route('login'));
        $this->existingOvertime($actor->employee_id);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->profileSession($actor)->get('/account/overtime?employee_id='.$other)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('SYN-OTHER-PRIVATE-OT')->assertDontSee('SYN-HIDDEN-BANK')->assertDontSee('SYN-HIDDEN-SSS');
        foreach ($queries as $sql) {
            foreach (['latitude', 'longitude', 'accuracy', 'address', 'basic_salary', 'bank_account', 'sss_number', 'approved_by'] as $field) {
                $this->assertStringNotContainsString($field, $sql);
            }
            $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|alter|create)\b/i', $sql);
        }
    }

    public function test_all_ten_native_roles_submit_only_owned_pending_requests_with_no_time_or_payroll_write(): void
    {
        Http::preventStrayRequests();
        foreach (['pos' => ['admin', 'cashier', 'inventory_staff', 'finance'], 'hrms' => ['admin', 'hr', 'hr_manager', 'supervisor', 'accountant', 'employee']] as $domain => $roles) {
            foreach ($roles as $role) {
                $actor = $this->profileActor($domain, $role);
                $response = $this->profileSession($actor)->postJson('/account/overtime', $this->overtimeData())->assertCreated()->assertJsonPath('status', 'pending');
                $id = $response->json('request_id');
                $this->assertDatabaseHas('overtime_requests', ['ot_id' => $id, 'employee_id' => $actor->employee_id, 'hours_requested' => '2.00', 'approved_by' => null, 'decided_at' => null], 'hrms');
                $audit = DB::connection('pos')->table('application_audit_events')->where('entity', 'overtime_requests:hrms')->where('entity_id', $id)->first();
                $this->assertSame($domain, $audit->actor_domain);
                $this->assertSame((int) $actor->id, (int) $audit->actor_id);
                $this->assertStringNotContainsString('Synthetic overtime request', $audit->changed_fields);
                $this->assertSame(0, DB::connection('hrms')->table('attendance')->where('employee_id', $actor->employee_id)->count());
                $this->assertSame(0, DB::connection('hrms')->table('payroll_records')->where('employee_id', $actor->employee_id)->count());
            }
        }
        Http::assertNothingSent();
    }

    public function test_hours_and_real_past_calendar_date_are_bounded_to_existing_storage_precision(): void
    {
        $actor = $this->profileActor();
        $this->profileSession($actor);
        foreach (['0', '-1', '12.01', '1e1', '0.001', '01.00', ['synthetic'], str_repeat('9', 80)] as $hours) {
            $this->postJson('/account/overtime', $this->overtimeData(['hours_requested' => $hours]))->assertUnprocessable()->assertJsonValidationErrors('hours_requested');
        }
        foreach (['0000-01-01', '2026-02-30', '10000-01-01', now('Asia/Manila')->addDay()->toDateString(), ['synthetic']] as $date) {
            $this->postJson('/account/overtime', $this->overtimeData(['ot_date' => $date]))->assertUnprocessable()->assertJsonValidationErrors('ot_date');
        }
        $this->postJson('/account/overtime', $this->overtimeData(['hours_requested' => '0.01']))->assertCreated();
        $this->postJson('/account/overtime', $this->overtimeData(['hours_requested' => '12.00']))->assertCreated();
    }

    public function test_identical_pending_retry_normalizes_hours_and_does_not_duplicate_audit(): void
    {
        $actor = $this->profileActor();
        $id = $this->profileSession($actor)->postJson('/account/overtime', $this->overtimeData(['hours_requested' => '2']))->assertCreated()->json('request_id');
        $this->postJson('/account/overtime', $this->overtimeData(['hours_requested' => '2.0']))->assertOk()->assertJsonPath('request_id', $id)->assertJsonPath('replayed', true);
        $this->assertSame(1, DB::connection('hrms')->table('overtime_requests')->where('employee_id', $actor->employee_id)->count());
        $this->assertSame(1, DB::connection('pos')->table('application_audit_events')->where('entity', 'overtime_requests:hrms')->where('entity_id', $id)->count());
    }

    public function test_request_cannot_set_target_status_actor_actual_hours_or_timestamps(): void
    {
        $actor = $this->profileActor();
        $this->profileSession($actor);
        foreach (['employee_id' => 1, 'user_id' => 1, 'id' => 1, 'ot_id' => 1, 'status' => 'approved', 'approved_by' => 1, 'requested_at' => '2000-01-01', 'decided_at' => '2000-01-01', 'actual_ot_hours' => 12, 'worked_hours' => 12] as $field => $value) {
            $this->postJson('/account/overtime', $this->overtimeData([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson('/account/overtime', $this->overtimeData(['reason' => ' ']))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/account/overtime', $this->overtimeData(['reason' => str_repeat('x', 2001)]))->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_history_batches_only_owned_attendance_and_displays_requested_separately_from_actual(): void
    {
        $actor = $this->profileActor();
        $date = now('Asia/Manila')->toDateString();
        $this->existingOvertime($actor->employee_id, ['hours_requested' => '5.00']);
        DB::connection('hrms')->table('attendance')->insert(['employee_id' => $actor->employee_id, 'date' => $date, 'time_in' => '07:00:00', 'time_out' => '18:00:00', 'status' => 'present', 'time_in_latitude' => '14.1234567', 'time_in_longitude' => '120.1234567', 'time_in_address' => 'SYN-PRIVATE-GPS-ADDRESS']);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $response = $this->profileSession($actor)->get('/account/overtime');
        $response->assertOk()->assertSee('5.00 hours requested')->assertSee('10.00 hours')->assertSee('8.00 hours')->assertSee('2.00 hours')->assertDontSee('SYN-PRIVATE-GPS-ADDRESS')->assertDontSee('14.1234567');
        $this->assertCount(1, array_filter($queries, fn ($sql) => str_contains($sql, 'from `attendance`')));
        $this->assertCount(1, array_filter($queries, fn ($sql) => str_contains($sql, 'from `employee_weekly_shifts`')));
        $this->get('/account/overtime?status=approved')->assertOk()->assertSee('No overtime requests match');
    }

    public function test_audit_failure_rolls_back_pending_request(): void
    {
        $actor = $this->profileActor();
        $injected = false;
        DB::listen(function ($query) use (&$injected) {
            if (! $injected && str_starts_with($query->sql, 'insert into `application_audit_events`')) {
                $injected = true;
                throw new \RuntimeException('Synthetic overtime audit failure');
            }
        });
        try {
            app(OwnOvertimeService::class)->submit($actor, 'pos', $this->overtimeData());
            $this->fail('Expected synthetic failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic overtime audit failure', $exception->getMessage());
        }
        $this->assertTrue($injected);
        $this->assertSame(0, DB::connection('hrms')->table('overtime_requests')->where('employee_id', $actor->employee_id)->count());
    }

    public function test_html_errors_clear_private_input_and_success_keeps_requested_date_year(): void
    {
        $actor = $this->profileActor();
        $this->profileSession($actor)->post('/account/overtime', $this->overtimeData(['hours_requested' => 99, 'reason' => 'SYN-PRIVATE-OT-ERROR']))->assertRedirect(route('overtime.index'))->assertSessionMissing('_old_input');
        $this->get('/account/overtime?year=invalid&reason=SYN-PRIVATE-OT-ERROR')->assertRedirect(route('overtime.index'))->assertSessionMissing('_old_input');
        $this->post('/account/overtime', $this->overtimeData(['ot_date' => '2020-01-01']))->assertRedirect(route('overtime.index', ['year' => '2020']));
        $this->get('/account/overtime?year=2020')->assertOk()->assertSee('2020-01-01')->assertSee('No complete attendance');
    }

    public function test_own_history_paginates_and_escapes_reasons_and_validates_filter_storage_bounds(): void
    {
        $actor = $this->profileActor();
        for ($i = 0; $i < 21; $i++) {
            $this->existingOvertime($actor->employee_id, ['reason' => '<script>syntheticOvertime()</script>']);
        }
        $this->profileSession($actor)->get('/account/overtime')->assertOk()->assertSee('page=2', false)->assertSee('<script>syntheticOvertime()</script>')->assertDontSee('<script>syntheticOvertime()</script>', false);
        $this->get('/account/overtime?page=2')->assertOk()->assertSee('syntheticOvertime()');
        foreach (['year=99999', 'status=invalid', 'page=0'] as $query) {
            $this->getJson('/account/overtime?'.$query)->assertUnprocessable();
        }
    }

    public function test_unlinked_ineligible_reassigned_and_unsupported_connections_cannot_submit(): void
    {
        $actor = PosUser::where('username', 'demo_cashier')->firstOrFail();
        $this->profileSession($actor)->get('/account/overtime')->assertOk()->assertSee('Your employee record needs to be linked.');
        $this->postJson('/account/overtime', $this->overtimeData())->assertForbidden();
        $actor = $this->profileActor();
        DB::connection('pos')->table('users')->where('id', $actor->id)->update(['employee_id' => $this->employee()]);
        $this->profileSession($actor)->postJson('/account/overtime', $this->overtimeData())->assertForbidden();
        $actor = $this->profileActor();
        config(['database.connections.hrms.host' => 'synthetic-unsupported.invalid']);
        $this->profileSession($actor)->postJson('/account/overtime', $this->overtimeData())->assertUnprocessable()->assertJsonValidationErrors('overtime');
        config(['database.connections.hrms.host' => config('database.connections.pos.host')]);
        DB::connection('hrms')->table('employees')->where('employee_id', $actor->employee_id)->update(['status' => 'terminated']);
        $this->profileSession($actor)->postJson('/account/overtime', $this->overtimeData())->assertUnauthorized();
    }
}
