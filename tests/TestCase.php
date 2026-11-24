<?php

namespace Tests;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests must not depend on a built frontend.
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        // Never let an initialised tenant leak into the next test.
        if (function_exists('tenancy') && $this->app) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * Create a tenant (e.g. "acme" => "Acme Sdn Bhd").
     */
    protected function createTenant(string $id = 'acme'): Tenant
    {
        $tenant = Tenant::query()->create(['id' => $id, 'name' => ucfirst($id).' Sdn Bhd'])->fresh();   // fresh(): load the DB defaults (prefix, terms)

        return $tenant;
    }

    /**
     * Create a user inside the tenant and log them in (for that tenant's guard/session).
     * Tenancy is ended again so requests initialise it themselves, like in production.
     */
    protected function actingAsTenantUser(Tenant $tenant): User
    {
        $user = $tenant->run(fn () => User::factory()->create());

        tenancy()->end();
        $this->actingAs($user);

        return $user;
    }

    /**
     * Create a user in the tenant without logging in.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createTenantUser(Tenant $tenant, array $attributes = []): User
    {
        $user = $tenant->run(fn () => User::factory()->create($attributes));
        tenancy()->end();

        return $user;
    }
}
