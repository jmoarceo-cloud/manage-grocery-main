<?php

use App\Models\HrUser;
use App\Models\PosUser;

return [
    'defaults' => ['guard' => 'pos', 'passwords' => 'users'],
    'guards' => ['pos' => ['driver' => 'session', 'provider' => 'pos'], 'hrms' => ['driver' => 'session', 'provider' => 'hrms']],
    'providers' => ['pos' => ['driver' => 'eloquent', 'model' => PosUser::class], 'hrms' => ['driver' => 'eloquent', 'model' => HrUser::class]],
    'passwords' => [], 'password_timeout' => 10800,
];
