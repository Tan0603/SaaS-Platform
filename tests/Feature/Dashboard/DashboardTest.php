<?php

namespace Tests\Feature\Dashboard;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\DashboardCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Dashboard numbers (one SQL query), the per-tenant cache and its invalidation.
 * "Now" is frozen at 2026-11-24 12:00 so month and overdue boundaries are deterministic.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private Tenant $globex;

    protected function setUp(): void
    {
        parent::setUp();

        // The array store is rebuilt every time tenancy is (re)initialised, so it would forget its contents between
        // requests. Use the real Redis store (isolated by a test-only prefix) like production does.
        config(['cache.default' => 'redis', 'cache.prefix' => 'phpunit-dashboard-']);

        $this->travelTo(Carbon::parse('2026-11-24 12:00:00'));
        $this->acme = $this->createTenant('acme');
        $this->globex = $this->createTenant('globex');
        $this->clearTenantCaches();
    }

    protected function tearDown(): void
    {
        $this->clearTenantCaches();
        $this->travelBack();

        parent::tearDown();
    }

    /** Cache::flush() inside a tenant only flushes that tenant's tag (and only under the test prefix). */
    private function clearTenantCaches(): void
    {
        foreach ([$this->acme, $this->globex] as $tenant) {
            $tenant->run(fn () => Cache::flush());
        }
        tenancy()->end();
    }

    /**
     * An issued invoice with exact totals. Written with raw updates so nothing is flushed or cached by the setup.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function invoice(Tenant $tenant, int $total, int $paid = 0, string $due = '2026-12-10', array $attributes = []): Invoice
    {
        return $tenant->run(function () use ($total, $paid, $due, $attributes) {
            $invoice = Invoice::factory()->issued()->create(['issue_date' => '2026-11-01', 'due_date' => $due]);
            Invoice::withoutGlobalScopes()->whereKey($invoice->id)->update(array_merge([
                'subtotal_cents' => $total,
                'total_cents' => $total,
                'amount_paid_cents' => $paid,
                'status' => $paid === 0 ? 'unpaid' : ($paid >= $total ? 'paid' : 'partial'),
            ], $attributes));
            Payment::withoutGlobalScopes()->where('invoice_id', $invoice->id)->delete();   // factory payments: we add our own

            return $invoice->fresh();
        });
    }

    private function payment(Tenant $tenant, Invoice $invoice, int $cents, string $paidAt = '2026-11-24', bool $voided = false): void
    {
        $tenant->run(function () use ($invoice, $cents, $paidAt, $voided) {
            $factory = $voided ? Payment::factory()->voided() : Payment::factory();
            $factory->create(['invoice_id' => $invoice->id, 'amount_cents' => $cents, 'paid_at' => $paidAt]);
        });
    }

    /** The stats prop of GET /dashboard for the logged-in user. @return array<string, mixed> */
    private function stats(): array
    {
        $stats = [];
        $this->get('/dashboard')->assertOk()->assertInertia(function ($page) use (&$stats) {
            $stats = $page->toArray()['props']['stats'];
        });

        return $stats;
    }

    /** Number of dashboard stats-card queries (the chart queries are covered in DashboardChartsTest) run while $callback executes. */
    private function aggregateQueries(callable $callback): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $callback();
        $count = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'month_revenue_cents'))->count();
        DB::disableQueryLog();

        return $count;
    }

    private function seedKnownData(Tenant $tenant): void
    {
        $unpaid = $this->invoice($tenant, 10000);                                              // outstanding
        $partial = $this->invoice($tenant, 20000, 5000);                                       // outstanding
        $paid = $this->invoice($tenant, 30000, 30000);                                         // paid
        $lastMonth = $this->invoice($tenant, 7000, 7000);                                      // paid, paid last month
        $overdue = $this->invoice($tenant, 40000, 0, '2026-11-19');                            // outstanding + overdue
        $overduePartial = $this->invoice($tenant, 15000, 5000, '2026-11-23');                  // outstanding + overdue
        $dueToday = $this->invoice($tenant, 1000, 0, '2026-11-24');                            // due today is NOT overdue
        $this->invoice($tenant, 99999, 0, '2026-11-01', ['state' => 'draft']);                 // excluded
        $this->invoice($tenant, 88888, 0, '2026-11-01', ['state' => 'void']);                  // excluded

        $this->payment($tenant, $partial, 5000);
        $this->payment($tenant, $paid, 30000);
        $this->payment($tenant, $lastMonth, 7000, '2026-10-15');                               // not this month
        $this->payment($tenant, $overduePartial, 5000);
        $this->payment($tenant, $unpaid, 9999, '2026-11-24', voided: true);                    // voided: excluded
        unset($dueToday, $overdue);
    }

    // ---- values ----------------------------------------------------------------------------

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $this->actingAsTenantUser($this->acme);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('tenant.id', 'acme')
                ->where('tenant.name', 'Acme Sdn Bhd')
                ->has('stats'));
    }

    public function test_a_new_company_sees_zeros(): void
    {
        $this->actingAsTenantUser($this->acme);

        $stats = $this->stats();

        foreach (['month_revenue_cents', 'outstanding_cents', 'paid_cents', 'overdue_cents', 'outstanding_count', 'paid_count', 'overdue_count', 'month_payments_count'] as $key) {
            $this->assertSame(0, $stats[$key], $key);
        }
    }

    public function test_the_numbers_are_correct_for_a_known_data_set(): void
    {
        $this->seedKnownData($this->acme);
        $this->actingAsTenantUser($this->acme);

        $stats = $this->stats();

        // outstanding = 10000 + 15000 + 40000 + 10000 + 1000 (draft and void excluded)
        $this->assertSame(76000, $stats['outstanding_cents']);
        $this->assertSame(5, $stats['outstanding_count']);
        $this->assertSame(37000, $stats['paid_cents']);                  // 30000 + 7000
        $this->assertSame(2, $stats['paid_count']);
        $this->assertSame(50000, $stats['overdue_cents']);               // 40000 + 10000 (due today is not overdue)
        $this->assertSame(2, $stats['overdue_count']);
        $this->assertSame(40000, $stats['month_revenue_cents']);         // 5000 + 30000 + 5000; not last month, not voided
        $this->assertSame(3, $stats['month_payments_count']);
    }

    public function test_the_overdue_number_matches_the_invoice_overdue_scope(): void
    {
        $this->seedKnownData($this->acme);
        $this->actingAsTenantUser($this->acme);

        $stats = $this->stats();
        $scope = $this->acme->run(fn () => Invoice::overdue()->selectRaw('SUM(total_cents - amount_paid_cents) AS s, COUNT(*) AS c')->toBase()->first());

        $this->assertSame((int) $scope->s, $stats['overdue_cents']);
        $this->assertSame((int) $scope->c, $stats['overdue_count']);
    }

    // ---- caching ---------------------------------------------------------------------------

    public function test_the_second_request_is_served_from_the_cache(): void
    {
        $this->seedKnownData($this->acme);
        $this->actingAsTenantUser($this->acme);

        $this->assertSame(1, $this->aggregateQueries(fn () => $this->stats()));
        $this->assertSame(0, $this->aggregateQueries(fn () => $this->stats()));
    }

    public function test_query_count_does_not_grow_with_the_amount_of_data(): void
    {
        $this->actingAsTenantUser($this->acme);

        $countQueries = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->stats();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $small = $countQueries();
        $this->seedKnownData($this->acme);
        $this->acme->run(fn () => DashboardCache::flush());
        tenancy()->end();
        $large = $countQueries();

        $this->assertSame($small, $large);
    }

    public function test_the_cache_key_is_tenant_scoped(): void
    {
        $this->seedKnownData($this->acme);
        $this->invoice($this->globex, 1234);
        $this->actingAsTenantUser($this->acme);
        $this->stats();                                                  // warms Acme only

        $this->assertTrue($this->acme->run(fn () => Cache::has(DashboardCache::key())));
        $this->assertFalse($this->globex->run(fn () => Cache::has(DashboardCache::key())));
    }

    public function test_one_tenant_never_sees_the_other_tenants_numbers_even_with_a_warm_cache(): void
    {
        $this->seedKnownData($this->acme);
        $this->invoice($this->globex, 5000, 0, '2026-11-01');            // globex: one overdue invoice, 50.00

        $acmeUser = $this->actingAsTenantUser($this->acme);
        $acmeStats = $this->stats();                                     // warm Acme
        tenancy()->end();

        $globexUser = $this->createTenantUser($this->globex);
        $this->actingAs($globexUser);
        $globexStats = $this->stats();

        $this->assertSame(5000, $globexStats['outstanding_cents']);
        $this->assertSame(5000, $globexStats['overdue_cents']);
        $this->assertSame(0, $globexStats['paid_cents']);
        $this->assertSame(0, $globexStats['month_revenue_cents']);
        $this->assertSame(76000, $acmeStats['outstanding_cents']);

        // Back to Acme (cache is warm for both): still Acme's own numbers.
        tenancy()->end();
        $this->actingAs($acmeUser);
        $this->assertSame($acmeStats['outstanding_cents'], $this->stats()['outstanding_cents']);
        $this->assertSame(40000, $this->stats()['month_revenue_cents']);
    }

    public function test_other_tenants_payments_never_count_towards_month_revenue(): void
    {
        $globexInvoice = $this->invoice($this->globex, 5000, 5000);
        $this->payment($this->globex, $globexInvoice, 5000);
        $this->actingAsTenantUser($this->acme);

        $this->assertSame(0, $this->stats()['month_revenue_cents']);
    }

    // ---- invalidation ----------------------------------------------------------------------

    public function test_recording_a_payment_clears_the_cache_and_shows_new_numbers(): void
    {
        $invoice = $this->invoice($this->acme, 10000);
        $this->actingAsTenantUser($this->acme);
        $this->assertSame(10000, $this->stats()['outstanding_cents']);

        $this->post("/invoices/{$invoice->id}/payments", ['amount' => '40.00', 'paid_at' => '2026-11-24', 'method' => 'cash'])
            ->assertSessionHasNoErrors();

        $stats = $this->stats();
        $this->assertSame(6000, $stats['outstanding_cents']);
        $this->assertSame(4000, $stats['month_revenue_cents']);

        $this->post("/invoices/{$invoice->id}/payments", ['amount' => '60.00', 'paid_at' => '2026-11-24', 'method' => 'cash'])
            ->assertSessionHasNoErrors();

        $stats = $this->stats();
        $this->assertSame(0, $stats['outstanding_cents']);
        $this->assertSame(10000, $stats['paid_cents']);
        $this->assertSame(10000, $stats['month_revenue_cents']);
    }

    public function test_voiding_a_payment_clears_the_cache(): void
    {
        $invoice = $this->invoice($this->acme, 10000);
        $this->actingAsTenantUser($this->acme);
        $this->post("/invoices/{$invoice->id}/payments", ['amount' => '100.00', 'paid_at' => '2026-11-24', 'method' => 'cash']);
        $this->assertSame(10000, $this->stats()['paid_cents']);

        $paymentId = $this->acme->run(fn () => Payment::where('invoice_id', $invoice->id)->value('id'));
        $this->post("/payments/{$paymentId}/void", ['reason' => 'Bounced'])->assertRedirect();

        $stats = $this->stats();
        $this->assertSame(0, $stats['paid_cents']);
        $this->assertSame(10000, $stats['outstanding_cents']);
        $this->assertSame(0, $stats['month_revenue_cents']);
    }

    public function test_creating_and_issuing_an_invoice_clears_the_cache(): void
    {
        $this->actingAsTenantUser($this->acme);
        $customerId = $this->acme->run(fn () => Invoice::factory()->create()->customer_id);
        $this->assertSame(0, $this->stats()['outstanding_cents']);

        $this->post('/invoices', [
            'customer_id' => $customerId,
            'issue_date' => '2026-11-24',
            'due_date' => '2026-12-24',
            'items' => [['description' => 'Work', 'quantity' => '2', 'unit_price' => '50.00']],
            'action' => 'issue',
        ])->assertSessionHasNoErrors();

        $stats = $this->stats();
        $this->assertSame(10000, $stats['outstanding_cents']);
        $this->assertSame(1, $stats['outstanding_count']);
    }

    public function test_editing_an_invoice_clears_the_cache(): void
    {
        $invoice = $this->invoice($this->acme, 10000);
        $this->actingAsTenantUser($this->acme);
        $this->assertSame(10000, $this->stats()['outstanding_cents']);

        $this->put("/invoices/{$invoice->id}", [
            'customer_id' => $invoice->customer_id,
            'issue_date' => '2026-11-24',
            'due_date' => '2026-12-24',
            'items' => [['description' => 'Replaced', 'quantity' => '4', 'unit_price' => '25.00']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(10000, $this->stats()['outstanding_cents']);   // 4 x 25.00
        $this->put("/invoices/{$invoice->id}", [
            'customer_id' => $invoice->customer_id,
            'issue_date' => '2026-11-24',
            'due_date' => '2026-12-24',
            'items' => [['description' => 'Replaced', 'quantity' => '1', 'unit_price' => '12.00']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1200, $this->stats()['outstanding_cents']);
    }

    public function test_voiding_an_invoice_clears_the_cache(): void
    {
        $invoice = $this->invoice($this->acme, 10000);
        $this->actingAsTenantUser($this->acme);
        $this->assertSame(10000, $this->stats()['outstanding_cents']);

        $this->post("/invoices/{$invoice->id}/void", ['reason' => 'Mistake'])->assertRedirect();

        $stats = $this->stats();
        $this->assertSame(0, $stats['outstanding_cents']);
        $this->assertSame(0, $stats['outstanding_count']);
    }

    public function test_the_daily_overdue_job_flushes_the_tenants_cache(): void
    {
        Queue::fake();
        $this->invoice($this->acme, 10000, 0, '2026-11-01');
        $this->invoice($this->globex, 500);
        $this->actingAsTenantUser($this->acme);
        $this->stats();
        $this->globex->run(fn () => DashboardCache::get());
        $this->assertTrue($this->acme->run(fn () => Cache::has(DashboardCache::key())));

        $this->artisan('tenants:run', ['commandname' => 'invoices:mark-overdue'])->assertSuccessful();

        $this->assertFalse($this->acme->run(fn () => Cache::has(DashboardCache::key())));
        $this->assertFalse($this->globex->run(fn () => Cache::has(DashboardCache::key())));   // every tenant is flushed in its own context
    }

    public function test_the_overdue_number_changes_when_the_day_changes(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 23:59:00'));
        $this->invoice($this->acme, 10000, 0, '2026-11-24');
        $this->actingAsTenantUser($this->acme);
        $this->assertSame(0, $this->stats()['overdue_cents']);

        $this->travelTo(Carbon::parse('2026-11-25 00:01:00'));           // no write happened; the key has a new date

        $stats = $this->stats();
        $this->assertSame(10000, $stats['overdue_cents']);
        $this->assertSame(1, $stats['overdue_count']);
    }

    public function test_a_rolled_back_change_does_not_flush_the_cache(): void
    {
        $invoice = $this->invoice($this->acme, 10000);
        $this->actingAsTenantUser($this->acme);
        $this->stats();

        $this->acme->run(function () use ($invoice) {
            try {
                DB::transaction(function () use ($invoice) {
                    Invoice::findOrFail($invoice->id)->forceFill(['total_cents' => 99999])->save();
                    throw new \RuntimeException('boom');
                });
            } catch (\RuntimeException) {
            }

            $this->assertTrue(Cache::has(DashboardCache::key()));
        });
    }

    public function test_changes_outside_tenancy_never_touch_a_cache(): void
    {
        $this->actingAsTenantUser($this->acme);
        $this->stats();
        tenancy()->end();

        DashboardCache::flush();                                         // no tenant: a no-op, not a global flush

        $this->assertTrue($this->acme->run(fn () => Cache::has(DashboardCache::key())));
    }
}
