<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->attributes->get('staff')?->role, ['admin', 'inventory_staff'], true);
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return match ($this->route('entity')) {
            'products' => ['product_name' => ['required', 'string', 'max:100'], 'barcode' => ['nullable', 'string', 'max:50', Rule::unique('pos.products', 'barcode')->ignore($id)], 'category' => ['nullable', 'string', 'max:80', Rule::exists('pos.categories', 'category_name')], 'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'], 'cost_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'], 'supplier_id' => ['bail', 'nullable', 'integer', 'min:1', 'max:2147483647', Rule::exists('pos.suppliers', 'id')], 'is_active' => ['required', 'boolean'], 'stock' => ['prohibited'], 'discount_type' => ['prohibited'], 'discount_value' => ['prohibited'], 'discount_reason' => ['prohibited']],
            'categories' => ['category_name' => ['required', 'string', 'max:80', Rule::unique('pos.categories', 'category_name')->ignore($id)]],
            'suppliers' => ['company_name' => ['required', 'string', 'max:150'], 'contact_person' => ['nullable', 'string', 'max:150'], 'contact_number' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email:rfc', 'max:150'], 'address' => ['nullable', 'string', 'max:255'], 'payment_terms_days' => ['required', 'integer', 'min:0', 'max:3650'], 'status' => ['required', 'in:active,inactive'], 'notes' => ['nullable', 'string', 'max:5000']],
            default => abort(404),
        };
    }
}
