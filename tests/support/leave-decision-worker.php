<?php

use App\Models\HrUser;
use App\Services\LeaveApprovalService;
use App\Support\IsolatedDatabase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
[$script, $actorId, $leaveId, $barrier, $index] = $argv;
if (! ctype_digit($actorId) || ! ctype_digit($leaveId) || ! Str::isUuid($barrier) || ! in_array($index, ['0', '1'], true)) {
    exit(2);
}
$actor = HrUser::findOrFail((int) $actorId);
$leave = DB::connection('hrms')->table('leave_requests')->where('leave_id', $leaveId)->first(LeaveApprovalService::REVISION_FIELDS);
$data = ['decision' => 'approved', 'revision' => LeaveApprovalService::revision($actor, $leave)];
$prefix = storage_path('testing/leave-decision-race-'.$barrier);
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
    app(LeaveApprovalService::class)->decide($actor, 'hrms', (int) $leaveId, $data);
    echo json_encode(['result' => 'approved']);
} catch (ValidationException) {
    echo json_encode(['result' => 'rejected']);
} catch (Throwable $error) {
    fwrite(STDERR, 'Synthetic leave decision worker failed: '.get_class($error));
    exit(1);
}
