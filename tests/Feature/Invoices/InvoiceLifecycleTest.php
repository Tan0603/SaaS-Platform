<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceState;
use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvoiceLifecycleTest extends TestCase
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

    /** @param  array<string, mixed>  $attributes */
    private function draftOf(Tenant $tenant, array $attributes = []): Invoice
    {
        return $tenant->run(fn () => Invoice::factory()->create($attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    private function issuedOf(Tenant $tenant, array $attributes = []): Invoice
    {
        return $tenant->run(fn () => Invoice::factory()->issued()->create($attributes));
    }

    /** Adds an active (or voided) payment through the factory and recalculates the invoice. */
    private function pay(Tenant $tenant, Invoice $invoice, int $cents, bool $voided = false): Invoice
    {
        return $tenant->run(function () use ($invoice, $cents, $voided) {
            $factory = Payment::factory();
            ($voided ? $factory->voided() : $factory)->create(['invoice_id' => $invoice->id, 'amount_cents' => $cents]);

            $fresh = Invoice::findOrFail($invoice->id);
            $fresh->recalculatePaymentState();

            return $fresh;
        });
    }

    private function reload(Tenant $tenant, Invoice $invoice): Invoice
    {
        return $tenant->run(fn () => Invoice::findOrFail($invoice->id));
    }

    /** @return array<string, mixed> */
    private function payload(Invoice|int $customer, array $overrides = []): array
    {
        $customerId = $customer instanceof Invoice ? $customer->customer_id : $customer;

        return array_merge([
            'customer_id' => $customerId,
            'issue_date' => '2026-11-24',
            'due_date' => '2026-12-24',
            'items' => [['description' => 'Replaced item', 'quantity' => '4', 'unit_price' => '25.00']],
        ], $overrides);
    }

    // ---- issue -----------------------------------------------------------------------------

    public function test_issuing_a_draft_assigns_the_next_number(): void
    {
        $this->actingAsTenantUser($this->acme);
        $first = $this->draftOf($this->acme);
        $second = $this->draftOf($this->acme);

        $this->post("/invoices/{$first->id}/issue")->assertRedirect("/invoices/{$first->id}");
        $this->post("/invoices/{$second->id}/issue")->assertRedirect("/invoices/{$second->id}");

        $first = $this->reload($this->acme, $first);
        $second = $this->reload($this->acme, $second);
        $this->assertSame('IV-00001', $first->number);
        $this->assertSame('IV-00002', $second->number);
        $this->assertSame(InvoiceState::Issued, $first->state);
        $this->assertNotNull($first->issued_at);
        $this->assertSame(InvoiceStatus::Unpaid, $first->status);
    }

    public function test_a_draft_has_no_number_and_deleting_it_leaves_no_gap(): void
    {
        $this->actingAsTenantUser($this->acme);
        $keep = $this->draftOf($this->acme);
        $discard = $this->draftOf($this->acme);
        $this->assertNull($discard->number);

        $this->post("/invoices/{$keep->id}/issue");                 // IV-00001
        $this->delete("/invoices/{$discard->id}")->assertRedirect('/invoices');
        $third = $this->draftOf($this->acme);
        $this->post("/invoices/{$third->id}/issue");                // IV-00002, not 00003

        $this->assertNull($this->acme->run(fn () => Invoice::find($discard->id)));
        $this->assertSame(['IV-00001', 'IV-00002'], $this->acme->run(fn () => Invoice::orderBy('sequence')->pluck('number')->all()));
    }

    public function test_a_zero_total_draft_cannot_be_issued(): void
    {
        $this->actingAsTenantUser($this->acme);
        $customer = $this->acme->run(fn () => Customer::factory()->create());
        $zeroItems = ['items' => [['description' => 'Free sample', 'quantity' => '1', 'unit_price' => '0']]];

        // "Save & issue" with a 0.00 total: validation error, nothing is created.
        $this->post('/invoices', $this->payload($customer->id, $zeroItems + ['action' => 'issue']))
            ->assertSessionHasErrors('items');
        $this->assertSame(0, $this->acme->run(fn () => Invoice::count()));

        // Saving it as a draft is fine, but the issue button is refused.
        $this->post('/invoices', $this->payload($customer->id, $zeroItems + ['action' => 'save']))
            ->assertSessionHasNoErrors();
        $draft = $this->acme->run(fn () => Invoice::sole());
        $this->assertSame(0, $draft->total_cents);

        $this->post("/invoices/{$draft->id}/issue")->assertForbidden();
        $draft = $this->reload($this->acme, $draft);
        $this->assertSame(InvoiceState::Draft, $draft->state);
        $this->assertNull($draft->number);
    }

    public function test_an_issued_invoice_cannot_be_issued_again(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->issuedOf($this->acme);

        $this->post("/invoices/{$invoice->id}/issue")->assertForbidden();
        $this->assertSame($invoice->number, $this->reload($this->acme, $invoice)->number);
    }

    public function test_the_database_refuses_a_duplicate_invoice_number_per_tenant(): void
    {
        $existing = $this->issuedOf($this->acme);

        $this->expectException(QueryException::class);

        $this->acme->run(function () use ($existing) {
            $draft = Invoice::factory()->create();
            DB::table('invoices')->where('id', $draft->id)->update([
                'state' => 'issued', 'sequence' => $existing->sequence, 'number' => 'IV-99999',
            ]);
        });
    }

    // ---- edit ------------------------------------------------------------------------------

    public function test_a_draft_can_be_edited_and_totals_are_recalculated(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->draftOf($this->acme);

        $this->put("/invoices/{$invoice->id}", $this->payload($invoice, ['total' => '1.00']))
            ->assertRedirect("/invoices/{$invoice->id}");

        $updated = $this->acme->run(fn () => Invoice::with('items')->findOrFail($invoice->id));
        $this->assertSame(10000, $updated->total_cents);
        $this->assertSame(10000, $updated->subtotal_cents);
        $this->assertCount(1, $updated->items);
        $this->assertSame('Replaced item', $updated->items[0]->description);
        $this->assertSame(InvoiceState::Draft, $updated->state);
        $this->assertNull($updated->number);
    }

    public function test_editing_a_draft_with_save_and_issue_issues_it(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->draftOf($this->acme);

        $this->put("/invoices/{$invoice->id}", $this->payload($invoice, ['action' => 'issue']))->assertRedirect();

        $this->assertSame('IV-00001', $this->reload($this->acme, $invoice)->number);
    }

    public function test_an_issued_invoice_without_payments_can_be_edited(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->issuedOf($this->acme);

        $this->get("/invoices/{$invoice->id}/edit")->assertOk();
        $this->put("/invoices/{$invoice->id}", $this->payload($invoice))->assertRedirect();

        $updated = $this->reload($this->acme, $invoice);
        $this->assertSame(10000, $updated->total_cents);
        $this->assertSame($invoice->number, $updated->number);          // number never changes
        $this->assertSame(InvoiceState::Issued, $updated->state);
        $this->assertSame(0, $updated->amount_paid_cents);
        $this->assertSame(InvoiceStatus::Unpaid, $updated->status);
    }

    public function test_an_issued_invoice_with_payments_cannot_be_edited(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->issuedOf($this->acme);
        $invoice = $this->pay($this->acme, $invoice, 100);
        $totalBefore = $invoice->total_cents;

        $this->get("/invoices/{$invoice->id}/edit")->assertForbidden();
        $this->put("/invoices/{$invoice->id}", $this->payload($invoice))->assertForbidden();

        $this->assertSame($totalBefore, $this->reload($this->acme, $invoice)->total_cents);
    }

    public function test_an_invoice_whose_only_payments_are_voided_can_be_edited_again(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->issuedOf($this->acme);
        $invoice = $this->pay($this->acme, $invoice, 100, voided: true);

        $this->put("/invoices/{$invoice->id}", $this->payload($invoice))->assertRedirect();
    }

    public function test_an_archived_customer_can_stay_on_an_existing_invoice_but_not_be_newly_picked(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->draftOf($this->acme);
        $this->acme->run(fn () => Customer::findOrFail($invoice->customer_id)->delete());
        $other = $this->acme->run(fn () => Customer::factory()->archived()->create());

        $this->put("/invoices/{$invoice->id}", $this->payload($invoice))->assertSessionHasNoErrors();
        $this->put("/invoices/{$invoice->id}", $this->payload($other->id))->assertSessionHasErrors('customer_id');
    }

    public function test_a_void_invoice_cannot_be_edited_issued_or_voided_again(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->issuedOf($this->acme);
        $this->post("/invoices/{$invoice->id}/void", ['reason' => 'Mistake'])->assertRedirect();

        $this->get("/invoices/{$invoice->id}/edit")->assertForbidden();
        $this->put("/invoices/{$invoice->id}", $this->payload($invoice))->assertForbidden();
        $this->post("/invoices/{$invoice->id}/issue")->assertForbidden();
        $this->post("/invoices/{$invoice->id}/void", ['reason' => 'Again'])->assertForbidden();
        $this->delete("/invoices/{$invoice->id}")->assertForbidden();
    }

    // ---- delete and void -------------------------------------------------------------------

    public function test_issued_invoices_cannot_be_deleted(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->issuedOf($this->acme);

        $this->delete("/invoices/{$invoice->id}")->assertForbidden();
        $this->assertNotNull($this->acme->run(fn () => Invoice::find($invoice->id)));
    }

    public function test_deleting_a_draft_removes_its_items(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->draftOf($this->acme);

        $this->delete("/invoices/{$invoice->id}")->assertRedirect('/invoices');

        $this->assertSame(0, DB::table('invoice_items')->where('invoice_id', $invoice->id)->count());
    }

    public function test_voiding_keeps_the_number_and_needs_a_reason(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->issuedOf($this->acme);

        $this->post("/invoices/{$invoice->id}/void", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(InvoiceState::Issued, $this->reload($this->acme, $invoice)->state);

        $this->post("/invoices/{$invoice->id}/void", ['reason' => 'Wrong customer'])->assertRedirect("/invoices/{$invoice->id}");

        $voided = $this->reload($this->acme, $invoice);
        $this->assertSame(InvoiceState::Void, $voided->state);
        $this->assertSame($invoice->number, $voided->number);
        $this->assertSame('Wrong customer', $voided->void_reason);
        $this->assertNotNull($voided->voided_at);
    }

    public function test_voiding_needs_no_active_payments(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->issuedOf($this->acme);
        $invoice = $this->pay($this->acme, $invoice, 100);

        $this->post("/invoices/{$invoice->id}/void", ['reason' => 'Nope'])->assertForbidden();
        $this->assertSame(InvoiceState::Issued, $this->reload($this->acme, $invoice)->state);

        // Once the only payment is voided and the state recalculated, voiding works.
        $this->acme->run(function () use ($invoice) {
            Payment::query()->where('invoice_id', $invoice->id)->update(['voided_at' => now(), 'void_reason' => 'Bounced']);
            Invoice::findOrFail($invoice->id)->recalculatePaymentState();
        });
        $this->post("/invoices/{$invoice->id}/void", ['reason' => 'Now ok'])->assertRedirect();
    }

    public function test_void_and_draft_invoices_are_excluded_from_totals(): void
    {
        $counted = $this->issuedOf($this->acme);
        $this->draftOf($this->acme);
        $voided = $this->issuedOf($this->acme);
        $this->actingAsTenantUser($this->acme);
        $this->post("/invoices/{$voided->id}/void", ['reason' => 'Cancelled'])->assertRedirect();

        $this->acme->run(function () use ($counted) {
            $this->assertSame($counted->total_cents, (int) Invoice::issued()->sum('total_cents'));
            $this->assertSame([$counted->id], Invoice::outstanding()->pluck('id')->all());
            $this->assertSame(0, Invoice::overdue()->count());
        });
    }

    // ---- payment state (the only writer) ---------------------------------------------------

    public function test_recalculate_payment_state_follows_active_payments(): void
    {
        $invoice = $this->issuedOf($this->acme);
        $total = $invoice->total_cents;

        $invoice = $this->pay($this->acme, $invoice, 1);
        $this->assertSame(InvoiceStatus::Partial, $invoice->status);
        $this->assertSame(1, $invoice->amount_paid_cents);

        $invoice = $this->pay($this->acme, $invoice, $total - 1);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame($total, $invoice->amount_paid_cents);

        // Voiding the first payment takes it back to partial; voiding both back to unpaid.
        $this->acme->run(function () use ($invoice) {
            $payments = Payment::query()->where('invoice_id', $invoice->id)->orderBy('id')->get();
            $payments[0]->forceFill(['voided_at' => now(), 'void_reason' => 'x'])->save();
            $fresh = Invoice::findOrFail($invoice->id);
            $fresh->recalculatePaymentState();
            $this->assertSame(InvoiceStatus::Partial, $fresh->status);

            $payments[1]->forceFill(['voided_at' => now(), 'void_reason' => 'x'])->save();
            $fresh->recalculatePaymentState();
            $this->assertSame(InvoiceStatus::Unpaid, $fresh->status);
            $this->assertSame(0, $fresh->amount_paid_cents);
        });
    }

    // ---- listing and filters ---------------------------------------------------------------

    public function test_index_lists_only_the_current_tenants_invoices_and_filters(): void
    {
        $this->actingAsTenantUser($this->acme);
        $draft = $this->draftOf($this->acme);
        $issued = $this->issuedOf($this->acme);
        $this->draftOf($this->globex);

        $this->get('/invoices')->assertInertia(fn (Assert $page) => $page
            ->component('invoices/Index')
            ->has('invoices.data', 2));

        $this->get('/invoices?status=draft')->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.id', $draft->id)
            ->where('invoices.data.0.display_status', 'draft'));

        $this->get('/invoices?status=unpaid')->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.id', $issued->id));

        $this->get('/invoices?customer_id='.$issued->customer_id)->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.id', $issued->id));

        $this->get('/invoices?status=bogus')->assertSessionHasErrors('status');
    }

    public function test_index_date_filter_uses_the_issue_date(): void
    {
        $this->actingAsTenantUser($this->acme);
        $this->draftOf($this->acme, ['issue_date' => '2026-01-10', 'due_date' => '2026-02-10']);
        $inRange = $this->draftOf($this->acme, ['issue_date' => '2026-03-10', 'due_date' => '2026-04-10']);

        $this->get('/invoices?from=2026-03-01&to=2026-03-31')->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.id', $inRange->id));
    }

    public function test_index_query_count_does_not_grow_with_the_number_of_invoices(): void
    {
        $this->actingAsTenantUser($this->acme);

        $this->acme->run(fn () => Invoice::factory()->count(3)->create());
        $few = $this->queryCountFor('/invoices');

        $this->acme->run(fn () => Invoice::factory()->count(12)->create());
        $many = $this->queryCountFor('/invoices');

        $this->assertSame($few, $many);
    }

    private function queryCountFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_show_page_exposes_items_payments_and_what_the_user_may_do(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->pay($this->acme, $this->issuedOf($this->acme), 100);

        $this->get("/invoices/{$invoice->id}")->assertInertia(fn (Assert $page) => $page
            ->component('invoices/Show')
            ->where('invoice.number', $invoice->number)
            ->has('invoice.items', 2)
            ->has('invoice.payments', 1)
            ->where('invoice.amount_paid_cents', 100)
            ->where('invoice.balance_cents', $invoice->total_cents - 100)
            ->where('can.update', false)   // has an active payment
            ->where('can.void', false)
            ->where('can.issue', false)
            ->where('can.delete', false));
    }

    // ---- tenant isolation ------------------------------------------------------------------

    public function test_another_tenants_invoice_returns_404_everywhere(): void
    {
        $foreign = $this->draftOf($this->globex);
        $issuedForeign = $this->issuedOf($this->globex);
        $this->actingAsTenantUser($this->acme);
        $ownCustomer = $this->acme->run(fn () => Customer::factory()->create());

        foreach ([$foreign, $issuedForeign] as $invoice) {
            $this->get("/invoices/{$invoice->id}")->assertNotFound();
            $this->get("/invoices/{$invoice->id}/edit")->assertNotFound();
            $this->put("/invoices/{$invoice->id}", $this->payload($ownCustomer->id))->assertNotFound();
            $this->post("/invoices/{$invoice->id}/issue")->assertNotFound();
            $this->post("/invoices/{$invoice->id}/void", ['reason' => 'x'])->assertNotFound();
            $this->delete("/invoices/{$invoice->id}")->assertNotFound();
        }

        $this->globex->run(function () use ($foreign, $issuedForeign) {
            $this->assertSame(InvoiceState::Draft, Invoice::findOrFail($foreign->id)->state);
            $this->assertSame(InvoiceState::Issued, Invoice::findOrFail($issuedForeign->id)->state);
        });
    }
}
