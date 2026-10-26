<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RestockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('staff')?->role === 'admin';
    }

    public function rules(): array
    {
        return ['product_id' => ['bail', 'required', 'integer', 'min:1', 'max:2147483647', 'exists:pos.products,id'], 'quantity' => ['required', 'integer', 'min:1', 'max:1000000'], 'cost_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'], 'expiry_date' => ['nullable', 'date'], 'reason' => ['required', 'string', 'max:255']];
    }
}
