<?php

namespace Tests\Feature;

use App\Services\OvertimeApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class OvertimeApprovalTest extends OvertimeApprovalTestCase
{
    public function test_only_actual_hr_admin_and_supervisor_roles_have_review_access(): void
    {
        $this->get('/overtime-approvals')->assertRedirect(route('login'));
        foreach (['pos' => ['admin', 'cashier', 'inventory_staff', 'finance'], 'hrms' => ['hr', 'hr_manager', 'employee', 'accountant']] as $domain => $roles) {
            foreach ($roles as $role) {
                $this->profileSession($this->profileActor($domain, $role))->get('/overtime-approvals')->assertForbidden();
            }
        }
        foreach (['admin', 'supervisor'] as $role) {
            $this->profileSession($this->profileActor('hrms', $role))->get('/overtime-approvals')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_supervisor_scope_and_admin_all_assigned_teams_do_not_include_unassigned_or_other_team_reasons(): void
    {
        $supervisor = $this->profileActor('hrms', 'supervisor');
        $otherSupervisor = $this->profileActor('hrms', 'supervisor');
        $admin = $this->profileActor('hrms', 'admin');
        $own = $this->profileActor();
        $other = $this->profileActor();
        $unassigned = $this->profileActor();
        $this->assignOvertimeTeam($supervisor->employee_id, $own->employee_id);
        $this->assignOvertimeTeam($otherSupervisor->employee_id, $other->employee_id);
        $ids = ['own' => $this->existingOvertime($own->employee_id, ['reason' => 'SYN-OT-OWN-REASON']), 'other' => $this->existingOvertime($other->employee_id, ['reason' => 'SYN-OT-OTHER-REASON']), 'unassigned' => $this->existingOvertime($unassigned->employee_id, ['reason' => 'SYN-OT-UNASSIGNED-REASON'])];
        $this->profileSession($supervisor)->get('/overtime-approvals?supervisor_id='.$otherSupervisor->employee_id.'&scope=all')->assertOk()->assertSee('SYN-OT-OWN-REASON')->assertDontSee('SYN-OT-OTHER-REASON')->assertDontSee('SYN-OT-UNASSIGNED-REASON');
        foreach (['other', 'unassigned'] as $key) {
            $this->postJson('/overtime-approvals/'.$ids[$key].'/decision', ['decision' => 'approved', 'revision' => str_repeat('a', 64)])->assertNotFound();
        }
        $this->profileSession($admin)->get('/overtime-approvals')->assertOk()->assertSee('SYN-OT-OWN-REASON')->assertSee('SYN-OT-OTHER-REASON')->assertDontSee('SYN-OT-UNASSIGNED-REASON');
        $this->postJson('/overtime-approvals/'.$ids['unassigned'].'/decision', ['decision' => 'approved', 'revision' => str_repeat('a', 64)])->assertNotFound();
    }

    public function test_recorded_work_is_distinct_private_and_batched_and_approval_does_not_write_time_or_payroll(): void
    {
        Http::preventStrayRequests();
        $supervisor = $this->profileActor('hrms', 'supervisor');
        $target = $this->profileActor();
        $this->assignOvertimeTeam($supervisor->employee_id, $target->employee_id);
        $this->overtimePunch($target->employee_id);
        $this->overtimeShift($target->employee_id);
        $id = $this->existingOvertime($target->employee_id, ['hours_requested' => '5.00']);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->profileSession($supervisor)->get('/overtime-approvals')->assertOk()->assertSee('5.00 hours requested')->assertSee('2.00 hours')->assertDontSee('SYN-OT-REVIEW-PRIVATE-GPS')->assertDontSee('14.1234567')->assertDontSee('SYN-HIDDEN-BANK')->assertDontSee('SYN-HIDDEN-SSS');
        foreach ($queries as $sql) {
            foreach (['latitude', 'longitude', 'address', 'accuracy', 'basic_salary', 'bank_account', 'sss_number', 'payroll'] as $field) {
                $this->assertStringNotContainsString($field, $sql);
            }
        }
        $this->assertCount(1, array_filter($queries, fn ($sql) => str_contains($sql, 'from `attendance`')));
        $this->postJson('/overtime-approvals/'.$id.'/decision', $this->overtimeDecisionData($supervisor, $id))->assertOk();
        $this->assertDatabaseHas('overtime_requests', ['ot_id' => $id, 'status' => 'approved', 'hours_requested' => '5.00', 'approved_by' => $supervisor->id], 'hrms');
        $this->assertDatabaseHas('attendance', ['employee_id' => $target->employee_id, 'time_in' => '07:00:00', 'time_out' => '18:00:00'], 'hrms');
        $this->assertSame(0, DB::connection('hrms')->table('payroll_records')->where('employee_id', $target->employee_id)->count());
        Http::assertNothingSent();
    }

    public function test_actual_hr_actor_id_is_used_and_missing_punches_do_not_invent_actual_hours(): void
    {
        $admin = $this->profileActor('hrms', 'admin', extra: ['id' => 1000001]);
        $pos = $this->profileActor('pos', 'admin', extra: ['id' => 1000002]);
        $supervisor = $this->profileActor('hrms', 'supervisor');
        $target = $this->profileActor();
        $this->assignOvertimeTeam($supervisor->employee_id, $target->employee_id);
        $id = $this->existingOvertime($target->employee_id);
        $this->profileSession($admin)->get('/overtime-approvals')->assertOk()->assertSee('No complete attendance punch');
        $this->postJson('/overtime-approvals/'.$id.'/decision', $this->overtimeDecisionData($admin, $id))->assertOk();
        $this->assertNotSame((int) $admin->id, (int) $pos->id);
        $this->assertDatabaseHas('application_audit_events', ['entity' => 'overtime_requests:hrms', 'entity_id' => $id, 'action' => 'approved', 'actor_domain' => 'hrms', 'actor_id' => $admin->id], 'pos');
        $this->assertSame(0, DB::connection('hrms')->table('attendance')->where('employee_id', $target->employee_id)->count());
    }

    public function test_changed_reason_and_request_hours_make_review_stale(): void
    {
        $supervisor = $this->profileActor('hrms', 'supervisor');
        $target = $this->profileActor();
        $this->assignOvertimeTeam($supervisor->employee_id, $target->employee_id);
        foreach (['reason' => 'Synthetic changed reason', 'hours_requested' => '3.00'] as $field => $value) {
            $id = $this->existingOvertime($target->employee_id);
            $data = $this->overtimeDecisionData($supervisor, $id);
            DB::connection('hrms')->table('overtime_requests')->where('ot_id', $id)->update([$field => $value]);
            $this->profileSession($supervisor)->postJson('/overtime-approvals/'.$id.'/decision', $data)->assertUnprocessable()->assertJsonValidationErrors('revision');
        }
    }

    public function test_one_second_attendance_change_invalidates_review_even_when_displayed_excess_is_unchanged(): void
    {
        $supervisor = $this->profileActor('hrms', 'supervisor');
        $target = $this->profileActor();
        $this->assignOvertimeTeam($supervisor->employee_id, $target->employee_id);
        $this->overtimePunch($target->employee_id);
        $id = $this->existingOvertime($target->employee_id);
        $data = $this->overtimeDecisionData($supervisor, $id);
        $this->overtimePunch($target->employee_id, ['time_out' => '18:00:01']);
        $this->profileSession($supervisor)->postJson('/overtime-approvals/'.$id.'/decision', $data)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->postJson('/overtime-approvals/'.$id.'/decision', $this->overtimeDecisionData($supervisor, $id))->assertOk();
    }

    public function test_current_shift_and_schedule_type_changes_require_fresh_review(): void
    {
        $supervisor = $this->profileActor('hrms', 'supervisor');
        $target = $this->profileActor();
        $this->assignOvertimeTeam($supervisor->employee_id, $target->employee_id);
        $shift = $this->overtimeShift($target->employee_id);
        $id = $this->existingOvertime($target->employee_id);
        $data = $this->overtimeDecisionData($supervisor, $id);
        DB::connection('hrms')->table('shifts')->where('shift_id', $shift)->update(['end_time' => '17:00:00']);
        $this->profileSession($supervisor)->postJson('/overtime-approvals/'.$id.'/decision', $data)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $data = $this->overtimeDecisionData($supervisor, $id);
        DB::connection('hrms')->table('employees')->where('employee_id', $target->employee_id)->update(['schedule_type' => 'part_time']);
        $this->postJson('/overtime-approvals/'.$id.'/decision', $data)->assertUnprocessable()->assertJsonValidationErrors('revision');
    }

    public function test_team_reassignment_and_supervisor_self_membership_do_not_allow_decisions(): void
    {
        $supervisor = $this->profileActor('hrms', 'supervisor');
        $other = $this->profileActor('hrms', 'supervisor');
        $target = $this->profileActor();
        $this->assignOvertimeTeam($supervisor->employee_id, $target->employee_id);
        $id = $this->existingOvertime($target->employee_id);
        $data = $this->overtimeDecisionData($supervisor, $id);
        $this->assignOvertimeTeam($other->employee_id, $target->employee_id);
        $this->profileSession($supervisor)->postJson('/overtime-approvals/'.$id.'/decision', $data)->assertNotFound();
        $this->assignOvertimeTeam($supervisor->employee_id, $supervisor->employee_id);
        $own = $this->existingOvertime($supervisor->employee_id);
        $this->postJson('/overtime-approvals/'.$own.'/decision', ['decision' => 'approved', 'revision' => str_repeat('a', 64)])->assertNotFound();
    }

    public function test_rejection_and_terminal_replay_do_not_duplicate_audit_or_change_requested_hours(): void
    {
        $supervisor = $this->profileActor('hrms', 'supervisor');
        $target = $this->profileActor();
        $this->assignOvertimeTeam($supervisor->employee_id, $target->employee_id);
        $id = $this->existingOvertime($target->employee_id);
        $data = $this->overtimeDecisionData($supervisor, $id, ['decision' => 'rejected']);
        $this->profileSession($supervisor)->postJson('/overtime-approvals/'.$id.'/decision', $data)->assertOk();
        $this->postJson('/overtime-approvals/'.$id.'/decision', $data)->assertUnprocessable();
        $this->assertDatabaseHas('overtime_requests', ['ot_id' => $id, 'status' => 'rejected', 'hours_requested' => '2.00'], 'hrms');
        $this->assertSame(1, DB::connection('pos')->table('application_audit_events')->where('entity', 'overtime_requests:hrms')->where('entity_id', $id)->count());
    }

    public function test_audit_failure_rolls_back_status_actor_and_decision_time(): void
    {
        $supervisor = $this->profileActor('hrms', 'supervisor');
        $target = $this->profileActor();
        $this->assignOvertimeTeam($supervisor->employee_id, $target->employee_id);
        $id = $this->existingOvertime($target->employee_id);
        $data = $this->overtimeDecisionData($supervisor, $id);
        $injected = false;
        DB::listen(function ($query) use (&$injected) {
            if (! $injected && str_starts_with($query->sql, 'insert into `application_audit_events`')) {
                $injected = true;
                throw new \RuntimeException('Synthetic overtime decision audit failure');
            }
        });
        try {
            app(OvertimeApprovalService::class)->decide($supervisor, 'hrms', $id, $data);
            $this->fail('Expected synthetic audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic overtime decision audit failure', $exception->getMessage());
        }
        $this->assertTrue($injected);
        $this->assertDatabaseHas('overtime_requests', ['ot_id' => $id, 'status' => 'pending', 'approved_by' => null, 'decided_at' => null], 'hrms');
    }

    public function test_ineligible_future_or_invalid_legacy_hours_cannot_be_approved_but_can_be_rejected(): void
    {
        $supervisor = $this->profileActor('hrms', 'supervisor');
        $target = $this->profileActor();
        $this->assignOvertimeTeam($supervisor->employee_id, $target->employee_id);
        $this->profileSession($supervisor);
        foreach ([['hours_requested' => '0.00'], ['hours_requested' => '13.00'], ['ot_date' => now('Asia/Manila')->addDay()->toDateString()]] as $extra) {
            $id = $this->existingOvertime($target->employee_id, $extra);
            $this->postJson('/overtime-approvals/'.$id.'/decision', $this->overtimeDecisionData($supervisor, $id))->assertUnprocessable();
            $this->postJson('/overtime-approvals/'.$id.'/decision', $this->overtimeDecisionData($supervisor, $id, ['decision' => 'rejected']))->assertOk();
        }
        $id = $this->existingOvertime($target->employee_id);
        DB::connection('hrms')->table('employees')->where('employee_id', $target->employee_id)->update(['status' => 'terminated']);
        $this->postJson('/overtime-approvals/'.$id.'/decision', $this->overtimeDecisionData($supervisor, $id))->assertUnprocessable();
        $this->postJson('/overtime-approvals/'.$id.'/decision', $this->overtimeDecisionData($supervisor, $id, ['decision' => 'rejected']))->assertOk();
    }

    public function test_cross_request_reviewer_and_tampered_values_fail_without_flashing_private_input(): void
    {
        $supervisor = $this->profileActor('hrms', 'supervisor');
        $admin = $this->profileActor('hrms', 'admin');
        $target = $this->profileActor();
        $this->assignOvertimeTeam($supervisor->employee_id, $target->employee_id);
        $id = $this->existingOvertime($target->employee_id);
        $otherId = $this->existingOvertime($target->employee_id);
        $data = $this->overtimeDecisionData($supervisor, $id);
        $this->profileSession($supervisor)->postJson('/overtime-approvals/'.$otherId.'/decision', $data)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->profileSession($admin)->postJson('/overtime-approvals/'.$id.'/decision', $data)->assertUnprocessable()->assertJsonValidationErrors('revision');
        foreach (['employee_id' => 1, 'approved_by' => 1, 'status' => 'approved', 'ot_date' => '2026-01-01', 'hours_requested' => 12, 'reason' => 'SYN-OT-PRIVATE-TAMPER', 'actual_ot_hours' => 12, 'worked_hours' => 12] as $field => $value) {
            $this->postJson('/overtime-approvals/'.$id.'/decision', $this->overtimeDecisionData($admin, $id, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->post('/overtime-approvals/'.$id.'/decision', $this->overtimeDecisionData($admin, $id, ['reason' => 'SYN-OT-PRIVATE-TAMPER']))->assertRedirect(route('overtime-approvals.index'))->assertSessionMissing('_old_input');
    }

    public function test_history_escapes_reasons_filters_and_paginates_only_current_team(): void
    {
        $supervisor = $this->profileActor('hrms', 'supervisor');
        $target = $this->profileActor();
        $this->assignOvertimeTeam($supervisor->employee_id, $target->employee_id);
        for ($i = 0; $i < 21; $i++) {
            $this->existingOvertime($target->employee_id, ['reason' => '<script>syntheticOtReview()</script>']);
        }
        $this->profileSession($supervisor)->get('/overtime-approvals')->assertOk()->assertSee('page=2', false)->assertSee('<script>syntheticOtReview()</script>')->assertDontSee('<script>syntheticOtReview()</script>', false);
        $this->get('/overtime-approvals?status=approved')->assertOk()->assertSee('No overtime requests in this view.');
        $this->get('/overtime-approvals?page=2')->assertOk()->assertSee('syntheticOtReview()');
        foreach (['status=private', 'page=0'] as $query) {
            $this->getJson('/overtime-approvals?'.$query)->assertUnprocessable();
        }
    }
}
