<?php

namespace Tests\Feature\Reports;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sales, outstanding (aging) and customer summary reports. "Now" is frozen at 2026-11-24 12:00.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private Tenant $globex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-24 12:00:00'));
        $this->acme = $this->createTenant('acme');
        $this->globex = $this->createTenant('globex');
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    /**
     * An invoice with exact numbers (raw update so nothing else is recalculated).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function invoice(Tenant $tenant, int $total, int $paid = 0, string $issue = '2026-11-10', string $due = '2026-12-10', array $attributes = [], ?int $customerId = null): Invoice
    {
        return $tenant->run(function () use ($total, $paid, $issue, $due, $attributes, $customerId) {
            $invoice = Invoice::factory()->issued()->create(array_filter([
                'issue_date' => $issue,
                'due_date' => $due,
                'customer_id' => $customerId,
            ]));
            Invoice::withoutGlobalScopes()->whereKey($invoice->id)->update(array_merge([
                'subtotal_cents' => $total,
                'total_cents' => $total,
                'amount_paid_cents' => $paid,
                'status' => $paid === 0 ? 'unpaid' : ($paid >= $total ? 'paid' : 'partial'),
            ], $attributes));

            return $invoice->fresh();
        });
    }

    /** An invoice that is $days past its due date today (negative = not yet due). */
    private function invoiceDue(Tenant $tenant, int $daysPastDue, int $total = 10000, int $paid = 0, ?int $customerId = null): Invoice
    {
        $due = today()->subDays($daysPastDue)->toDateString();

        return $this->invoice($tenant, $total, $paid, today()->subDays($daysPastDue + 60)->toDateString(), $due, [], $customerId);
    }

    private function customer(Tenant $tenant, string $name): Customer
    {
        return $tenant->run(fn () => Customer::factory()->create(['name' => $name]));
    }

    /** @return array<string, mixed> */
    private function props(string $url, ?string $component = null): array
    {
        $props = [];
        $this->get($url)->assertOk()->assertInertia(function ($page) use (&$props, $component) {
            if ($component) {
                $page->component($component);
            }
            $props = $page->toArray()['props'];
        });

        return $props;
    }

    // ---- access ---------------------------------------------------------------------------

    public function test_guests_are_redirected_to_login(): void
    {
        foreach (['sales', 'outstanding', 'customers'] as $report) {
            $this->get("/reports/$report")->assertRedirect('/login');
        }
    }

    // ---- sales ----------------------------------------------------------------------------

    public function test_sales_report_defaults_to_the_current_month(): void
    {
        $this->actingAsTenantUser($this->acme);
        $this->invoice($this->acme, 10000, 4000, '2026-11-10');
        $this->invoice($this->acme, 5000, 0, '2026-10-31', '2026-11-30');   // last month: outside the default range

        $props = $this->props('/reports/sales', 'reports/Sales');

        $this->assertSame(['from' => '2026-11-01', 'to' => '2026-11-30'], $props['filters']);
        $this->assertSame(10000, $props['report']['totals']['billed_cents']);
    }

    public function test_sales_report_totals_match_a_known_data_set(): void
    {
        $this->invoice($this->acme, 10000, 4000, '2026-09-05');
        $this->invoice($this->acme, 20000, 20000, '2026-09-20');
        $this->invoice($this->acme, 5000, 0, '2026-10-02');
        $this->invoice($this->acme, 7000, 1000, '2026-11-15');
        // Excluded: drafts and void invoices.
        $this->invoice($this->acme, 99999, 0, '2026-10-10', '2026-12-10', ['state' => 'draft']);
        $this->invoice($this->acme, 88888, 0, '2026-10-11', '2026-12-10', ['state' => 'void']);
        $this->actingAsTenantUser($this->acme);

        $report = $this->props('/reports/sales?from=2026-09-01&to=2026-11-30')['report'];

        $this->assertSame([
            ['month' => '2026-09', 'invoice_count' => 2, 'billed_cents' => 30000, 'collected_cents' => 24000, 'outstanding_cents' => 6000],
            ['month' => '2026-10', 'invoice_count' => 1, 'billed_cents' => 5000, 'collected_cents' => 0, 'outstanding_cents' => 5000],
            ['month' => '2026-11', 'invoice_count' => 1, 'billed_cents' => 7000, 'collected_cents' => 1000, 'outstanding_cents' => 6000],
        ], $report['rows']);
        $this->assertSame(
            ['invoice_count' => 4, 'billed_cents' => 42000, 'collected_cents' => 25000, 'outstanding_cents' => 17000],
            $report['totals'],
        );
    }

    public function test_voided_payments_are_not_collected(): void
    {
        // Real flow: the voided payment is ignored by recalculatePaymentState(), so collected stays 3000.
        $invoice = $this->invoice($this->acme, 10000, 0, '2026-11-10');
        $this->acme->run(function () use ($invoice) {
            Payment::factory()->create(['invoice_id' => $invoice->id, 'amount_cents' => 3000]);
            Payment::factory()->voided()->create(['invoice_id' => $invoice->id, 'amount_cents' => 5000]);
            Invoice::findOrFail($invoice->id)->recalculatePaymentState();
        });
        $this->actingAsTenantUser($this->acme);

        $totals = $this->props('/reports/sales')['report']['totals'];

        $this->assertSame(3000, $totals['collected_cents']);
        $this->assertSame(7000, $totals['outstanding_cents']);
    }

    public function test_sales_date_boundaries_are_inclusive(): void
    {
        $this->invoice($this->acme, 1000, 0, '2026-10-31');   // day before
        $this->invoice($this->acme, 2000, 0, '2026-11-01');   // on from
        $this->invoice($this->acme, 4000, 0, '2026-11-30');   // on to
        $this->invoice($this->acme, 8000, 0, '2026-12-01');   // day after
        $this->actingAsTenantUser($this->acme);

        $totals = $this->props('/reports/sales?from=2026-11-01&to=2026-11-30')['report']['totals'];

        $this->assertSame(2, $totals['invoice_count']);
        $this->assertSame(6000, $totals['billed_cents']);
    }

    public function test_sales_report_only_shows_the_current_tenants_data(): void
    {
        $this->invoice($this->acme, 10000);
        $this->invoice($this->globex, 777700);
        $this->actingAsTenantUser($this->acme);

        $totals = $this->props('/reports/sales')['report']['totals'];

        $this->assertSame(1, $totals['invoice_count']);
        $this->assertSame(10000, $totals['billed_cents']);
    }

    public function test_sales_report_with_no_invoices_returns_zero_totals(): void
    {
        $this->actingAsTenantUser($this->acme);

        $report = $this->props('/reports/sales')['report'];

        $this->assertSame([], $report['rows']);
        $this->assertSame(['invoice_count' => 0, 'billed_cents' => 0, 'collected_cents' => 0, 'outstanding_cents' => 0], $report['totals']);
    }

    public function test_a_reversed_range_is_rejected(): void
    {
        $this->actingAsTenantUser($this->acme);

        $this->get('/reports/sales?from=2026-11-30&to=2026-11-01')->assertSessionHasErrors('to');
        $this->get('/reports/customers?from=2026-11-30&to=2026-11-01')->assertSessionHasErrors('to');
    }

    public function test_a_range_longer_than_two_years_is_rejected(): void
    {
        $this->actingAsTenantUser($this->acme);

        $this->get('/reports/sales?from=2024-01-01&to=2026-01-02')->assertSessionHasErrors('to');
        $this->get('/reports/sales?from=2024-01-01&to=2026-01-01')->assertSessionHasNoErrors();   // exactly 2 years is fine
    }

    public function test_malformed_dates_are_rejected(): void
    {
        $this->actingAsTenantUser($this->acme);

        $this->get('/reports/sales?from=yesterday&to=2026-11-01')->assertSessionHasErrors('from');
    }

    // ---- outstanding ----------------------------------------------------------------------

    public function test_aging_buckets_put_each_invoice_in_the_right_bucket(): void
    {
        foreach ([0 => 1000, 15 => 2000, 45 => 4000, 75 => 8000, 120 => 16000] as $days => $total) {
            $this->invoiceDue($this->acme, $days, $total);
        }
        $this->invoiceDue($this->acme, -10, 32000);   // due in the future: Current as well
        $this->actingAsTenantUser($this->acme);

        $aging = $this->props('/reports/outstanding', 'reports/Outstanding')['aging'];

        $this->assertSame(['balance_cents' => 33000, 'count' => 2], $aging['current']);
        $this->assertSame(['balance_cents' => 2000, 'count' => 1], $aging['days_1_30']);
        $this->assertSame(['balance_cents' => 4000, 'count' => 1], $aging['days_31_60']);
        $this->assertSame(['balance_cents' => 8000, 'count' => 1], $aging['days_61_90']);
        $this->assertSame(['balance_cents' => 16000, 'count' => 1], $aging['days_90_plus']);
        $this->assertSame(['balance_cents' => 63000, 'count' => 6], $aging['total']);
    }

    public function test_aging_bucket_edges(): void
    {
        foreach ([1, 30, 31, 60, 61, 90, 91] as $days) {
            $this->invoiceDue($this->acme, $days, 100);
        }
        $this->actingAsTenantUser($this->acme);

        $aging = $this->props('/reports/outstanding')['aging'];

        $this->assertSame(2, $aging['days_1_30']['count']);      // 1, 30
        $this->assertSame(2, $aging['days_31_60']['count']);     // 31, 60
        $this->assertSame(2, $aging['days_61_90']['count']);     // 61, 90
        $this->assertSame(1, $aging['days_90_plus']['count']);   // 91
    }

    public function test_outstanding_uses_the_balance_and_excludes_paid_draft_and_void(): void
    {
        $this->invoiceDue($this->acme, 10, 10000, 4000);                         // balance 6000
        $this->invoiceDue($this->acme, 10, 5000, 5000);                          // paid: excluded
        $this->invoice($this->acme, 9999, 0, '2026-10-01', '2026-10-10', ['state' => 'draft']);
        $this->invoice($this->acme, 8888, 0, '2026-10-01', '2026-10-10', ['state' => 'void']);
        $this->actingAsTenantUser($this->acme);

        $props = $this->props('/reports/outstanding');

        $this->assertSame(['balance_cents' => 6000, 'count' => 1], $props['aging']['total']);
        $this->assertCount(1, $props['invoices']['data']);
        $row = $props['invoices']['data'][0];
        $this->assertSame(10000, $row['total_cents']);
        $this->assertSame(4000, $row['paid_cents']);
        $this->assertSame(6000, $row['balance_cents']);
        $this->assertSame(10, $row['days_overdue']);
        $this->assertSame('overdue', $row['display_status']);
        $this->assertNotNull($row['number']);
    }

    public function test_not_yet_due_invoices_have_zero_days_overdue_and_keep_their_status(): void
    {
        $this->invoiceDue($this->acme, -5, 10000, 2500);
        $this->actingAsTenantUser($this->acme);

        $row = $this->props('/reports/outstanding')['invoices']['data'][0];

        $this->assertSame(0, $row['days_overdue']);
        $this->assertSame('partial', $row['display_status']);
    }

    public function test_overdue_only_and_customer_filters(): void
    {
        $alice = $this->customer($this->acme, 'Alice');
        $bob = $this->customer($this->acme, 'Bob');
        $this->invoiceDue($this->acme, 20, 1000, 0, $alice->id);
        $this->invoiceDue($this->acme, -20, 2000, 0, $alice->id);
        $this->invoiceDue($this->acme, 20, 4000, 0, $bob->id);
        $this->actingAsTenantUser($this->acme);

        $this->assertSame(3, $this->props('/reports/outstanding')['aging']['total']['count']);

        $overdue = $this->props('/reports/outstanding?overdue_only=1');
        $this->assertSame(2, $overdue['aging']['total']['count']);
        $this->assertSame(0, $overdue['aging']['current']['count']);

        $alices = $this->props('/reports/outstanding?customer_id='.$alice->id);
        $this->assertSame(3000, $alices['aging']['total']['balance_cents']);
        $this->assertSame(['Alice', 'Alice'], array_column($alices['invoices']['data'], 'customer'));

        $both = $this->props('/reports/outstanding?overdue_only=1&customer_id='.$alice->id);
        $this->assertSame(1000, $both['aging']['total']['balance_cents']);
    }

    public function test_outstanding_report_only_shows_the_current_tenants_data(): void
    {
        $this->invoiceDue($this->acme, 10, 1000);
        $this->invoiceDue($this->globex, 10, 555500);
        $this->actingAsTenantUser($this->acme);

        $props = $this->props('/reports/outstanding');

        $this->assertSame(1000, $props['aging']['total']['balance_cents']);
        $this->assertCount(1, $props['invoices']['data']);
    }

    public function test_another_tenants_customer_id_is_a_validation_error_not_data(): void
    {
        $foreign = $this->customer($this->globex, 'Globex Customer');
        $this->invoiceDue($this->globex, 10, 5000, 0, $foreign->id);
        $this->actingAsTenantUser($this->acme);

        $this->get('/reports/outstanding?customer_id='.$foreign->id)->assertSessionHasErrors('customer_id');
    }

    public function test_the_outstanding_list_does_not_run_a_query_per_row(): void
    {
        foreach (range(1, 6) as $i) {
            $this->invoiceDue($this->acme, $i, 1000);
        }
        $this->actingAsTenantUser($this->acme);

        DB::enableQueryLog();
        $this->props('/reports/outstanding');
        $customerQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "customers"'))->count();
        DB::disableQueryLog();

        // One for the eager load, one for the filter dropdown; never one per invoice.
        $this->assertLessThanOrEqual(2, $customerQueries);
    }

    // ---- customer summary -----------------------------------------------------------------

    private function seedCustomers(): void
    {
        $alice = $this->customer($this->acme, 'Alice');
        $bob = $this->customer($this->acme, 'Bob');
        $this->customer($this->acme, 'Carol');   // no invoices

        $this->invoice($this->acme, 10000, 4000, '2026-11-01', '2026-11-30', [], $alice->id);   // balance 6000, not overdue
        $this->invoice($this->acme, 20000, 0, '2026-09-01', '2026-10-01', [], $alice->id);      // overdue 20000
        $this->invoice($this->acme, 5000, 5000, '2026-11-02', '2026-11-30', [], $bob->id);      // paid
        $this->invoice($this->acme, 9999, 0, '2026-11-02', '2026-11-30', ['state' => 'draft'], $bob->id);
        $this->invoice($this->acme, 8888, 0, '2026-11-02', '2026-11-30', ['state' => 'void'], $bob->id);
    }

    /** @return array<string, array<string, mixed>> keyed by customer name */
    private function customerRows(string $query = ''): array
    {
        $rows = $this->props('/reports/customers'.$query, 'reports/CustomerSummary')['customers']['data'];

        return array_column($rows, null, 'name');
    }

    public function test_customer_summary_matches_a_known_data_set(): void
    {
        $this->seedCustomers();
        $this->actingAsTenantUser($this->acme);

        $rows = $this->customerRows();

        $this->assertSame(2, $rows['Alice']['invoice_count']);
        $this->assertSame(30000, $rows['Alice']['billed_cents']);
        $this->assertSame(4000, $rows['Alice']['paid_cents']);
        $this->assertSame(26000, $rows['Alice']['balance_cents']);
        $this->assertSame(20000, $rows['Alice']['overdue_cents']);

        $this->assertSame(1, $rows['Bob']['invoice_count']);   // draft and void excluded
        $this->assertSame(5000, $rows['Bob']['billed_cents']);
        $this->assertSame(0, $rows['Bob']['balance_cents']);
        $this->assertSame(0, $rows['Bob']['overdue_cents']);
    }

    public function test_a_customer_without_invoices_shows_zeros(): void
    {
        $this->seedCustomers();
        $this->actingAsTenantUser($this->acme);

        $carol = $this->customerRows()['Carol'];

        foreach (['invoice_count', 'billed_cents', 'paid_cents', 'balance_cents', 'overdue_cents'] as $key) {
            $this->assertSame(0, $carol[$key], $key);
        }
    }

    public function test_customer_summary_date_range_is_inclusive_and_applies_to_invoices(): void
    {
        $this->seedCustomers();
        $this->actingAsTenantUser($this->acme);

        // 2026-11-01 (Alice) and 2026-11-02 (Bob) are on the boundaries; the September invoice is out.
        $rows = $this->customerRows('?from=2026-11-01&to=2026-11-02');

        $this->assertSame(1, $rows['Alice']['invoice_count']);
        $this->assertSame(10000, $rows['Alice']['billed_cents']);
        $this->assertSame(1, $rows['Bob']['invoice_count']);

        $rows = $this->customerRows('?from=2026-11-02&to=2026-11-02');
        $this->assertSame(0, $rows['Alice']['invoice_count']);
        $this->assertSame(1, $rows['Bob']['invoice_count']);
    }

    public function test_customer_summary_sorting_is_whitelisted(): void
    {
        $this->seedCustomers();
        $this->actingAsTenantUser($this->acme);

        $names = fn (string $query) => array_column($this->props('/reports/customers'.$query)['customers']['data'], 'name');

        $this->assertSame(['Alice', 'Bob', 'Carol'], $names(''));
        $this->assertSame(['Carol', 'Bob', 'Alice'], $names('?sort=name&dir=desc'));
        $this->assertSame(['Alice', 'Bob', 'Carol'], $names('?sort=billed&dir=desc'));
        $this->assertSame(['Alice', 'Bob', 'Carol'], $names('?sort=balance&dir=desc'));
        $this->assertSame(['Alice', 'Bob', 'Carol'], $names('?sort=overdue&dir=desc'));
        $this->assertSame(['Carol', 'Alice', 'Bob'], $names('?sort=paid&dir=asc'));

        $this->get('/reports/customers?sort=password')->assertSessionHasErrors('sort');
        $this->get('/reports/customers?sort=name;drop table invoices')->assertSessionHasErrors('sort');
        $this->get('/reports/customers?dir=sideways')->assertSessionHasErrors('dir');
    }

    public function test_customer_summary_only_shows_the_current_tenants_data(): void
    {
        $this->seedCustomers();
        $other = $this->customer($this->globex, 'Zed');
        $this->invoice($this->globex, 123400, 0, '2026-11-05', '2026-12-05', [], $other->id);
        $this->actingAsTenantUser($this->acme);

        $rows = $this->customerRows();

        $this->assertArrayNotHasKey('Zed', $rows);
        $this->assertSame(30000 + 5000, array_sum(array_column($rows, 'billed_cents')));
    }

    public function test_archived_customers_only_appear_when_they_have_invoices(): void
    {
        $this->seedCustomers();
        $this->acme->run(function () {
            Customer::where('name', 'Carol')->first()->delete();   // archived, no invoices: hidden
            Customer::where('name', 'Bob')->first()->delete();     // archived with an invoice: kept
        });
        $this->actingAsTenantUser($this->acme);

        $rows = $this->customerRows();

        $this->assertArrayNotHasKey('Carol', $rows);
        $this->assertTrue($rows['Bob']['archived']);
        $this->assertSame(5000, $rows['Bob']['billed_cents']);
    }

    public function test_customer_summary_is_paginated(): void
    {
        $this->acme->run(fn () => Customer::factory()->count(20)->create());
        $this->actingAsTenantUser($this->acme);

        $customers = $this->props('/reports/customers')['customers'];

        $this->assertSame(20, $customers['total']);
        $this->assertCount(15, $customers['data']);
    }
}
