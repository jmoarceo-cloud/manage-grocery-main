<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class OwnProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('staff') !== null;
    }

    public static function constraints(array $data): array
    {
        return [
            'revision' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'current_password' => ['required', 'string', 'max:1024'],
            'email' => ['bail', 'required', 'string', 'max:100', 'regex:/\A[\x20-\x7E]+\z/', function ($field, $value, $fail) {
                if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $fail('Enter a valid workplace contact email.');
                }
            }],
            'email_confirmation' => ['required', 'string', 'same:email', 'max:100'],
            'contact_number_country' => ['required', 'string', Rule::in([...array_keys(config('profile.countries')), 'KEEP'])],
            'contact_number' => ['bail', 'nullable', 'string', 'max:40', function ($field, $value, $fail) use ($data) {
                $country = $data['contact_number_country'] ?? null;
                if ($country === 'KEEP' || ! is_string($country) || ! isset(config('profile.countries')[$country])) {
                    return;
                }
                $needed = config('profile.countries.'.$country.'.length');
                if (! preg_match('/\A[0-9 ().-]+\z/', $value) || strlen(preg_replace('/[^0-9]/', '', $value)) !== $needed) {
                    $fail('Enter exactly '.$needed.' digits for the selected contact country.');
                }
            }],
            'date_of_birth' => ['nullable', 'string', 'date_format:Y-m-d', 'before_or_equal:today'],
            'place_of_birth' => ['nullable', 'string', 'max:150'],
            'sex' => ['nullable', 'string', 'max:20'], 'civil_status' => ['nullable', 'string', 'max:20'],
            'home_address' => ['nullable', 'string', 'max:1000'],
            'highest_educational_attainment' => ['nullable', 'string', 'max:60'],
            'year_graduated' => ['nullable', 'string', 'regex:/\A[0-9]{4}\z/', 'integer', 'min:1950', 'max:'.now()->year],
            'employee_id' => ['prohibited'], 'user_id' => ['prohibited'], 'id' => ['prohibited'],
            'role' => ['prohibited'], 'status' => ['prohibited'], 'department' => ['prohibited'], 'position' => ['prohibited'],
            'basic_salary' => ['prohibited'], 'bank_account_number' => ['prohibited'], 'sss_number' => ['prohibited'],
        ];
    }

    public function rules(): array
    {
        return self::constraints($this->all());
    }

    protected function failedValidation(Validator $validator)
    {
        if (! $this->expectsJson()) {
            $this->session()->forget('_old_input');
            throw new HttpResponseException(redirect()->route('profile.edit')->withErrors($validator));
        }
        parent::failedValidation($validator);
    }
}
