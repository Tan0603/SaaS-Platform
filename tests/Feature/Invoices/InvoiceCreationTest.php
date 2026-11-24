<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceState;
use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvoiceCreationTest extends TestCase
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

    private function customerOf(Tenant $tenant, array $attributes = []): Customer
    {
        return $tenant->run(fn () => Customer::factory()->create($attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Customer $customer, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $customer->id,
            'issue_date' => '2026-11-24',
            'due_date' => '2026-12-24',
            'items' => [
                ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '10.00'],
                ['description' => 'Parts', 'quantity' => '1.5', 'unit_price' => '3.33'],
            ],
        ], $overrides);
    }

    private function latestInvoice(Tenant $tenant): Invoice
    {
        return $tenant->run(fn () => Invoice::with('items')->latest('id')->firstOrFail());
    }

    // ---- creation and totals ---------------------------------------------------------------

    public function test_a_draft_is_created_with_items_and_server_calculated_totals(): void
    {
        $this->actingAsTenantUser($this->acme);
        $customer = $this->customerOf($this->acme);

        $this->post('/invoices', $this->payload($customer))->assertRedirect();

        $invoice = $this->latestInvoice($this->acme);
        $this->assertSame('acme', $invoice->tenant_id);
        $this->assertSame(InvoiceState::Draft, $invoice->state);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertNull($invoice->number);
        $this->assertSame(2000 + 500, $invoice->subtotal_cents);   // 2 x 10.00 + 1.5 x 3.33 (4.995 -> 5.00)
        $this->assertSame(2500, $invoice->total_cents);
        $this->assertSame(0, $invoice->amount_paid_cents);
        $this->assertCount(2, $invoice->items);
        $this->assertSame([2000, 500], $invoice->items->pluck('line_total_cents')->all());
        $this->assertSame([1000, 333], $invoice->items->pluck('unit_price_cents')->all());
        $this->assertSame('1.50', $invoice->items[1]->quantity);
        $this->assertSame(['acme', 'acme'], $invoice->items->pluck('tenant_id')->all());
    }

    public function test_totals_sent_by_the_browser_are_ignored(): void
    {
        $this->actingAsTenantUser($this->acme);
        $customer = $this->customerOf($this->acme);

        $payload = $this->payload($customer, ['subtotal' => '1.00', 'total' => '1.00', 'total_cents' => 1, 'amount_paid_cents' => 99999, 'status' => 'paid', 'state' => 'issued']);
        $payload['items'][0]['line_total_cents'] = 1;
        $payload['items'][0]['line_total'] = '0.01';

        $this->post('/invoices', $payload)->assertSessionHasNoErrors();

        $invoice = $this->latestInvoice($this->acme);
        $this->assertSame(2500, $invoice->total_cents);
        $this->assertSame(2500, $invoice->subtotal_cents);
        $this->assertSame(0, $invoice->amount_paid_cents);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(InvoiceState::Draft, $invoice->state);
        $this->assertSame(2000, $invoice->items[0]->line_total_cents);
    }

    public function test_save_and_issue_creates_a_numbered_invoice_in_one_step(): void
    {
        $this->actingAsTenantUser($this->acme);
        $customer = $this->customerOf($this->acme);

        $this->post('/invoices', $this->payload($customer, ['action' => 'issue']))->assertRedirect();

        $invoice = $this->latestInvoice($this->acme);
        $this->assertSame(InvoiceState::Issued, $invoice->state);
        $this->assertSame('IV-00001', $invoice->number);
        $this->assertNotNull($invoice->issued_at);
    }

    public function test_a_draft_sends_no_email(): void
    {
        Mail::fake();
        Queue::fake();
        $this->actingAsTenantUser($this->acme);
        $customer = $this->customerOf($this->acme);

        $this->post('/invoices', $this->payload($customer))->assertRedirect();

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();

        // A draft is not an outstanding receivable either.
        $this->assertSame(0, $this->acme->run(fn () => Invoice::outstanding()->count()));
    }

    // ---- validation ------------------------------------------------------------------------

    public function test_items_are_required(): void
    {
        $this->actingAsTenantUser($this->acme);
        $customer = $this->customerOf($this->acme);

        $this->post('/invoices', $this->payload($customer, ['items' => []]))->assertSessionHasErrors('items');
        $this->assertSame(0, $this->acme->run(fn () => Invoice::count()));
    }

    public function test_due_date_cannot_be_before_issue_date(): void
    {
        $this->actingAsTenantUser($this->acme);
        $customer = $this->customerOf($this->acme);

        $this->post('/invoices', $this->payload($customer, ['issue_date' => '2026-11-24', 'due_date' => '2026-11-23']))
            ->assertSessionHasErrors('due_date');
    }

    public function test_price_and_quantity_must_be_valid(): void
    {
        $this->actingAsTenantUser($this->acme);
        $customer = $this->customerOf($this->acme);

        $cases = [
            'negative price' => [['description' => 'X', 'quantity' => '1', 'unit_price' => '-5.00'], 'items.0.unit_price'],
            'three decimals' => [['description' => 'X', 'quantity' => '1', 'unit_price' => '5.001'], 'items.0.unit_price'],
            'not a number' => [['description' => 'X', 'quantity' => '1', 'unit_price' => 'abc'], 'items.0.unit_price'],
            'zero quantity' => [['description' => 'X', 'quantity' => '0', 'unit_price' => '5.00'], 'items.0.quantity'],
            'negative quantity' => [['description' => 'X', 'quantity' => '-1', 'unit_price' => '5.00'], 'items.0.quantity'],
            'quantity with 3 decimals' => [['description' => 'X', 'quantity' => '1.234', 'unit_price' => '5.00'], 'items.0.quantity'],
            'missing description' => [['description' => '', 'quantity' => '1', 'unit_price' => '5.00'], 'items.0.description'],
        ];

        foreach ($cases as [$item, $field]) {
            $this->post('/invoices', $this->payload($customer, ['items' => [$item]]))
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(0, $this->acme->run(fn () => Invoice::count()));
    }

    public function test_a_zero_price_line_is_allowed(): void
    {
        $this->actingAsTenantUser($this->acme);
        $customer = $this->customerOf($this->acme);

        $this->post('/invoices', $this->payload($customer, ['items' => [['description' => 'Free sample', 'quantity' => '1', 'unit_price' => '0']]]))
            ->assertSessionHasNoErrors();
    }

    public function test_a_customer_from_another_tenant_is_rejected(): void
    {
        $this->actingAsTenantUser($this->acme);
        $foreign = $this->customerOf($this->globex);

        $this->post('/invoices', $this->payload($foreign))->assertSessionHasErrors('customer_id');
        $this->assertSame(0, $this->globex->run(fn () => Invoice::count()));
        $this->assertSame(0, $this->acme->run(fn () => Invoice::count()));
    }

    public function test_an_archived_customer_cannot_be_picked_for_a_new_invoice(): void
    {
        $this->actingAsTenantUser($this->acme);
        $archived = $this->customerOf($this->acme, ['deleted_at' => now()]);

        $this->post('/invoices', $this->payload($archived))->assertSessionHasErrors('customer_id');
        $this->assertSame(0, $this->acme->run(fn () => Invoice::count()));
    }

    // ---- numbering -------------------------------------------------------------------------

    public function test_invoice_numbers_are_sequential_per_tenant(): void
    {
        $acmeCustomer = $this->customerOf($this->acme);
        $globexCustomer = $this->customerOf($this->globex);

        $this->actingAsTenantUser($this->acme);
        $this->post('/invoices', $this->payload($acmeCustomer, ['action' => 'issue']))->assertRedirect();
        $this->post('/invoices', $this->payload($acmeCustomer, ['action' => 'issue']))->assertRedirect();

        $this->actingAsTenantUser($this->globex);
        $this->post('/invoices', $this->payload($globexCustomer, ['action' => 'issue']))->assertRedirect();

        $this->assertSame(['IV-00001', 'IV-00002'], $this->acme->run(fn () => Invoice::orderBy('id')->pluck('number')->all()));
        $this->assertSame(['IV-00001'], $this->globex->run(fn () => Invoice::pluck('number')->all()));
    }

    public function test_the_invoice_prefix_comes_from_the_tenant(): void
    {
        $this->acme->update(['invoice_prefix' => 'INV-']);
        $this->actingAsTenantUser($this->acme);
        $customer = $this->customerOf($this->acme);

        $this->post('/invoices', $this->payload($customer, ['action' => 'issue']))->assertRedirect();

        $this->assertSame('INV-00001', $this->latestInvoice($this->acme)->number);
    }

    // ---- create page -----------------------------------------------------------------------

    public function test_create_page_offers_active_customers_with_their_payment_terms(): void
    {
        $this->actingAsTenantUser($this->acme);
        $active = $this->customerOf($this->acme, ['name' => 'Active Co', 'payment_terms_days' => 14]);
        $this->customerOf($this->acme, ['name' => 'Archived Co', 'deleted_at' => now()]);
        $this->customerOf($this->globex, ['name' => 'Foreign Co']);

        $this->get('/invoices/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('invoices/Form')
                ->has('customers', 1)
                ->where('customers.0.id', $active->id)
                ->where('customers.0.payment_terms_days', 14)   // the form pre-fills due date = issue date + terms
                ->where('defaultPaymentTerms', 30));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/invoices')->assertRedirect('/login');
        $this->post('/invoices', [])->assertRedirect('/login');
    }
}
