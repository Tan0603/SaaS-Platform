<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $this->actingAsTenantUser($this->createTenant('acme'));

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('tenant.id', 'acme')
                ->where('tenant.name', 'Acme Sdn Bhd'));
    }
}
