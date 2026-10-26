<?php

namespace Tests\Feature;

use App\Services\PosService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosTransactionsTest extends IsolatedFeatureTestCase
{
    public function test_unused_statutory_identity_fields_are_not_retained_for_an_ordinary_sale(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        $cart = $this->cart($id);
        $cart += ['discount_id_number' => '000-000', 'discount_holder_name' => 'Synthetic Previous Cardholder', 'supervisor_username' => 'synthetic_unused', 'supervisor_password' => 'Synthetic unused password', 'discount_request_id' => 999999];
        $sale = app(PosService::class)->checkout($cashier->id, $cart);
        $this->assertDatabaseHas('sales', ['id' => $sale['sale_id'], 'discount_id_number' => null, 'discount_holder_name' => null, 'discount_approved_by_name' => null], 'pos');
    }

    public function test_fefo_uses_earlier_expiry_before_later_and_undated_batches(): void
    {
        $cashier = $this->cashier();
        $id = $this->product(6);
        $db = DB::connection('pos');
        $later = $db->table('product_batches')->where('product_id', $id)->value('id');
        $db->table('product_batches')->where('id', $later)->update(['quantity_received' => 3, 'quantity_remaining' => 3]);
        $earlier = $db->table('product_batches')->insertGetId(['product_id' => $id, 'quantity_received' => 2, 'quantity_remaining' => 2, 'expiry_date' => now()->addDays(2)->toDateString()]);
        $undated = $db->table('product_batches')->insertGetId(['product_id' => $id, 'quantity_received' => 1, 'quantity_remaining' => 1]);
        $cart = $this->cart($id);
        $cart['items'][0]['quantity'] = 4;
        app(PosService::class)->checkout($cashier->id, $cart);
        $this->assertSame([2, 2], array_map('intval', $db->table('sale_batch_consumption')->orderBy('id')->pluck('quantity')->all()));
        $this->assertSame([(int) $earlier, (int) $later], array_map('intval', $db->table('sale_batch_consumption')->orderBy('id')->pluck('batch_id')->all()));
        $this->assertSame(1, (int) $db->table('product_batches')->where('id', $undated)->value('quantity_remaining'));
    }

    public function test_discounted_partial_returns_reconcile_the_last_cent_to_the_original_net_sale(): void
    {
        $cashier = $this->cashier();
        $first = $this->product(10, '0.05');
        $second = $this->product(10, '0.03');
        $approval = $this->supervisor();
        $cart = $this->cart($first);
        $cart['items'] = [['id' => $first, 'quantity' => 1], ['id' => $second, 'quantity' => 2]];
        $cart['payment'] = '1.00';
        $cart['discount_type'] = 'senior';
        $cart['discount_id_number'] = '000-000';
        $cart += $approval;
        $service = app(PosService::class);
        $sale = $service->checkout($cashier->id, $cart);
        $this->assertSame('0.09', $sale['total']);
        $db = DB::connection('pos');
        $lines = $db->table('sales_details')->where('sale_id', $sale['sale_id'])->pluck('id', 'product_id');
        $amounts = [];
        foreach ([$first, $second, $second] as $productId) {
            $refund = $service->refund($cashier->id, $sale['sale_id'], $approval + ['reason' => 'Synthetic cent reconciliation', 'items' => [['id' => $lines[$productId], 'quantity' => 1]]]);
            $amounts[] = PosService::cents($refund['amount']);
        }
        $this->assertSame([4, 2, 3], $amounts);
        $this->assertSame(PosService::cents($sale['total']), array_sum($amounts));
        $this->assertDatabaseHas('sales', ['id' => $sale['sale_id'], 'status' => 'refunded'], 'pos');
    }

    public function test_a_checkout_key_cannot_be_reused_for_a_changed_cart(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        $cart = $this->cart($id);
        $this->staffSession($cashier)->postJson('/api/pos/checkout', $cart)->assertOk();
        $cart['items'][0]['quantity'] = 1;
        $this->postJson('/api/pos/checkout', $cart)->assertStatus(409);
        $this->assertDatabaseCount('sales', 1, 'pos');
        $this->assertSame(8, (int) DB::connection('pos')->table('products')->where('id', $id)->value('stock'));
    }

    public function test_inactive_product_cannot_be_sold(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        DB::connection('pos')->table('products')->where('id', $id)->update(['is_active' => 0]);
        $this->staffSession($cashier)->postJson('/api/pos/checkout', $this->cart($id))->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0, 'pos');
    }

    public function test_inactive_supervisor_cannot_approve_refund(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        $approval = $this->supervisor();
        $sale = app(PosService::class)->checkout($cashier->id, $this->cart($id));
        $db = DB::connection('pos');
        $line = $db->table('sales_details')->value('id');
        DB::connection('hrms')->table('users')->where('username', $approval['supervisor_username'])->update(['is_active' => 0]);
        $this->staffSession($cashier)->postJson('/sales/'.$sale['sale_id'].'/refund', $approval + ['reason' => 'Synthetic rejected approval', 'items' => [['id' => $line, 'quantity' => 1]]])->assertUnprocessable();
        $this->assertDatabaseCount('sale_refunds', 0, 'pos');
        $this->assertSame(8, (int) $db->table('products')->where('id', $id)->value('stock'));
    }

    public function test_void_log_failure_rolls_back_stock_batches_and_status(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        $sale = app(PosService::class)->checkout($cashier->id, $this->cart($id));
        DB::listen(function ($event): void {
            if (str_contains($event->sql, 'insert into `stock_logs`')) {
                throw new \RuntimeException('Synthetic void log failure');
            }
        });
        try {
            app(PosService::class)->void($cashier->id, $sale['sale_id']);
            $this->fail('Void failure was ignored.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic void log failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('sales', ['id' => $sale['sale_id'], 'status' => 'completed'], 'pos');
        $this->assertSame(8, (int) DB::connection('pos')->table('products')->where('id', $id)->value('stock'));
        $this->assertSame(8, (int) DB::connection('pos')->table('product_batches')->where('product_id', $id)->value('quantity_remaining'));
    }

    public function test_void_restores_original_stock_and_batches_exactly_once(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        $sale = app(PosService::class)->checkout($cashier->id, $this->cart($id));
        $this->staffSession($cashier)->post('/sales/'.$sale['sale_id'].'/void')->assertRedirect();
        $this->assertDatabaseHas('sales', ['id' => $sale['sale_id'], 'status' => 'voided'], 'pos');
        $this->assertSame(10, (int) DB::connection('pos')->table('products')->where('id', $id)->value('stock'));
        $this->assertSame(10, (int) DB::connection('pos')->table('product_batches')->where('product_id', $id)->value('quantity_remaining'));
        $this->postJson('/sales/'.$sale['sale_id'].'/void')->assertUnprocessable();
        $this->assertSame(1, DB::connection('pos')->table('stock_logs')->where('change_type', 'void')->count());
        $this->assertDatabaseCount('sale_refunds', 0, 'pos');
    }

    public function test_void_denies_other_cashiers_and_prior_day_transactions(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        $sale = app(PosService::class)->checkout($cashier->id, $this->cart($id));
        $db = DB::connection('pos');
        $db->table('sales')->where('id', $sale['sale_id'])->update(['transaction_date' => now()->subDay()]);
        $this->staffSession($cashier)->postJson('/sales/'.$sale['sale_id'].'/void')->assertUnprocessable();
        $other = $db->table('users')->insertGetId(['username' => 'synthetic_other_cashier', 'full_name' => 'Synthetic Other', 'password' => $cashier->password, 'role' => 'cashier', 'is_active' => 1]);
        $db->table('sales')->where('id', $sale['sale_id'])->update(['transaction_date' => now(), 'cashier_id' => $other]);
        $this->postJson('/sales/'.$sale['sale_id'].'/void')->assertUnprocessable();
        $this->assertSame(8, (int) $db->table('products')->where('id', $id)->value('stock'));
        $this->assertDatabaseHas('sales', ['id' => $sale['sale_id'], 'status' => 'completed'], 'pos');
    }

    public function test_checkout_recomputes_prices_and_records_stock_and_fefo_allocations(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        $sale = app(PosService::class)->checkout($cashier->id, $this->cart($id));
        $this->assertSame('50.00', $sale['total']);
        $this->assertSame(8, (int) DB::connection('pos')->table('products')->where('id', $id)->value('stock'));
        $this->assertSame(8, (int) DB::connection('pos')->table('product_batches')->where('product_id', $id)->value('quantity_remaining'));
        $this->assertDatabaseCount('sales', 1, 'pos');
        $this->assertDatabaseCount('sales_details', 1, 'pos');
        $this->assertDatabaseCount('stock_logs', 1, 'pos');
        $this->assertSame(2, (int) DB::connection('pos')->table('sale_batch_consumption')->sum('quantity'));
    }

    public function test_duplicate_checkout_key_returns_same_sale_and_deducts_stock_once(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        $cart = $this->cart($id);
        $first = app(PosService::class)->checkout($cashier->id, $cart);
        $second = app(PosService::class)->checkout($cashier->id, $cart);
        $this->assertSame($first['sale_id'], $second['sale_id']);
        $this->assertDatabaseCount('sales', 1, 'pos');
        $this->assertSame(8, (int) DB::connection('pos')->table('products')->where('id', $id)->value('stock'));
    }

    public function test_stock_failure_rolls_back_header_items_allocations_and_request_key(): void
    {
        $cashier = $this->cashier();
        $id = $this->product(1);
        try {
            app(PosService::class)->checkout($cashier->id, $this->cart($id));
            $this->fail('Overselling was allowed.');
        } catch (ValidationException $expected) {
        }
        foreach (['sales', 'sales_details', 'stock_logs', 'sale_batch_consumption', 'laravel_checkout_requests'] as $table) {
            $this->assertDatabaseCount($table, 0, 'pos');
        }
        $this->assertSame(1, (int) DB::connection('pos')->table('products')->where('id', $id)->value('stock'));
    }

    public function test_expired_batches_cannot_be_sold(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        DB::connection('pos')->table('product_batches')->where('product_id', $id)->update(['expiry_date' => now()->subDay()->toDateString()]);
        $this->expectException(ValidationException::class);
        app(PosService::class)->checkout($cashier->id, $this->cart($id));
    }

    public function test_injected_log_failure_rolls_back_every_sale_effect(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        DB::listen(function ($event): void {
            if (str_contains($event->sql, 'insert into `stock_logs`')) {
                throw new \RuntimeException('Synthetic injected failure');
            }
        });
        try {
            app(PosService::class)->checkout($cashier->id, $this->cart($id));
            $this->fail('Injected write failure was ignored.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic injected failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('sales', 0, 'pos');
        $this->assertDatabaseCount('sales_details', 0, 'pos');
        $this->assertDatabaseCount('stock_logs', 0, 'pos');
        $this->assertSame(10, (int) DB::connection('pos')->table('products')->where('id', $id)->value('stock'));
    }

    public function test_partial_refunds_restore_original_batches_and_block_over_refunds(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        $approval = $this->supervisor();
        $sale = app(PosService::class)->checkout($cashier->id, $this->cart($id));
        $line = DB::connection('pos')->table('sales_details')->value('id');
        $data = $approval + ['items' => [['id' => $line, 'quantity' => 1]], 'reason' => 'Synthetic return'];
        app(PosService::class)->refund($cashier->id, $sale['sale_id'], $data);
        $this->assertDatabaseHas('sales', ['id' => $sale['sale_id'], 'status' => 'partial_refunded'], 'pos');
        app(PosService::class)->refund($cashier->id, $sale['sale_id'], $data);
        $this->assertDatabaseHas('sales', ['id' => $sale['sale_id'], 'status' => 'refunded'], 'pos');
        $this->assertSame(10, (int) DB::connection('pos')->table('product_batches')->where('product_id', $id)->value('quantity_remaining'));
        $this->expectException(ValidationException::class);
        app(PosService::class)->refund($cashier->id, $sale['sale_id'], $data);
    }
}
