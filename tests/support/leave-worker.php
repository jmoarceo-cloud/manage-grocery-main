<?php

use App\Models\HrUser;
use App\Models\PosUser;
use App\Services\OwnLeaveService;
use App\Support\IsolatedDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
IsolatedDatabase::verify();
if (count($argv) !== 5) {
    exit(2);
}
[$script, $domain, $id, $barrier, $index] = $argv;
if (! in_array($domain, ['pos', 'hrms'], true) || ! ctype_digit($id) || ! Str::isUuid($barrier) || ! in_array($index, ['0', '1'], true)) {
    exit(2);
}
$actor = ($domain === 'pos' ? PosUser::class : HrUser::class)::findOrFail((int) $id);
$date = CarbonImmutable::today('Asia/Manila')->addDays(10)->toDateString();
$data = ['leave_type' => 'sick', 'date_from' => $date, 'date_to' => $date, 'reason' => 'Synthetic concurrent leave retry'];
$prefix = storage_path('testing/leave-race-'.$barrier);
file_put_contents($prefix.'.'.$index.'.ready', 'ready');
$deadline = microtime(true) + 15;
while (! is_file($prefix.'.go')) {
    if (microtime(true) > $deadline) {
        exit(3);
    }
    usleep(5000);
}
DB::listen(function ($query): void {
    if (str_contains($query->sql, 'employees`') && str_ends_with($query->sql, 'for update')) {
        usleep(250000);
    }
});
try {
    echo json_encode(app(OwnLeaveService::class)->submit($actor, $domain, $data));
} catch (Throwable $error) {
    fwrite(STDERR, 'Synthetic leave worker failed: '.get_class($error));
    exit(1);
}
