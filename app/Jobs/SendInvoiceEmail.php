<?php

namespace App\Jobs;

use App\Enums\InvoiceEmailEvent;
use App\Enums\InvoiceState;
use App\Mail\InvoiceIssuedMail;
use App\Mail\InvoiceOverdueMail;
use App\Models\Invoice;
use App\Models\InvoiceEmailLog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Sends one invoice email, exactly once per (tenant, invoice, event).
 *
 * Tenant context: dispatched inside a tenant, QueueTenancyBootstrapper stores the tenant id in the payload and
 * re-initialises it in the worker before handle() runs, so Invoice::find() below is scoped to that tenant.
 * Only plain ids are carried by the job (no serialized models), so nothing stale or foreign can be restored.
 *
 * Idempotency: UNIQUE (tenant_id, invoice_id, event) in invoice_email_logs. A duplicate or retried run finds
 * `sent_at` set and exits; a crash before sending leaves `sent_at` null so the retry sends.
 */
class SendInvoiceEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Release a stuck unique lock after an hour (e.g. a dispatch whose transaction rolled back). */
    public int $uniqueFor = 3600;

    public function __construct(
        public int $invoiceId,
        public InvoiceEmailEvent $event,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /** Includes the tenant id so two tenants with the same invoice id never share a lock. */
    public function uniqueId(): string
    {
        return (tenant('id') ?? 'central').':'.$this->invoiceId.':'.$this->event->value;
    }

    public function handle(): void
    {
        // Without a tenant the BelongsToTenant scope is not applied, so the lookup below could read any
        // tenant's invoice. A job dispatched outside a tenant is a bug: refuse it instead of running unscoped.
        if (! tenancy()->initialized) {
            $this->fail(new RuntimeException('SendInvoiceEmail must be dispatched inside a tenant context.'));

            return;
        }

        // Eloquent is tenant-scoped here: another tenant's invoice id simply is not found.
        $invoice = Invoice::with('customer')->find($this->invoiceId);

        if (! $invoice || ! $invoice->customer?->email) {
            return;
        }

        // Drafts and void invoices never email; a reminder is pointless once the invoice is paid.
        if ($invoice->state !== InvoiceState::Issued) {
            return;
        }

        if ($this->event === InvoiceEmailEvent::Overdue && ! $invoice->is_overdue) {
            return;
        }

        DB::transaction(function () use ($invoice) {
            $now = now();

            // Claim the slot once; a second claim is silently ignored by the UNIQUE index.
            InvoiceEmailLog::insertOrIgnore([
                'tenant_id' => tenant('id'),
                'invoice_id' => $invoice->id,
                'event' => $this->event->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $log = InvoiceEmailLog::query()
                ->where('invoice_id', $invoice->id)
                ->where('event', $this->event->value)
                ->lockForUpdate()
                ->firstOrFail();

            if ($log->sent_at !== null) {
                return;
            }

            $mailable = $this->event === InvoiceEmailEvent::Issued
                ? new InvoiceIssuedMail($invoice)
                : new InvoiceOverdueMail($invoice);

            Mail::to($invoice->customer->email)->send($mailable);

            $log->forceFill(['sent_at' => now()])->save();
        });
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Invoice email failed permanently.', [
            'tenant_id' => tenant('id'),
            'invoice_id' => $this->invoiceId,
            'event' => $this->event->value,
            'error' => $exception?->getMessage(),
        ]);
    }
}
