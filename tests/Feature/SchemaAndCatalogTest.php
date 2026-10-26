<?php

namespace Tests\Feature;

use App\Services\PosService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SchemaAndCatalogTest extends IsolatedFeatureTestCase
{
    public function test_public_images_require_an_active_product_allowed_directory_and_actual_image_mime(): void
    {
        $name = 'synthetic-image-'.Str::uuid().'.png';
        $allowed = public_path('assets/images/'.$name);
        $private = storage_path('testing/'.$name);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1UAAAAASUVORK5CYII=');
        if (! is_dir(dirname($allowed))) {
            mkdir(dirname($allowed), 0777, true);
        }
        file_put_contents($allowed, $png);
        file_put_contents($private, $png);
        try {
            $id = $this->product();
            $db = DB::connection('pos');
            $db->table('products')->where('id', $id)->update(['image' => $name]);
            $this->get('/product-image/'.$id)->assertOk()->assertHeader('Content-Type', 'image/png');
            $db->table('products')->where('id', $id)->update(['is_active' => 0]);
            $this->get('/product-image/'.$id)->assertNotFound();
            $db->table('products')->where('id', $id)->update(['is_active' => 1, 'image' => '../storage/testing/'.$name]);
            $this->get('/product-image/'.$id)->assertNotFound();
            file_put_contents($allowed, 'synthetic text pretending to be an image');
            $db->table('products')->where('id', $id)->update(['image' => $name]);
            $this->get('/product-image/'.$id)->assertNotFound();
        } finally {
            unlink($allowed);
            unlink($private);
        }
    }

    public function test_all_legacy_tables_preserve_column_types_nullability_indexes_and_foreign_key_rules(): void
    {
        $metadata = json_decode(file_get_contents(base_path('docs/audit/DATABASE_METADATA.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($metadata['databases'] as $domain => $database) {
            foreach ($database['tables'] as $table) {
                $db = DB::connection($domain);
                $columns = collect($db->select('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLUMN_KEY,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table['TABLE_NAME']]))->keyBy('COLUMN_NAME');
                $this->assertCount(count($table['columns']), $columns);
                foreach ($table['columns'] as $expected) {
                    $this->assertTrue($columns->has($expected['COLUMN_NAME']));
                    foreach (['COLUMN_TYPE', 'IS_NULLABLE', 'COLUMN_DEFAULT', 'EXTRA', 'COLUMN_KEY', 'COLLATION_NAME'] as $field) {
                        $this->assertSame($expected[$field], $columns[$expected['COLUMN_NAME']]->$field);
                    }
                }
                $tableInfo = $db->selectOne('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table['TABLE_NAME']]);
                $this->assertSame($table['ENGINE'], $tableInfo->ENGINE);
                $this->assertSame($table['TABLE_COLLATION'], $tableInfo->TABLE_COLLATION);
                $indexes = $db->select('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table['TABLE_NAME']]);
                $normalize = fn ($items) => array_map(fn ($row) => array_map(fn ($value) => $value === null ? null : (string) $value, (array) $row), $items);
                $this->assertSame($normalize($table['indexes']), $normalize($indexes));
            }
            $rules = DB::connection($domain)->select('SELECT CONSTRAINT_NAME,TABLE_NAME,REFERENCED_TABLE_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME');
            $rules = array_values(array_filter($rules, fn ($rule) => ! in_array($rule->TABLE_NAME, ['laravel_checkout_requests', 'application_audit_events'], true)));
            $this->assertSame(json_encode($database['foreign_key_rules']), json_encode($rules));
        }
    }

    public function test_product_creation_starts_at_zero_stock_and_html_is_escaped(): void
    {
        $user = $this->cashier();
        DB::connection('pos')->table('users')->where('id', $user->id)->update(['role' => 'admin']);
        $user->refresh();
        $this->staffSession($user);
        $name = '<script>synthetic</script>';
        $this->post('/manage/products', ['product_name' => $name, 'price' => '25.50', 'cost_price' => '10.00', 'is_active' => 1])->assertRedirect(route('manage.index', 'products'));
        $this->assertDatabaseHas('products', ['product_name' => $name, 'stock' => 0], 'pos');
        $this->get('/manage/products')->assertOk()->assertSee('&lt;script&gt;synthetic&lt;/script&gt;', false)->assertDontSee($name, false);
    }

    public function test_category_rename_preserves_product_category_links(): void
    {
        $user = $this->cashier();
        DB::connection('pos')->table('users')->where('id', $user->id)->update(['role' => 'admin']);
        $user->refresh();
        $this->staffSession($user);
        $category = DB::connection('pos')->table('categories')->insertGetId(['category_name' => 'Synthetic old']);
        $id = $this->product();
        DB::connection('pos')->table('products')->where('id', $id)->update(['category' => 'Synthetic old']);
        $this->put('/manage/categories/'.$category, ['category_name' => 'Synthetic new'])->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $id, 'category' => 'Synthetic new'], 'pos');
    }

    public function test_inventory_staff_cannot_bypass_finance_with_a_direct_restock(): void
    {
        $user = $this->cashier();
        DB::connection('pos')->table('users')->where('id', $user->id)->update(['role' => 'inventory_staff']);
        $user->refresh();
        $this->staffSession($user);
        $id = $this->product();
        $this->post('/inventory/restock', ['product_id' => $id, 'quantity' => 5, 'cost_price' => '10.00', 'reason' => 'Synthetic restock'])->assertForbidden();
        $this->assertDatabaseHas('products', ['id' => $id, 'stock' => 10], 'pos');
        $this->assertDatabaseCount('stock_logs', 0, 'pos');
    }

    public function test_administrator_restock_records_batch_log_and_notification_atomically(): void
    {
        $user = $this->cashier();
        DB::connection('pos')->table('users')->where('id', $user->id)->update(['role' => 'admin']);
        $user->refresh();
        $this->staffSession($user);
        $id = $this->product();
        $this->post('/inventory/restock', ['product_id' => $id, 'quantity' => 5, 'cost_price' => '10.00', 'reason' => 'Synthetic restock'])->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $id, 'stock' => 15], 'pos');
        $this->assertSame(15, (int) DB::connection('pos')->table('product_batches')->where('product_id', $id)->sum('quantity_remaining'));
        $this->assertDatabaseCount('stock_logs', 1, 'pos');
        $this->assertDatabaseCount('notifications', 1, 'pos');
    }

    public function test_stock_log_trigger_rejects_modification(): void
    {
        $user = $this->cashier();
        $id = $this->product();
        app(PosService::class)->checkout($user->id, $this->cart($id));
        try {
            DB::connection('pos')->table('stock_logs')->update(['reason' => 'Synthetic forbidden change']);
            $this->fail('Immutable audit record was changed.');
        } catch (QueryException $exception) {
            $this->assertSame('45000', (string) $exception->getCode());
        }
        try {
            DB::connection('pos')->table('stock_logs')->delete();
            $this->fail('Immutable audit record was deleted.');
        } catch (QueryException $exception) {
            $this->assertSame('45000', (string) $exception->getCode());
        }
    }
}
