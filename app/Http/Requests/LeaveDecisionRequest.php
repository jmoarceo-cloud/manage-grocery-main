<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class LeaveDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('staff_domain') === 'hrms' && in_array($this->attributes->get('staff')?->role, ['admin', 'hr', 'hr_manager', 'supervisor'], true);
    }

    public static function constraints(): array
    {
        return ['decision' => ['required', 'in:approved,rejected'], 'revision' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'employee_id' => ['prohibited'], 'approved_by' => ['prohibited'], 'performed_by' => ['prohibited'], 'status' => ['prohibited'],
            'used_days' => ['prohibited'], 'allocated_days' => ['prohibited'], 'date_from' => ['prohibited'], 'date_to' => ['prohibited'], 'reason' => ['prohibited']];
    }

    public function rules(): array
    {
        return self::constraints();
    }

    protected function failedValidation(Validator $validator)
    {
        if (! $this->expectsJson()) {
            $this->session()->forget('_old_input');
            throw new HttpResponseException(redirect()->route('leave-approvals.index')->withErrors($validator));
        }
        parent::failedValidation($validator);
    }
}
