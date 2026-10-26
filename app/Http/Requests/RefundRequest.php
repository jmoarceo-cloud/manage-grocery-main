<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('staff')?->role === 'cashier';
    }

    public static function constraints(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*' => ['required', 'array:id,quantity'],
            'items.*.id' => ['required', 'integer', 'min:1', 'max:2147483647', 'distinct'], 'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'reason' => ['required', 'string', 'max:255'], 'supervisor_username' => ['required', 'string', 'max:50'], 'supervisor_password' => ['required', 'string', 'max:1024'],
        ];
    }

    public function rules(): array
    {
        return self::constraints();
    }
}
