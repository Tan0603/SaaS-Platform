<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Customer::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // A plain `unique:customers` would be global; scope it to the current tenant.
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->where('tenant_id', tenant('id'))],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'registration_no' => ['nullable', 'string', 'max:100'],
            'payment_terms_days' => ['required', 'integer', Rule::in(Customer::PAYMENT_TERMS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'A customer with this email already exists (it may be archived).',
        ];
    }
}
