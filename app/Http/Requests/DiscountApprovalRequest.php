<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DiscountApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('staff')?->role === 'cashier';
    }

    public static function constraints(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:id,quantity'],
            'items.*.id' => ['required', 'integer', 'min:1', 'max:2147483647', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'discount_type' => ['required', 'in:percent,fixed'],
            'discount_value' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'reason' => ['nullable', 'string', 'max:255'],
            'subtotal' => ['prohibited'], 'cashier_id' => ['prohibited'], 'status' => ['prohibited'],
        ];
    }

    public function rules(): array
    {
        return self::constraints();
    }
}
