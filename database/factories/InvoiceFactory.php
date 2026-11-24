<?php

namespace Database\Factories;

use App\Enums\InvoiceState;
use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Builds invoices whose numbers always add up:
 *   subtotal = total = sum(item line totals), amount_paid = sum(active payments),
 *   status matches amount_paid. The items and payments are created in afterCreating().
 *
 * Usage: Invoice::factory()->create()                    draft with 2 items
 *        Invoice::factory()->withItems(5)->issued()->create()
 *        Invoice::factory()->partial()->create()         issued, part paid (payments created)
 *        Invoice::factory()->paid()->create()
 *        Invoice::factory()->overdue()->create()         issued, past due, unpaid
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    protected int $itemCount = 2;

    /** 'none' | 'partial' | 'full' */
    protected string $paymentPlan = 'none';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $issueDate = now()->subDays(fake()->numberBetween(0, 10))->startOfDay();

        return [
            'customer_id' => Customer::factory(),
            // Always the same tenant as the customer, even outside an initialised tenancy.
            'tenant_id' => fn (array $attributes) => Customer::withoutGlobalScopes()->withTrashed()
                ->whereKey($attributes['customer_id'])->value('tenant_id'),
            'issue_date' => $issueDate,
            'due_date' => $issueDate->copy()->addDays(30),
            'state' => InvoiceState::Draft->value,
            'status' => InvoiceStatus::Unpaid->value,
            'subtotal_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
        ];
    }

    protected function newInstance(array $arguments = []): static
    {
        $new = parent::newInstance($arguments);
        $new->itemCount = $this->itemCount;
        $new->paymentPlan = $this->paymentPlan;

        return $new;
    }

    public function withItems(int $count): static
    {
        $new = $this->newInstance();
        $new->itemCount = max(1, $count);

        return $new;
    }

    public function issued(): static
    {
        return $this->state(fn () => ['state' => InvoiceState::Issued->value]);
    }

    public function partial(): static
    {
        $new = $this->issued()->newInstance();
        $new->paymentPlan = 'partial';

        return $new;
    }

    public function paid(): static
    {
        $new = $this->issued()->newInstance();
        $new->paymentPlan = 'full';

        return $new;
    }

    /** Issued, due date in the past, nothing paid. */
    public function overdue(): static
    {
        return $this->issued()->state(fn () => [
            'issue_date' => today()->subDays(60),
            'due_date' => today()->subDays(30),
        ]);
    }

    // Overriding the hooks (instead of using afterMaking()/afterCreating() closures) keeps the
    // state of the *current* factory instance: state() clones the factory, closures would not follow.
    protected function callAfterMaking(Collection $instances)
    {
        parent::callAfterMaking($instances);

        $instances->each(fn (Invoice $invoice) => $this->prepare($invoice));
    }

    protected function callAfterCreating(Collection $instances, ?Model $parent = null)
    {
        parent::callAfterCreating($instances, $parent);

        $instances->each(function (Invoice $invoice) {
            // Laravel unsets empty loaded relations after save, so read them defensively.
            $items = $invoice->relationLoaded('items') ? $invoice->getRelation('items') : collect();
            $payments = $invoice->relationLoaded('payments') ? $invoice->getRelation('payments') : collect();

            $invoice->items()->saveMany($items);
            $invoice->payments()->saveMany($payments);
            $invoice->setRelation('items', $items)->setRelation('payments', $payments);
        });
    }

    // Numbers are assigned one invoice at a time, right before each insert, so count(n) never collides.
    protected function store(Collection $results)
    {
        $results->each(function (Invoice $invoice) {
            if ($invoice->state === InvoiceState::Issued) {
                $sequence = (int) Invoice::withoutGlobalScopes()->where('tenant_id', $invoice->tenant_id)->max('sequence') + 1;
                $invoice->sequence = $sequence;
                $invoice->number = 'IV-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
                $invoice->issued_at = $invoice->issue_date->copy()->setTime(9, 0);
            }

            parent::store(new Collection([$invoice]));
        });
    }

    /** Computes items, totals, payments, status and number before the invoice row is inserted. */
    private function prepare(Invoice $invoice): void
    {
        $items = collect(range(1, $this->itemCount))->map(function (int $position) use ($invoice) {
            $quantity = fake()->randomElement([1, 1, 2, 3, 5, 10]);
            $unitPrice = fake()->numberBetween(1000, 50000);

            $item = new InvoiceItem([
                'position' => $position,
                'description' => fake()->words(3, true),
                'quantity' => $quantity,
                'unit_price_cents' => $unitPrice,
                'line_total_cents' => (int) round($quantity * $unitPrice),
            ]);
            $item->tenant_id = $invoice->tenant_id;

            return $item;
        });

        $total = (int) $items->sum('line_total_cents');
        $invoice->subtotal_cents = $total;
        $invoice->total_cents = $total;

        $paid = match ($this->paymentPlan) {
            'full' => $total,
            'partial' => fake()->numberBetween(1, $total - 1),
            default => 0,
        };

        // One payment, or two when the amount can be split.
        $amounts = $paid >= 2 && fake()->boolean()
            ? [$first = intdiv($paid, 2), $paid - $first]
            : array_filter([$paid]);

        $payments = collect($amounts)->map(function (int $amount) use ($invoice) {
            $payment = new Payment([
                'amount_cents' => $amount,
                'paid_at' => $invoice->issue_date,
                'method' => fake()->randomElement(['cash', 'bank_transfer', 'cheque', 'card', 'e_wallet']),
                'reference' => fake()->boolean() ? strtoupper(fake()->bothify('REF-####??')) : null,
            ]);
            $payment->tenant_id = $invoice->tenant_id;

            return $payment;
        })->values();

        $invoice->amount_paid_cents = $paid;
        $invoice->status = match (true) {
            $paid === 0 => InvoiceStatus::Unpaid,
            $paid >= $total => InvoiceStatus::Paid,
            default => InvoiceStatus::Partial,
        };

        $invoice->setRelation('items', $items);
        $invoice->setRelation('payments', $payments);
    }
}
