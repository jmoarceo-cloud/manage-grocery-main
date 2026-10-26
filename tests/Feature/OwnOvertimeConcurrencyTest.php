<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class OwnOvertimeConcurrencyTest extends OwnOvertimeTestCase
{
    public function test_distinct_domain_accounts_share_one_identical_normalized_pending_request(): void
    {
        $pos = $this->profileActor();
        $hr = $this->profileActor('hrms', 'admin', $pos->employee_id);
        $barrier = (string) Str::uuid();
        $prefix = storage_path('testing/overtime-race-'.$barrier);
        $processes = [];
        try {
            foreach ([$pos, $hr] as $index => $actor) {
                $process = new Process([PHP_BINARY, base_path('tests/support/overtime-worker.php'), $actor->getConnectionName(), (string) $actor->id, $barrier, (string) $index], base_path(), ['APP_ENV' => 'testing', 'MAIL_MAILER' => 'array']);
                $process->setTimeout(25)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (! is_file($prefix.'.0.ready') || ! is_file($prefix.'.1.ready')) {
                if (microtime(true) > $deadline || ! $processes[0]->isRunning() || ! $processes[1]->isRunning()) {
                    $this->fail('Synthetic overtime workers did not reach the barrier.');
                }
                usleep(5000);
            }
            file_put_contents($prefix.'.go', 'go');
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $this->assertSame($results[0]['id'], $results[1]['id']);
            $this->assertEqualsCanonicalizing([false, true], array_column($results, 'replayed'));
            $this->assertSame(1, DB::connection('hrms')->table('overtime_requests')->where('employee_id', $pos->employee_id)->count());
            $this->assertSame(1, DB::connection('pos')->table('application_audit_events')->where('entity', 'overtime_requests:hrms')->where('entity_id', $results[0]['id'])->count());
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
