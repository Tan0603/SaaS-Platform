<?php

namespace Tests\Feature\Jobs;

use App\Actions\Invoices\IssueInvoiceAction;
use App\Enums\InvoiceEmailEvent;
use App\Jobs\SendInvoiceEmail;
use App\Mail\InvoiceIssuedMail;
use App\Mail\InvoiceOverdueMail;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceEmailLog;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Invoice emails: queued after commit, sent exactly once per (tenant, invoice, event), inside the right tenant.
 */
class SendInvoiceEmailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private Tenant $globex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = $this->createTenant('acme');
        $this->globex = $this->createTenant('globex');
    }

    private function issuedInvoice(Tenant $tenant, array $customer = [], array $invoice = []): Invoice
    {
        return $tenant->run(fn () => Invoice::factory()->issued()->create(array_merge([
            'customer_id' => Customer::factory()->create($customer)->id,
        ], $invoice)));
    }

    /** Runs the job's handle() inside the tenant, the way the worker would. */
    private function runJob(Tenant $tenant, Invoice $invoice, InvoiceEmailEvent $event = InvoiceEmailEvent::Issued): void
    {
        $tenant->run(fn () => (new SendInvoiceEmail($invoice->id, $event))->handle());
    }

    private function logCount(Tenant $tenant): int
    {
        return $tenant->run(fn () => InvoiceEmailLog::count());
    }

    // ---- dispatching -----------------------------------------------------------------------

    public function test_issuing_an_invoice_queues_exactly_one_email_job(): void
    {
        Queue::fake();
        $draft = $this->acme->run(fn () => Invoice::factory()->create());

        $this->acme->run(fn () => app(IssueInvoiceAction::class)->execute($draft));

        Queue::assertPushed(SendInvoiceEmail::class, 1);
        Queue::assertPushed(SendInvoiceEmail::class, fn (SendInvoiceEmail $job) => $job->invoiceId === $draft->id
            && $job->event === InvoiceEmailEvent::Issued);
    }

    public function test_saving_a_draft_queues_no_email(): void
    {
        Queue::fake();
        $this->actingAsTenantUser($this->acme);
        $customer = $this->acme->run(fn () => Customer::factory()->create());

        $this->post('/invoices', [
            'customer_id' => $customer->id,
            'issue_date' => '2026-11-24',
            'due_date' => '2026-12-24',
            'items' => [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '10.00']],
        ])->assertRedirect();

        Queue::assertNothingPushed();
    }

    public function test_saving_and_issuing_through_the_form_queues_one_job(): void
    {
        Queue::fake();
        $this->actingAsTenantUser($this->acme);
        $customer = $this->acme->run(fn () => Customer::factory()->create());

        $this->post('/invoices', [
            'action' => 'issue',
            'customer_id' => $customer->id,
            'issue_date' => '2026-11-24',
            'due_date' => '2026-12-24',
            'items' => [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '10.00']],
        ])->assertRedirect();

        Queue::assertPushed(SendInvoiceEmail::class, 1);
    }

    public function test_an_invoice_issued_in_a_rolled_back_transaction_queues_nothing(): void
    {
        // A real queue: Queue::fake() ignores afterCommit(), the database queue honours it.
        config(['queue.default' => 'database']);
        $draft = $this->acme->run(fn () => Invoice::factory()->create());

        try {
            $this->acme->run(fn () => DB::transaction(function () use ($draft) {
                app(IssueInvoiceAction::class)->execute($draft);

                throw new RuntimeException('something later in the same transaction failed');
            }));
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame('draft', $this->acme->run(fn () => Invoice::findOrFail($draft->id)->state->value));
    }

    // ---- idempotency and retry -------------------------------------------------------------

    public function test_running_the_job_twice_sends_exactly_one_email(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice($this->acme, ['email' => 'buyer@example.test']);

        $this->runJob($this->acme, $invoice);
        $this->runJob($this->acme, $invoice);

        Mail::assertSent(InvoiceIssuedMail::class, 1);
        Mail::assertSent(InvoiceIssuedMail::class, fn (InvoiceIssuedMail $mail) => $mail->hasTo('buyer@example.test'));
        $this->assertSame(1, $this->logCount($this->acme));
        $this->assertNotNull($this->acme->run(fn () => InvoiceEmailLog::first()->sent_at));
    }

    public function test_issued_and_overdue_are_separate_events_with_their_own_email(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice($this->acme, [], ['issue_date' => today()->subDays(60), 'due_date' => today()->subDays(30)]);

        $this->runJob($this->acme, $invoice, InvoiceEmailEvent::Issued);
        $this->runJob($this->acme, $invoice, InvoiceEmailEvent::Overdue);
        $this->runJob($this->acme, $invoice, InvoiceEmailEvent::Overdue);

        Mail::assertSent(InvoiceIssuedMail::class, 1);
        Mail::assertSent(InvoiceOverdueMail::class, 1);
        $this->assertSame(2, $this->logCount($this->acme));
    }

    public function test_a_log_row_without_sent_at_is_treated_as_a_crash_and_the_next_run_sends(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice($this->acme);
        // The slot was claimed but the process died before the email went out.
        $this->acme->run(fn () => InvoiceEmailLog::insert([
            'tenant_id' => 'acme', 'invoice_id' => $invoice->id, 'event' => 'issued',
            'sent_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->runJob($this->acme, $invoice);

        Mail::assertSent(InvoiceIssuedMail::class, 1);
        $this->assertSame(1, $this->logCount($this->acme));
        $this->assertNotNull($this->acme->run(fn () => InvoiceEmailLog::first()->sent_at));
    }

    public function test_a_failed_send_leaves_the_slot_unsent_so_the_retry_sends(): void
    {
        $invoice = $this->issuedInvoice($this->acme);
        $smtpDown = true;
        $delivered = 0;
        Event::listen(MessageSending::class, function () use (&$smtpDown, &$delivered) {
            $smtpDown ? throw new RuntimeException('smtp down') : $delivered++;
        });

        try {
            $this->runJob($this->acme, $invoice);
            $this->fail('The first attempt should have failed.');
        } catch (RuntimeException) {
            // the queue would retry
        }
        $this->assertSame(0, $this->acme->run(fn () => InvoiceEmailLog::whereNotNull('sent_at')->count()));

        $smtpDown = false;
        $this->runJob($this->acme, $invoice);
        $this->runJob($this->acme, $invoice);

        $this->assertSame(1, $delivered);
        $this->assertSame(1, $this->acme->run(fn () => InvoiceEmailLog::whereNotNull('sent_at')->count()));
    }

    // ---- skipped cases ---------------------------------------------------------------------

    public function test_a_customer_without_an_email_gets_no_email_and_no_error(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice($this->acme, ['email' => null]);

        $this->runJob($this->acme, $invoice);

        Mail::assertNothingSent();
        $this->assertSame(0, $this->logCount($this->acme));
    }

    public function test_a_missing_invoice_is_ignored_quietly(): void
    {
        Mail::fake();

        $this->acme->run(fn () => (new SendInvoiceEmail(999999, InvoiceEmailEvent::Issued))->handle());

        Mail::assertNothingSent();
    }

    public function test_drafts_and_void_invoices_never_get_an_email(): void
    {
        Mail::fake();
        $draft = $this->acme->run(fn () => Invoice::factory()->create());
        $void = $this->issuedInvoice($this->acme);
        $this->acme->run(fn () => Invoice::whereKey($void->id)->update(['state' => 'void']));

        $this->runJob($this->acme, $draft);
        $this->runJob($this->acme, $void);

        Mail::assertNothingSent();
        $this->assertSame(0, $this->logCount($this->acme));
    }

    public function test_the_overdue_email_is_skipped_when_the_invoice_was_paid_before_the_job_ran(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice($this->acme, [], ['issue_date' => today()->subDays(60), 'due_date' => today()->subDays(30)]);
        $this->acme->run(function () use ($invoice) {
            Payment::factory()->create(['invoice_id' => $invoice->id, 'amount_cents' => $invoice->total_cents]);
            Invoice::findOrFail($invoice->id)->recalculatePaymentState();
        });

        $this->runJob($this->acme, $invoice, InvoiceEmailEvent::Overdue);

        Mail::assertNothingSent();
        $this->assertSame(0, $this->logCount($this->acme));
    }

    public function test_the_overdue_email_is_skipped_when_the_invoice_is_not_overdue(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice($this->acme, [], ['due_date' => today()->addDays(5)]);

        $this->runJob($this->acme, $invoice, InvoiceEmailEvent::Overdue);

        Mail::assertNothingSent();
    }

    // ---- tenant isolation ------------------------------------------------------------------

    public function test_a_job_running_without_a_tenant_refuses_to_read_any_invoice(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice($this->acme);
        $this->assertFalse(tenancy()->initialized);

        // Outside a tenant the scope is off, so the job must not look the invoice up at all.
        (new SendInvoiceEmail($invoice->id, InvoiceEmailEvent::Issued))->handle();

        Mail::assertNothingSent();
        $this->assertSame(0, $this->logCount($this->acme));
    }

    public function test_a_job_cannot_read_another_tenants_invoice(): void
    {
        Mail::fake();
        $globexInvoice = $this->issuedInvoice($this->globex);

        // The id belongs to Globex, but the job runs in Acme's context.
        $this->runJob($this->acme, $globexInvoice);

        Mail::assertNothingSent();
        $this->assertSame(0, $this->logCount($this->acme));
        $this->assertSame(0, $this->logCount($this->globex));
    }

    public function test_the_log_row_belongs_to_the_tenant_that_ran_the_job(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice($this->globex);

        $this->runJob($this->globex, $invoice);

        $this->assertSame('globex', $this->globex->run(fn () => InvoiceEmailLog::firstOrFail()->tenant_id));
    }

    public function test_the_worker_restores_the_tenant_that_dispatched_the_job(): void
    {
        // A real queue (database), not sync: the job is stored with the tenant in its payload...
        config(['queue.default' => 'database']);
        $acmeInvoice = $this->issuedInvoice($this->acme, ['email' => 'acme-buyer@example.test']);
        $globexInvoice = $this->issuedInvoice($this->globex, ['email' => 'globex-buyer@example.test']);
        // Both tenants can have the same invoice id; only the tenant tells them apart.
        $this->assertNotSame('', (string) $acmeInvoice->id.$globexInvoice->id);

        // Statement bodies (not arrow fns): the PendingDispatch must be destroyed, i.e. pushed, inside the tenant.
        $this->acme->run(function () use ($acmeInvoice) {
            SendInvoiceEmail::dispatch($acmeInvoice->id, InvoiceEmailEvent::Issued)->afterCommit();
        });
        $this->globex->run(function () use ($globexInvoice) {
            SendInvoiceEmail::dispatch($globexInvoice->id, InvoiceEmailEvent::Issued)->afterCommit();
        });

        $this->assertSame(2, DB::table('jobs')->count());
        $payloads = DB::table('jobs')->orderBy('id')->pluck('payload')->map(fn ($p) => json_decode($p, true)['tenant_id'] ?? null)->all();
        $this->assertSame(['acme', 'globex'], $payloads);

        // ...and the worker runs in a "central" context (no tenant initialised).
        tenancy()->end();
        $this->assertNull(tenant());

        $seen = [];
        Event::listen(MessageSending::class, function (MessageSending $event) use (&$seen) {
            $seen[] = [
                'tenant' => tenant('id'),
                'to' => $event->message->getTo()[0]->getAddress(),
                'body' => $event->message->getHtmlBody(),
            ];
        });

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

        $this->assertCount(2, $seen);
        $this->assertSame('acme', $seen[0]['tenant']);
        $this->assertSame('acme-buyer@example.test', $seen[0]['to']);
        $this->assertStringContainsString('Acme Sdn Bhd', $seen[0]['body']);
        $this->assertStringNotContainsString('Globex', $seen[0]['body']);
        $this->assertSame('globex', $seen[1]['tenant']);
        $this->assertSame('globex-buyer@example.test', $seen[1]['to']);
        $this->assertStringContainsString('Globex Sdn Bhd', $seen[1]['body']);

        // Each log row carries the dispatching tenant, and the worker leaves no tenant behind.
        $this->assertSame(['acme'], $this->acme->run(fn () => InvoiceEmailLog::pluck('tenant_id')->all()));
        $this->assertSame(['globex'], $this->globex->run(fn () => InvoiceEmailLog::pluck('tenant_id')->all()));
        $this->assertNull(tenant());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_issuing_on_a_real_queue_stores_one_job_with_the_tenant_after_commit(): void
    {
        config(['queue.default' => 'database']);
        $draft = $this->acme->run(fn () => Invoice::factory()->create());

        $this->acme->run(fn () => app(IssueInvoiceAction::class)->execute($draft));

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame('acme', json_decode(DB::table('jobs')->value('payload'), true)['tenant_id']);
    }

    public function test_the_unique_lock_is_per_tenant_and_released_after_the_worker_ran(): void
    {
        config(['queue.default' => 'database']);
        $invoice = $this->issuedInvoice($this->acme);

        $dispatchTwice = function () use ($invoice) {
            $this->acme->run(function () use ($invoice) {
                SendInvoiceEmail::dispatch($invoice->id, InvoiceEmailEvent::Issued)->afterCommit();
                SendInvoiceEmail::dispatch($invoice->id, InvoiceEmailEvent::Issued)->afterCommit();
            });
        };

        $dispatchTwice();
        $this->assertSame(1, DB::table('jobs')->count(), 'identical pending jobs are not piled up');

        tenancy()->end();
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
        $this->assertSame(0, DB::table('jobs')->count());

        // The lock was released by the worker, so a new dispatch is accepted again.
        $dispatchTwice();
        $this->assertSame(1, DB::table('jobs')->count());
    }
}
