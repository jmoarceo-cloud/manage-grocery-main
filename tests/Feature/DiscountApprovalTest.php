<?php

namespace Tests\Feature;

use App\Models\PosUser;
use App\Services\DiscountApprovalService;
use App\Services\PosService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DiscountApprovalTest extends IsolatedFeatureTestCase
{
    private function finance(): PosUser
    {
        return PosUser::where('username', 'demo_finance')->firstOrFail();
    }

    private function data(int $product): array
    {
        return ['items' => [['id' => $product, 'quantity' => 2]], 'discount_type' => 'percent', 'discount_value' => '10.00', 'reason' => 'Synthetic basket promotion'];
    }

    public function test_request_uses_current_server_prices_without_reserving_stock(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        DB::connection('pos')->table('products')->where('id', $id)->update(['discount_type' => 'percent', 'discount_value' => '10.00']);
        $this->staffSession($cashier)->postJson('/api/discount-requests', $this->data($id))->assertCreated()->assertJsonPath('status', 'pending');
        $this->assertDatabaseHas('discount_requests', ['cashier_id' => $cashier->id, 'subtotal_at_request' => '45.00', 'computed_discount_amount' => '4.50', 'status' => 'pending'], 'pos');
        $this->assertDatabaseHas('products', ['id' => $id, 'stock' => 10], 'pos');
        $this->assertDatabaseCount('stock_logs', 0, 'pos');
    }

    public function test_finance_approval_can_be_used_once_by_its_cashier_at_checkout(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        $service = app(DiscountApprovalService::class);
        $request = $service->request($cashier->id, $this->data($id));
        $this->staffSession($this->finance())->postJson('/discount-requests/'.$request.'/decision', ['decision' => 'approved'])->assertOk();
        $this->staffSession($cashier)->getJson('/api/discount-requests')->assertOk()->assertJsonPath('requests.0.request_id', $request);
        $cart = $this->cart($id);
        $cart['discount_type'] = 'approved';
        $cart['discount_request_id'] = $request;
        $receipt = app(PosService::class)->checkout($cashier->id, $cart);
        $this->assertSame('45.00', $receipt['total']);
        $this->getJson('/api/discount-requests')->assertJsonCount(0, 'requests');
        $cart['request_key'] = (string) Str::uuid();
        try {
            app(PosService::class)->checkout($cashier->id, $cart);
            $this->fail('Approved request was reused.');
        } catch (ValidationException $exception) {
            $this->assertDatabaseCount('sales', 1, 'pos');
        }
        $this->assertSame(8, (int) DB::connection('pos')->table('products')->where('id', $id)->value('stock'));
    }

    public function test_cashier_cannot_decide_or_cancel_another_cashiers_request(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        $other = DB::connection('pos')->table('users')->where('username', 'demo_cashier')->value('id');
        $request = app(DiscountApprovalService::class)->request($other, $this->data($id));
        $this->staffSession($cashier)->postJson('/discount-requests/'.$request.'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->postJson('/discount-requests/'.$request.'/cancel')->assertForbidden();
        $this->get('/discount-requests?status=all')->assertOk()->assertDontSee('Synthetic basket promotion');
        $this->assertDatabaseHas('discount_requests', ['request_id' => $request, 'status' => 'pending'], 'pos');
    }

    public function test_pending_request_is_unique_for_native_submissions_and_owner_can_cancel(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        $this->staffSession($cashier);
        $first = $this->postJson('/api/discount-requests', $this->data($id))->assertCreated()->json('request_id');
        $this->postJson('/api/discount-requests', $this->data($id))->assertUnprocessable();
        $this->postJson('/discount-requests/'.$first.'/cancel')->assertOk();
        $this->postJson('/discount-requests/'.$first.'/cancel')->assertUnprocessable();
        $this->staffSession($this->finance())->postJson('/discount-requests/'.$first.'/decision', ['decision' => 'approved'])->assertUnprocessable();
        $this->assertDatabaseHas('discount_requests', ['request_id' => $first, 'status' => 'cancelled'], 'pos');
    }

    public function test_tampered_subtotals_and_prices_are_rejected_and_finance_cannot_create_cashier_requests(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        $data = $this->data($id);
        $data['subtotal'] = '0.01';
        $this->staffSession($cashier)->postJson('/api/discount-requests', $data)->assertUnprocessable();
        unset($data['subtotal']);
        $data['items'][0]['price'] = '0.01';
        $this->postJson('/api/discount-requests', $data)->assertUnprocessable();
        $this->staffSession($this->finance())->postJson('/api/discount-requests', $this->data($id))->assertForbidden();
        $this->assertDatabaseCount('discount_requests', 0, 'pos');
    }

    public function test_rejection_records_finance_actor_and_cannot_be_overwritten(): void
    {
        $cashier = $this->cashier();
        $id = $this->product();
        $request = app(DiscountApprovalService::class)->request($cashier->id, $this->data($id));
        $finance = $this->finance();
        $this->staffSession($finance)->postJson('/discount-requests/'.$request.'/decision', ['decision' => 'rejected', 'reason' => 'Synthetic decision'])->assertOk();
        $this->postJson('/discount-requests/'.$request.'/decision', ['decision' => 'approved'])->assertUnprocessable();
        $this->assertDatabaseHas('discount_requests', ['request_id' => $request, 'status' => 'rejected', 'decided_by' => $finance->id, 'reject_reason' => 'Synthetic decision'], 'pos');
        $this->assertDatabaseCount('sales',0,'pos');
    }
}
