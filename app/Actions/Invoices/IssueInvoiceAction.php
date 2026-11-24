<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceEmailEvent;
use App\Enums\InvoiceState;
use App\Jobs\SendInvoiceEmail;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueInvoiceAction
{
    /**
     * Draft -> issued: assigns the next gap-free number (B3) and stamps `issued_at`.
     *
     * Two issues at the same moment are serialised by locking the tenant row (the sequence belongs to the
     * tenant), then max(sequence) + 1. UNIQUE (tenant_id, sequence) is the safety net.
     *
     * @throws AuthorizationException
     */
    public function execute(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $tenant = Tenant::query()->whereKey(tenant('id'))->lockForUpdate()->firstOrFail();
            $invoice = Invoice::whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($invoice->state !== InvoiceState::Draft) {
                throw new AuthorizationException('Only a draft invoice can be issued.');
            }

            // A zero-total invoice could never become paid (a payment must be > 0 and <= total), so it would
            // stay unpaid and turn overdue forever. Inside "Save & issue" this also rolls back the new draft.
            if ($invoice->total_cents <= 0) {
                throw ValidationException::withMessages([
                    'items' => 'An invoice with a total of 0.00 cannot be issued. Save it as a draft instead.',
                ]);
            }

            $sequence = (int) Invoice::query()->max('sequence') + 1;

            $invoice->forceFill([
                'state' => InvoiceState::Issued,
                'sequence' => $sequence,
                'number' => $tenant->invoice_prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
                'issued_at' => now(),
            ])->save();

            // After commit: a rolled-back transaction (also an outer one, e.g. "Save & issue") queues nothing.
            // Idempotency (one email per invoice + event) is enforced inside the job.
            SendInvoiceEmail::dispatch($invoice->id, InvoiceEmailEvent::Issued)->afterCommit();

            return $invoice;
        });
    }
}
