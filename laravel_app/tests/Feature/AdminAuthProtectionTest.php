<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_routes_redirect_guests_to_login(): void
    {
        $this->get('/admin/trade-setups')->assertRedirect('/login');
        $this->get('/admin/orders')->assertRedirect('/login');
        $this->get('/admin/trades')->assertRedirect('/login');
    }

    public function test_user_can_login_and_logout_and_guest_cannot_login_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('secret-pass-123'),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'bad-password',
        ])->assertSessionHasErrors('email');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-pass-123',
        ])->assertRedirect('/admin/trade-setups');

        $this->assertAuthenticatedAs($user);

        $this->get('/login')->assertRedirect('/admin/trade-setups');

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_register_route_does_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
