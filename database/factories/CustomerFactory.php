<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Inside an initialised tenant (or `$tenant->run()`), tenant_id is filled by BelongsToTenant.
 * Outside tenancy a new tenant is created so the row is still valid.
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => tenancy()->initialized ? tenant('id') : Tenant::factory(),
            'name' => fake()->unique()->company(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'registration_no' => fake()->boolean(60) ? fake()->numerify('20##########') : null,
            'payment_terms_days' => fake()->randomElement(Customer::PAYMENT_TERMS),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['deleted_at' => now()]);
    }
}
