<?php

use App\Services\PosService;
use App\Support\IsolatedDatabase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// A separate PHP process is necessary to exercise actual database contention.
// This helper cannot boot against an unmarked or non-testing database pair.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
IsolatedDatabase::verify();
[$script, $cashier, $product, $key, $barrier, $worker] = $argv;
if (! ctype_digit($cashier) || ! ctype_digit($product) || ! Str::isUuid($key) || ! Str::isUuid($barrier) || ! in_array($worker, ['0', '1'], true)) {
    exit(2);
}
$prefix = storage_path('testing/concurrency-'.$barrier);
file_put_contents($prefix.'.'.$worker.'.ready', 'ready');
$deadline = microtime(true) + 15;
while (! is_file($prefix.'.go')) {
    if (microtime(true) > $deadline) {
        exit(3);
    }
    usleep(5000);
}
DB::listen(function ($query): void {
    if (str_contains($query->sql, 'from `products`') && str_contains($query->sql, 'for update')) {
        usleep(250000);
    }
});
try {
    $sale = app(PosService::class)->checkout((int) $cashier, ['request_key' => $key, 'items' => [['id' => (int) $product, 'quantity' => 1]], 'payment' => '25.00', 'discount_type' => 'none']);
    echo json_encode(['result' => 'sold', 'sale_id' => $sale['sale_id']]);
} catch (ValidationException $exception) {
    echo json_encode(['result' => 'rejected']);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Synthetic worker failed: '.get_class($exception));
    exit(1);
}
