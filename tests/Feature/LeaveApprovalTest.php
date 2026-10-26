<?php

namespace Tests\Feature;

use App\Services\LeaveApprovalService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class LeaveApprovalTest extends LeaveApprovalTestCase
{
    public function test_full_mfa_and_actual_hr_reviewer_domain_are_required_even_for_pos_admin(): void
    {
        $this->get('/leave-approvals')->assertRedirect(route('login'));
        foreach (['admin', 'cashier', 'inventory_staff', 'finance'] as $role) {
            $this->profileSession($this->profileActor('pos', $role))->get('/leave-approvals')->assertForbidden();
        }
        foreach (['employee', 'accountant'] as $role) {
            $this->profileSession($this->profileActor('hrms', $role))->get('/leave-approvals')->assertForbidden();
        }
        $actor = $this->profileActor('hrms', 'hr');
        $this->profileSession($actor)->get('/leave-approvals')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_queue_scope_matches_role_hierarchy_and_never_lists_out_of_scope_private_reasons(): void
    {
        $reviewers = [];
        foreach (['admin', 'hr', 'hr_manager', 'supervisor'] as $role) {
            $reviewers[$role] = $this->profileActor('hrms', $role);
        }
        $ids = [];
        foreach (['none', 'employee', 'accountant', 'supervisor', 'hr', 'hr_manager', 'admin', 'assigned_employee', 'assigned_hr'] as $type) {
            $employee = $this->employee();
            $role = str_replace('assigned_', '', $type);
            if ($role !== 'none') {
                $this->profileActor('hrms', $role, $employee);
            }
            if (str_starts_with($type, 'assigned_')) {
                $this->assignTeam($reviewers['supervisor']->employee_id, $employee);
            }
            $ids[$type] = $this->existingLeave($employee, ['reason' => 'SYN-PRIVATE-SCOPE-'.$type]);
        }
        $allowed = ['admin' => array_keys($ids), 'hr' => ['none', 'employee', 'supervisor'], 'hr_manager' => ['hr', 'assigned_hr'], 'supervisor' => ['assigned_employee', 'assigned_hr']];
        foreach ($reviewers as $role => $actor) {
            $response = $this->profileSession($actor)->get('/leave-approvals');
            $response->assertOk();
            foreach ($ids as $type => $id) {
                if (in_array($type, $allowed[$role], true)) {
                    $response->assertSee('SYN-PRIVATE-SCOPE-'.$type);
                } else {
                    $response->assertDontSee('SYN-PRIVATE-SCOPE-'.$type);
                    $this->postJson('/leave-approvals/'.$id.'/decision', $this->decisionData($actor, $id))->assertNotFound();
                }
            }
            $response->assertDontSee('SYN-HIDDEN-BANK')->assertDontSee('SYN-HIDDEN-SSS');
        }
    }

    public function test_supervisor_cannot_override_team_query_or_approve_self_and_reassignment_revokes_access(): void
    {
        $supervisor = $this->profileActor('hrms', 'supervisor');
        $other = $this->profileActor('hrms', 'supervisor');
        $member = $this->profileActor();
        $this->assignTeam($supervisor->employee_id, $member->employee_id);
        $id = $this->existingLeave($member->employee_id, ['reason' => 'SYN-MOVED-TEAM-REASON']);
        $data = $this->decisionData($supervisor, $id);
        $this->profileSession($supervisor)->get('/leave-approvals')->assertOk()->assertSee('SYN-MOVED-TEAM-REASON');
        $this->assignTeam($other->employee_id, $member->employee_id);
        $this->get('/leave-approvals?supervisor_id='.$other->employee_id.'&scope=all')->assertOk()->assertDontSee('SYN-MOVED-TEAM-REASON');
        $this->postJson('/leave-approvals/'.$id.'/decision', $data)->assertNotFound();
        $this->assignTeam($supervisor->employee_id, $supervisor->employee_id);
        $own = $this->existingLeave($supervisor->employee_id);
        $this->postJson('/leave-approvals/'.$own.'/decision', $this->decisionData($supervisor, $own))->assertNotFound();
    }

    public function test_duplicate_requester_accounts_use_the_highest_role_instead_of_an_arbitrary_join_row(): void
    {
        $reviewer = $this->profileActor('hrms', 'hr_manager');
        $target = $this->profileActor('hrms', 'hr');
        $id = $this->existingLeave($target->employee_id, ['reason' => 'SYN-HIGHER-ROLE-PRIVATE']);
        $data = $this->decisionData($reviewer, $id);
        $this->profileSession($reviewer)->get('/leave-approvals')->assertSee('SYN-HIGHER-ROLE-PRIVATE');
        $this->profileActor('hrms', 'hr_manager', $target->employee_id);
        $this->get('/leave-approvals')->assertOk()->assertDontSee('SYN-HIGHER-ROLE-PRIVATE');
        $this->postJson('/leave-approvals/'.$id.'/decision', $data)->assertNotFound();
    }

    public function test_approval_uses_actual_hr_actor_id_and_atomically_deducts_inclusive_credit_once(): void
    {
        Http::preventStrayRequests();
        $reviewer = $this->profileActor('hrms', 'admin', extra: ['id' => 700001]);
        $pos = $this->profileActor('pos', 'admin', extra: ['id' => 700002]);
        $target = $this->profileActor();
        $from = CarbonImmutable::today('Asia/Manila')->addDays(10);
        $id = $this->existingLeave($target->employee_id, ['leave_type' => 'vacation', 'date_to' => $from->addDay()->toDateString()]);
        $data = $this->decisionData($reviewer, $id);
        $this->profileSession($reviewer)->postJson('/leave-approvals/'.$id.'/decision', $data)->assertOk();
        $this->assertDatabaseHas('leave_requests', ['leave_id' => $id, 'status' => 'approved', 'approved_by' => $reviewer->id], 'hrms');
        $this->assertDatabaseHas('leave_request_logs', ['leave_id' => $id, 'action' => 'approved', 'performed_by' => $reviewer->id], 'hrms');
        $this->assertDatabaseHas('leave_credits', ['employee_id' => $target->employee_id, 'leave_type' => 'vacation', 'year' => $from->year, 'allocated_days' => '15.00', 'used_days' => '2.00'], 'hrms');
        $this->assertDatabaseHas('application_audit_events', ['entity' => 'leave_requests:hrms', 'entity_id' => $id, 'actor_domain' => 'hrms', 'actor_id' => $reviewer->id, 'action' => 'approved'], 'pos');
        $this->assertNotSame((int) $pos->id, (int) $reviewer->id);
        $this->postJson('/leave-approvals/'.$id.'/decision', $data)->assertUnprocessable();
        $this->assertSame(1, DB::connection('hrms')->table('leave_request_logs')->where('leave_id', $id)->count());
        Http::assertNothingSent();
    }

    public function test_hr_hr_manager_and_supervisor_can_decide_their_allowed_requests(): void
    {
        foreach (['hr' => 'supervisor', 'hr_manager' => 'hr', 'supervisor' => 'employee'] as $reviewerRole => $targetRole) {
            $reviewer = $this->profileActor('hrms', $reviewerRole);
            $target = $this->profileActor('hrms', $targetRole);
            if ($reviewerRole === 'supervisor') {
                $this->assignTeam($reviewer->employee_id, $target->employee_id);
            }
            $id = $this->existingLeave($target->employee_id);
            $this->profileSession($reviewer)->postJson('/leave-approvals/'.$id.'/decision', $this->decisionData($reviewer, $id))->assertOk();
            $this->assertDatabaseHas('leave_request_logs', ['leave_id' => $id, 'performed_by' => $reviewer->id], 'hrms');
        }
    }

    public function test_rejection_does_not_create_or_deduct_credit_and_cannot_be_reversed(): void
    {
        $reviewer = $this->profileActor('hrms', 'admin');
        $target = $this->profileActor();
        $id = $this->existingLeave($target->employee_id);
        $this->profileSession($reviewer)->postJson('/leave-approvals/'.$id.'/decision', $this->decisionData($reviewer, $id, ['decision' => 'rejected']))->assertOk();
        $this->assertSame(0, DB::connection('hrms')->table('leave_credits')->where('employee_id', $target->employee_id)->count());
        $this->postJson('/leave-approvals/'.$id.'/decision', $this->decisionData($reviewer, $id))->assertUnprocessable();
        $this->assertDatabaseHas('leave_requests', ['leave_id' => $id, 'status' => 'rejected'], 'hrms');
    }

    public function test_current_vacation_balance_is_rechecked_and_sick_emergency_can_exceed_allocations(): void
    {
        $reviewer = $this->profileActor('hrms', 'admin');
        $target = $this->profileActor();
        $from = CarbonImmutable::today('Asia/Manila')->addDays(10);
        $id = $this->existingLeave($target->employee_id, ['leave_type' => 'vacation', 'date_to' => $from->addDay()->toDateString()]);
        $data = $this->decisionData($reviewer, $id);
        DB::connection('hrms')->table('leave_credits')->insert(['employee_id' => $target->employee_id, 'leave_type' => 'vacation', 'year' => $from->year, 'allocated_days' => '15.00', 'used_days' => '13.50']);
        $this->profileSession($reviewer)->postJson('/leave-approvals/'.$id.'/decision', $data)->assertUnprocessable();
        $this->assertDatabaseHas('leave_requests', ['leave_id' => $id, 'status' => 'pending', 'approved_by' => null], 'hrms');
        foreach (['sick', 'emergency'] as $type) {
            $id = $this->existingLeave($target->employee_id, ['leave_type' => $type, 'date_to' => $from->addDays(19)->toDateString()]);
            $this->postJson('/leave-approvals/'.$id.'/decision', $this->decisionData($reviewer, $id))->assertOk();
            $this->assertDatabaseHas('leave_credits', ['employee_id' => $target->employee_id, 'leave_type' => $type, 'used_days' => '20.00'], 'hrms');
        }
    }

    public function test_other_leave_has_no_credit_and_cross_year_tracked_leave_uses_start_year(): void
    {
        $reviewer = $this->profileActor('hrms', 'admin');
        $target = $this->profileActor();
        $id = $this->existingLeave($target->employee_id, ['leave_type' => 'others']);
        $this->profileSession($reviewer)->postJson('/leave-approvals/'.$id.'/decision', $this->decisionData($reviewer, $id))->assertOk();
        $this->assertSame(0, DB::connection('hrms')->table('leave_credits')->where('employee_id', $target->employee_id)->count());
        $id = $this->existingLeave($target->employee_id, ['date_from' => '2026-12-31', 'date_to' => '2027-01-01']);
        $this->postJson('/leave-approvals/'.$id.'/decision', $this->decisionData($reviewer, $id))->assertOk();
        $this->assertDatabaseHas('leave_credits', ['employee_id' => $target->employee_id, 'leave_type' => 'sick', 'year' => 2026, 'used_days' => '2.00'], 'hrms');
    }

    public function test_changed_reason_cross_request_and_cross_reviewer_revisions_are_rejected(): void
    {
        $reviewer = $this->profileActor('hrms', 'admin');
        $other = $this->profileActor('hrms', 'admin');
        $target = $this->profileActor();
        $id = $this->existingLeave($target->employee_id);
        $data = $this->decisionData($reviewer, $id);
        DB::connection('hrms')->table('leave_requests')->where('leave_id', $id)->update(['reason' => 'Synthetic changed reason']);
        $this->profileSession($reviewer)->postJson('/leave-approvals/'.$id.'/decision', $data)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $second = $this->existingLeave($target->employee_id);
        $this->postJson('/leave-approvals/'.$second.'/decision', $this->decisionData($reviewer, $id))->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->profileSession($other)->postJson('/leave-approvals/'.$id.'/decision', $this->decisionData($reviewer, $id))->assertUnprocessable()->assertJsonValidationErrors('revision');
    }

    public function test_audit_failure_rolls_back_status_credit_and_hr_log(): void
    {
        $reviewer = $this->profileActor('hrms', 'admin');
        $target = $this->profileActor();
        $id = $this->existingLeave($target->employee_id);
        $injected = false;
        DB::listen(function ($query) use (&$injected) {
            if (! $injected && str_starts_with($query->sql, 'insert into `application_audit_events`')) {
                $injected = true;
                throw new \RuntimeException('Synthetic decision audit failure');
            }
        });
        try {
            app(LeaveApprovalService::class)->decide($reviewer, 'hrms', $id, $this->decisionData($reviewer, $id));
            $this->fail('Expected synthetic audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic decision audit failure', $exception->getMessage());
        }
        $this->assertTrue($injected);
        $this->assertDatabaseHas('leave_requests', ['leave_id' => $id, 'status' => 'pending', 'approved_by' => null], 'hrms');
        $this->assertSame(0, DB::connection('hrms')->table('leave_credits')->where('employee_id', $target->employee_id)->count());
        $this->assertSame(0, DB::connection('hrms')->table('leave_request_logs')->where('leave_id', $id)->count());
    }

    public function test_ineligible_employee_and_tracking_overflow_cannot_be_approved_but_can_be_rejected(): void
    {
        $reviewer = $this->profileActor('hrms', 'admin');
        $target = $this->profileActor();
        $id = $this->existingLeave($target->employee_id);
        DB::connection('hrms')->table('employees')->where('employee_id', $target->employee_id)->update(['status' => 'terminated']);
        $this->profileSession($reviewer)->postJson('/leave-approvals/'.$id.'/decision', $this->decisionData($reviewer, $id))->assertUnprocessable();
        $this->postJson('/leave-approvals/'.$id.'/decision', $this->decisionData($reviewer, $id, ['decision' => 'rejected']))->assertOk();
        $other = $this->profileActor();
        $id = $this->existingLeave($other->employee_id);
        $year = (int) substr($this->leaveData()['date_from'], 0, 4);
        DB::connection('hrms')->table('leave_credits')->insert(['employee_id' => $other->employee_id, 'leave_type' => 'sick', 'year' => $year, 'allocated_days' => '15.00', 'used_days' => '999.50']);
        $this->postJson('/leave-approvals/'.$id.'/decision', $this->decisionData($reviewer, $id))->assertUnprocessable();
        $this->assertDatabaseHas('leave_credits', ['employee_id' => $other->employee_id, 'used_days' => '999.50'], 'hrms');
    }

    public function test_tampering_and_html_failures_do_not_flash_private_fields_and_history_is_scoped(): void
    {
        $reviewer = $this->profileActor('hrms', 'admin');
        $target = $this->profileActor();
        $id = $this->existingLeave($target->employee_id, ['reason' => '<script>syntheticApproval()</script>']);
        $this->profileSession($reviewer)->get('/leave-approvals')->assertOk()->assertSee('<script>syntheticApproval()</script>')->assertDontSee('<script>syntheticApproval()</script>', false);
        foreach (['employee_id' => 1, 'approved_by' => 1, 'performed_by' => 1, 'status' => 'approved', 'used_days' => 0, 'allocated_days' => 100, 'date_from' => '2026-01-01', 'date_to' => '2026-01-01', 'reason' => 'SYN-PRIVATE-TAMPER'] as $field => $value) {
            $this->postJson('/leave-approvals/'.$id.'/decision', $this->decisionData($reviewer, $id, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->post('/leave-approvals/'.$id.'/decision', $this->decisionData($reviewer, $id, ['reason' => 'SYN-PRIVATE-TAMPER']))->assertRedirect(route('leave-approvals.index'))->assertSessionMissing('_old_input');
        $this->postJson('/leave-approvals/'.$id.'/decision', $this->decisionData($reviewer, $id, ['decision' => 'rejected']))->assertOk();
        $this->get('/leave-approvals')->assertOk()->assertDontSee('syntheticApproval()');
        $this->get('/leave-approvals?status=rejected')->assertOk()->assertSee('syntheticApproval()');
        $this->getJson('/leave-approvals?status=private')->assertUnprocessable();
    }
}
