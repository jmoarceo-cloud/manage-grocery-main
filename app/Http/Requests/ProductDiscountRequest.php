<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->attributes->get('staff')?->role, ['admin', 'inventory_staff'], true);
    }

    public static function constraints(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'discount_type' => ['required', 'in:none,percent,fixed'],
            'discount_value' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'price' => ['prohibited'], 'price_at_request' => ['prohibited'], 'requested_by' => ['prohibited'], 'status' => ['prohibited'], 'decided_by' => ['prohibited'],
        ];
    }

    public function rules(): array
    {
        return self::constraints();
    }
}
