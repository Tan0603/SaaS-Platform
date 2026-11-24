<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\InitializeTenancyByUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenancyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', InitializeTenancyByUser::class])
            ->get('/_test/tenancy', fn () => response()->json([
                'tenant' => tenant('id'),
                'users' => User::count(),
            ]));
    }

    public function test_only_the_current_tenants_users_are_visible(): void
    {
        $acme = $this->createTenant('acme');
        $globex = $this->createTenant('globex');
        $acmeUser = $this->createTenantUser($acme);
        $this->createTenantUser($acme);
        $globexUser = $this->createTenantUser($globex);

        $this->actingAs($acmeUser)->getJson('/_test/tenancy')
            ->assertOk()->assertExactJson(['tenant' => 'acme', 'users' => 2]);

        tenancy()->end();

        $this->actingAs($globexUser)->getJson('/_test/tenancy')
            ->assertOk()->assertExactJson(['tenant' => 'globex', 'users' => 1]);
    }

    public function test_guests_are_rejected_before_tenancy_starts(): void
    {
        $this->getJson('/_test/tenancy')->assertUnauthorized();

        $this->assertFalse(tenancy()->initialized);
    }

    public function test_user_without_a_tenant_gets_403(): void
    {
        // tenant_id is NOT NULL in the database, so simulate an orphan with an unsaved model.
        $orphan = new User(['name' => 'Orphan', 'email' => 'orphan@test.test']);
        $orphan->id = 999999;

        $this->actingAs($orphan)->getJson('/_test/tenancy')->assertForbidden();

        $this->assertFalse(tenancy()->initialized);
    }
}
