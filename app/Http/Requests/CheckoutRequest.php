<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('staff')?->role === 'cashier';
    }

    public static function constraints(): array
    {
        return [
            'request_key' => ['required', 'uuid'], 'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:id,quantity'], 'items.*.id' => ['required', 'integer', 'min:1', 'max:2147483647', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'], 'payment' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'discount_type' => ['required', 'in:none,senior,pwd,approved'], 'discount_id_number' => ['nullable', 'required_if:discount_type,senior,pwd', 'regex:/^[0-9-]{1,20}$/'],
            'discount_holder_name' => ['nullable', 'string', 'max:150'], 'supervisor_username' => ['nullable', 'required_if:discount_type,senior,pwd', 'string', 'max:50'],
            'supervisor_password' => ['nullable', 'required_if:discount_type,senior,pwd', 'string', 'max:1024'],
            'discount_request_id' => ['nullable', 'required_if:discount_type,approved', 'integer', 'min:1', 'max:2147483647'], 'cashier_id' => ['prohibited'],
        ];
    }

    public function rules(): array
    {
        return self::constraints();
    }
}
