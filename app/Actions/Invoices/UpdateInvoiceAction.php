<?php

namespace App\Actions\Invoices;

use App\Data\InvoiceData;
use App\Enums\InvoiceState;
use App\Models\Invoice;
use App\Services\InvoiceCalculator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class UpdateInvoiceAction
{
    use WritesInvoiceItems;

    public function __construct(
        private readonly InvoiceCalculator $calculator,
        private readonly IssueInvoiceAction $issueInvoice,
    ) {}

    /**
     * Replaces the header and all items and recalculates totals. The invoice row is locked and the edit rule
     * (B4) is re-checked under the lock, so a payment recorded a moment ago cannot slip past the policy.
     *
     * @throws AuthorizationException
     */
    public function execute(Invoice $invoice, InvoiceData $data, bool $issue = false): Invoice
    {
        return DB::transaction(function () use ($invoice, $data, $issue) {
            $invoice = Invoice::whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if (! $invoice->canBeEdited()) {
                throw new AuthorizationException('This invoice can no longer be edited.');
            }

            $invoice->fill([
                'customer_id' => $data->customerId,
                'issue_date' => $data->issueDate,
                'due_date' => $data->dueDate,
            ])->save();

            $this->writeItems($invoice, $data, $this->calculator);

            // The total may have changed: keep amount_paid / status consistent through the one writer.
            $invoice->recalculatePaymentState();

            return $issue && $invoice->state === InvoiceState::Draft
                ? $this->issueInvoice->execute($invoice)
                : $invoice;
        });
    }
}
