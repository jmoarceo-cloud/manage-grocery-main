<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class LeaveApprovalConcurrencyTest extends LeaveApprovalTestCase
{
    private function race(array $reviewers, array $ids): array
    {
        $barrier = (string) Str::uuid();
        $prefix = storage_path('testing/leave-decision-race-'.$barrier);
        $processes = [];
        try {
            foreach ($reviewers as $index => $actor) {
                $process = new Process([PHP_BINARY, base_path('tests/support/leave-decision-worker.php'), (string) $actor->id, (string) $ids[$index], $barrier, (string) $index], base_path(), ['APP_ENV' => 'testing', 'MAIL_MAILER' => 'array']);
                $process->setTimeout(25)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (! is_file($prefix.'.0.ready') || ! is_file($prefix.'.1.ready')) {
                if (microtime(true) > $deadline || ! $processes[0]->isRunning() || ! $processes[1]->isRunning()) {
                    $this->fail('Synthetic leave decision workers did not reach the barrier.');
                }
                usleep(5000);
            }
            file_put_contents($prefix.'.go', 'go');
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)['result'];
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($prefix.'.*') as $file) {
                unlink($file);
            }
        }
    }

    public function test_two_reviewers_cannot_deduct_the_same_request_twice(): void
    {
        $reviewers = [$this->profileActor('hrms', 'admin'), $this->profileActor('hrms', 'admin')];
        $target = $this->profileActor();
        $id = $this->existingLeave($target->employee_id);
        $this->assertEqualsCanonicalizing(['approved', 'rejected'], $this->race($reviewers, [$id, $id]));
        $this->assertDatabaseHas('leave_credits', ['employee_id' => $target->employee_id, 'leave_type' => 'sick', 'used_days' => '1.00'], 'hrms');
        $this->assertSame(1, DB::connection('hrms')->table('leave_request_logs')->where('leave_id', $id)->count());
        $this->assertSame(1, DB::connection('pos')->table('application_audit_events')->where('entity', 'leave_requests:hrms')->where('entity_id', $id)->where('action', 'approved')->count());
    }

    public function test_competing_requests_cannot_together_exceed_the_locked_vacation_balance(): void
    {
        $reviewers = [$this->profileActor('hrms', 'admin'), $this->profileActor('hrms', 'admin')];
        $target = $this->profileActor();
        $data = $this->leaveData();
        $date = CarbonImmutable::parse($data['date_from'], 'Asia/Manila');
        $ids = [$this->existingLeave($target->employee_id, ['leave_type' => 'vacation', 'date_to' => $date->addDay()->toDateString()]), $this->existingLeave($target->employee_id, ['leave_type' => 'vacation', 'date_from' => $date->addDays(5)->toDateString(), 'date_to' => $date->addDays(6)->toDateString()])];
        DB::connection('hrms')->table('leave_credits')->insert(['employee_id' => $target->employee_id, 'leave_type' => 'vacation', 'year' => $date->year, 'allocated_days' => '15.00', 'used_days' => '12.00']);
        $this->assertEqualsCanonicalizing(['approved', 'rejected'], $this->race($reviewers, $ids));
        $this->assertDatabaseHas('leave_credits', ['employee_id' => $target->employee_id, 'leave_type' => 'vacation', 'used_days' => '14.00'], 'hrms');
        $this->assertSame(1, DB::connection('hrms')->table('leave_requests')->whereIn('leave_id', $ids)->where('status', 'approved')->count());
        $this->assertSame(1, DB::connection('hrms')->table('leave_requests')->whereIn('leave_id', $ids)->where('status', 'pending')->count());
        $this->assertSame(1, DB::connection('hrms')->table('leave_request_logs')->whereIn('leave_id', $ids)->count());
    }
}
