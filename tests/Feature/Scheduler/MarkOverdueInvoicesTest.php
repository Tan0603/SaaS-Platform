<?php

namespace Tests\Feature\Scheduler;

use App\Enums\InvoiceEmailEvent;
use App\Enums\InvoiceState;
use App\Enums\InvoiceStatus;
use App\Jobs\SendInvoiceEmail;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The daily overdue job runs once per tenant, inside that tenant, and never touches `status`.
 */
class MarkOverdueInvoicesTest extends TestCase
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

    private function overdueInvoice(Tenant $tenant): Invoice
    {
        return $tenant->run(fn () => Invoice::factory()->overdue()->create());
    }

    private function fresh(Tenant $tenant, Invoice $invoice): Invoice
    {
        return $tenant->run(fn () => Invoice::findOrFail($invoice->id));
    }

    /** @return list<SendInvoiceEmail> */
    private function pushedOverdueJobs(): array
    {
        return Queue::pushed(SendInvoiceEmail::class)->values()->all();
    }

    public function test_each_tenants_overdue_invoices_are_marked_and_queued_in_their_own_tenant(): void
    {
        Queue::fake();
        $acmeInvoice = $this->overdueInvoice($this->acme);
        $globexInvoice = $this->overdueInvoice($this->globex);

        $this->artisan('tenants:run', ['commandname' => 'invoices:mark-overdue'])->assertSuccessful();

        foreach ([[$this->acme, $acmeInvoice], [$this->globex, $globexInvoice]] as [$tenant, $invoice]) {
            $fresh = $this->fresh($tenant, $invoice);
            $this->assertNotNull($fresh->overdue_notified_at);
            $this->assertSame(InvoiceStatus::Unpaid, $fresh->status, 'overdue is computed; status is never rewritten');
            $this->assertSame(InvoiceState::Issued, $fresh->state);
        }

        $jobs = $this->pushedOverdueJobs();
        $this->assertCount(2, $jobs);
        $this->assertEqualsCanonicalizing(
            [$acmeInvoice->id, $globexInvoice->id],
            array_map(fn (SendInvoiceEmail $job) => $job->invoiceId, $jobs),
        );
        foreach ($jobs as $job) {
            $this->assertSame(InvoiceEmailEvent::Overdue, $job->event);
        }
    }

    public function test_the_overdue_jobs_carry_the_tenant_of_their_invoice(): void
    {
        config(['queue.default' => 'database']);
        $this->overdueInvoice($this->acme);
        $this->overdueInvoice($this->globex);

        $this->artisan('tenants:run', ['commandname' => 'invoices:mark-overdue'])->assertSuccessful();

        $tenants = \DB::table('jobs')->pluck('payload')
            ->map(fn (string $payload) => json_decode($payload, true)['tenant_id'] ?? null)
            ->sort()->values()->all();

        $this->assertSame(['acme', 'globex'], $tenants);
    }

    public function test_a_tenant_only_ever_marks_its_own_invoices(): void
    {
        Queue::fake();
        $acmeInvoice = $this->overdueInvoice($this->acme);
        $globexInvoice = $this->overdueInvoice($this->globex);

        $this->artisan('tenants:run', ['commandname' => 'invoices:mark-overdue', '--tenants' => ['acme']])->assertSuccessful();

        $this->assertNotNull($this->fresh($this->acme, $acmeInvoice)->overdue_notified_at);
        $this->assertNull($this->fresh($this->globex, $globexInvoice)->overdue_notified_at);
        $this->assertCount(1, $this->pushedOverdueJobs());
    }

    public function test_running_it_twice_does_not_queue_a_second_email(): void
    {
        Queue::fake();
        $this->overdueInvoice($this->acme);

        $this->artisan('tenants:run', ['commandname' => 'invoices:mark-overdue'])->assertSuccessful();
        $this->artisan('tenants:run', ['commandname' => 'invoices:mark-overdue'])->assertSuccessful();

        $this->assertCount(1, $this->pushedOverdueJobs());
    }

    public function test_invoices_that_are_not_overdue_are_left_alone(): void
    {
        Queue::fake();
        $notDueYet = $this->acme->run(fn () => Invoice::factory()->issued()->create(['due_date' => today()->addDays(3)]));
        $dueToday = $this->acme->run(fn () => Invoice::factory()->issued()->create(['due_date' => today()]));
        $paid = $this->acme->run(fn () => Invoice::factory()->paid()->create(['issue_date' => today()->subDays(60), 'due_date' => today()->subDays(30)]));
        $draft = $this->acme->run(fn () => Invoice::factory()->create(['issue_date' => today()->subDays(60), 'due_date' => today()->subDays(30)]));
        $void = $this->acme->run(fn () => Invoice::factory()->issued()->create(['issue_date' => today()->subDays(60), 'due_date' => today()->subDays(30)]));
        $this->acme->run(fn () => Invoice::whereKey($void->id)->update(['state' => InvoiceState::Void]));
        $partial = $this->acme->run(fn () => Invoice::factory()->partial()->create(['issue_date' => today()->subDays(60), 'due_date' => today()->subDays(30)]));

        $this->artisan('tenants:run', ['commandname' => 'invoices:mark-overdue'])->assertSuccessful();

        foreach ([$notDueYet, $dueToday, $paid, $draft, $void] as $invoice) {
            $this->assertNull($this->fresh($this->acme, $invoice)->overdue_notified_at, "invoice {$invoice->id} must stay untouched");
        }

        // A part-paid past-due invoice IS overdue, and keeps its "partial" status.
        $partialFresh = $this->fresh($this->acme, $partial);
        $this->assertNotNull($partialFresh->overdue_notified_at);
        $this->assertSame(InvoiceStatus::Partial, $partialFresh->status);
        $this->assertCount(1, $this->pushedOverdueJobs());
    }

    public function test_without_a_tenant_the_command_fails_safely_and_changes_nothing(): void
    {
        Queue::fake();
        $invoice = $this->overdueInvoice($this->acme);
        $this->assertFalse(tenancy()->initialized);

        $this->artisan('invoices:mark-overdue')
            ->expectsOutputToContain('tenants:run')
            ->assertFailed();

        $this->assertNull($this->fresh($this->acme, $invoice)->overdue_notified_at);
        Queue::assertNothingPushed();
    }

    public function test_the_command_leaves_no_tenant_initialised_behind(): void
    {
        Queue::fake();
        $this->overdueInvoice($this->acme);

        Artisan::call('tenants:run', ['commandname' => 'invoices:mark-overdue']);

        $this->assertFalse(tenancy()->initialized);
    }

    public function test_the_command_is_scheduled_daily_through_tenants_run(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (ScheduledEvent $event) => str_contains($event->command, 'tenants:run invoices:mark-overdue'));

        $this->assertCount(1, $events);

        /** @var ScheduledEvent $event */
        $event = $events->first();
        $this->assertSame('5 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }
}
