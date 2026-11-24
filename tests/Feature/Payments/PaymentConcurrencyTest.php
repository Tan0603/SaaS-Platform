<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VoidPaymentAction;
use App\Data\PaymentData;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InvoiceNotPayableException;
use App\Exceptions\OverpaymentException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Overpayment layers 2 and 3.
 *
 * A true parallel race (two PDO connections, one blocked on `SELECT ... FOR UPDATE`) cannot be reproduced under
 * RefreshDatabase: the test data lives in an uncommitted transaction that a second connection cannot see. What
 * these tests prove instead is the two halves the race protection is built from:
 *   - the action re-reads the paid amount from the payments table under the lock, so the SECOND of two payments
 *     is judged against the balance the first one left (exactly what a blocked concurrent request sees once the
 *     lock is released);
 *   - even if that code were wrong, PostgreSQL itself refuses an invoice with amount_paid_cents > total_cents
 *     and a payment with amount_cents <= 0.
 */
class PaymentConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = $this->createTenant('acme');
        $this->invoice = $this->acme->run(function () {
            $invoice = Invoice::factory()->issued()->create(['issue_date' => today()->subDays(3), 'due_date' => today()->addDays(27)]);
            Invoice::withoutGlobalScopes()->whereKey($invoice->id)->update(['subtotal_cents' => 10000, 'total_cents' => 10000]);

            return $invoice->fresh();
        });
    }

    private function data(int $cents): PaymentData
    {
        return new PaymentData($cents, today()->toDateString(), PaymentMethod::Cash, null);
    }

    public function test_the_second_payment_is_judged_against_the_balance_the_first_left(): void
    {
        $this->acme->run(function () {
            $action = app(RecordPaymentAction::class);

            // Two "simultaneous" requests that each saw a balance of 100.00 in the browser.
            $action->execute($this->invoice->id, $this->data(10000));

            try {
                $action->execute($this->invoice->id, $this->data(10000));
                $this->fail('The second payment must be rejected: the balance is used up.');
            } catch (OverpaymentException $e) {
                $this->assertArrayHasKey('amount', $e->errors());
            }

            $this->assertSame(1, Payment::count());
            $fresh = Invoice::findOrFail($this->invoice->id);
            $this->assertSame(10000, $fresh->amount_paid_cents);
            $this->assertSame(InvoiceStatus::Paid, $fresh->status);
        });
    }

    public function test_the_action_trusts_the_payments_table_not_the_stored_invoice_copy(): void
    {
        $this->acme->run(function () {
            $action = app(RecordPaymentAction::class);
            $action->execute($this->invoice->id, $this->data(6000));

            // Simulate a stale stored copy (as if another request had not recalculated yet).
            DB::table('invoices')->where('id', $this->invoice->id)->update(['amount_paid_cents' => 0, 'status' => 'unpaid']);

            $this->expectException(OverpaymentException::class);
            $action->execute($this->invoice->id, $this->data(5000));   // stored copy says 100.00 free, reality is 40.00
        });
    }

    public function test_a_rejected_payment_creates_nothing(): void
    {
        $this->acme->run(function () {
            try {
                app(RecordPaymentAction::class)->execute($this->invoice->id, $this->data(10001));
            } catch (OverpaymentException) {
            }

            $this->assertSame(0, Payment::count());
            $this->assertSame(0, Invoice::findOrFail($this->invoice->id)->amount_paid_cents);
        });
    }

    public function test_the_action_refuses_zero_negative_and_non_issued_invoices(): void
    {
        $this->acme->run(function () {
            $action = app(RecordPaymentAction::class);

            foreach ([0, -100] as $cents) {
                try {
                    $action->execute($this->invoice->id, $this->data($cents));
                    $this->fail("Amount {$cents} must be rejected.");
                } catch (OverpaymentException) {
                }
            }

            $draft = Invoice::factory()->create();   // draft
            $this->expectException(InvoiceNotPayableException::class);
            $action->execute($draft->id, $this->data(100));
        });
    }

    public function test_voiding_then_paying_again_never_exceeds_the_total(): void
    {
        $this->acme->run(function () {
            $record = app(RecordPaymentAction::class);
            $first = $record->execute($this->invoice->id, $this->data(10000));
            app(VoidPaymentAction::class)->execute($first, 'Wrong account');
            $record->execute($this->invoice->id, $this->data(10000));

            try {
                $record->execute($this->invoice->id, $this->data(1));
                $this->fail('Invoice is fully paid again; one more cent must be rejected.');
            } catch (OverpaymentException) {
            }

            $this->assertSame(10000, (int) Payment::active()->sum('amount_cents'));
            $this->assertSame(10000, Invoice::findOrFail($this->invoice->id)->amount_paid_cents);
        });
    }

    public function test_the_database_refuses_amount_paid_above_the_total(): void
    {
        try {
            // A savepoint keeps the surrounding test transaction usable after the failed statement.
            DB::transaction(fn () => DB::table('invoices')->where('id', $this->invoice->id)->update(['amount_paid_cents' => 10001]));
            $this->fail('The CHECK constraint must refuse amount_paid_cents > total_cents.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('check', strtolower($e->getMessage()));
        }

        $this->assertSame(0, (int) DB::table('invoices')->where('id', $this->invoice->id)->value('amount_paid_cents'));
    }

    public function test_the_database_refuses_a_zero_or_negative_payment(): void
    {
        foreach ([0, -1] as $cents) {
            try {
                DB::transaction(fn () => DB::table('payments')->insert([
                    'tenant_id' => 'acme', 'invoice_id' => $this->invoice->id, 'amount_cents' => $cents,
                    'paid_at' => today(), 'method' => 'cash', 'created_at' => now(), 'updated_at' => now(),
                ]));
                $this->fail("A payment of {$cents} cents must be refused by the database.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('payments_amount_positive_check', $e->getMessage());
            }
        }

        $this->assertSame(0, DB::table('payments')->count());
    }
}
