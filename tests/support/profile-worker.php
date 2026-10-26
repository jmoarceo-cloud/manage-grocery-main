<?php

use App\Models\PosUser;
use App\Services\OwnProfileService;
use App\Support\IsolatedDatabase;
use App\Support\ProfilePhone;
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
[$script, $id, $barrier, $index] = $argv;
if (! ctype_digit($id) || ! Str::isUuid($barrier) || ! in_array($index, ['0', '1'], true)) {
    exit(2);
}
$actor = PosUser::findOrFail((int) $id);
$service = app(OwnProfileService::class);
$employee = $service->read($actor, 'pos');
[$country, $number] = ProfilePhone::parse($employee->contact_number);
$email = $index === '0' ? 'shared.native.profile@example.invalid' : 'SHARED.native.profile@EXAMPLE.INVALID';
$data = ['revision' => OwnProfileService::revision($actor, 'pos', $employee), 'current_password' => 'SyntheticProfile!Pass123', 'email' => $email, 'email_confirmation' => $email, 'contact_number_country' => $country, 'contact_number' => $number, 'date_of_birth' => $employee->birthdate, 'place_of_birth' => $employee->place_of_birth, 'sex' => $employee->sex, 'civil_status' => $employee->civil_status, 'home_address' => $employee->address, 'highest_educational_attainment' => $employee->highest_educational_attainment, 'year_graduated' => $employee->year_graduated];
$prefix = storage_path('testing/profile-race-'.$barrier);
file_put_contents($prefix.'.'.$index.'.ready', 'ready');
$deadline = microtime(true) + 15;
while (! is_file($prefix.'.go')) {
    if (microtime(true) > $deadline) {
        exit(3);
    }
    usleep(5000);
}
DB::listen(function ($query): void {
    if (str_starts_with($query->sql, 'SELECT GET_LOCK')) {
        usleep(250000);
    }
});
try {
    $service->change($actor, 'pos', $data);
    echo json_encode(['result' => 'updated']);
} catch (ValidationException) {
    echo json_encode(['result' => 'rejected']);
} catch (Throwable $error) {
    fwrite(STDERR, 'Synthetic profile worker failed: '.get_class($error));
    exit(1);
}
