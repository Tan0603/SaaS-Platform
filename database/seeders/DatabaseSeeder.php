<?php

namespace Database\Seeders;

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
            });
        }
    }
}
