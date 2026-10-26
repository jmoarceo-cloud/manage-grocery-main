<?php

namespace Tests\Feature;

use App\Models\PosUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RequestBoundaryTest extends IsolatedFeatureTestCase
{
    public function test_oversized_receipt_identifier_returns_not_found_instead_of_a_type_error(): void
    {
        $this->staffSession($this->cashier())->getJson('/receipts/'.str_repeat('9', 30))->assertNotFound();
    }

    public function test_new_product_can_use_the_uncategorized_option_without_a_database_error(): void
    {
        $admin = PosUser::where('username', 'demo_admin')->firstOrFail();
        $this->staffSession($admin)->postJson('/manage/products', ['product_name' => 'Synthetic uncategorized item', 'barcode' => null, 'category' => null, 'price' => '10.00', 'cost_price' => '1.00', 'supplier_id' => null, 'is_active' => 1])->assertRedirect(route('manage.index', 'products'));
        $this->assertDatabaseHas('products', ['product_name' => 'Synthetic uncategorized item', 'category' => '', 'stock' => 0], 'pos');
    }

    public function test_malformed_route_identifiers_never_enter_controllers_or_query_records(): void
    {
        $this->staffSession($this->cashier());
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $routes = [['GET', '/catalog/%s'], ['GET', '/product-image/%s'], ['GET', '/receipts/%s'], ['POST', '/overtime-approvals/%s/decision'], ['POST', '/leave-approvals/%s/decision'], ['GET', '/manage/products/%s/edit'], ['PUT', '/manage/products/%s'], ['DELETE', '/manage/products/%s'], ['GET', '/manage/products/%s/information'], ['POST', '/manage/products/%s/information'], ['POST', '/product-discounts/%s/decision'], ['POST', '/product-discounts/%s/cancel'], ['POST', '/discount-requests/%s/decision'], ['POST', '/discount-requests/%s/cancel'], ['POST', '/sales/%s/refund'], ['POST', '/sales/%s/void']];
        foreach (['0', '-1', '0001', '1e3', '1.5', '9223372036854775808', str_repeat('9', 64)] as $value) {
            foreach ($routes as [$method, $uri]) {
                $this->json($method, sprintf($uri, $value))->assertNotFound();
            }
        }
        $this->assertSame([], $queries);
    }

    public function test_checkout_refund_and_basket_requests_validate_identifier_storage_bounds_before_queries(): void
    {
        $this->staffSession($this->cashier());
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        foreach ([0, -1, 2147483648, PHP_INT_MAX, str_repeat('9', 30), ['synthetic-array']] as $id) {
            $items = [['id' => $id, 'quantity' => 1]];
            $this->postJson('/api/pos/checkout', ['request_key' => (string) Str::uuid(), 'items' => $items, 'payment' => '100.00', 'discount_type' => 'none'])->assertUnprocessable()->assertJsonValidationErrors('items.0.id');
            $this->postJson('/api/discount-requests', ['items' => $items, 'discount_type' => 'percent', 'discount_value' => '10.00'])->assertUnprocessable()->assertJsonValidationErrors('items.0.id');
            $this->postJson('/sales/1/refund', ['items' => $items, 'reason' => 'Synthetic boundary', 'supervisor_username' => 'Synthetic', 'supervisor_password' => 'Synthetic-only-password'])->assertUnprocessable()->assertJsonValidationErrors('items.0.id');
        }
        $this->postJson('/api/pos/checkout', ['request_key' => (string) Str::uuid(), 'items' => [['id' => 1, 'quantity' => 1]], 'payment' => '100.00', 'discount_type' => 'approved', 'discount_request_id' => 2147483648])->assertUnprocessable()->assertJsonValidationErrors('discount_request_id');
        $this->assertSame([], $queries);
    }

    public function test_label_restock_and_supplier_ids_reject_out_of_range_values_without_exists_queries(): void
    {
        $this->staffSession(PosUser::where('username', 'demo_admin')->firstOrFail());
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        foreach ([0, -1, 2147483648, PHP_INT_MAX, ['synthetic-array']] as $id) {
            $this->postJson('/labels/print', ['kind' => 'shelf', 'product_ids' => [$id]])->assertUnprocessable()->assertJsonValidationErrors('product_ids.0');
            $this->postJson('/inventory/restock', ['product_id' => $id, 'quantity' => 1, 'cost_price' => '1.00', 'reason' => 'Synthetic boundary'])->assertUnprocessable()->assertJsonValidationErrors('product_id');
            $this->postJson('/manage/products', ['product_name' => 'Synthetic boundary product', 'barcode' => null, 'category' => null, 'price' => '10.00', 'cost_price' => '1.00', 'supplier_id' => $id, 'is_active' => 1])->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
        }
        $this->assertSame([], $queries);
    }

    public function test_existing_product_category_can_be_cleared_without_creating_a_named_category(): void
    {
        $id = $this->product();
        $categories = DB::connection('pos')->table('categories')->count();
        $this->staffSession(PosUser::where('username', 'demo_admin')->firstOrFail());
        $this->putJson('/manage/products/'.$id, ['product_name' => 'Synthetic category cleared', 'barcode' => null, 'category' => null, 'price' => '25.00', 'cost_price' => '1.00', 'is_active' => 1])->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $id, 'category' => '', 'stock' => 10], 'pos');
        $this->assertDatabaseCount('categories', $categories, 'pos');
    }
}
