<?php

namespace Tests\Feature;

use App\Models\PosUser;
use App\Services\OwnLeaveService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class OwnLeaveTest extends OwnLeaveTestCase
{
    public function test_mfa_and_explicit_employee_ownership_hide_other_personnel_and_keep_get_read_only(): void
    {
        $this->get('/account/leave')->assertRedirect(route('login'));
        $actor = $this->profileActor(extra: ['id' => 600001]);
        $other = $this->employee(['employee_id' => $actor->id]);
        $this->existingLeave($other, ['reason' => 'SYN-OTHER-PRIVATE-LEAVE']);
        $this->actingAs($actor, 'pos')->get('/account/leave')->assertRedirect(route('login'));
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->profileSession($actor)->get('/account/leave?employee_id='.$other)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('SYN-OTHER-PRIVATE-LEAVE')->assertDontSee('SYN-HIDDEN-BANK')->assertDontSee('SYN-HIDDEN-SSS')->assertSee('Default allocation');
        foreach ($queries as $sql) {
            foreach (['basic_salary', 'bank_account_number', 'sss_number', 'approved_by', 'performed_by', '`email`'] as $field) {
                $this->assertStringNotContainsString($field, $sql);
            }
            $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|create|alter)\b/i', $sql);
        }
        $this->assertSame(0, DB::connection('hrms')->table('leave_credits')->where('employee_id', $actor->employee_id)->count());
    }

    public function test_owned_submission_is_pending_with_minimal_atomic_audit_and_no_other_effects(): void
    {
        Http::preventStrayRequests();
        $actor = $this->profileActor();
        $other = $this->employee();
        $response = $this->profileSession($actor)->postJson('/account/leave', $this->leaveData(['reason' => 'Synthetic private reason']))->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('replayed', false);
        $id = $response->json('request_id');
        $this->assertDatabaseHas('leave_requests', ['leave_id' => $id, 'employee_id' => $actor->employee_id, 'reason' => 'Synthetic private reason', 'approved_by' => null, 'decided_at' => null], 'hrms');
        $audit = DB::connection('pos')->table('application_audit_events')->where('entity', 'leave_requests:hrms')->where('entity_id', $id)->first();
        $this->assertSame('requested', $audit->action);
        $this->assertSame('pos', $audit->actor_domain);
        $this->assertSame((int) $actor->id, (int) $audit->actor_id);
        $this->assertStringNotContainsString('Synthetic private reason', $audit->changed_fields);
        $this->assertEqualsCanonicalizing(['leave_type', 'date_from', 'date_to', 'reason', 'status'], json_decode($audit->changed_fields, true));
        $this->assertSame(0, DB::connection('hrms')->table('leave_requests')->where('employee_id', $other)->count());
        $this->assertSame(0, DB::connection('hrms')->table('leave_credits')->where('employee_id', $actor->employee_id)->count());
        $this->assertSame(0, DB::connection('hrms')->table('leave_request_logs')->where('leave_id', $id)->count());
        Http::assertNothingSent();
    }

    public function test_all_native_roles_can_request_only_for_their_actual_link(): void
    {
        foreach (['pos' => ['admin', 'cashier', 'inventory_staff', 'finance'], 'hrms' => ['admin', 'hr', 'hr_manager', 'supervisor', 'accountant', 'employee']] as $domain => $roles) {
            foreach ($roles as $role) {
                $actor = $this->profileActor($domain, $role);
                $response = $this->profileSession($actor)->postJson('/account/leave', $this->leaveData())->assertCreated();
                $this->assertDatabaseHas('leave_requests', ['leave_id' => $response->json('request_id'), 'employee_id' => $actor->employee_id, 'status' => 'pending'], 'hrms');
                $this->assertDatabaseHas('application_audit_events', ['entity' => 'leave_requests:hrms', 'entity_id' => $response->json('request_id'), 'actor_id' => $actor->id, 'actor_domain' => $domain], 'pos');
            }
        }
    }

    public function test_identical_pending_retry_returns_same_id_without_duplicate_request_or_audit(): void
    {
        $actor = $this->profileActor();
        $data = $this->leaveData(['leave_type' => 'vacation']);
        $id = $this->profileSession($actor)->postJson('/account/leave', $data)->assertCreated()->json('request_id');
        $this->postJson('/account/leave', $data)->assertOk()->assertJsonPath('request_id', $id)->assertJsonPath('replayed', true);
        $this->assertSame(1, DB::connection('hrms')->table('leave_requests')->where('employee_id', $actor->employee_id)->count());
        $this->assertSame(1, DB::connection('pos')->table('application_audit_events')->where('entity', 'leave_requests:hrms')->where('entity_id', $id)->count());
    }

    public function test_caller_cannot_choose_employee_status_approval_dates_or_balances(): void
    {
        $actor = $this->profileActor();
        $this->profileSession($actor);
        foreach (['employee_id' => 1, 'user_id' => 1, 'id' => 1, 'leave_id' => 1, 'status' => 'approved', 'approved_by' => 1, 'decided_at' => '2026-01-01', 'requested_at' => '2026-01-01', 'allocated_days' => 999, 'used_days' => 0] as $field => $value) {
            $this->postJson('/account/leave', $this->leaveData([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame(0, DB::connection('hrms')->table('leave_requests')->where('employee_id', $actor->employee_id)->count());
    }

    public function test_real_calendar_future_order_and_reason_limits_are_validated_before_leave_writes(): void
    {
        $actor = $this->profileActor();
        $this->profileSession($actor);
        foreach ([['date_from' => '2026-02-30'], ['date_from' => CarbonImmutable::today('Asia/Manila')->subDay()->toDateString()], ['date_to' => CarbonImmutable::today('Asia/Manila')->toDateString()], ['date_from' => '10000-01-01'], ['date_to' => ['synthetic']], ['reason' => '  '], ['reason' => str_repeat('x', 2001)], ['leave_type' => 'admin']] as $extra) {
            $this->postJson('/account/leave', $this->leaveData($extra))->assertUnprocessable()->assertJsonValidationErrors(array_key_first($extra));
        }
        $this->assertSame(0, DB::connection('hrms')->table('leave_requests')->where('employee_id', $actor->employee_id)->count());
    }

    public function test_vacation_inclusive_days_use_fractional_remaining_balance_without_deducting_pending(): void
    {
        $actor = $this->profileActor();
        $from = CarbonImmutable::today('Asia/Manila')->addDays(10);
        DB::connection('hrms')->table('leave_credits')->insert(['employee_id' => $actor->employee_id, 'leave_type' => 'vacation', 'year' => $from->year, 'allocated_days' => '15.00', 'used_days' => '13.50']);
        $this->profileSession($actor)->postJson('/account/leave', $this->leaveData(['leave_type' => 'vacation', 'date_to' => $from->addDay()->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->postJson('/account/leave', $this->leaveData(['leave_type' => 'vacation']))->assertCreated();
        $this->assertDatabaseHas('leave_credits', ['employee_id' => $actor->employee_id, 'leave_type' => 'vacation', 'used_days' => '13.50'], 'hrms');
        $this->get('/account/leave?year='.$from->year)->assertOk()->assertSee('1.50')->assertSee('Pending requests have not been deducted');
    }

    public function test_default_vacation_credit_is_created_only_with_successful_request(): void
    {
        $actor = $this->profileActor();
        $from = CarbonImmutable::today('Asia/Manila')->addDays(10);
        $this->profileSession($actor)->postJson('/account/leave', $this->leaveData(['leave_type' => 'vacation', 'date_to' => $from->addDays(15)->toDateString()]))->assertUnprocessable();
        $this->assertSame(0, DB::connection('hrms')->table('leave_credits')->where('employee_id', $actor->employee_id)->count());
        $this->postJson('/account/leave', $this->leaveData(['leave_type' => 'vacation', 'date_to' => $from->addDays(14)->toDateString()]))->assertCreated();
        $this->assertDatabaseHas('leave_credits', ['employee_id' => $actor->employee_id, 'leave_type' => 'vacation', 'year' => $from->year, 'allocated_days' => '15.00', 'used_days' => '0.00'], 'hrms');
    }

    public function test_sick_emergency_and_other_requests_are_not_capped_by_reported_balances(): void
    {
        $actor = $this->profileActor();
        $this->profileSession($actor);
        $to = CarbonImmutable::today('Asia/Manila')->addDays(40)->toDateString();
        foreach (['sick', 'emergency', 'others'] as $type) {
            $this->postJson('/account/leave', $this->leaveData(['leave_type' => $type, 'date_to' => $to]))->assertCreated();
        }
        $this->assertSame(3, DB::connection('hrms')->table('leave_requests')->where('employee_id', $actor->employee_id)->count());
    }

    public function test_vacation_overlap_and_adjacency_are_denied_but_one_clear_calendar_day_is_allowed(): void
    {
        $actor = $this->profileActor();
        $from = CarbonImmutable::today('Asia/Manila')->addDays(10);
        $this->existingLeave($actor->employee_id, ['leave_type' => 'vacation', 'date_from' => $from->toDateString(), 'date_to' => $from->addDay()->toDateString(), 'status' => 'approved', 'requested_at' => now('Asia/Manila')->subDays(35)]);
        $this->profileSession($actor);
        foreach ([[0, 0], [1, 2], [2, 2], [-1, -1]] as [$start, $end]) {
            $this->postJson('/account/leave', $this->leaveData(['leave_type' => 'vacation', 'date_from' => $from->addDays($start)->toDateString(), 'date_to' => $from->addDays($end)->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors('date_from');
        }
        $this->postJson('/account/leave', $this->leaveData(['leave_type' => 'vacation', 'date_from' => $from->addDays(3)->toDateString(), 'date_to' => $from->addDays(3)->toDateString()]))->assertCreated();
    }

    public function test_vacation_filing_30_day_boundary_applies_to_hr_as_well_as_pos_and_ignores_rejection(): void
    {
        foreach (['pos', 'hrms'] as $domain) {
            $actor = $this->profileActor($domain, $domain === 'pos' ? 'cashier' : 'hr');
            $from = CarbonImmutable::today('Asia/Manila')->addDays(100);
            $id = $this->existingLeave($actor->employee_id, ['leave_type' => 'vacation', 'date_from' => $from->toDateString(), 'date_to' => $from->toDateString(), 'requested_at' => now('Asia/Manila')->subDays(29)]);
            $this->profileSession($actor)->postJson('/account/leave', $this->leaveData(['leave_type' => 'vacation']))->assertUnprocessable()->assertJsonValidationErrors('leave_type');
            DB::connection('hrms')->table('leave_requests')->where('leave_id', $id)->update(['requested_at' => now('Asia/Manila')->subDays(30)]);
            $this->postJson('/account/leave', $this->leaveData(['leave_type' => 'vacation']))->assertCreated();
            $other = $this->profileActor($domain, $domain === 'pos' ? 'cashier' : 'hr');
            $this->existingLeave($other->employee_id, ['leave_type' => 'vacation', 'status' => 'rejected']);
            $this->profileSession($other)->postJson('/account/leave', $this->leaveData(['leave_type' => 'vacation']))->assertCreated();
        }
    }

    public function test_audit_failure_rolls_back_request_and_lazy_credit(): void
    {
        $actor = $this->profileActor();
        $injected = false;
        DB::listen(function ($query) use (&$injected) {
            if (! $injected && str_starts_with($query->sql, 'insert into `application_audit_events`')) {
                $injected = true;
                throw new \RuntimeException('Synthetic leave audit failure');
            }
        });
        try {
            app(OwnLeaveService::class)->submit($actor, 'pos', $this->leaveData(['leave_type' => 'vacation']));
            $this->fail('Expected synthetic audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic leave audit failure', $exception->getMessage());
        }
        $this->assertTrue($injected);
        $this->assertSame(0, DB::connection('hrms')->table('leave_requests')->where('employee_id', $actor->employee_id)->count());
        $this->assertSame(0, DB::connection('hrms')->table('leave_credits')->where('employee_id', $actor->employee_id)->count());
    }

    public function test_html_errors_do_not_flash_private_reason_and_success_redirects_to_start_year(): void
    {
        $actor = $this->profileActor();
        $this->profileSession($actor)->post('/account/leave', $this->leaveData(['leave_type' => 'invalid', 'reason' => 'SYN-PRIVATE-ERROR-REASON']))->assertRedirect(route('leave.index'))->assertSessionHasErrors('leave_type')->assertSessionMissing('_old_input');
        $this->get('/account/leave?year=invalid&reason=SYN-PRIVATE-ERROR-REASON')->assertRedirect(route('leave.index'))->assertSessionMissing('_old_input');
        $from = CarbonImmutable::today('Asia/Manila')->addYear()->startOfYear();
        $this->post('/account/leave', $this->leaveData(['date_from' => $from->toDateString(), 'date_to' => $from->toDateString()]))->assertRedirect(route('leave.index', ['year' => $from->year]));
    }

    public function test_history_filters_paginate_owned_rows_and_escape_reasons(): void
    {
        $actor = $this->profileActor();
        for ($i = 0; $i < 21; $i++) {
            $this->existingLeave($actor->employee_id, ['reason' => '<script>syntheticLeave()</script>', 'status' => 'approved']);
        }
        $this->existingLeave($actor->employee_id, ['reason' => 'SYN-REJECTED-HIDDEN', 'status' => 'rejected']);
        $this->existingLeave($actor->employee_id, ['reason' => 'SYN-PAST-YEAR-HIDDEN', 'date_from' => '2001-01-01', 'date_to' => '2001-01-01', 'status' => 'approved']);
        $this->profileSession($actor)->get('/account/leave?status=approved')->assertOk()->assertSee('<script>syntheticLeave()</script>')->assertDontSee('<script>syntheticLeave()</script>', false)->assertDontSee('SYN-REJECTED-HIDDEN')->assertDontSee('SYN-PAST-YEAR-HIDDEN')->assertSee('page=2', false);
        $this->get('/account/leave?status=approved&page=2')->assertOk()->assertSee('syntheticLeave()');
        foreach (['year=99999', 'status=private', 'page=0'] as $query) {
            $this->getJson('/account/leave?'.$query)->assertUnprocessable();
        }
    }

    public function test_unlinked_ineligible_changed_link_and_unsupported_topology_cannot_submit(): void
    {
        $unlinked = PosUser::where('username', 'demo_cashier')->firstOrFail();
        $this->profileSession($unlinked)->get('/account/leave')->assertOk()->assertSee('Your employee record needs to be linked.');
        $this->postJson('/account/leave', $this->leaveData())->assertForbidden();
        $actor = $this->profileActor();
        $other = $this->employee();
        DB::connection('pos')->table('users')->where('id', $actor->id)->update(['employee_id' => $other]);
        $this->profileSession($actor)->postJson('/account/leave', $this->leaveData())->assertForbidden();
        $actor = $this->profileActor();
        config(['database.connections.hrms.host' => 'synthetic-unsupported.invalid']);
        $this->profileSession($actor)->postJson('/account/leave', $this->leaveData())->assertUnprocessable()->assertJsonValidationErrors('leave');
        config(['database.connections.hrms.host' => config('database.connections.pos.host')]);
        DB::connection('hrms')->table('employees')->where('employee_id', $actor->employee_id)->update(['status' => 'terminated']);
        $this->profileSession($actor)->postJson('/account/leave', $this->leaveData())->assertUnauthorized();
    }
}
