<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class OvertimeDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('staff_domain') === 'hrms' && in_array($this->attributes->get('staff')?->role, ['admin', 'supervisor'], true);
    }

    public static function constraints(): array
    {
        return ['decision' => ['required', 'in:approved,rejected'], 'revision' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'employee_id' => ['prohibited'], 'approved_by' => ['prohibited'], 'status' => ['prohibited'], 'ot_date' => ['prohibited'],
            'hours_requested' => ['prohibited'], 'reason' => ['prohibited'], 'actual_ot_hours' => ['prohibited'], 'worked_hours' => ['prohibited']];
    }

    public function rules(): array
    {
        return self::constraints();
    }

    protected function failedValidation(Validator $validator)
    {
        if (! $this->expectsJson()) {
            $this->session()->forget('_old_input');
            throw new HttpResponseException(redirect()->route('overtime-approvals.index')->withErrors($validator));
        }
        parent::failedValidation($validator);
    }
}
