<?php

use App\Support\IsolatedDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $metadata = json_decode(file_get_contents(base_path('docs/audit/DATABASE_METADATA.json')), true, 512, JSON_THROW_ON_ERROR);
        if (app()->environment('testing')) {
            IsolatedDatabase::verify();
            foreach ($metadata['databases'] as $domain => $database) {
                $connection = DB::connection($domain);
                $sql = file_get_contents(base_path($database['schema_only_dump']['path']));
                preg_match_all('/CREATE TABLE `[^`]+` \([\s\S]*?\) ENGINE=[^;]+;/', $sql, $tables);
                if (count($tables[0]) !== count($database['tables'])) {
                    throw new RuntimeException('Captured schema table count mismatch.');
                }
                $connection->statement('SET FOREIGN_KEY_CHECKS=0');
                try {
                    foreach ($tables[0] as $statement) {
                        $connection->unprepared(str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $statement));
                    }
                } finally {
                    $connection->statement('SET FOREIGN_KEY_CHECKS=1');
                }
                if ($domain === 'pos') {
                    foreach (['update', 'delete'] as $action) {
                        $name = 'trg_stock_logs_no_'.$action;
                        $exists = $connection->selectOne('SELECT COUNT(*) AS total FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?', [$name]);
                        if (! (int) $exists->total) {
                            $connection->unprepared('CREATE TRIGGER `'.$name.'` BEFORE '.strtoupper($action)." ON stock_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit trail records cannot be modified.'");
                        }
                    }
                }
            }
        } elseif (! config('grocery.allow_adoption')) {
            throw new RuntimeException('Use the dedicated approved migration environment. Runtime credentials cannot adopt schemas.');
        }

        // Existing business tables and rows are never recreated or imported on live targets.
        foreach ($metadata['databases'] as $domain => $database) {
            foreach ($database['tables'] as $table) {
                $columns = DB::connection($domain)->select('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table['TABLE_NAME']]);
                $actual = collect($columns)->keyBy('COLUMN_NAME');
                foreach ($table['columns'] as $expected) {
                    $column = $actual->get($expected['COLUMN_NAME']);
                    if (! $column || $column->COLUMN_TYPE !== $expected['COLUMN_TYPE'] || $column->IS_NULLABLE !== $expected['IS_NULLABLE']) {
                        throw new RuntimeException('Legacy column contract differs; adoption stopped without rewriting business tables.');
                    }
                }
            }
        }
        $schema = Schema::connection('pos');
        if (! $schema->hasTable('laravel_checkout_requests')) {
            $schema->create('laravel_checkout_requests', function (Blueprint $table): void {
                $table->id();
                $table->integer('cashier_id');
                $table->uuid('request_key');
                $table->char('payload_hash', 64);
                $table->integer('sale_id')->nullable();
                $table->integer('discount_request_id')->nullable()->unique();
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['cashier_id', 'request_key']);
                $table->foreign('cashier_id')->references('id')->on('users');
                $table->foreign('sale_id')->references('id')->on('sales');
                $table->foreign('discount_request_id')->references('request_id')->on('discount_requests');
            });
        }
        if (! $schema->hasTable('application_audit_events')) {
            $schema->create('application_audit_events', function (Blueprint $table): void {
                $table->id();
                $table->string('actor_domain', 12);
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('entity', 60);
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->string('action', 24);
                $table->json('changed_fields');
                $table->timestamp('occurred_at')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        // Production rollback needs a reviewed recovery procedure, never a generic table drop.
        throw new RuntimeException('Automatic rollback is disabled to preserve business records and audit history.');
    }
};
