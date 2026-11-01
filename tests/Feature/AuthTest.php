<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Admin;
use App\Domain\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_with_username_and_use_token(): void
    {
        Admin::create(['username' => 'admin', 'name' => 'Administrator', 'password' => 'admin', 'is_active' => true]);

        $response = $this->postJson('/api/v1/admin/auth/login', ['login' => 'admin', 'password' => 'admin'])
            ->assertOk()
            ->assertJsonStructure(['token', 'admin' => ['id', 'username', 'name']]);

        $this->withToken($response->json('token'))
            ->getJson('/api/v1/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('admin.username', 'admin');
    }

    public function test_admin_login_fails_with_wrong_password(): void
    {
        Admin::create(['username' => 'admin', 'name' => 'Administrator', 'password' => 'admin', 'is_active' => true]);

        $this->postJson('/api/v1/admin/auth/login', ['login' => 'admin', 'password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('login');
    }

    public function test_customer_can_login_with_email(): void
    {
        User::create(['full_name' => 'Test User', 'email' => 'test@gmail.com', 'password' => 'test', 'is_active' => true]);

        $this->postJson('/api/v1/store/auth/login', ['login' => 'test@gmail.com', 'password' => 'test'])
            ->assertOk()
            ->assertJsonPath('user.email', 'test@gmail.com')
            ->assertJsonStructure(['token']);
    }

    public function test_customer_token_cannot_open_admin_api_and_admin_token_cannot_open_customer_api(): void
    {
        $customer = User::create(['full_name' => 'Test User', 'email' => 'test@gmail.com', 'password' => 'test', 'is_active' => true]);
        $customerToken = $customer->createToken('customer')->plainTextToken;

        $admin = Admin::create(['username' => 'admin', 'name' => 'Administrator', 'password' => 'admin', 'is_active' => true]);
        $adminToken = $admin->createToken('admin')->plainTextToken;

        $this->withToken($customerToken)->getJson('/api/v1/admin/categories')->assertStatus(401);

        // A real client sends each request on a fresh app; tests reuse one, so drop the cached guard user.
        $this->app['auth']->forgetGuards();
        $this->withToken($adminToken)->getJson('/api/v1/store/auth/me')->assertStatus(401);
    }

    /** Requests from the admin panel origin are "stateful": they get a session cookie instead of a token. */
    private function adminPanelHeaders(): array
    {
        return ['Origin' => 'http://localhost:5190', 'Referer' => 'http://localhost:5190/'];
    }

    public function test_admin_panel_origin_logs_in_with_a_cookie_session_and_gets_no_token(): void
    {
        $admin = Admin::create(['username' => 'admin', 'name' => 'Administrator', 'password' => 'admin', 'is_active' => true]);

        $response = $this->withHeaders($this->adminPanelHeaders())
            ->postJson('/api/v1/admin/auth/login', ['login' => 'admin', 'password' => 'admin'])
            ->assertOk()
            ->assertJsonPath('admin.username', 'admin')
            ->assertJsonMissingPath('token');

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertNotEmpty(collect($response->headers->getCookies())->map->getName()->filter(fn ($n) => str_contains($n, 'session')));
    }

    public function test_cookie_session_wrong_password_and_inactive_admin_are_rejected(): void
    {
        Admin::create(['username' => 'admin', 'name' => 'Administrator', 'password' => 'admin', 'is_active' => true]);
        Admin::create(['username' => 'off', 'name' => 'Off', 'password' => 'admin', 'is_active' => false]);

        $this->withHeaders($this->adminPanelHeaders())
            ->postJson('/api/v1/admin/auth/login', ['login' => 'admin', 'password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors('login');

        $this->withHeaders($this->adminPanelHeaders())
            ->postJson('/api/v1/admin/auth/login', ['login' => 'off', 'password' => 'admin'])
            ->assertStatus(422);

        $this->assertGuest('admin');
    }

    public function test_session_authenticated_admin_can_use_protected_endpoints_and_logout(): void
    {
        $admin = Admin::create(['username' => 'admin', 'name' => 'Administrator', 'password' => 'admin', 'is_active' => true]);

        $this->actingAs($admin, 'admin')
            ->withHeaders($this->adminPanelHeaders())
            ->getJson('/api/v1/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('admin.username', 'admin');

        $this->withHeaders($this->adminPanelHeaders())
            ->postJson('/api/v1/admin/auth/logout')
            ->assertOk();

        $this->assertGuest('admin');
    }

    public function test_customers_are_never_logged_in_by_cookie(): void
    {
        User::create(['full_name' => 'Test User', 'email' => 'test@gmail.com', 'password' => 'test', 'is_active' => true]);

        // Even from the admin panel origin, the customer login still returns a bearer token.
        $this->withHeaders($this->adminPanelHeaders())
            ->postJson('/api/v1/store/auth/login', ['login' => 'test@gmail.com', 'password' => 'test'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_admin_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/categories')->assertStatus(401);
    }
}
