<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangeOwnPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('staff') !== null;
    }

    public static function constraints(): array
    {
        return [
            'credential_type' => ['required', 'string', 'in:password,recovery'],
            'current_password' => ['nullable', 'required_if:credential_type,password', 'string', 'max:1024'],
            'backup_code' => ['nullable', 'required_if:credential_type,recovery', 'string', 'max:64'],
            'password' => ['bail', 'required', 'string', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols(), 'not_regex:/[\x00-\x1F\x7F]/', function ($attribute, $value, $fail) {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('This password is too long. Use a shorter password.');
                }
            }],
            'password_confirmation' => ['required', 'string'],
            'user_id' => ['prohibited'], 'domain' => ['prohibited'], 'role' => ['prohibited'],
        ];
    }

    public function rules(): array
    {
        return self::constraints();
    }
}
