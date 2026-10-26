<?php

namespace App\Services;

use App\Http\Requests\ProductDiscountRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductDiscountService
{
    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['discount' => $message]);
    }

    private function actor(int $id, array $roles): object
    {
        $actor = DB::connection('pos')->table('users')->where('id', $id)->whereIn('role', $roles)->where('is_active', 1)->where('is_locked', 0)->where('force_logout', 0)->lockForUpdate()->first();
        abort_unless($actor, 403);
        foreach (['locked_until', 'two_factor_locked_until'] as $field) {
            abort_if(! empty($actor->$field) && now()->lt($actor->$field), 403);
        }

        return $actor;
    }

    private function proposal(object $product, string $type, int $value): void
    {
        if (! $product->is_active) {
            $this->invalid('Choose an active product.');
        }
        if ($type === 'none') {
            if ($value !== 0) {
                $this->invalid('Use zero when removing a product discount.');
            }

            return;
        }
        $price = PosService::cents($product->price);
        if ($price <= 0 || $value <= 0 || ($type === 'percent' && $value > 10000) || ($type === 'fixed' && $value >= $price)) {
            $this->invalid('Use a positive discount: at most 100 percent, or a fixed amount below the selling price.');
        }
        // Retain the legacy earliest remaining batch expiry gate; never discount expired stock.
        $batches = DB::connection('pos')->table('product_batches')->where('product_id', $product->id)->where('quantity_remaining', '>', 0)->orderBy('id')->lockForUpdate()->get(['expiry_date']);
        $expiry = $batches->pluck('expiry_date')->filter()->min();
        if (($expiry && $expiry < now()->toDateString()) || ($batches->isEmpty() && $product->expiry_date && $product->expiry_date < now()->toDateString())) {
            $this->invalid('An expired product cannot receive a new discount.');
        }
    }

    public function request(int $actor, array $data): int
    {
        $data = Validator::make($data, ProductDiscountRequest::constraints())->validate();
        $db = DB::connection('pos');

        return $db->transaction(function () use ($db, $actor, $data): int {
            $this->actor($actor, ['admin', 'inventory_staff']);
            $product = $db->table('products')->where('id', $data['product_id'])->lockForUpdate()->first();
            abort_unless($product, 404);
            $value = PosService::cents($data['discount_value']);
            $reason = $data['discount_type'] === 'none' ? null : ($data['discount_reason'] ?? null);
            $this->proposal($product, $data['discount_type'], $value);
            if ($db->table('product_discount_requests')->where('product_id', $product->id)->where('status', 'pending')->exists()) {
                $this->invalid('This product already has a pending discount request.');
            }
            if ($product->discount_type === $data['discount_type'] && PosService::cents($product->discount_value) === $value && $product->discount_reason === $reason) {
                $this->invalid('The proposed discount matches the current product discount.');
            }
            $id = $db->table('product_discount_requests')->insertGetId(['product_id' => $product->id, 'requested_by' => $actor, 'discount_type' => $data['discount_type'], 'discount_value' => PosService::money($value), 'discount_reason' => $reason, 'price_at_request' => $product->price, 'old_discount_type' => $product->discount_type, 'old_discount_value' => $product->discount_value, 'old_discount_reason' => $product->discount_reason, 'status' => 'pending'], 'request_id');
            DomainAudit::record('product_discount_requests', $id, 'requested', ['product_id', 'discount_type', 'discount_value', 'status'], $actor);

            return $id;
        }, 3);
    }

    public function transition(int $actorId, int $id, string $decision, ?string $reason = null): void
    {
        $data = Validator::make(['decision' => $decision, 'reason' => $reason], ['decision' => ['required', 'in:approved,rejected,cancelled'], 'reason' => ['nullable', 'string', 'max:255']])->validate();
        $db = DB::connection('pos');
        $db->transaction(function () use ($db, $actorId, $id, $data): void {
            $actor = $this->actor($actorId, $data['decision'] === 'cancelled' ? ['admin', 'inventory_staff'] : ['admin', 'finance']);
            $productId = $db->table('product_discount_requests')->where('request_id', $id)->value('product_id');
            abort_unless($productId, 404);
            // Every native transition locks actor, product, request, then remaining batches.
            $product = $db->table('products')->where('id', $productId)->lockForUpdate()->first();
            $request = $db->table('product_discount_requests')->where('request_id', $id)->lockForUpdate()->first();
            abort_unless($request && (int) $request->product_id === (int) $productId, 404);
            if ($data['decision'] === 'cancelled') {
                abort_unless($actor->role === 'admin' || (int) $request->requested_by === $actorId, 403);
            }
            if ($request->status !== 'pending') {
                $this->invalid('Only pending product discount requests can change status.');
            }
            if ($data['decision'] === 'approved') {
                if (! $product) {
                    $this->invalid('The product is no longer available. Reject or cancel this request.');
                }
                if (PosService::cents($product->price) !== PosService::cents($request->price_at_request) || $product->discount_type !== $request->old_discount_type || PosService::cents($product->discount_value) !== PosService::cents($request->old_discount_value) || $product->discount_reason !== $request->old_discount_reason) {
                    $this->invalid('The price or existing discount changed. Cancel this request and submit a fresh proposal.');
                }
                abort_unless(in_array($request->discount_type, ['none', 'percent', 'fixed'], true), 422);
                $this->proposal($product, $request->discount_type, PosService::cents($request->discount_value));
                $db->table('products')->where('id', $product->id)->update(['discount_type' => $request->discount_type, 'discount_value' => $request->discount_value, 'discount_reason' => $request->discount_reason]);
                DomainAudit::record('products', (int) $product->id, 'discount_approved', ['discount_type', 'discount_value', 'discount_reason'], $actorId);
            }
            $db->table('product_discount_requests')->where('request_id', $id)->update(['status' => $data['decision'], 'reject_reason' => $data['decision'] === 'rejected' ? $data['reason'] : null, 'decided_by' => $actorId, 'decided_at' => now()]);
            DomainAudit::record('product_discount_requests', $id, $data['decision'], ['status', 'decided_by', 'decided_at', 'reject_reason'], $actorId);
        }, 3);
    }
}
