<?php

namespace App\Policies;

use App\Enums\InvoiceState;
use App\Models\Invoice;
use App\Models\User;

/**
 * Second line of defence behind the tenant scope, plus the lifecycle rules of PLAN.md section 1.8 (B2, B4).
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null;
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->sameTenant($user, $invoice);
    }

    public function create(User $user): bool
    {
        return $user->tenant_id !== null;
    }

    /** Draft: always. Issued: only while it has no active payments. */
    public function update(User $user, Invoice $invoice): bool
    {
        return $this->sameTenant($user, $invoice) && $invoice->canBeEdited();
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $this->sameTenant($user, $invoice)
            && $invoice->state === InvoiceState::Draft
            && $invoice->total_cents > 0;
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $this->sameTenant($user, $invoice) && $invoice->canBeVoided();
    }

    /** B6/B7: only an issued invoice with an outstanding balance can receive a payment. */
    public function recordPayment(User $user, Invoice $invoice): bool
    {
        return $this->sameTenant($user, $invoice)
            && $invoice->state === InvoiceState::Issued
            && $invoice->balance_cents > 0;
    }

    /** Only drafts are ever deleted. */
    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->sameTenant($user, $invoice) && $invoice->state === InvoiceState::Draft;
    }

    private function sameTenant(User $user, Invoice $invoice): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === $invoice->tenant_id;
    }
}
