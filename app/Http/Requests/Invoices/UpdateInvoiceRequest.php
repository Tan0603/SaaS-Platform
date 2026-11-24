<?php

namespace App\Http\Requests\Invoices;

use App\Models\Invoice;

class UpdateInvoiceRequest extends InvoiceFormRequest
{
    /** 403 when the invoice is locked (issued with active payments, or void). */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('invoice')) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->route('invoice');

        return $this->invoiceRules($invoice->customer_id);
    }
}
