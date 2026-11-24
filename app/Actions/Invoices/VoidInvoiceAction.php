<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceState;
use App\Models\Invoice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class VoidInvoiceAction
{
    /**
     * Issued -> void. Keeps the number and the history; requires that every payment was voided first (B2).
     * The check is repeated under the row lock so a concurrent payment cannot be orphaned.
     *
     * @throws AuthorizationException
     */
    public function execute(Invoice $invoice, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason) {
            $invoice = Invoice::whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if (! $invoice->canBeVoided()) {
                throw new AuthorizationException('This invoice cannot be voided.');
            }

            $invoice->forceFill([
                'state' => InvoiceState::Void,
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            return $invoice;
        });
    }
}
