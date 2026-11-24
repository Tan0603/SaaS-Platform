<?php

namespace Tests\Feature\Payments;

use App\Enums\InvoiceState;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentProcessingTest extends TestCase
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

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    /**
     * An issued, unpaid invoice whose total is exactly $totalCents (10000 = 100.00).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function invoiceOf(Tenant $tenant, int $totalCents = 10000, array $attributes = []): Invoice
    {
        return $tenant->run(function () use ($totalCents, $attributes) {
            $invoice = Invoice::factory()->issued()->create(array_merge([
                'issue_date' => today()->subDays(5),
                'due_date' => today()->addDays(25),
            ], $attributes));
            Invoice::withoutGlobalScopes()->whereKey($invoice->id)->update(['subtotal_cents' => $totalCents, 'total_cents' => $totalCents]);

            return $invoice->fresh();
        });
    }

    private function reload(Tenant $tenant, Invoice $invoice): Invoice
    {
        return $tenant->run(fn () => Invoice::findOrFail($invoice->id));
    }

    private function paymentCount(Tenant $tenant, Invoice $invoice): int
    {
        return $tenant->run(fn () => Payment::where('invoice_id', $invoice->id)->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(string $amount, array $overrides = []): array
    {
        return array_merge([
            'amount' => $amount,
            'paid_at' => today()->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'REF-1',
        ], $overrides);
    }

    private function payUrl(Invoice $invoice): string
    {
        return "/invoices/{$invoice->id}/payments";
    }

    // ---- happy paths -----------------------------------------------------------------------

    public function test_a_partial_payment_makes_the_invoice_partial(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->invoiceOf($this->acme, 10000);

        $this->post($this->payUrl($invoice), $this->payload('40.00'))
            ->assertRedirect("/invoices/{$invoice->id}")
            ->assertSessionHasNoErrors();

        $fresh = $this->reload($this->acme, $invoice);
        $this->assertSame(InvoiceStatus::Partial, $fresh->status);
        $this->assertSame(4000, $fresh->amount_paid_cents);
        $this->assertSame(6000, $fresh->balance_cents);

        $payment = $this->acme->run(fn () => Payment::sole());
        $this->assertSame(4000, $payment->amount_cents);
        $this->assertSame('REF-1', $payment->reference);
        $this->assertSame('acme', $payment->tenant_id);
    }

    public function test_two_partial_payments_adding_up_to_the_total_make_it_paid(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->invoiceOf($this->acme, 10000);

        $this->post($this->payUrl($invoice), $this->payload('33.33'))->assertSessionHasNoErrors();
        $this->assertSame(InvoiceStatus::Partial, $this->reload($this->acme, $invoice)->status);

        $this->post($this->payUrl($invoice), $this->payload('66.67'))->assertSessionHasNoErrors();

        $fresh = $this->reload($this->acme, $invoice);
        $this->assertSame(InvoiceStatus::Paid, $fresh->status);
        $this->assertSame(10000, $fresh->amount_paid_cents);
        $this->assertSame(0, $fresh->balance_cents);
    }

    public function test_one_payment_equal_to_the_total_makes_it_paid(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->invoiceOf($this->acme, 12550);

        $this->post($this->payUrl($invoice), $this->payload('125.50'))->assertSessionHasNoErrors();

        $fresh = $this->reload($this->acme, $invoice);
        $this->assertSame(InvoiceStatus::Paid, $fresh->status);
        $this->assertSame(12550, $fresh->amount_paid_cents);
    }

    // ---- overpayment and invalid amounts ---------------------------------------------------

    public function test_a_payment_larger_than_the_balance_is_rejected(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->invoiceOf($this->acme, 10000);

        $this->post($this->payUrl($invoice), $this->payload('100.01'))->assertSessionHasErrors('amount');
        $this->postJson($this->payUrl($invoice), $this->payload('100.01'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, $this->paymentCount($this->acme, $invoice));
        $fresh = $this->reload($this->acme, $invoice);
        $this->assertSame(InvoiceStatus::Unpaid, $fresh->status);
        $this->assertSame(0, $fresh->amount_paid_cents);
    }

    public function test_a_second_payment_exceeding_the_remaining_balance_is_rejected(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->invoiceOf($this->acme, 10000);

        $this->post($this->payUrl($invoice), $this->payload('70.00'))->assertSessionHasNoErrors();
        $this->post($this->payUrl($invoice), $this->payload('30.01'))->assertSessionHasErrors('amount');

        $this->assertSame(1, $this->paymentCount($this->acme, $invoice));
        $fresh = $this->reload($this->acme, $invoice);
        $this->assertSame(InvoiceStatus::Partial, $fresh->status);
        $this->assertSame(7000, $fresh->amount_paid_cents);
    }

    public function test_zero_negative_and_malformed_amounts_are_rejected(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->invoiceOf($this->acme, 10000);

        foreach (['0', '0.00', '-5', '-0.01', 'abc', '', '1.234', '1e3', '10,00'] as $amount) {
            $this->post($this->payUrl($invoice), $this->payload($amount))->assertSessionHasErrors('amount');
        }

        $this->assertSame(0, $this->paymentCount($this->acme, $invoice));
        $this->assertSame(InvoiceStatus::Unpaid, $this->reload($this->acme, $invoice)->status);
    }

    public function test_a_payment_on_a_paid_invoice_is_rejected(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->invoiceOf($this->acme, 10000);
        $this->post($this->payUrl($invoice), $this->payload('100.00'))->assertSessionHasNoErrors();

        $this->post($this->payUrl($invoice), $this->payload('0.01'))->assertSessionHasErrors('amount');

        $this->assertSame(1, $this->paymentCount($this->acme, $invoice));
        $this->assertSame(10000, $this->reload($this->acme, $invoice)->amount_paid_cents);
    }

    public function test_a_payment_on_a_draft_or_void_invoice_is_rejected(): void
    {
        $this->actingAsTenantUser($this->acme);
        $draft = $this->acme->run(function () {
            $invoice = Invoice::factory()->create(['issue_date' => today()->subDays(5), 'due_date' => today()->addDays(25)]);
            Invoice::withoutGlobalScopes()->whereKey($invoice->id)->update(['subtotal_cents' => 10000, 'total_cents' => 10000]);

            return $invoice->fresh();
        });
        $this->assertSame(InvoiceState::Draft, $draft->state);

        $void = $this->invoiceOf($this->acme, 10000);
        $this->post("/invoices/{$void->id}/void", ['reason' => 'Mistake'])->assertRedirect();

        foreach ([$draft, $void] as $invoice) {
            $this->post($this->payUrl($invoice), $this->payload('10.00'))->assertSessionHasErrors('amount');
            $this->assertSame(0, $this->paymentCount($this->acme, $invoice));
            $this->assertSame(0, $this->reload($this->acme, $invoice)->amount_paid_cents);
        }

        // The UI is told not to offer the form either.
        $this->get("/invoices/{$draft->id}")->assertInertia(fn (Assert $page) => $page->where('can.record_payment', false));
        $this->get("/invoices/{$void->id}")->assertInertia(fn (Assert $page) => $page->where('can.record_payment', false));
    }

    // ---- date, method, reference -----------------------------------------------------------

    public function test_the_payment_date_cannot_be_in_the_future_or_before_the_issue_date(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 24)->setTime(12, 0));
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->invoiceOf($this->acme, 10000, ['issue_date' => '2026-11-10', 'due_date' => '2026-12-10']);

        $this->post($this->payUrl($invoice), $this->payload('10.00', ['paid_at' => '2026-11-25']))->assertSessionHasErrors('paid_at');
        $this->post($this->payUrl($invoice), $this->payload('10.00', ['paid_at' => '2026-11-09']))->assertSessionHasErrors('paid_at');
        $this->post($this->payUrl($invoice), $this->payload('10.00', ['paid_at' => 'not a date']))->assertSessionHasErrors('paid_at');
        $this->assertSame(0, $this->paymentCount($this->acme, $invoice));

        // Both boundaries are allowed.
        $this->post($this->payUrl($invoice), $this->payload('10.00', ['paid_at' => '2026-11-10']))->assertSessionHasNoErrors();
        $this->post($this->payUrl($invoice), $this->payload('10.00', ['paid_at' => '2026-11-24']))->assertSessionHasNoErrors();
        $this->assertSame(2, $this->paymentCount($this->acme, $invoice));
    }

    public function test_an_invalid_method_or_overlong_reference_is_rejected(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->invoiceOf($this->acme, 10000);

        $this->post($this->payUrl($invoice), $this->payload('10.00', ['method' => 'bitcoin']))->assertSessionHasErrors('method');
        $this->post($this->payUrl($invoice), $this->payload('10.00', ['method' => '']))->assertSessionHasErrors('method');
        $this->post($this->payUrl($invoice), $this->payload('10.00', ['reference' => str_repeat('x', 101)]))->assertSessionHasErrors('reference');
        $this->assertSame(0, $this->paymentCount($this->acme, $invoice));

        $this->post($this->payUrl($invoice), $this->payload('10.00', ['reference' => '']))->assertSessionHasNoErrors();
        $this->assertNull($this->acme->run(fn () => Payment::sole())->reference);
    }

    public function test_the_browser_cannot_set_the_tenant_status_or_totals_of_a_payment(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->invoiceOf($this->acme, 10000);

        $this->post($this->payUrl($invoice), $this->payload('10.00', [
            'tenant_id' => 'globex', 'invoice_id' => 999999, 'voided_at' => now()->toDateTimeString(),
            'amount_paid_cents' => 10000, 'status' => 'paid',
        ]))->assertSessionHasNoErrors();

        $payment = $this->acme->run(fn () => Payment::sole());
        $this->assertSame('acme', $payment->tenant_id);
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertNull($payment->voided_at);
        $fresh = $this->reload($this->acme, $invoice);
        $this->assertSame(1000, $fresh->amount_paid_cents);
        $this->assertSame(InvoiceStatus::Partial, $fresh->status);
    }

    // ---- void ------------------------------------------------------------------------------

    public function test_voiding_a_payment_on_a_paid_invoice_makes_it_partial_and_payable_again(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->invoiceOf($this->acme, 10000);
        $this->post($this->payUrl($invoice), $this->payload('60.00'));
        $this->post($this->payUrl($invoice), $this->payload('40.00'));
        $this->assertSame(InvoiceStatus::Paid, $this->reload($this->acme, $invoice)->status);
        $second = $this->acme->run(fn () => Payment::orderByDesc('id')->first());

        $this->post("/payments/{$second->id}/void", ['reason' => 'Cheque bounced'])
            ->assertRedirect("/invoices/{$invoice->id}")
            ->assertSessionHasNoErrors();

        $fresh = $this->reload($this->acme, $invoice);
        $this->assertSame(InvoiceStatus::Partial, $fresh->status);
        $this->assertSame(6000, $fresh->amount_paid_cents);
        $this->assertSame(4000, $fresh->balance_cents);

        $voided = $this->acme->run(fn () => Payment::findOrFail($second->id));
        $this->assertNotNull($voided->voided_at);
        $this->assertSame('Cheque bounced', $voided->void_reason);
        $this->assertSame(2, $this->paymentCount($this->acme, $invoice));    // never deleted
        $this->acme->run(fn () => $this->assertSame(6000, (int) Payment::active()->sum('amount_cents')));

        // The voided money can be paid again, but not more than the balance.
        $this->post($this->payUrl($invoice), $this->payload('40.01'))->assertSessionHasErrors('amount');
        $this->post($this->payUrl($invoice), $this->payload('40.00'))->assertSessionHasNoErrors();
        $this->assertSame(InvoiceStatus::Paid, $this->reload($this->acme, $invoice)->status);
    }

    public function test_voiding_every_payment_returns_the_invoice_to_unpaid(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->invoiceOf($this->acme, 10000);
        $this->post($this->payUrl($invoice), $this->payload('25.00'));
        $payment = $this->acme->run(fn () => Payment::sole());

        $this->post("/payments/{$payment->id}/void", ['reason' => 'Entered twice'])->assertRedirect();

        $fresh = $this->reload($this->acme, $invoice);
        $this->assertSame(InvoiceStatus::Unpaid, $fresh->status);
        $this->assertSame(0, $fresh->amount_paid_cents);
        $this->acme->run(function () use ($invoice) {
            $fresh = Invoice::findOrFail($invoice->id);
            $this->assertTrue($fresh->canBeEdited());
            $this->assertTrue($fresh->canBeVoided());
        });
    }

    public function test_voiding_needs_a_reason_and_an_already_voided_payment_is_rejected(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->invoiceOf($this->acme, 10000);
        $this->post($this->payUrl($invoice), $this->payload('50.00'));
        $payment = $this->acme->run(fn () => Payment::sole());

        $this->post("/payments/{$payment->id}/void", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/payments/{$payment->id}/void", ['reason' => str_repeat('x', 256)])->assertSessionHasErrors('reason');
        $this->assertNull($this->acme->run(fn () => Payment::findOrFail($payment->id))->voided_at);

        $this->post("/payments/{$payment->id}/void", ['reason' => 'First'])->assertRedirect();
        $this->post("/payments/{$payment->id}/void", ['reason' => 'Second'])->assertForbidden();

        $this->assertSame('First', $this->acme->run(fn () => Payment::findOrFail($payment->id))->void_reason);
        $this->assertSame(0, $this->reload($this->acme, $invoice)->amount_paid_cents);
    }

    public function test_an_invoice_with_active_payments_stays_locked_and_unlocks_when_they_are_voided(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->invoiceOf($this->acme, 10000);
        $this->post($this->payUrl($invoice), $this->payload('10.00'));
        $payment = $this->acme->run(fn () => Payment::sole());

        $this->get("/invoices/{$invoice->id}/edit")->assertForbidden();
        $this->post("/invoices/{$invoice->id}/void", ['reason' => 'x'])->assertForbidden();

        $this->post("/payments/{$payment->id}/void", ['reason' => 'Oops'])->assertRedirect();

        $this->get("/invoices/{$invoice->id}/edit")->assertOk();
        $this->post("/invoices/{$invoice->id}/void", ['reason' => 'Now fine'])->assertRedirect();
        $this->assertSame(InvoiceState::Void, $this->reload($this->acme, $invoice)->state);
    }

    // ---- tenant isolation ------------------------------------------------------------------

    public function test_another_tenants_invoice_and_payment_return_404(): void
    {
        $foreign = $this->invoiceOf($this->globex, 10000);
        $foreignPayment = $this->globex->run(fn () => Payment::factory()->create(['invoice_id' => $foreign->id, 'amount_cents' => 100]));
        $this->actingAsTenantUser($this->acme);

        $this->post($this->payUrl($foreign), $this->payload('10.00'))->assertNotFound();
        $this->post("/payments/{$foreignPayment->id}/void", ['reason' => 'x'])->assertNotFound();

        $this->assertSame(1, $this->paymentCount($this->globex, $foreign));
        $this->assertNull($this->globex->run(fn () => Payment::findOrFail($foreignPayment->id))->voided_at);
        $this->assertSame(0, $this->reload($this->globex, $foreign)->amount_paid_cents);
    }

    // ---- overdue interplay -----------------------------------------------------------------

    public function test_a_paid_invoice_is_never_overdue_and_paying_in_full_clears_overdue(): void
    {
        $this->travelTo(now()->setDate(2026, 12, 10)->setTime(10, 0));
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->invoiceOf($this->acme, 10000, ['issue_date' => '2026-11-01', 'due_date' => '2026-12-01']);

        $this->acme->run(function () use ($invoice) {
            $this->assertTrue(Invoice::findOrFail($invoice->id)->is_overdue);
            $this->assertSame([$invoice->id], Invoice::overdue()->pluck('id')->all());
        });

        $this->post($this->payUrl($invoice), $this->payload('40.00'))->assertSessionHasNoErrors();
        $this->acme->run(fn () => $this->assertSame('overdue', Invoice::findOrFail($invoice->id)->display_status));   // partial but past due

        $this->post($this->payUrl($invoice), $this->payload('60.00'))->assertSessionHasNoErrors();

        $this->acme->run(function () use ($invoice) {
            $fresh = Invoice::findOrFail($invoice->id);
            $this->assertFalse($fresh->is_overdue);
            $this->assertSame('paid', $fresh->display_status);
            $this->assertSame(0, Invoice::overdue()->count());
        });

        // Voiding a payment on a past-due invoice makes it overdue again (computed, nothing stored).
        $payment = $this->acme->run(fn () => Payment::orderByDesc('id')->first());
        $this->post("/payments/{$payment->id}/void", ['reason' => 'Bounced']);
        $this->acme->run(fn () => $this->assertSame('overdue', Invoice::findOrFail($invoice->id)->display_status));
    }

    // ---- invoice page ----------------------------------------------------------------------

    public function test_the_invoice_page_exposes_the_payment_form_state_and_void_buttons(): void
    {
        $this->actingAsTenantUser($this->acme);
        $invoice = $this->invoiceOf($this->acme, 10000);
        $this->post($this->payUrl($invoice), $this->payload('30.00'));

        $this->get("/invoices/{$invoice->id}")->assertInertia(fn (Assert $page) => $page
            ->component('invoices/Show')
            ->where('can.record_payment', true)
            ->where('invoice.balance_cents', 7000)
            ->has('methods', 5)
            ->has('invoice.payments', 1)
            ->where('invoice.payments.0.can_void', true)
            ->where('invoice.payments.0.voided', false));

        $this->post($this->payUrl($invoice), $this->payload('70.00'));
        $this->get("/invoices/{$invoice->id}")->assertInertia(fn (Assert $page) => $page
            ->where('can.record_payment', false)   // fully paid: form hidden
            ->where('invoice.balance_cents', 0));
    }

    // ---- payments screen -------------------------------------------------------------------

    public function test_the_payments_index_lists_only_own_payments_and_hides_voided_by_default(): void
    {
        $mine = $this->invoiceOf($this->acme, 10000);
        $this->acme->run(function () use ($mine) {
            Payment::factory()->create(['invoice_id' => $mine->id, 'amount_cents' => 100]);
            Payment::factory()->voided()->create(['invoice_id' => $mine->id, 'amount_cents' => 200]);
        });
        $theirs = $this->invoiceOf($this->globex, 10000);
        $this->globex->run(fn () => Payment::factory()->create(['invoice_id' => $theirs->id, 'amount_cents' => 300]));
        $this->actingAsTenantUser($this->acme);

        $this->get('/payments')->assertInertia(fn (Assert $page) => $page
            ->component('payments/Index')
            ->has('payments.data', 1)
            ->where('payments.data.0.amount_cents', 100)
            ->where('payments.data.0.invoice_number', $mine->number)
            ->has('payments.data.0.customer'));

        $this->get('/payments?include_voided=1')->assertInertia(fn (Assert $page) => $page
            ->has('payments.data', 2)
            ->where('filters.include_voided', true));
    }

    public function test_the_payments_index_filters_by_date_and_method(): void
    {
        $invoice = $this->invoiceOf($this->acme, 100000, ['issue_date' => '2026-01-01', 'due_date' => '2026-02-01']);
        $this->acme->run(function () use ($invoice) {
            Payment::factory()->create(['invoice_id' => $invoice->id, 'amount_cents' => 100, 'paid_at' => '2026-01-10', 'method' => 'cash']);
            Payment::factory()->create(['invoice_id' => $invoice->id, 'amount_cents' => 200, 'paid_at' => '2026-02-10', 'method' => 'card']);
            Payment::factory()->create(['invoice_id' => $invoice->id, 'amount_cents' => 300, 'paid_at' => '2026-03-10', 'method' => 'card']);
        });
        $this->actingAsTenantUser($this->acme);

        $this->get('/payments?from=2026-02-01&to=2026-02-28')->assertInertia(fn (Assert $page) => $page
            ->has('payments.data', 1)->where('payments.data.0.amount_cents', 200));
        $this->get('/payments?method=card')->assertInertia(fn (Assert $page) => $page->has('payments.data', 2));
        $this->get('/payments?method=card&from=2026-03-01')->assertInertia(fn (Assert $page) => $page
            ->has('payments.data', 1)->where('payments.data.0.amount_cents', 300));
        $this->get('/payments?method=cash&from=2026-03-01')->assertInertia(fn (Assert $page) => $page->has('payments.data', 0));

        $this->get('/payments?method=bitcoin')->assertSessionHasErrors('method');
        $this->get('/payments?from=yesterday')->assertSessionHasErrors('from');
    }

    public function test_the_payments_index_query_count_does_not_grow_with_the_number_of_payments(): void
    {
        $this->actingAsTenantUser($this->acme);

        $this->acme->run(fn () => Payment::factory()->count(3)->create());
        $few = $this->queryCountFor('/payments');

        $this->acme->run(fn () => Payment::factory()->count(12)->create());
        $many = $this->queryCountFor('/payments');

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

    public function test_guests_cannot_reach_payment_routes(): void
    {
        $this->get('/payments')->assertRedirect('/login');
        $this->post('/invoices/1/payments', [])->assertRedirect('/login');
        $this->post('/payments/1/void', [])->assertRedirect('/login');
    }
}
