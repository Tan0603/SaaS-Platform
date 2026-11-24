<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_users_can_authenticate_with_correct_password(): void
    {
        $user = $this->createTenantUser($this->createTenant('acme'));

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = $this->createTenantUser($this->createTenant('acme'));

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $this->actingAsTenantUser($this->createTenant('acme'));

        $this->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_guests_are_redirected_to_login_from_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_root_redirects_guests_to_login_and_users_to_dashboard(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
        $this->followingRedirects()->get('/')
            ->assertInertia(fn ($page) => $page->component('auth/Login'));

        $this->actingAsTenantUser($this->createTenant('acme'));
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_acme_user_runs_in_acme_tenant_context_after_login(): void
    {
        $user = $this->createTenantUser($this->createTenant('acme'));

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('tenant.id', 'acme')
                ->where('tenant.name', 'Acme Sdn Bhd'));

        $this->assertSame('acme', tenant('id'));
    }

    public function test_globex_user_gets_globex_tenant_not_acme(): void
    {
        $this->createTenantUser($this->createTenant('acme'), ['email' => 'a@acme.test']);
        $this->createTenantUser($this->createTenant('globex'), ['email' => 'g@globex.test']);

        $this->post('/login', ['email' => 'g@globex.test', 'password' => 'password']);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tenant.id', 'globex')
                ->where('tenant.name', 'Globex Sdn Bhd'));

        $this->assertSame('globex', tenant('id'));
    }

    public function test_same_email_cannot_exist_in_two_tenants(): void
    {
        $this->createTenantUser($this->createTenant('acme'), ['email' => 'boss@shared.test']);
        $globex = $this->createTenant('globex');

        $this->expectException(QueryException::class);

        $this->createTenantUser($globex, ['email' => 'boss@shared.test']);
    }

    public function test_deleting_a_tenant_deletes_its_users(): void
    {
        $acme = $this->createTenant('acme');
        $globex = $this->createTenant('globex');
        $acmeUser = $this->createTenantUser($acme);
        $globexUser = $this->createTenantUser($globex);

        $acme->delete();

        $this->assertNull(User::withoutGlobalScopes()->find($acmeUser->id));
        $this->assertNotNull(User::withoutGlobalScopes()->find($globexUser->id));
        $this->assertNull(Tenant::find('acme'));
    }
}
