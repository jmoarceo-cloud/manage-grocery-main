<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class OvertimeApprovalConcurrencyTest extends OvertimeApprovalTestCase
{
    public function test_admin_and_team_supervisor_cannot_record_two_decisions_for_one_pending_request(): void
    {
        $admin = $this->profileActor('hrms', 'admin');
        $supervisor = $this->profileActor('hrms', 'supervisor');
        $target = $this->profileActor();
        $this->assignOvertimeTeam($supervisor->employee_id, $target->employee_id);
        $id = $this->existingOvertime($target->employee_id);
        $barrier = (string) Str::uuid();
        $prefix = storage_path('testing/overtime-decision-race-'.$barrier);
        $processes = [];
        try {
            foreach ([$admin, $supervisor] as $index => $actor) {
                $process = new Process([PHP_BINARY, base_path('tests/support/overtime-decision-worker.php'), (string) $actor->id, (string) $id, $barrier, (string) $index], base_path(), ['APP_ENV' => 'testing', 'MAIL_MAILER' => 'array']);
                $process->setTimeout(25)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (! is_file($prefix.'.0.ready') || ! is_file($prefix.'.1.ready')) {
                if (microtime(true) > $deadline || ! $processes[0]->isRunning() || ! $processes[1]->isRunning()) {
                    $this->fail('Synthetic overtime decision workers did not reach their barrier.');
                }
                usleep(5000);
            }
            file_put_contents($prefix.'.go', 'go');
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)['result'];
            }
            $this->assertEqualsCanonicalizing(['approved', 'rejected'], $results);
            $this->assertDatabaseHas('overtime_requests', ['ot_id' => $id, 'status' => 'approved', 'hours_requested' => '2.00'], 'hrms');
            $this->assertSame(1, DB::connection('pos')->table('application_audit_events')->where('entity', 'overtime_requests:hrms')->where('entity_id', $id)->where('action', 'approved')->count());
            $this->assertSame(0, DB::connection('hrms')->table('attendance')->where('employee_id', $target->employee_id)->count());
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
}
