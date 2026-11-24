<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/** Second line of defence behind the tenant scope. Recording a payment is `InvoicePolicy::recordPayment`. */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null;
    }

    /** Only active payments can be voided (B8). The action re-checks under the row lock. */
    public function void(User $user, Payment $payment): bool
    {
        return $user->tenant_id !== null
            && $user->tenant_id === $payment->tenant_id
            && $payment->voided_at === null;
    }
}
