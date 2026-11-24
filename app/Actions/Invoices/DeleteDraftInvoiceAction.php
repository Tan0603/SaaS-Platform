<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceState;
use App\Models\Invoice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class DeleteDraftInvoiceAction
{
    /**
     * Only drafts are deleted (they have no number, so no gap appears). Items go with it (FK cascade).
     *
     * @throws AuthorizationException
     */
    public function execute(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($invoice->state !== InvoiceState::Draft) {
                throw new AuthorizationException('Only a draft invoice can be deleted. Void it instead.');
            }

            $invoice->delete();
        });
    }
}
