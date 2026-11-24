<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\DashboardChartsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The demo seeder (run against the TEST database only): spread over 12 months, and every total adds up. */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_demo_tenant_gets_a_consistent_twelve_month_data_set(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 12:00:00'));
        $this->seed(DatabaseSeeder::class);

        foreach (['acme', 'globex'] as $tenantId) {
            $user = User::withoutGlobalScopes()->where('email', "admin@{$tenantId}.test")->firstOrFail();
            $this->assertSame($tenantId, $user->tenant_id);
            $this->assertTrue(Hash::check('password', $user->password));

            tenancy()->initialize($tenantId);

            // Counts
            $invoices = Invoice::count();
            $this->assertSame(DatabaseSeeder::INVOICES_PER_TENANT, $invoices);
            $this->assertGreaterThanOrEqual(40, $invoices);
            $this->assertSame(DatabaseSeeder::CUSTOMERS_PER_TENANT, Customer::withTrashed()->count());
            $this->assertSame(1, Customer::onlyTrashed()->count());
            $this->assertGreaterThan(0, Payment::count());

            // Months covered: every one of the last 12 calendar months has invoices.
            $months = Invoice::query()->get()->map(fn (Invoice $i) => $i->issue_date->format('Y-m'))->unique()->sort()->values()->all();
            $this->assertSame(
                collect(range(11, 0))->map(fn ($m) => today()->startOfMonth()->subMonths($m)->format('Y-m'))->all(),
                $months,
            );
            $this->assertGreaterThanOrEqual(11, collect(app(DashboardChartsService::class)->compute()['monthly']['invoiced_cents'])->filter()->count());

            // A mix of states and statuses.
            $this->assertGreaterThan(0, Invoice::overdue()->count());
            foreach (['paid', 'partial', 'unpaid'] as $status) {
                $this->assertGreaterThan(0, Invoice::issued()->where('status', $status)->count(), $status);
            }

            // Payments: never before the issue date, never in the future, never on a draft or void invoice.
            $this->assertSame(0, Payment::query()->where('paid_at', '>', today())->count());
            $this->assertSame(0, Payment::query()->join('invoices', 'invoices.id', '=', 'payments.invoice_id')->whereColumn('payments.paid_at', '<', 'invoices.issue_date')->count());
            $this->assertSame(0, Payment::query()->join('invoices', 'invoices.id', '=', 'payments.invoice_id')->where('invoices.state', '<>', 'issued')->count());

            // Totals: subtotal = total = SUM(items); amount_paid = SUM(active payments); status follows.
            Invoice::query()->with(['items', 'payments'])->get()->each(function (Invoice $invoice) {
                $this->assertSame((int) $invoice->items->sum('line_total_cents'), $invoice->total_cents, "{$invoice->id} total");
                $this->assertSame($invoice->total_cents, $invoice->subtotal_cents);

                $paid = (int) $invoice->payments->whereNull('voided_at')->sum('amount_cents');
                $this->assertSame($paid, $invoice->amount_paid_cents, "{$invoice->id} amount paid");
                $this->assertLessThanOrEqual($invoice->total_cents, $paid);

                $expected = match (true) {
                    $paid === 0 => 'unpaid',
                    $paid >= $invoice->total_cents => 'paid',
                    default => 'partial',
                };
                $this->assertSame($expected, $invoice->status->value, "{$invoice->id} status");
            });

            tenancy()->end();
        }

        // Nothing leaked between the two companies.
        $this->assertSame(2 * DatabaseSeeder::INVOICES_PER_TENANT, Invoice::withoutGlobalScopes()->count());
        $this->assertSame(0, Invoice::withoutGlobalScopes()->join('customers', 'customers.id', '=', 'invoices.customer_id')->whereColumn('customers.tenant_id', '<>', 'invoices.tenant_id')->count());
    }
}
