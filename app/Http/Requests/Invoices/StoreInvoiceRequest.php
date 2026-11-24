<?php

namespace App\Http\Requests\Invoices;

use App\Models\Invoice;

class StoreInvoiceRequest extends InvoiceFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Invoice::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->invoiceRules();
    }
}
