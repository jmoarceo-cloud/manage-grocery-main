<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class OwnLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('staff') !== null;
    }

    public static function constraints(): array
    {
        return [
            'leave_type' => ['required', 'string', Rule::in(array_keys(config('leave.types')))],
            'date_from' => ['bail', 'required', 'string', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', 'date_format:Y-m-d', 'after_or_equal:'.CarbonImmutable::today('Asia/Manila')->toDateString()],
            'date_to' => ['bail', 'required', 'string', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'employee_id' => ['prohibited'], 'user_id' => ['prohibited'], 'id' => ['prohibited'], 'leave_id' => ['prohibited'],
            'status' => ['prohibited'], 'approved_by' => ['prohibited'], 'decided_at' => ['prohibited'], 'requested_at' => ['prohibited'],
            'allocated_days' => ['prohibited'], 'used_days' => ['prohibited'],
        ];
    }

    public function rules(): array
    {
        return self::constraints();
    }

    protected function failedValidation(Validator $validator)
    {
        if (! $this->expectsJson()) {
            $this->session()->forget('_old_input');
            throw new HttpResponseException(redirect()->route('leave.index')->withErrors($validator));
        }
        parent::failedValidation($validator);
    }
}
