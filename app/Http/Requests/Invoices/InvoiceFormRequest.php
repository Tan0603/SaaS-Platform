<?php

namespace App\Http\Requests\Invoices;

use App\Data\InvoiceData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared rules for creating and updating an invoice. `subtotal`, `total` and line totals are deliberately
 * absent: anything the browser sends for them is dropped, the server recalculates everything.
 */
abstract class InvoiceFormRequest extends FormRequest
{
    /**
     * The customer must belong to this tenant and must not be archived. A plain `exists:customers,id` would
     * bypass the tenant scope, so the tenant is part of the rule.
     *
     * @return array<int, mixed>
     */
    protected function customerRules(?int $keepCustomerId = null): array
    {
        return [
            'required',
            'integer',
            Rule::exists('customers', 'id')
                ->where('tenant_id', tenant('id'))
                ->where(function ($query) use ($keepCustomerId) {
                    $query->whereNull('deleted_at');

                    // An existing invoice may keep its (since archived) customer.
                    if ($keepCustomerId !== null) {
                        $query->orWhere('id', $keepCustomerId);
                    }
                }),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function invoiceRules(?int $keepCustomerId = null): array
    {
        return [
            'customer_id' => $this->customerRules($keepCustomerId),
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'action' => ['nullable', Rule::in(['save', 'issue'])],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000', 'decimal:0,2'],
            // Digits only, at most 2 decimals, at most 7 integer digits; with the quantity cap, 100 lines cannot overflow bigint.
            'items.*.unit_price' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_id.exists' => 'Choose an active customer of your company.',
            'items.required' => 'Add at least one item.',
            'items.min' => 'Add at least one item.',
            'items.*.unit_price.regex' => 'Enter a valid price (for example 125.50).',
            'items.*.quantity.gt' => 'Quantity must be greater than zero.',
            'items.*.quantity.decimal' => 'Quantity can have at most 2 decimals.',
        ];
    }

    public function toData(): InvoiceData
    {
        return InvoiceData::fromArray($this->validated());
    }

    /** True for the "Save & issue" button. */
    public function shouldIssue(): bool
    {
        return $this->input('action') === 'issue';
    }
}
