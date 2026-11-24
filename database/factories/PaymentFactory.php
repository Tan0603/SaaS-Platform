<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Note: a stand-alone payment does not update its invoice's amount_paid_cents / status.
 * To get consistent invoices use Invoice::factory()->partial() / ->paid().
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory()->issued(),
            'tenant_id' => fn (array $attributes) => Invoice::withoutGlobalScopes()
                ->whereKey($attributes['invoice_id'])->value('tenant_id'),
            'amount_cents' => fake()->numberBetween(100, 1000),
            'paid_at' => today(),
            'method' => fake()->randomElement(PaymentMethod::cases())->value,
            'reference' => null,
        ];
    }

    public function voided(): static
    {
        return $this->state(fn () => [
            'voided_at' => now(),
            'void_reason' => 'Entered in error',
        ]);
    }
}
