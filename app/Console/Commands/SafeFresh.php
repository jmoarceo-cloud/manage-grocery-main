<?php

namespace App\Console\Commands;

use App\Support\IsolatedDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SafeFresh extends Command
{
    protected $signature = 'migrate:fresh {--seed} {--force} {--seeder=} {--database=}';

    protected $description = 'Rebuild only the verified, marked GroceryOne synthetic test pair';

    public function handle(): int
    {
        try {
            IsolatedDatabase::verify();
        } catch (\RuntimeException $exception) {
            $this->error('Refusing reset: a verified synthetic test pair is required.');

            return self::FAILURE;
        }
        if ($this->option('database') && $this->option('database') !== 'pos') {
            $this->error('The synthetic pair is reset together; another connection cannot be selected.');

            return self::FAILURE;
        }
        $nonce = config('grocery.test_nonce');
        foreach (['pos', 'hrms'] as $domain) {
            Schema::connection($domain)->dropAllTables();
            DB::connection($domain)->statement('CREATE TABLE groceryone_test_target (nonce VARCHAR(40) PRIMARY KEY)');
            DB::connection($domain)->table('groceryone_test_target')->insert(['nonce' => $nonce]);
        }
        $result = $this->call('migrate', ['--force' => true]);
        if ($result !== 0) {
            return $result;
        }
        if ($this->option('seed')) {
            return $this->call('db:seed', ['--class' => $this->option('seeder') ?: 'Database\Seeders\DatabaseSeeder', '--force' => true]);
        }

        return self::SUCCESS;
    }
}
