<?php

namespace Tests\Feature;

use App\Support\IsolatedDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentCheckoutTest extends TestCase
{
    protected function tearDown(): void
    {
        // These processes need committed fixtures; rebuild only the verified pair.
        IsolatedDatabase::verify();
        Artisan::call('migrate:fresh', ['--seed' => true]);
        parent::tearDown();
    }

    private function race(bool $sameRequest): array
    {
        IsolatedDatabase::verify();
        $db = DB::connection('pos');
        $cashier = $db->table('users')->insertGetId(['username' => 'synthetic_race_one', 'full_name' => 'Synthetic Race One', 'role' => 'cashier', 'password' => Hash::make('Synthetic!Pass123'), 'is_active' => 1]);
        $other = $sameRequest ? $cashier : $db->table('users')->insertGetId(['username' => 'synthetic_race_two', 'full_name' => 'Synthetic Race Two', 'role' => 'cashier', 'password' => Hash::make('Synthetic!Pass123'), 'is_active' => 1]);
        $product = $db->table('products')->insertGetId(['product_name' => 'Synthetic Last Unit', 'barcode' => (string) Str::uuid(), 'stock' => 1, 'price' => '25.00', 'is_active' => 1]);
        $db->table('product_batches')->insert(['product_id' => $product, 'quantity_received' => 1, 'quantity_remaining' => 1, 'expiry_date' => now()->addMonth()->toDateString()]);
        $key = (string) Str::uuid();
        $barrier = (string) Str::uuid();
        $prefix = storage_path('testing/concurrency-'.$barrier);
        if (! is_dir(dirname($prefix))) {
            mkdir(dirname($prefix), 0777, true);
        }
        $processes = [];
        try {
            foreach ([$cashier, $other] as $index => $actor) {
                $process = new Process([PHP_BINARY, base_path('tests/support/checkout-worker.php'), (string) $actor, (string) $product, $sameRequest ? $key : (string) Str::uuid(), $barrier, (string) $index], base_path(), ['APP_ENV' => 'testing', 'MAIL_MAILER' => 'array']);
                $process->setTimeout(25)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (! is_file($prefix.'.0.ready') || ! is_file($prefix.'.1.ready')) {
                if (microtime(true) > $deadline || ! $processes[0]->isRunning() || ! $processes[1]->isRunning()) {
                    $this->fail('Synthetic workers did not reach the checkout barrier.');
                }
                usleep(5000);
            }
            file_put_contents($prefix.'.go', 'go');
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $this->assertSame(0, (int) $db->table('products')->where('id', $product)->value('stock'));
            $this->assertSame(0, (int) $db->table('product_batches')->where('product_id', $product)->value('quantity_remaining'));
            $this->assertSame(1, $db->table('sales')->whereIn('cashier_id', [$cashier, $other])->count());
            $this->assertSame(1, $db->table('stock_logs')->where('product_id', $product)->count());

            return $results;
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

    public function test_two_cashiers_cannot_sell_the_same_last_unit(): void
    {
        $results = $this->race(false);
        $this->assertEqualsCanonicalizing(['sold', 'rejected'], array_column($results, 'result'));
    }

    public function test_simultaneous_retry_returns_one_sale_and_one_stock_deduction(): void
    {
        $results = $this->race(true);
        $this->assertSame(['sold', 'sold'], array_column($results, 'result'));
        $this->assertSame($results[0]['sale_id'], $results[1]['sale_id']);
    }
}
