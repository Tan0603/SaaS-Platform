<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Mail\Mailable;

/**
 * Shared data for the invoice emails. Not ShouldQueue on purpose: SendInvoiceEmail (a queued job) sends them
 * inside the worker, in the right tenant context, and owns the retry / idempotency rules.
 *
 * Must be built while tenancy is initialised for the invoice's tenant (the job guarantees that).
 */
abstract class InvoiceMail extends Mailable
{
    public function __construct(public Invoice $invoice) {}

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        $currency = (string) tenant('currency');

        return [
            'company' => (string) tenant('name'),
            'customerName' => $this->invoice->customer->name,
            'number' => $this->invoice->number,
            'issueDate' => $this->invoice->issue_date->toFormattedDateString(),
            'dueDate' => $this->invoice->due_date->toFormattedDateString(),
            'total' => $currency.' '.Money::format($this->invoice->total_cents),
            'amountPaid' => $currency.' '.Money::format($this->invoice->amount_paid_cents),
            'balance' => $currency.' '.Money::format($this->invoice->balance_cents),
        ];
    }
}
