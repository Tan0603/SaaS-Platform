<?php

namespace App\Actions\Payments;

use App\Data\PaymentData;
use App\Enums\InvoiceState;
use App\Exceptions\InvoiceNotPayableException;
use App\Exceptions\OverpaymentException;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class RecordPaymentAction
{
    /**
     * Overpayment layer 2: lock the invoice row, re-read the paid amount from the payments table, then write.
     * A concurrent payment on the same invoice waits for the lock and sees the updated balance.
     * (Layer 1 is StorePaymentRequest, layer 3 the CHECK amount_paid_cents <= total_cents.)
     * The invoice lookup is tenant-scoped, so another tenant's invoice is a 404.
     *
     * @throws InvoiceNotPayableException
     * @throws OverpaymentException
     */
    public function execute(int $invoiceId, PaymentData $data): Payment
    {
        return DB::transaction(function () use ($invoiceId, $data) {
            $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();

            if ($invoice->state !== InvoiceState::Issued) {
                throw InvoiceNotPayableException::make();
            }

            // Fresh from the payments table, not the stored copy on the invoice.
            $paid = (int) $invoice->payments()->active()->sum('amount_cents');
            $balance = $invoice->total_cents - $paid;

            if ($data->amountCents <= 0 || $data->amountCents > $balance) {
                throw OverpaymentException::make($balance);
            }

            $payment = $invoice->payments()->create([
                'amount_cents' => $data->amountCents,
                'paid_at' => $data->paidAt,
                'method' => $data->method,
                'reference' => $data->reference,
            ]);

            $invoice->recalculatePaymentState();

            // Phase 7: dispatch the optional "payment receipt" email after commit (idempotent per payment).

            return $payment;
        });
    }
}
