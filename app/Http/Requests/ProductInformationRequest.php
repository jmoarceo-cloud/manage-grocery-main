<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->attributes->get('staff')?->role, ['admin', 'inventory_staff'], true);
    }

    public static function constraints(): array
    {
        $editable = ['nullable', 'string', 'present_if:action,save,submit,resubmit', 'prohibited_if:action,verify,reject'];

        return [
            'action' => ['required', 'in:save,submit,resubmit,verify,reject'],
            'revision' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'description' => [...$editable, 'max:5000'], 'brand' => [...$editable, 'max:100'],
            'unit_name' => [...$editable, 'max:50'], 'storage_notes' => [...$editable, 'max:5000'],
            'rejected_reason' => ['nullable', 'required_if:action,reject', 'prohibited_unless:action,reject', 'string', 'max:1000'],
            'review_status' => ['prohibited'], 'verified_by' => ['prohibited'], 'updated_by' => ['prohibited'], 'verified_at' => ['prohibited'],
            'ingredients' => ['prohibited'], 'allergens' => ['prohibited'], 'nutrition_source' => ['prohibited'], 'calories' => ['prohibited'],
        ];
    }

    public function rules(): array
    {
        return self::constraints();
    }
}
