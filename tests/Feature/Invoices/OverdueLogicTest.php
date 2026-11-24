<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceState;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Overdue" is never stored: it is computed from state, status and due date, both in SQL (scope) and
 * in PHP (accessor). Time is frozen with travelTo() so every day boundary is exact.
 */
class OverdueLogicTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = $this->createTenant('acme');
        $this->travelTo(now()->setDate(2026, 12, 10)->setTime(15, 30));
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $attributes */
    private function issuedDueOn(string $dueDate, array $attributes = []): Invoice
    {
        return $this->acme->run(fn () => Invoice::factory()->issued()->create(array_merge([
            'issue_date' => '2026-11-01',
            'due_date' => $dueDate,
        ], $attributes)));
    }

    private function pay(Invoice $invoice, ?int $cents = null): void
    {
        $this->acme->run(function () use ($invoice, $cents) {
            Payment::factory()->create(['invoice_id' => $invoice->id, 'amount_cents' => $cents ?? $invoice->total_cents]);
            Invoice::findOrFail($invoice->id)->recalculatePaymentState();
        });
    }

    /** Asserts the SQL scope, the PHP accessor and the display status all say the same thing. */
    private function assertOverdue(bool $expected, Invoice $invoice, ?string $displayStatus = null): void
    {
        $this->acme->run(function () use ($expected, $invoice, $displayStatus) {
            $fresh = Invoice::findOrFail($invoice->id);

            $this->assertSame($expected, $fresh->is_overdue, 'accessor');
            $this->assertSame($expected, Invoice::overdue()->whereKey($invoice->id)->exists(), 'scope');
            $this->assertSame($expected, Invoice::withDisplayStatus('overdue')->whereKey($invoice->id)->exists(), 'display filter');

            if ($displayStatus !== null) {
                $this->assertSame($displayStatus, $fresh->display_status);
            }
        });
    }

    public function test_an_invoice_is_not_overdue_on_its_due_date(): void
    {
        $invoice = $this->issuedDueOn('2026-12-10');

        $this->assertOverdue(false, $invoice, 'unpaid');
    }

    public function test_an_invoice_is_overdue_the_day_after_its_due_date(): void
    {
        $invoice = $this->issuedDueOn('2026-12-10');

        $this->travelTo(now()->addDay());   // 2026-12-11

        $this->assertOverdue(true, $invoice, 'overdue');
    }

    public function test_the_transition_happens_at_midnight_not_at_the_time_of_day(): void
    {
        $invoice = $this->issuedDueOn('2026-12-10');

        $this->travelTo(now()->setTime(23, 59, 59));
        $this->assertOverdue(false, $invoice);

        $this->travelTo(now()->addSecond());   // 00:00:00 on the 11th
        $this->assertOverdue(true, $invoice);
    }

    public function test_unpaid_and_partial_past_due_invoices_are_overdue(): void
    {
        $unpaid = $this->issuedDueOn('2026-12-01');
        $partial = $this->issuedDueOn('2026-12-01');
        $this->pay($partial, 1);

        $this->assertOverdue(true, $unpaid, 'overdue');
        $this->assertOverdue(true, $partial, 'overdue');

        $this->acme->run(fn () => $this->assertSame('partial', Invoice::findOrFail($partial->id)->status->value));  // payment status is untouched
    }

    public function test_a_paid_invoice_is_never_overdue(): void
    {
        $invoice = $this->issuedDueOn('2026-11-15');
        $this->pay($invoice);

        $this->assertOverdue(false, $invoice, 'paid');

        $this->travelTo(now()->addYears(2));
        $this->assertOverdue(false, $invoice, 'paid');
    }

    public function test_paying_an_overdue_invoice_in_full_clears_the_overdue_state(): void
    {
        $invoice = $this->issuedDueOn('2026-11-15');
        $this->assertOverdue(true, $invoice, 'overdue');

        $this->pay($invoice);

        $this->assertOverdue(false, $invoice, 'paid');
    }

    public function test_voiding_the_payment_makes_a_past_due_invoice_overdue_again(): void
    {
        $invoice = $this->issuedDueOn('2026-11-15');
        $this->pay($invoice);
        $this->assertOverdue(false, $invoice);

        $this->acme->run(function () use ($invoice) {
            Payment::query()->where('invoice_id', $invoice->id)->update(['voided_at' => now(), 'void_reason' => 'Bounced']);
            Invoice::findOrFail($invoice->id)->recalculatePaymentState();
        });

        $this->assertOverdue(true, $invoice, 'overdue');
    }

    public function test_draft_and_void_invoices_are_never_overdue(): void
    {
        $draft = $this->acme->run(fn () => Invoice::factory()->create(['issue_date' => '2026-10-01', 'due_date' => '2026-10-15']));
        $void = $this->issuedDueOn('2026-10-15', ['issue_date' => '2026-10-01']);
        $this->acme->run(fn () => Invoice::findOrFail($void->id)->forceFill(['state' => InvoiceState::Void, 'voided_at' => now(), 'void_reason' => 'x'])->save());

        $this->assertOverdue(false, $draft, 'draft');
        $this->assertOverdue(false, $void, 'void');
    }

    public function test_the_scope_and_the_accessor_always_agree(): void
    {
        $this->acme->run(function () {
            foreach (['2026-12-08', '2026-12-09', '2026-12-10', '2026-12-11', '2026-12-12'] as $due) {
                Invoice::factory()->issued()->create(['issue_date' => '2026-11-01', 'due_date' => $due]);
                Invoice::factory()->partial()->create(['issue_date' => '2026-11-01', 'due_date' => $due]);
                Invoice::factory()->paid()->create(['issue_date' => '2026-11-01', 'due_date' => $due]);
                Invoice::factory()->create(['issue_date' => '2026-11-01', 'due_date' => $due]);   // draft
            }

            $byScope = Invoice::overdue()->pluck('id')->sort()->values()->all();
            $byAccessor = Invoice::all()->filter(fn (Invoice $invoice) => $invoice->is_overdue)->pluck('id')->sort()->values()->all();

            $this->assertNotEmpty($byScope);
            $this->assertSame($byScope, $byAccessor);

            // 2 past-due dates (8th, 9th) x (unpaid + partial); the 10th is not past due yet.
            $this->assertCount(4, $byScope);
        });
    }

    public function test_the_overdue_filter_on_the_invoice_list_matches_the_badge(): void
    {
        $this->actingAsTenantUser($this->acme);
        $overdue = $this->issuedDueOn('2026-12-01');
        $notYetDue = $this->issuedDueOn('2026-12-10');
        $paid = $this->issuedDueOn('2026-12-01');
        $this->pay($paid);
        $partialOverdue = $this->issuedDueOn('2026-12-01');
        $this->pay($partialOverdue, 1);

        $this->get('/invoices?status=overdue')->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 2)
            ->where('invoices.data.0.display_status', 'overdue')
            ->where('invoices.data.1.display_status', 'overdue'));

        // "unpaid" and "partial" show only what the badge says, so they never include overdue invoices.
        $this->get('/invoices?status=unpaid')->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.id', $notYetDue->id));
        $this->get('/invoices?status=partial')->assertInertia(fn (Assert $page) => $page->has('invoices.data', 0));
        $this->get('/invoices?status=paid')->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.id', $paid->id));

        $this->assertNotNull($overdue->id);
    }
}
