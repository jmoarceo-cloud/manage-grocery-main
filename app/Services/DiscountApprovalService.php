<?php

namespace App\Services;

use App\Http\Requests\DiscountApprovalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DiscountApprovalService
{
    private function actor(int $id, array $roles): void
    {
        $user = DB::connection('pos')->table('users')->where('id', $id)->whereIn('role', $roles)->where('is_active', 1)->where('is_locked', 0)->where('force_logout', 0)->lockForUpdate()->first();
        abort_unless($user, 403);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['discount' => $message]);
    }

    public function request(int $cashier, array $data): int
    {
        $data = Validator::make($data, DiscountApprovalRequest::constraints())->validate();
        $value = PosService::cents($data['discount_value']);
        if ($data['discount_type'] === 'percent' && $value > 10000) {
            $this->invalid('The percentage cannot exceed 100.');
        }
        $db = DB::connection('pos');

        return $db->transaction(function () use ($db, $cashier, $data, $value): int {
            $this->actor($cashier, ['cashier']);
            if ($db->table('discount_requests')->where('cashier_id', $cashier)->where('status', 'pending')->exists()) {
                $this->invalid('A discount request is already awaiting Finance. Cancel it or wait for a decision.');
            }
            $products = $db->table('products')->whereIn('id', array_column($data['items'], 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $subtotal = 0;
            foreach ($data['items'] as $item) {
                $product = $products->get($item['id']);
                if (! $product || ! $product->is_active) {
                    $this->invalid('An item is no longer available.');
                }
                $subtotal += PosService::unitPriceCents($product) * (int) $item['quantity'];
            }
            if ($subtotal <= 0 || $subtotal > 9999999999) {
                $this->invalid('A supported, positive basket subtotal is required.');
            }
            $discount = $data['discount_type'] === 'percent' ? (int) round($subtotal * $value / 10000) : min($subtotal, $value);
            $id = $db->table('discount_requests')->insertGetId(['cashier_id' => $cashier, 'discount_type' => $data['discount_type'], 'discount_value' => PosService::money($value), 'subtotal_at_request' => PosService::money($subtotal), 'computed_discount_amount' => PosService::money($discount), 'reason' => $data['reason'] ?? null, 'status' => 'pending']);
            DomainAudit::record('discount_requests', $id, 'requested', ['discount_type', 'discount_value', 'subtotal_at_request', 'status'], $cashier);

            return $id;
        }, 3);
    }

    public function cancel(int $cashier, int $id): void
    {
        $db = DB::connection('pos');
        $db->transaction(function () use ($db, $cashier, $id): void {
            $this->actor($cashier, ['cashier']);
            $request = $db->table('discount_requests')->where('request_id', $id)->lockForUpdate()->first();
            abort_unless($request, 404);
            abort_unless((int) $request->cashier_id === $cashier, 403);
            if ($request->status !== 'pending') {
                $this->invalid('Only pending requests can be cancelled.');
            }
            $db->table('discount_requests')->where('request_id', $id)->update(['status' => 'cancelled']);
            DomainAudit::record('discount_requests', $id, 'cancelled', ['status'], $cashier);
        }, 3);
    }

    public function decide(int $actor, int $id, string $decision, ?string $reason): void
    {
        $data = Validator::make(['decision' => $decision, 'reason' => $reason], ['decision' => ['required', 'in:approved,rejected'], 'reason' => ['nullable', 'string', 'max:255']])->validate();
        $db = DB::connection('pos');
        $db->transaction(function () use ($db, $actor, $id, $data): void {
            $this->actor($actor, ['admin', 'finance']);
            $request = $db->table('discount_requests')->where('request_id', $id)->lockForUpdate()->first();
            abort_unless($request, 404);
            if ($request->status !== 'pending') {
                $this->invalid('Only pending requests can receive a Finance decision.');
            }
            $db->table('discount_requests')->where('request_id', $id)->update(['status' => $data['decision'], 'reject_reason' => $data['decision'] === 'rejected' ? $data['reason'] : null, 'decided_by' => $actor, 'decided_at' => now()]);
            DomainAudit::record('discount_requests',$id,$data['decision'],['status', 'decided_by', 'decided_at', 'reject_reason'],$actor);
        }, 3);
    }
}
