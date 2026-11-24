<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Tenant ids are readable slugs we supply ourselves (see Tenant::shouldGenerateId()).
        $slug = fake()->unique()->slug(2);

        return [
            'id' => $slug,
            'name' => ucwords(str_replace('-', ' ', $slug)).' Sdn Bhd',
        ];
    }
}
