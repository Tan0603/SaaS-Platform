<?php

namespace Tests\Feature\Dashboard;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\DashboardCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dashboard chart data: three grouped SQL queries, cached in the same payload as the cards.
 * "Now" is frozen at 2026-11-24 12:00, so the 12-month window is Dec 2025 ... Nov 2026.
 */
class DashboardChartsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private Tenant $globex;

    protected function setUp(): void
    {
        parent::setUp();

        // Real Redis (test-only prefix), because the array store forgets its contents whenever tenancy is re-initialised.
        config(['cache.default' => 'redis', 'cache.prefix' => 'phpunit-dashboard-charts-']);

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

    private function clearTenantCaches(): void
    {
        foreach ([$this->acme, $this->globex] as $tenant) {
            $tenant->run(fn () => Cache::flush());
        }
        tenancy()->end();
    }

    /**
     * An invoice with exact totals (raw update: nothing is flushed or cached by the setup).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function invoice(Tenant $tenant, int $total, int $paid = 0, string $issued = '2026-11-01', string $due = '2026-12-10', array $attributes = [], ?int $customerAttr = null): Invoice
    {
        return $tenant->run(function () use ($total, $paid, $issued, $due, $attributes, $customerAttr) {
            $invoice = Invoice::factory()->issued()->create(array_filter(['issue_date' => $issued, 'due_date' => $due, 'customer_id' => $customerAttr]));
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

    private function payment(Tenant $tenant, Invoice $invoice, int $cents, string $paidAt, bool $voided = false): void
    {
        $tenant->run(function () use ($invoice, $cents, $paidAt, $voided) {
            $factory = $voided ? Payment::factory()->voided() : Payment::factory();
            $factory->create(['invoice_id' => $invoice->id, 'amount_cents' => $cents, 'paid_at' => $paidAt]);
        });
    }

    private function customer(Tenant $tenant, string $name, bool $archived = false): int
    {
        return $tenant->run(function () use ($name, $archived) {
            $customer = Customer::factory()->create(['name' => $name]);
            $archived && $customer->delete();

            return $customer->id;
        });
    }

    /** The charts prop of GET /dashboard for the logged-in user. @return array<string, mixed> */
    private function charts(): array
    {
        $charts = [];
        $this->get('/dashboard')->assertOk()->assertInertia(function ($page) use (&$charts) {
            $charts = $page->toArray()['props']['charts'];
        });

        return $charts;
    }

    /** Number of chart queries (the monthly / status / top-customers statements) run while $callback executes. */
    private function chartQueries(callable $callback): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $callback();
        $count = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], "date_trunc('month'")
            || str_contains($q['query'], 'unpaid_count')
            || str_contains($q['query'], 'inner join "customers"'))->count();
        DB::disableQueryLog();

        return $count;
    }

    // ---- invoiced vs collected -------------------------------------------------------------

    public function test_the_monthly_series_has_twelve_zero_filled_buckets_with_the_right_values(): void
    {
        $t = $this->acme;
        $nov = $this->invoice($t, 10000, 0, '2026-11-24');
        $this->invoice($t, 5000, 0, '2026-11-01');
        $jan = $this->invoice($t, 7000, 7000, '2026-01-31');
        $this->invoice($t, 3000, 0, '2025-12-01');                                    // first bucket
        $this->invoice($t, 99999, 0, '2025-11-30');                                   // before the window
        $this->invoice($t, 88888, 0, '2026-11-10', '2026-12-10', ['state' => 'draft']);   // excluded
        $this->invoice($t, 77777, 0, '2026-01-10', '2026-12-10', ['state' => 'void']);    // excluded

        $this->payment($t, $nov, 4000, '2026-11-24');
        $this->payment($t, $jan, 7000, '2026-02-01');                                 // collected in the next month
        $this->payment($t, $nov, 3000, '2026-10-31');
        $this->payment($t, $nov, 6666, '2026-11-06', voided: true);                   // voided: excluded
        $this->payment($t, $nov, 5555, '2025-11-30');                                 // before the window
        $this->payment($t, $nov, 100, '2025-12-15');

        $this->actingAsTenantUser($t);
        $monthly = $this->charts()['monthly'];

        $this->assertCount(12, $monthly['labels']);
        $this->assertCount(12, $monthly['invoiced_cents']);
        $this->assertCount(12, $monthly['collected_cents']);
        $this->assertSame('Dec 2025', $monthly['labels'][0]);
        $this->assertSame('Nov 2026', $monthly['labels'][11]);
        $this->assertSame('2025-12', $monthly['months'][0]);
        $this->assertSame('2026-11', $monthly['months'][11]);

        // Dec 2025 ... Nov 2026
        $this->assertSame([3000, 7000, 0, 0, 0, 0, 0, 0, 0, 0, 0, 15000], $monthly['invoiced_cents']);
        $this->assertSame([100, 0, 7000, 0, 0, 0, 0, 0, 0, 0, 3000, 4000], $monthly['collected_cents']);
    }

    public function test_an_empty_company_gets_twelve_zero_buckets(): void
    {
        $this->actingAsTenantUser($this->acme);
        $charts = $this->charts();

        $this->assertSame(array_fill(0, 12, 0), $charts['monthly']['invoiced_cents']);
        $this->assertSame(array_fill(0, 12, 0), $charts['monthly']['collected_cents']);
        $this->assertSame([0, 0, 0, 0], array_column($charts['status'], 'count'));
        $this->assertSame([], $charts['top_customers']);
    }

    // ---- invoices by status ----------------------------------------------------------------

    public function test_the_status_breakdown_matches_the_badges_and_list_filters(): void
    {
        $t = $this->acme;
        $this->invoice($t, 10000);                                                    // unpaid
        $this->invoice($t, 2000, 0, '2026-11-01', '2026-11-24');                      // unpaid, due today: not overdue
        $this->invoice($t, 20000, 5000);                                              // partial
        $this->invoice($t, 30000, 30000);                                             // paid
        $this->invoice($t, 40000, 0, '2026-10-01', '2026-11-19');                     // overdue (unpaid)
        $this->invoice($t, 15000, 5000, '2026-10-01', '2026-11-23');                  // overdue (partial)
        $this->invoice($t, 9000, 9000, '2026-09-01', '2026-10-01');                   // paid + past due: paid, not overdue
        $this->invoice($t, 99999, 0, '2026-11-01', '2026-11-01', ['state' => 'draft']);
        $this->invoice($t, 88888, 0, '2026-11-01', '2026-11-01', ['state' => 'void']);

        $this->actingAsTenantUser($t);
        $rows = collect($this->charts()['status'])->keyBy('key');

        $this->assertSame(['unpaid', 'partial', 'paid', 'overdue'], $rows->keys()->all());

        foreach (['unpaid', 'partial', 'paid', 'overdue'] as $key) {
            $expected = $t->run(fn () => Invoice::withDisplayStatus($key)->selectRaw('COUNT(*) AS c, COALESCE(SUM(total_cents), 0) AS s')->toBase()->first());
            $this->assertSame((int) $expected->c, $rows[$key]['count'], "{$key} count");
            $this->assertSame((int) $expected->s, $rows[$key]['total_cents'], "{$key} total");
        }

        $this->assertSame(2, $rows['unpaid']['count']);
        $this->assertSame(12000, $rows['unpaid']['total_cents']);
        $this->assertSame(1, $rows['partial']['count']);
        $this->assertSame(2, $rows['paid']['count']);
        $this->assertSame(39000, $rows['paid']['total_cents']);
        $this->assertSame(2, $rows['overdue']['count']);
        $this->assertSame(55000, $rows['overdue']['total_cents']);
    }

    // ---- top customers ---------------------------------------------------------------------

    public function test_top_customers_are_ordered_limited_to_five_and_exclude_paid_invoices(): void
    {
        $t = $this->acme;
        $balances = ['A' => 1000, 'B' => 6000, 'C' => 3000, 'D' => 5000, 'E' => 2000, 'F' => 4000, 'G' => 7000];
        foreach ($balances as $name => $balance) {
            $this->invoice($t, $balance, 0, customerAttr: $this->customer($t, "Customer {$name}"));
        }

        // Customer A also has a fully paid invoice and a partial one; Z only has paid / draft / void invoices.
        $a = $t->run(fn () => Customer::where('name', 'Customer A')->value('id'));
        $this->invoice($t, 50000, 50000, customerAttr: $a);
        $z = $this->customer($t, 'Customer Z');
        $this->invoice($t, 90000, 90000, customerAttr: $z);
        $this->invoice($t, 90000, 0, '2026-11-01', '2026-12-10', ['state' => 'draft'], customerAttr: $z);
        $this->invoice($t, 90000, 0, '2026-11-01', '2026-12-10', ['state' => 'void'], customerAttr: $z);

        $this->actingAsTenantUser($t);
        $top = $this->charts()['top_customers'];

        $this->assertSame(
            ['Customer G', 'Customer B', 'Customer D', 'Customer F', 'Customer C'],
            array_column($top, 'name'),
        );
        $this->assertSame([7000, 6000, 5000, 4000, 3000], array_column($top, 'outstanding_cents'));
    }

    public function test_top_customers_use_the_balance_sum_the_invoice_count_and_include_archived_customers(): void
    {
        $t = $this->acme;
        $archived = $this->customer($t, 'Archived Co', archived: true);
        $this->invoice($t, 10000, 4000, customerAttr: $archived);                     // balance 6000
        $this->invoice($t, 3000, 0, customerAttr: $archived);                         // balance 3000

        $this->actingAsTenantUser($t);
        $top = $this->charts()['top_customers'];

        $this->assertCount(1, $top);
        $this->assertSame('Archived Co', $top[0]['name']);
        $this->assertSame(9000, $top[0]['outstanding_cents']);
        $this->assertSame(2, $top[0]['invoice_count']);
    }

    // ---- tenant isolation + cache ----------------------------------------------------------

    public function test_one_tenant_never_sees_the_other_tenants_charts_even_with_a_warm_cache(): void
    {
        $this->invoice($this->acme, 10000, 0, '2026-11-02', customerAttr: $this->customer($this->acme, 'Acme Customer'));
        $this->invoice($this->globex, 5000, 5000, '2026-09-02', customerAttr: $this->customer($this->globex, 'Globex Customer'));

        $acmeUser = $this->actingAsTenantUser($this->acme);
        $acme = $this->charts();                                                      // warms Acme
        tenancy()->end();

        $this->actingAs($this->createTenantUser($this->globex));
        $globex = $this->charts();

        $this->assertSame(10000, array_sum($acme['monthly']['invoiced_cents']));
        $this->assertSame(['Acme Customer'], array_column($acme['top_customers'], 'name'));
        $this->assertSame(5000, array_sum($globex['monthly']['invoiced_cents']));
        $this->assertSame([], $globex['top_customers']);                              // its only invoice is paid
        $this->assertSame([0, 0, 1, 0], array_column($globex['status'], 'count'));

        tenancy()->end();
        $this->actingAs($acmeUser);
        $this->assertSame($acme, $this->charts());                                    // warm again: still Acme's own data
    }

    public function test_charts_come_from_the_cached_payload_and_a_new_payment_invalidates_them(): void
    {
        $invoice = $this->invoice($this->acme, 10000, 0, '2026-11-02', customerAttr: $this->customer($this->acme, 'Cached Co'));
        $this->actingAsTenantUser($this->acme);

        $this->assertSame(4, $this->chartQueries(fn () => $this->charts()));         // cold: invoiced + collected months, status, top customers
        $this->assertSame(0, $this->chartQueries(fn () => $this->charts()));         // warm: served from the cache
        $this->assertTrue($this->acme->run(fn () => Cache::has(DashboardCache::key())));

        $this->post("/invoices/{$invoice->id}/payments", ['amount' => '40.00', 'paid_at' => '2026-11-24', 'method' => 'cash'])
            ->assertSessionHasNoErrors();

        $charts = $this->charts();                                                    // recomputed
        $this->assertSame(4000, $charts['monthly']['collected_cents'][11]);
        $this->assertSame(6000, $charts['top_customers'][0]['outstanding_cents']);
        $this->assertSame([0, 1, 0, 0], array_column($charts['status'], 'count'));
    }

    public function test_the_dashboard_page_props_contain_stats_and_charts(): void
    {
        $this->actingAsTenantUser($this->acme);

        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('stats.outstanding_cents')
            ->has('charts.monthly.labels', 12)
            ->has('charts.monthly.invoiced_cents', 12)
            ->has('charts.monthly.collected_cents', 12)
            ->has('charts.status', 4)
            ->has('charts.top_customers', 0));
    }

    public function test_chart_query_count_does_not_grow_with_the_amount_of_data(): void
    {
        $this->actingAsTenantUser($this->acme);

        $count = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->charts();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $small = $count();

        for ($i = 0; $i < 12; $i++) {
            $month = Carbon::parse('2026-11-15')->subMonths($i)->toDateString();
            $invoice = $this->invoice($this->acme, 1000 + $i, 0, $month, customerAttr: $this->customer($this->acme, "Customer {$i}"));
            $this->payment($this->acme, $invoice, 100, $month);
        }
        $this->acme->run(fn () => DashboardCache::flush());
        tenancy()->end();

        $this->assertSame($small, $count());
    }
}
