<?php

namespace App\Actions\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class VoidPaymentAction
{
    /**
     * B8: payments are voided, never deleted. The invoice row is locked FIRST (same order as RecordPaymentAction,
     * so the two can never deadlock), then the payment; the invoice status is recalculated afterwards.
     *
     * @throws AuthorizationException
     */
    public function execute(Payment $payment, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $invoice = Invoice::whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($payment->voided_at !== null) {
                throw new AuthorizationException('This payment is already voided.');
            }

            $payment->forceFill([
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            $invoice->recalculatePaymentState();

            // Phase 8: forget the tenant's dashboard cache here (or via an observer).

            return $payment;
        });
    }
}
