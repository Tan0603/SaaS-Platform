<?php

namespace App\Http\Requests\Reports;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OutstandingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Invoice::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['overdue_only' => $this->boolean('overdue_only')]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // A plain `exists:customers,id` would ignore the tenant; scope it explicitly.
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('tenant_id', tenant('id'))],
            'overdue_only' => ['boolean'],
        ];
    }

    public function customerId(): ?int
    {
        return $this->filled('customer_id') ? (int) $this->input('customer_id') : null;
    }

    public function overdueOnly(): bool
    {
        return $this->boolean('overdue_only');
    }
}
