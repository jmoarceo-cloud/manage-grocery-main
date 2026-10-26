<?php

namespace Tests\Feature;

use App\Services\OwnProfileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class OwnProfileConcurrencyTest extends OwnProfileTestCase
{
    public function test_concurrent_native_updates_cannot_assign_case_variants_of_one_email_to_two_employees(): void
    {
        $actors = [$this->profileActor(), $this->profileActor()];
        $barrier = (string) Str::uuid();
        $prefix = storage_path('testing/profile-race-'.$barrier);
        $processes = [];
        try {
            foreach ($actors as $index => $actor) {
                $process = new Process([PHP_BINARY, base_path('tests/support/profile-worker.php'), (string) $actor->id, $barrier, (string) $index], base_path(), ['APP_ENV' => 'testing', 'MAIL_MAILER' => 'array']);
                $process->setTimeout(25)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (! is_file($prefix.'.0.ready') || ! is_file($prefix.'.1.ready')) {
                if (microtime(true) > $deadline || ! $processes[0]->isRunning() || ! $processes[1]->isRunning()) {
                    $this->fail('Synthetic profile workers did not reach the barrier.');
                }
                usleep(5000);
            }
            file_put_contents($prefix.'.go', 'go');
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)['result'];
            }
            $this->assertEqualsCanonicalizing(['updated', 'rejected'], $results);
            $this->assertSame(1, DB::connection('hrms')->table('employees')->whereIn('employee_id', array_map(fn ($a) => $a->employee_id, $actors))->where('email', 'shared.native.profile@example.invalid')->count());
            $this->assertSame(1, DB::connection('pos')->table('application_audit_events')->where('entity', 'employees:hrms')->whereIn('entity_id', array_map(fn ($a) => $a->employee_id, $actors))->count());
            $this->assertSame(1, (int) DB::connection('pos')->selectOne('SELECT IS_FREE_LOCK(?) AS available', [OwnProfileService::emailLock('shared.native.profile@example.invalid')])->available);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (['.0.ready', '.1.ready', '.go'] as $suffix) {
                if (is_file($prefix.$suffix)) {
                    unlink($prefix.$suffix);
                }
            }
        }
    }
}
