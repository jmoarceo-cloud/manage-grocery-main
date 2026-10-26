<?php

namespace Tests\Feature;

use App\Models\HrUser;
use App\Models\PosUser;
use App\Services\PosService;
use App\Services\ProductDiscountService;
use App\Support\StaffSessionVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProductDiscountTest extends IsolatedFeatureTestCase
{
    private function inventory(): PosUser
    {
        return PosUser::where('username', 'demo_inventory_staff')->firstOrFail();
    }

    private function finance(): PosUser
    {
        return PosUser::where('username', 'demo_finance')->firstOrFail();
    }

    private function data(int $id, string $type = 'percent', string $value = '10.00'): array
    {
        return ['product_id' => $id, 'discount_type' => $type, 'discount_value' => $value, 'discount_reason' => 'Synthetic product promotion'];
    }

    private function submit(int $id): int
    {
        return app(ProductDiscountService::class)->request($this->inventory()->id, $this->data($id));
    }

    public function test_request_derives_current_price_and_discount_snapshot_without_changing_price_or_stock(): void
    {
        $logs = DB::connection('pos')->table('stock_logs')->count();
        $id = $this->product();
        $this->staffSession($this->inventory())->postJson('/product-discounts', $this->data($id))->assertCreated()->assertJsonPath('status', 'pending');
        $this->assertDatabaseHas('product_discount_requests', ['product_id' => $id, 'requested_by' => $this->inventory()->id, 'price_at_request' => '25.00', 'old_discount_type' => 'none', 'old_discount_value' => '0.00', 'discount_value' => '10.00', 'status' => 'pending'], 'pos');
        $this->assertDatabaseHas('products', ['id' => $id, 'price' => '25.00', 'discount_type' => 'none', 'stock' => 10], 'pos');
        $this->assertDatabaseCount('stock_logs', $logs, 'pos');
    }

    public function test_finance_approval_applies_to_public_prices_and_checkout_once(): void
    {
        $id = $this->product();
        $request = $this->submit($id);
        $this->staffSession($this->finance())->postJson('/product-discounts/'.$request.'/decision', ['decision' => 'approved'])->assertOk();
        $this->assertDatabaseHas('products', ['id' => $id, 'discount_type' => 'percent', 'discount_value' => '10.00', 'discount_reason' => 'Synthetic product promotion', 'stock' => 10], 'pos');
        $this->assertDatabaseHas('product_discount_requests', ['request_id' => $request, 'status' => 'approved', 'decided_by' => $this->finance()->id], 'pos');
        $this->get('/catalog/'.$id)->assertOk()->assertSee('22.50');
        $cashier = $this->cashier();
        $receipt = app(PosService::class)->checkout($cashier->id, $this->cart($id));
        $this->assertSame('45.00', $receipt['total']);
        $this->staffSession($this->finance())->postJson('/product-discounts/'.$request.'/decision', ['decision' => 'approved'])->assertUnprocessable();
    }

    public function test_request_and_decision_permissions_are_enforced_and_pending_cancellation_is_owner_or_admin(): void
    {
        $id = $this->product();
        $request = $this->submit($id);
        $cashier = $this->cashier();
        $this->staffSession($cashier)->get('/product-discounts')->assertForbidden();
        $this->postJson('/product-discounts', $this->data($id))->assertForbidden();
        $this->postJson('/product-discounts/'.$request.'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->staffSession($this->finance())->postJson('/product-discounts', $this->data($id))->assertForbidden();
        $this->postJson('/product-discounts/'.$request.'/cancel')->assertForbidden();
        $this->staffSession($this->inventory())->postJson('/product-discounts/'.$request.'/decision', ['decision' => 'approved'])->assertForbidden();
        $otherId = DB::connection('pos')->table('users')->insertGetId(['username' => 'synthetic_other_inventory', 'full_name' => 'Synthetic Other Inventory', 'password' => Hash::make('Synthetic!Pass123'), 'role' => 'inventory_staff', 'is_active' => 1]);
        $this->staffSession(PosUser::findOrFail($otherId))->postJson('/product-discounts/'.$request.'/cancel')->assertForbidden();
        $this->get('/product-discounts?status=all')->assertOk()->assertDontSee('Synthetic product promotion');
        $this->postJson('/product-discounts', $this->data($id))->assertUnprocessable();
        $this->staffSession($this->inventory())->postJson('/product-discounts/'.$request.'/cancel')->assertOk();
        $this->postJson('/product-discounts/'.$request.'/cancel')->assertUnprocessable();
        $request = $this->submit($id);
        $admin = PosUser::where('username', 'demo_admin')->firstOrFail();
        $this->staffSession($admin)->postJson('/product-discounts/'.$request.'/cancel')->assertOk();
        $this->postJson('/product-discounts', $this->data($id))->assertCreated();
    }

    public function test_invalid_values_and_actor_or_price_tampering_cannot_mutate_products_or_requests(): void
    {
        $id = $this->product();
        $this->staffSession($this->inventory());
        foreach ([['percent', '100.01'], ['percent', '0.00'], ['fixed', '25.00'], ['fixed', '26.00'], ['fixed', '-1'], ['none', '1.00'], ['percent', '1e4'], ['percent', '1.001']] as [$type,$value]) {
            $this->postJson('/product-discounts', $this->data($id, $type, $value))->assertUnprocessable();
        }
        $this->postJson('/product-discounts', $this->data($id) + ['price_at_request' => '0.01', 'requested_by' => 1, 'status' => 'approved'])->assertUnprocessable();
        $catalog = ['product_name' => 'Synthetic product', 'barcode' => null, 'category' => null, 'price' => '25.00', 'cost_price' => '5.00', 'is_active' => 1, 'discount_type' => 'percent', 'discount_value' => '100.00'];
        $this->putJson('/manage/products/'.$id, $catalog)->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $id, 'discount_type' => 'none', 'price' => '25.00'], 'pos');
        $this->assertDatabaseCount('product_discount_requests', 0, 'pos');
    }

    public function test_rejection_keeps_product_unchanged_and_cannot_be_reversed(): void
    {
        $id = $this->product();
        $request = $this->submit($id);
        $this->staffSession($this->finance())->postJson('/product-discounts/'.$request.'/decision', ['decision' => 'rejected', 'reason' => 'Synthetic rejection'])->assertOk();
        $this->assertDatabaseHas('product_discount_requests', ['request_id' => $request, 'status' => 'rejected', 'reject_reason' => 'Synthetic rejection'], 'pos');
        $this->assertDatabaseHas('products', ['id' => $id, 'discount_type' => 'none'], 'pos');
        $this->postJson('/product-discounts/'.$request.'/decision', ['decision' => 'approved'])->assertUnprocessable();
    }

    public function test_missing_legacy_product_cannot_be_approved_but_pending_request_can_still_be_rejected(): void
    {
        $id = $this->product();
        $request = $this->submit($id);
        DB::connection('pos')->table('products')->where('id', $id)->delete();
        $this->staffSession($this->finance())->postJson('/product-discounts/'.$request.'/decision', ['decision' => 'approved'])->assertUnprocessable();
        $this->postJson('/product-discounts/'.$request.'/decision', ['decision' => 'rejected'])->assertOk();
        $this->assertDatabaseHas('product_discount_requests', ['request_id' => $request, 'status' => 'rejected'], 'pos');
    }

    public function test_changed_price_or_current_discount_blocks_approval_until_a_fresh_proposal(): void
    {
        foreach ([['price' => '26.00'], ['discount_type' => 'fixed', 'discount_value' => '1.00'], ['discount_reason' => 'Changed synthetic reason']] as $change) {
            $id = $this->product();
            $request = $this->submit($id);
            DB::connection('pos')->table('products')->where('id', $id)->update($change);
            $this->staffSession($this->finance())->postJson('/product-discounts/'.$request.'/decision', ['decision' => 'approved'])->assertUnprocessable()->assertJsonValidationErrors('discount');
            $this->assertDatabaseHas('product_discount_requests', ['request_id' => $request, 'status' => 'pending', 'decided_by' => null], 'pos');
        }
    }

    public function test_expired_or_deactivated_products_cannot_gain_discount_but_expired_discount_can_be_removed(): void
    {
        $id = $this->product();
        $request = $this->submit($id);
        $db = DB::connection('pos');
        $db->table('product_batches')->where('product_id', $id)->update(['expiry_date' => now()->subDay()->toDateString()]);
        $this->staffSession($this->finance())->postJson('/product-discounts/'.$request.'/decision', ['decision' => 'approved'])->assertUnprocessable();
        $this->staffSession($this->inventory())->postJson('/product-discounts/'.$request.'/cancel')->assertOk();
        $this->postJson('/product-discounts', $this->data($id))->assertUnprocessable();
        $db->table('products')->where('id', $id)->update(['discount_type' => 'percent', 'discount_value' => '10.00']);
        $clear = $this->postJson('/product-discounts', $this->data($id, 'none', '0.00'))->assertCreated()->json('request_id');
        $this->staffSession($this->finance())->postJson('/product-discounts/'.$clear.'/decision', ['decision' => 'approved'])->assertOk();
        $this->assertDatabaseHas('products', ['id' => $id, 'discount_type' => 'none', 'discount_value' => '0.00', 'discount_reason' => null], 'pos');
        $db->table('products')->where('id', $id)->update(['is_active' => 0]);
        $this->staffSession($this->inventory())->postJson('/product-discounts', $this->data($id))->assertUnprocessable();
    }

    public function test_audit_failure_rolls_back_product_change_and_decision_together(): void
    {
        $id = $this->product();
        $request = $this->submit($id);
        $auditCount = DB::connection('pos')->table('application_audit_events')->count();
        DB::listen(function ($event): void {
            if (str_starts_with($event->sql, 'insert into `application_audit_events`')) {
                throw new \RuntimeException('Synthetic product-discount audit failure');
            }
        });
        try {
            app(ProductDiscountService::class)->transition($this->finance()->id, $request, 'approved');
            $this->fail('Discount committed without its audit.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic product-discount audit failure', $error->getMessage());
        }
        $this->assertDatabaseHas('products', ['id' => $id, 'discount_type' => 'none', 'discount_value' => '0.00'], 'pos');
        $this->assertDatabaseHas('product_discount_requests', ['request_id' => $request, 'status' => 'pending', 'decided_by' => null], 'pos');
        $this->assertDatabaseCount('application_audit_events', $auditCount, 'pos');
    }

    public function test_hr_admin_decision_uses_its_distinct_pos_mirror_and_catalog_view_escapes_reason(): void
    {
        $pos = $this->cashier();
        $pos->role = 'admin';
        $pos->save();
        $hrId = DB::connection('hrms')->table('users')->insertGetId(['id' => 910001, 'username' => $pos->username, 'full_name' => 'Synthetic HR Admin', 'password' => $pos->password, 'role' => 'admin', 'is_active' => 1]);
        $hr = HrUser::findOrFail($hrId);
        $id = $this->product();
        $request = app(ProductDiscountService::class)->request($this->inventory()->id, $this->data($id) + []);
        DB::connection('pos')->table('product_discount_requests')->where('request_id', $request)->update(['discount_reason' => '<script>Synthetic private reason</script>']);
        $this->actingAs($hr, 'hrms')->withSession(['staff_domain' => 'hrms', 'staff_mfa' => true, 'staff_password_signature' => hash('sha256', $hr->password), 'staff_activity' => time(), 'staff_session_version' => StaffSessionVersion::current('hrms', $hrId)]);
        $this->get('/product-discounts')->assertOk()->assertSee('&lt;script&gt;Synthetic private reason&lt;/script&gt;', false)->assertDontSee('<script>Synthetic private reason</script>', false);
        $this->postJson('/product-discounts/'.$request.'/decision', ['decision' => 'approved'])->assertOk();
        $this->assertNotSame($pos->id, $hrId);
        $this->assertDatabaseHas('product_discount_requests', ['request_id' => $request, 'decided_by' => $pos->id], 'pos');
    }
}
