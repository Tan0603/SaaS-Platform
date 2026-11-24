<?php

namespace App\Actions\Invoices;

use App\Data\InvoiceData;
use App\Enums\InvoiceState;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\InvoiceCalculator;
use Illuminate\Support\Facades\DB;

class CreateInvoiceAction
{
    use WritesInvoiceItems;

    public function __construct(
        private readonly InvoiceCalculator $calculator,
        private readonly IssueInvoiceAction $issueInvoice,
    ) {}

    /** Creates a draft (no number yet); with $issue = true it is issued in the same transaction. */
    public function execute(InvoiceData $data, bool $issue = false): Invoice
    {
        return DB::transaction(function () use ($data, $issue) {
            $invoice = new Invoice([
                'customer_id' => $data->customerId,
                'issue_date' => $data->issueDate,
                'due_date' => $data->dueDate,
            ]);
            $invoice->forceFill([
                'state' => InvoiceState::Draft,
                'status' => InvoiceStatus::Unpaid,
                'subtotal_cents' => 0,
                'total_cents' => 0,
                'amount_paid_cents' => 0,
            ])->save();

            $this->writeItems($invoice, $data, $this->calculator);

            return $issue ? $this->issueInvoice->execute($invoice) : $invoice;
        });
    }
}
