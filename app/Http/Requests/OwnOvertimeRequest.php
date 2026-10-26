<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class OwnOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('staff') !== null;
    }

    public static function constraints(): array
    {
        return ['ot_date' => ['bail', 'required', 'string', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:'.CarbonImmutable::today('Asia/Manila')->toDateString()],
            'hours_requested' => ['bail', 'required', 'regex:/\A(?:0|[1-9][0-9]?)(?:\.[0-9]{1,2})?\z/', 'numeric', 'min:0.01', 'max:12'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'employee_id' => ['prohibited'], 'user_id' => ['prohibited'], 'id' => ['prohibited'], 'ot_id' => ['prohibited'],
            'status' => ['prohibited'], 'approved_by' => ['prohibited'], 'requested_at' => ['prohibited'], 'decided_at' => ['prohibited'],
            'actual_ot_hours' => ['prohibited'], 'worked_hours' => ['prohibited']];
    }

    public function rules(): array
    {
        return self::constraints();
    }

    protected function failedValidation(Validator $validator)
    {
        if (! $this->expectsJson()) {
            $this->session()->forget('_old_input');
            throw new HttpResponseException(redirect()->route('overtime.index')->withErrors($validator));
        }
        parent::failedValidation($validator);
    }
}
