<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo companies. Each gets one admin user; the tenant is found from the user's tenant_id at login.
     */
    public function run(): void
    {
        foreach ([
            'acme' => 'Acme Sdn Bhd',
            'globex' => 'Globex Sdn Bhd',
        ] as $id => $name) {
            $tenant = Tenant::query()->create(['id' => $id, 'name' => $name]);

            // Inside run() tenancy is initialised, so BelongsToTenant fills tenant_id.
            $tenant->run(function () use ($id) {
                User::factory()->create([
                    'name' => 'Admin',
                    'email' => "admin@{$id}.test",
                ]);

                // Small demo data set; the large performance seed comes in a later phase.
                $customers = Customer::factory()->count(10)->create();
                foreach ($customers->take(3) as $customer) {
                    Invoice::factory()->issued()->create(['customer_id' => $customer->id]);
                }
                Invoice::factory()->partial()->create(['customer_id' => $customers[3]->id]);
                Invoice::factory()->paid()->create(['customer_id' => $customers[4]->id]);
                Invoice::factory()->overdue()->create(['customer_id' => $customers[5]->id]);
            });
        }
    }
}
