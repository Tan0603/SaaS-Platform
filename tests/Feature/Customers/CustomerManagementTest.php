<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
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

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Beta Trading Sdn Bhd',
            'email' => 'accounts@beta.test',
            'phone' => '012-3456789',
            'address' => '1 Jalan Beta, Kuala Lumpur',
            'registration_no' => '202001000001',
            'payment_terms_days' => 14,
        ], $overrides);
    }

    private function customerOf(Tenant $tenant, array $attributes = []): Customer
    {
        return $tenant->run(fn () => Customer::factory()->create($attributes));
    }

    // ---- happy paths ----------------------------------------------------------------------

    public function test_customer_can_be_created_in_the_current_tenant(): void
    {
        $this->actingAsTenantUser($this->acme);

        $this->post('/customers', $this->payload())->assertRedirect('/customers');

        $customer = $this->acme->run(fn () => Customer::firstWhere('email', 'accounts@beta.test'));
        $this->assertSame('acme', $customer->tenant_id);
        $this->assertSame(14, $customer->payment_terms_days);
    }

    public function test_customer_can_be_updated(): void
    {
        $this->actingAsTenantUser($this->acme);
        $customer = $this->customerOf($this->acme);

        $this->put("/customers/{$customer->id}", $this->payload(['name' => 'Renamed Co', 'email' => $customer->email]))
            ->assertRedirect("/customers/{$customer->id}");

        $this->assertSame('Renamed Co', $this->acme->run(fn () => Customer::find($customer->id)->name));
    }

    public function test_updating_without_changing_the_email_is_not_a_duplicate(): void
    {
        $this->actingAsTenantUser($this->acme);
        $customer = $this->customerOf($this->acme, ['email' => 'same@acme.test']);

        $this->put("/customers/{$customer->id}", $this->payload(['email' => 'same@acme.test']))
            ->assertSessionHasNoErrors();
    }

    public function test_customer_can_be_archived_and_restored(): void
    {
        $this->actingAsTenantUser($this->acme);
        $customer = $this->customerOf($this->acme);

        $this->delete("/customers/{$customer->id}")->assertRedirect('/customers');
        $this->acme->run(function () use ($customer) {
            $this->assertNull(Customer::find($customer->id));
            $this->assertNotNull(Customer::onlyTrashed()->find($customer->id));
        });

        $this->patch("/customers/{$customer->id}/restore")->assertRedirect();
        $this->acme->run(fn () => $this->assertNotNull(Customer::find($customer->id)));
    }

    public function test_archiving_a_customer_with_invoices_keeps_the_invoices(): void
    {
        $this->actingAsTenantUser($this->acme);
        $customer = $this->customerOf($this->acme);
        $this->acme->run(fn () => Invoice::factory()->count(2)->paid()->create(['customer_id' => $customer->id]));

        $this->delete("/customers/{$customer->id}")->assertRedirect('/customers');

        $this->acme->run(function () use ($customer) {
            $this->assertSame(2, Invoice::where('customer_id', $customer->id)->count());
            $this->assertNotNull(Customer::onlyTrashed()->find($customer->id));
        });
    }

    public function test_customer_without_invoices_can_be_deleted_permanently(): void
    {
        $this->actingAsTenantUser($this->acme);
        $active = $this->customerOf($this->acme);
        $archived = $this->acme->run(fn () => Customer::factory()->archived()->create());

        $this->delete("/customers/{$active->id}/permanent")->assertRedirect('/customers');
        $this->delete("/customers/{$archived->id}/permanent")->assertRedirect('/customers');

        $this->acme->run(function () use ($active, $archived) {
            $this->assertNull(Customer::withTrashed()->find($active->id));
            $this->assertNull(Customer::withTrashed()->find($archived->id));
        });
    }

    public function test_customer_with_invoices_cannot_be_deleted_permanently(): void
    {
        $this->actingAsTenantUser($this->acme);
        $customer = $this->customerOf($this->acme);
        $this->acme->run(fn () => Invoice::factory()->create(['customer_id' => $customer->id]));

        $this->from('/customers')
            ->delete("/customers/{$customer->id}/permanent")
            ->assertRedirect('/customers')
            ->assertSessionHas('error');

        $this->acme->run(function () use ($customer) {
            $this->assertNotNull(Customer::find($customer->id));
            $this->assertSame(1, Invoice::where('customer_id', $customer->id)->count());
        });
    }

    public function test_database_refuses_to_delete_a_customer_with_invoices(): void
    {
        $customer = $this->customerOf($this->acme);
        $this->acme->run(fn () => Invoice::factory()->create(['customer_id' => $customer->id]));

        $this->expectException(QueryException::class);
        DB::table('customers')->where('id', $customer->id)->delete();
    }

    public function test_index_exposes_invoice_count_to_choose_delete_or_archive(): void
    {
        $this->actingAsTenantUser($this->acme);
        $withInvoices = $this->customerOf($this->acme, ['name' => 'A With Invoices']);
        $this->customerOf($this->acme, ['name' => 'B Without Invoices']);
        $this->acme->run(fn () => Invoice::factory()->count(2)->create(['customer_id' => $withInvoices->id]));

        $this->get('/customers')->assertInertia(fn (Assert $page) => $page
            ->where('customers.data.0.invoices_count', 2)
            ->where('customers.data.1.invoices_count', 0));
    }

    // ---- validation -----------------------------------------------------------------------

    public function test_name_is_required(): void
    {
        $this->actingAsTenantUser($this->acme);

        $this->post('/customers', $this->payload(['name' => '']))->assertSessionHasErrors('name');
    }

    public function test_email_must_be_valid(): void
    {
        $this->actingAsTenantUser($this->acme);

        $this->post('/customers', $this->payload(['email' => 'not-an-email']))->assertSessionHasErrors('email');
    }

    public function test_payment_terms_must_be_an_allowed_value(): void
    {
        $this->actingAsTenantUser($this->acme);

        $this->post('/customers', $this->payload(['payment_terms_days' => 45]))->assertSessionHasErrors('payment_terms_days');
    }

    public function test_duplicate_email_in_the_same_tenant_is_rejected(): void
    {
        $this->actingAsTenantUser($this->acme);
        $this->customerOf($this->acme, ['email' => 'dup@test.test']);

        $this->post('/customers', $this->payload(['email' => 'dup@test.test']))->assertSessionHasErrors('email');
        $this->assertSame(1, $this->acme->run(fn () => Customer::count()));
    }

    public function test_same_email_is_allowed_in_another_tenant(): void
    {
        $this->customerOf($this->globex, ['email' => 'shared@test.test']);
        $this->actingAsTenantUser($this->acme);

        $this->post('/customers', $this->payload(['email' => 'shared@test.test']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/customers');

        $this->assertSame(1, $this->acme->run(fn () => Customer::where('email', 'shared@test.test')->count()));
    }

    // ---- listing --------------------------------------------------------------------------

    public function test_index_lists_only_the_current_tenants_customers_and_searches(): void
    {
        $this->actingAsTenantUser($this->acme);
        $this->customerOf($this->acme, ['name' => 'Alpha Corp', 'email' => 'a@alpha.test']);
        $this->customerOf($this->acme, ['name' => 'Bravo Ltd', 'email' => 'b@bravo.test']);
        $this->customerOf($this->globex, ['name' => 'Alpha Globex']);

        $this->get('/customers')->assertInertia(fn (Assert $page) => $page
            ->component('customers/Index')
            ->has('customers.data', 2));

        $this->get('/customers?search=ALPHA')->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 1)
            ->where('customers.data.0.name', 'Alpha Corp'));

        $this->get('/customers?search=bravo.test')->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 1));
    }

    public function test_archived_customers_are_hidden_unless_requested(): void
    {
        $this->actingAsTenantUser($this->acme);
        $this->customerOf($this->acme);
        $this->acme->run(fn () => Customer::factory()->archived()->create(['name' => 'Old Customer']));

        $this->get('/customers')->assertInertia(fn (Assert $page) => $page->has('customers.data', 1));
        $this->get('/customers?archived=1')->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 1)
            ->where('customers.data.0.name', 'Old Customer'));
    }

    public function test_show_page_lists_the_customers_invoices(): void
    {
        $this->actingAsTenantUser($this->acme);
        $customer = $this->customerOf($this->acme);
        $this->acme->run(fn () => Invoice::factory()->count(3)->issued()->create(['customer_id' => $customer->id]));
        $this->acme->run(fn () => Invoice::factory()->create());

        $this->get("/customers/{$customer->id}")->assertInertia(fn (Assert $page) => $page
            ->component('customers/Show')
            ->where('customer.id', $customer->id)
            ->has('invoices.data', 3));
    }

    public function test_index_query_count_does_not_grow_with_the_number_of_customers(): void
    {
        $this->actingAsTenantUser($this->acme);

        $this->acme->run(fn () => Customer::factory()->count(3)->create());
        $few = $this->queryCountFor('/customers');

        $this->acme->run(fn () => Customer::factory()->count(27)->create());
        $many = $this->queryCountFor('/customers');

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

    // ---- tenant isolation -----------------------------------------------------------------

    public function test_another_tenants_customer_returns_404_everywhere(): void
    {
        $foreign = $this->customerOf($this->globex);
        $this->actingAsTenantUser($this->acme);

        $this->get("/customers/{$foreign->id}")->assertNotFound();
        $this->get("/customers/{$foreign->id}/edit")->assertNotFound();
        $this->put("/customers/{$foreign->id}", $this->payload())->assertNotFound();
        $this->delete("/customers/{$foreign->id}")->assertNotFound();
        $this->patch("/customers/{$foreign->id}/restore")->assertNotFound();
        $this->delete("/customers/{$foreign->id}/permanent")->assertNotFound();

        $this->globex->run(function () use ($foreign) {
            $untouched = Customer::find($foreign->id);
            $this->assertNotNull($untouched);
            $this->assertSame($foreign->name, $untouched->name);
        });
    }

    public function test_another_tenants_archived_customer_cannot_be_restored(): void
    {
        $foreign = $this->globex->run(fn () => Customer::factory()->archived()->create());
        $this->actingAsTenantUser($this->acme);

        $this->patch("/customers/{$foreign->id}/restore")->assertNotFound();

        $this->globex->run(fn () => $this->assertNotNull(Customer::onlyTrashed()->find($foreign->id)));
    }

    public function test_route_binding_resolves_inside_the_tenant_scope(): void
    {
        // Same numeric id ranges overlap between tenants; binding must never return the other tenant's row.
        $mine = $this->customerOf($this->acme, ['name' => 'Mine']);
        $theirs = $this->customerOf($this->globex, ['name' => 'Theirs']);
        $this->actingAsTenantUser($this->acme);

        $this->get("/customers/{$mine->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('customer.name', 'Mine'));
        $this->get("/customers/{$theirs->id}")->assertNotFound();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $customer = $this->customerOf($this->acme);

        $this->get('/customers')->assertRedirect('/login');
        $this->get('/customers/create')->assertRedirect('/login');
        $this->post('/customers', $this->payload())->assertRedirect('/login');
        $this->get("/customers/{$customer->id}")->assertRedirect('/login');
        $this->delete("/customers/{$customer->id}")->assertRedirect('/login');
    }
}
