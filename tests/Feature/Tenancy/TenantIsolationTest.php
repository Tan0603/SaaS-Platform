<?php

namespace Tests\Feature\Tenancy;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
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

    public function test_counts_only_include_the_current_tenants_rows(): void
    {
        $this->acme->run(fn () => Invoice::factory()->count(3)->paid()->create());
        $this->globex->run(fn () => Invoice::factory()->count(5)->create());

        $this->acme->run(function () {
            $this->assertSame(3, Customer::count());
            $this->assertSame(3, Invoice::count());
            $this->assertSame(6, InvoiceItem::count());
            $this->assertGreaterThanOrEqual(3, Payment::count());
        });

        $this->globex->run(function () {
            $this->assertSame(5, Customer::count());
            $this->assertSame(5, Invoice::count());
            $this->assertSame(0, Payment::count());
        });
    }

    public function test_finding_another_tenants_record_returns_null(): void
    {
        $invoiceId = $this->globex->run(fn () => Invoice::factory()->partial()->create()->id);
        $customerId = $this->globex->run(fn () => Customer::factory()->create()->id);
        $paymentId = $this->globex->run(fn () => Payment::query()->firstOrFail()->id);
        $itemId = $this->globex->run(fn () => InvoiceItem::query()->firstOrFail()->id);

        $this->acme->run(function () use ($invoiceId, $customerId, $paymentId, $itemId) {
            $this->assertNull(Invoice::find($invoiceId));
            $this->assertNull(Customer::find($customerId));
            $this->assertNull(Payment::find($paymentId));
            $this->assertNull(InvoiceItem::find($itemId));
        });

        $this->globex->run(fn () => $this->assertNotNull(Invoice::find($invoiceId)));
    }

    public function test_relationships_never_cross_tenants(): void
    {
        $customerId = $this->acme->run(function () {
            $customer = Customer::factory()->create();
            Invoice::factory()->count(2)->create(['customer_id' => $customer->id]);

            return $customer->id;
        });

        // Even if a stray row in another tenant points at acme's customer id, acme never sees it.
        $this->globex->run(fn () => Invoice::factory()->create());
        DB::table('invoices')->where('tenant_id', 'globex')->update(['customer_id' => $customerId]);

        $this->acme->run(function () use ($customerId) {
            $invoices = Customer::findOrFail($customerId)->invoices;

            $this->assertCount(2, $invoices);
            $this->assertSame(['acme'], $invoices->pluck('tenant_id')->unique()->values()->all());
        });
    }

    public function test_tenant_id_is_filled_automatically(): void
    {
        $this->acme->run(function () {
            $this->assertSame('acme', Customer::factory()->create()->tenant_id);
            $invoice = Invoice::factory()->partial()->create();

            $this->assertSame('acme', $invoice->tenant_id);
            $this->assertSame('acme', $invoice->items->first()->tenant_id);
            $this->assertSame('acme', $invoice->payments->first()->tenant_id);
            $this->assertSame('acme', Customer::create(['name' => 'Plain', 'payment_terms_days' => 30])->tenant_id);
        });
    }

    public function test_factory_invoice_is_in_the_same_tenant_as_its_customer(): void
    {
        $this->globex->run(function () {
            $invoice = Invoice::factory()->create();

            $this->assertSame($invoice->customer->tenant_id, $invoice->tenant_id);
        });
    }

    public function test_factory_invoices_have_consistent_totals_and_status(): void
    {
        $this->acme->run(function () {
            $paid = Invoice::factory()->withItems(4)->paid()->create();
            $partial = Invoice::factory()->partial()->create();
            $overdue = Invoice::factory()->overdue()->create();

            foreach ([$paid, $partial, $overdue] as $invoice) {
                $invoice->refresh();
                $this->assertSame((int) $invoice->items()->sum('line_total_cents'), $invoice->total_cents);
                $this->assertSame($invoice->total_cents, $invoice->subtotal_cents);
                $this->assertSame((int) $invoice->payments()->sum('amount_cents'), $invoice->amount_paid_cents);
            }

            $this->assertCount(4, $paid->items);
            $this->assertSame('paid', $paid->status->value);
            $this->assertSame($paid->total_cents, $paid->amount_paid_cents);
            $this->assertSame('partial', $partial->status->value);
            $this->assertLessThan($partial->total_cents, $partial->amount_paid_cents);
            $this->assertTrue($overdue->is_overdue);
            $this->assertSame('overdue', $overdue->display_status);
            $this->assertFalse($paid->is_overdue);
            $this->assertSame(1, Invoice::overdue()->count());
            $this->assertSame(2, Invoice::outstanding()->count());
        });
    }

    public function test_database_rejects_a_zero_payment(): void
    {
        $this->expectException(QueryException::class);

        $this->acme->run(function () {
            $invoice = Invoice::factory()->issued()->create();

            DB::transaction(fn () => Payment::factory()->create([
                'invoice_id' => $invoice->id,
                'amount_cents' => 0,
            ]));
        });
    }

    public function test_database_rejects_amount_paid_greater_than_total(): void
    {
        $this->expectException(QueryException::class);

        $this->acme->run(function () {
            $invoice = Invoice::factory()->issued()->create();

            DB::transaction(fn () => DB::table('invoices')->where('id', $invoice->id)->update([
                'amount_paid_cents' => $invoice->total_cents + 1,
            ]));
        });
    }

    public function test_database_rejects_an_issued_invoice_without_a_number_and_due_before_issue(): void
    {
        $this->acme->run(function () {
            $invoice = Invoice::factory()->issued()->create();

            $this->assertThrows(
                fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['number' => null])),
                QueryException::class,
            );
            $this->assertThrows(
                fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['due_date' => $invoice->issue_date->copy()->subDay()])),
                QueryException::class,
            );
        });
    }
}
