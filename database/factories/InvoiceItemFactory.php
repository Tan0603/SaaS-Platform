<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Note: a stand-alone item does not update its invoice totals. To get consistent
 * invoices use Invoice::factory()->withItems($n).
 *
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'tenant_id' => fn (array $attributes) => Invoice::withoutGlobalScopes()
                ->whereKey($attributes['invoice_id'])->value('tenant_id'),
            'position' => 1,
            'description' => fake()->words(3, true),
            'quantity' => $quantity = fake()->randomElement([1, 2, 3, 5]),
            'unit_price_cents' => $unitPrice = fake()->numberBetween(1000, 50000),
            'line_total_cents' => (int) round($quantity * $unitPrice),
        ];
    }
}
