<?php

namespace Tests\Feature;

use App\Support\IsolatedDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OwnLeaveConcurrencyTest extends OwnLeaveTestCase
{
    public function test_two_distinct_domain_accounts_linked_to_one_employee_serialize_the_same_pending_request(): void
    {
        IsolatedDatabase::verify();
        $pos = $this->profileActor();
        $hr = $this->profileActor('hrms', 'admin', $pos->employee_id);
        $this->assertNotSame((int) $pos->id, (int) $hr->id);
        $barrier = (string) Str::uuid();
        $prefix = storage_path('testing/leave-race-'.$barrier);
        $workers = [];
        try {
            foreach ([['pos', $pos->id], ['hrms', $hr->id]] as $index => [$domain, $id]) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/support/leave-worker.php'), $domain, (string) $id, $barrier, (string) $index], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 15;
            while (! is_file($prefix.'.0.ready') || ! is_file($prefix.'.1.ready')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Synthetic leave workers did not reach their barrier.');
                }
                usleep(5000);
            }
            file_put_contents($prefix.'.go', 'go');
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
                $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            }
            $workers = [];
            $this->assertSame($results[0]['id'], $results[1]['id']);
            $this->assertEqualsCanonicalizing([false, true], array_column($results, 'replayed'));
            $this->assertSame(1, DB::connection('hrms')->table('leave_requests')->where('employee_id', $pos->employee_id)->count());
            $this->assertSame(1, DB::connection('pos')->table('application_audit_events')->where('entity', 'leave_requests:hrms')->where('entity_id', $results[0]['id'])->count());
        } finally {
            foreach ($workers as [$process]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            foreach (glob($prefix.'.*') as $file) {
                unlink($file);
            }
        }
    }
}
