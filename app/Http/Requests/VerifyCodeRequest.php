<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->session()->has('mfa_pending');
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:32']];
    }
}
