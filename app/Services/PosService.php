<?php

namespace App\Services;

use App\Http\Requests\CheckoutRequest;
use App\Http\Requests\RefundRequest;
use App\Models\HrUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PosService
{
    public static function cents(string|int|float $value): int
    {
        if (! preg_match('/\A(\d+)(?:\.(\d{1,2}))?\z/', (string) $value, $match)) {
            throw ValidationException::withMessages(['payment' => 'A valid monetary value is required.']);
        }

        return ((int) $match[1]) * 100 + (int) str_pad($match[2] ?? '', 2, '0');
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public static function unitPriceCents(object $product): int
    {
        $price = self::cents($product->price);
        $discountValue = self::cents($product->discount_value ?? '0');
        if ($product->discount_type === 'percent') {
            return max(0, $price - (int) round($price * min($discountValue, 10000) / 10000));
        }
        if ($product->discount_type === 'fixed') {
            return max(0, $price - $discountValue);
        }

        return $price;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['items' => $message]);
    }

    private function cashier(int $id): void
    {
        $user = DB::connection('pos')->table('users')->where('id', $id)->where('role', 'cashier')->where('is_active', 1)->where('is_locked', 0)->where('force_logout', 0)->lockForUpdate()->first();
        abort_unless($user, 403);
    }

    public function approver(array $data): string
    {
        $key = 'supervisor-approval:'.hash('sha256', ($data['supervisor_username'] ?? '').'|'.request()->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->invalid('Approval could not be verified. Please try again later.');
        }
        $user = HrUser::whereRaw('BINARY username = ?', [$data['supervisor_username'] ?? ''])->whereIn('role', ['supervisor', 'admin'])->first();
        if (! $user || ! Hash::check($data['supervisor_password'] ?? '', $user->password) || ! StaffAuthentication::eligible($user, 'hrms')) {
            RateLimiter::hit($key, 900);
            $this->invalid('Approval could not be verified.');
        }
        RateLimiter::clear($key);

        return $user->full_name;
    }

    public function checkout(int $cashierId, array $data): array
    {
        $data = Validator::make($data, CheckoutRequest::constraints())->validate();
        if (! in_array($data['discount_type'], ['senior', 'pwd'], true)) {
            foreach (['discount_id_number', 'discount_holder_name', 'supervisor_username', 'supervisor_password'] as $field) {
                unset($data[$field]);
            }
        }
        if ($data['discount_type'] !== 'approved') {
            unset($data['discount_request_id']);
        }
        $items = collect($data['items'])->map(fn ($item) => ['id' => (int) $item['id'], 'quantity' => (int) $item['quantity']])->sortBy('id')->values()->all();
        $payload = hash('sha256', json_encode(['items' => $items, 'payment' => self::cents($data['payment']), 'discount' => $data['discount_type'], 'discount_request_id' => $data['discount_request_id'] ?? null, 'discount_id' => $data['discount_id_number'] ?? null, 'discount_holder' => $data['discount_holder_name'] ?? null], JSON_THROW_ON_ERROR));
        $db = DB::connection('pos');
        $saleId = $db->transaction(function () use ($db, $cashierId, $data, $items, $payload): int {
            $this->cashier($cashierId);
            $existing = $db->table('laravel_checkout_requests')->where('cashier_id', $cashierId)->where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $payload), 409, 'This checkout key was used for another cart.');
                if (! $existing->sale_id) {
                    $this->invalid('This checkout is still being processed.');
                }

                return (int) $existing->sale_id;
            }
            $requestId = $db->table('laravel_checkout_requests')->insertGetId(['cashier_id' => $cashierId, 'request_key' => $data['request_key'], 'payload_hash' => $payload]);
            $products = $db->table('products')->whereIn('id', array_column($items, 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $subtotal = 0;
            $prepared = [];
            foreach ($items as $item) {
                $product = $products->get($item['id']);
                if (! $product || ! $product->is_active) {
                    $this->invalid('An item is no longer available.');
                }
                $quantity = $item['quantity'];
                if ($product->stock < $quantity) {
                    $this->invalid('Insufficient stock for an item in the cart.');
                }
                $allBatches = $db->table('product_batches')->where('product_id', $product->id)->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('date_received')->orderBy('id')->lockForUpdate()->get();
                $sellable = $allBatches->filter(fn ($batch) => $batch->quantity_remaining > 0 && (! $batch->expiry_date || $batch->expiry_date >= now()->toDateString()));
                if (($allBatches->isNotEmpty() && $sellable->sum('quantity_remaining') < $quantity) || ($allBatches->isEmpty() && $product->expiry_date && $product->expiry_date < now()->toDateString())) {
                    $this->invalid('An item has insufficient non-expired stock.');
                }
                $price = self::unitPriceCents($product);
                $line = $price * $quantity;
                $subtotal += $line;
                $prepared[] = ['product' => $product, 'quantity' => $quantity, 'subtotal' => $line, 'batches' => $sellable, 'unbatched' => $allBatches->isEmpty()];
            }
            if ($subtotal > 9999999999) {
                $this->invalid('This sale exceeds the supported transaction amount.');
            }
            $discount = 0;
            $approver = null;
            $discountRequestId = null;
            if (in_array($data['discount_type'], ['senior', 'pwd'], true)) {
                $approver = $this->approver($data);
                $discount = (int) round($subtotal * 0.2);
            } elseif ($data['discount_type'] === 'approved') {
                $approval = $db->table('discount_requests')->where('request_id', $data['discount_request_id'])->where('cashier_id', $cashierId)->where('status', 'approved')->lockForUpdate()->first();
                if (! $approval || self::cents($approval->subtotal_at_request) !== $subtotal || $db->table('laravel_checkout_requests')->where('discount_request_id', $approval->request_id)->exists()) {
                    $this->invalid('The approved discount does not match this cart or has already been used.');
                }
                $discount = $approval->discount_type === 'percent' ? (int) round($subtotal * min(self::cents($approval->discount_value), 10000) / 10000) : min($subtotal, self::cents($approval->discount_value));
                $discountRequestId = $approval->request_id;
            }
            $total = $subtotal - $discount;
            $payment = self::cents($data['payment']);
            if ($payment < $total || $payment > (int) ceil($total / 100000) * 100000) {
                $this->invalid('Check the amount received; it must cover the total without exceeding the next 1,000-peso bill step.');
            }
            $sale = $db->table('sales')->insertGetId(['cashier_id' => $cashierId, 'total_amount' => self::money($total), 'discount_amount' => self::money($discount), 'discount_id_number' => $data['discount_id_number'] ?? null, 'discount_holder_name' => $data['discount_holder_name'] ?? null, 'discount_approved_by_name' => $approver, 'payment' => self::money($payment), 'change_amount' => self::money($payment - $total), 'status' => 'completed']);
            foreach ($prepared as $line) {
                $product = $line['product'];
                $quantity = $line['quantity'];
                $detail = $db->table('sales_details')->insertGetId(['sale_id' => $sale, 'product_id' => $product->id, 'quantity' => $quantity, 'subtotal' => self::money($line['subtotal'])]);
                $remaining = $quantity;
                foreach ($line['batches'] as $batch) {
                    $take = min($remaining, $batch->quantity_remaining);
                    if ($take <= 0) {
                        continue;
                    }$db->table('product_batches')->where('id', $batch->id)->decrement('quantity_remaining', $take);
                    $db->table('sale_batch_consumption')->insert(['sales_detail_id' => $detail, 'batch_id' => $batch->id, 'quantity' => $take]);
                    $remaining -= $take;
                    if ($remaining === 0) {
                        break;
                    }
                }
                if ($remaining > 0) {
                    if (! $line['unbatched']) {
                        $this->invalid('Batch allocation failed.');
                    }$db->table('sale_batch_consumption')->insert(['sales_detail_id' => $detail, 'batch_id' => null, 'quantity' => $remaining]);
                }
                $db->table('products')->where('id', $product->id)->decrement('stock', $quantity);
                $db->table('stock_logs')->insert(['product_id' => $product->id, 'sale_id' => $sale, 'change_type' => 'sale', 'quantity_change' => -$quantity, 'previous_stock' => $product->stock, 'new_stock' => $product->stock - $quantity, 'reason' => 'Sale #'.$sale, 'user_id' => $cashierId]);
            }
            $db->table('laravel_checkout_requests')->where('id', $requestId)->update(['sale_id' => $sale, 'discount_request_id' => $discountRequestId]);
            DomainAudit::record('sales', $sale, 'created', ['items', 'total_amount', 'discount_amount', 'payment', 'stock'], $cashierId);

            return $sale;
        }, 3);

        return $this->receipt($saleId);
    }

    public function receipt(int $id): array
    {
        $db = DB::connection('pos');
        $sale = $db->table('sales')->where('id', $id)->first();
        abort_unless($sale, 404);
        $items = $db->table('sales_details as sd')->join('products as p', 'p.id', '=', 'sd.product_id')->where('sd.sale_id', $id)->select('sd.id', 'sd.quantity', 'sd.subtotal', 'p.product_name')->get();

        return ['sale_id' => (int) $sale->id, 'receipt_no' => 'GO-'.date('Ymd', strtotime($sale->transaction_date)).'-'.str_pad((string) $sale->id, 8, '0', STR_PAD_LEFT), 'date' => $sale->transaction_date, 'subtotal' => self::money((int) $items->sum(fn ($item) => self::cents($item->subtotal))), 'discount' => $sale->discount_amount, 'total' => $sale->total_amount, 'payment' => $sale->payment, 'change' => $sale->change_amount, 'status' => $sale->status, 'items' => $items->map(fn ($item) => (array) $item)->all()];
    }

    public function refund(int $cashierId, int $saleId, array $data): array
    {
        $data = Validator::make($data, RefundRequest::constraints())->validate();
        $approver = $this->approver($data);
        $db = DB::connection('pos');

        return $db->transaction(function () use ($cashierId, $saleId, $data, $approver, $db): array {
            $this->cashier($cashierId);
            $sale = $db->table('sales')->where('id', $saleId)->lockForUpdate()->first();
            if (! $sale || ! in_array($sale->status, ['completed', 'partial_refunded'], true)) {
                $this->invalid('This sale cannot be refunded.');
            }
            $lines = $db->table('sales_details')->where('sale_id', $saleId)->orderBy('product_id')->lockForUpdate()->get()->keyBy('id');
            $products = $db->table('products')->whereIn('id', $lines->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $refunded = $db->table('sale_refunds')->where('sale_id', $saleId)->get();
            $totalSubtotal = (int) $lines->sum(fn ($line) => self::cents($line->subtotal));
            $totalNet = self::cents($sale->total_amount);
            $refundAmount = 0;
            foreach ($data['items'] as $item) {
                $line = $lines->get($item['id']);
                if (! $line) {
                    $this->invalid('A returned item is not part of this sale.');
                }$quantity = (int) $item['quantity'];
                $already = (int) $refunded->where('sales_detail_id', $line->id)->sum('quantity');
                if ($quantity > $line->quantity - $already) {
                    $this->invalid('Return quantity exceeds the remaining purchased quantity.');
                }
                $amount = $totalSubtotal > 0 ? (int) round(self::cents($line->subtotal) * $quantity / $line->quantity * $totalNet / $totalSubtotal) : 0;
                $pastAmount = (int) $refunded->sum(fn ($entry) => self::cents($entry->amount));
                $remainingUnits = (int) $lines->sum('quantity') - (int) $refunded->sum('quantity');
                if ($remainingUnits === $quantity) {
                    $amount = $totalNet - $pastAmount;
                }$amount = min($amount, $totalNet - $pastAmount);
                $product = $products->get($line->product_id);
                $before = (int) $product->stock;
                $db->table('products')->where('id', $product->id)->increment('stock', $quantity);
                $product->stock += $quantity;
                $allocations = $db->table('sale_batch_consumption')->where('sales_detail_id', $line->id)->orderBy('id')->lockForUpdate()->get();
                $skip = $already;
                $remaining = $quantity;
                foreach ($allocations as $allocation) {
                    $ignored = min($skip, $allocation->quantity);
                    $skip -= $ignored;
                    $available = $allocation->quantity - $ignored;
                    $take = min($remaining, $available);
                    if ($take <= 0) {
                        continue;
                    }if ($allocation->batch_id) {
                        $db->table('product_batches')->where('id', $allocation->batch_id)->increment('quantity_remaining', $take);
                    }$remaining -= $take;
                    if ($remaining === 0) {
                        break;
                    }
                }
                $entry = ['sale_id' => $saleId, 'sales_detail_id' => $line->id, 'product_id' => $product->id, 'quantity' => $quantity, 'amount' => self::money($amount), 'reason' => $data['reason'], 'refunded_by' => $cashierId, 'approved_by_name' => $approver];
                $db->table('sale_refunds')->insert($entry);
                $refunded->push((object) $entry);
                $db->table('stock_logs')->insert(['product_id' => $product->id, 'sale_id' => $saleId, 'change_type' => 'refund', 'quantity_change' => $quantity, 'previous_stock' => $before, 'new_stock' => $before + $quantity, 'reason' => 'Refund #'.$saleId, 'user_id' => $cashierId]);
                $refundAmount += $amount;
            }
            $status = (int) $refunded->sum('quantity') === (int) $lines->sum('quantity') ? 'refunded' : 'partial_refunded';
            $db->table('sales')->where('id', $saleId)->update(['status' => $status, 'refund_reason' => $data['reason'], 'refunded_at' => now(), 'refunded_by' => $cashierId, 'refund_approved_by_name' => $approver]);
            DomainAudit::record('sales', $saleId, 'refunded', ['status', 'items', 'stock'], $cashierId);

            return ['sale_id' => $saleId, 'status' => $status, 'amount' => self::money($refundAmount)];
        }, 3);
    }

    public function void(int $cashierId, int $saleId): array
    {
        $db = DB::connection('pos');

        return $db->transaction(function () use ($db, $cashierId, $saleId): array {
            $this->cashier($cashierId);
            $sale = $db->table('sales')->where('id', $saleId)->lockForUpdate()->first();
            if (! $sale || $sale->status !== 'completed' || (int) $sale->cashier_id !== $cashierId || substr($sale->transaction_date, 0, 10) !== now()->toDateString()) {
                $this->invalid('Only your own completed transactions from today can be voided.');
            }
            if ($db->table('sale_refunds')->where('sale_id', $saleId)->exists()) {
                $this->invalid('A returned transaction cannot be voided.');
            }
            $lines = $db->table('sales_details')->where('sale_id', $saleId)->orderBy('product_id')->lockForUpdate()->get();
            $products = $db->table('products')->whereIn('id', $lines->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $line) {
                $product = $products->get($line->product_id);
                if (! $product || $product->stock + $line->quantity > 2147483647) {
                    $this->invalid('The original product cannot safely receive the returned stock.');
                }
                $allocations = $db->table('sale_batch_consumption')->where('sales_detail_id', $line->id)->orderBy('id')->lockForUpdate()->get();
                if ($allocations->isNotEmpty() && (int) $allocations->sum('quantity') !== (int) $line->quantity) {
                    $this->invalid('The original batch allocation needs reconciliation before voiding.');
                }
                foreach ($allocations as $allocation) {
                    if ($allocation->batch_id) {
                        $batch = $db->table('product_batches')->where('id', $allocation->batch_id)->where('product_id', $product->id)->lockForUpdate()->first();
                        if (! $batch || $batch->quantity_remaining + $allocation->quantity > 2147483647) {
                            $this->invalid('The original batch cannot safely receive the returned stock.');
                        }
                        $db->table('product_batches')->where('id', $batch->id)->increment('quantity_remaining', $allocation->quantity);
                    }
                }
                $before = (int) $product->stock;
                $product->stock += $line->quantity;
                $db->table('products')->where('id', $product->id)->increment('stock', $line->quantity);
                $db->table('stock_logs')->insert(['product_id' => $product->id, 'sale_id' => $saleId, 'change_type' => 'void', 'quantity_change' => $line->quantity, 'previous_stock' => $before, 'new_stock' => $product->stock, 'reason' => 'Voided Sale #'.$saleId, 'user_id' => $cashierId]);
            }
            $db->table('sales')->where('id', $saleId)->update(['status' => 'voided']);
            DomainAudit::record('sales', $saleId, 'voided', ['status', 'stock', 'batches'], $cashierId);

            return ['sale_id' => $saleId, 'status' => 'voided'];
        }, 3);
    }
}
