<?php

namespace Tests\Feature;

use App\Domain\Auth\AdminAccess;
use App\Domain\Auth\Models\Admin;
use App\Providers\AppServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedShop();
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();   // one app serves every request of a test: drop the cached user

        return $this->withToken($token);
    }

    // ------------------------------------------------------------------ admin tokens expire

    public function test_admin_api_tokens_expire(): void
    {
        config(['sanctum.admin_token_minutes' => 120]);
        AdminAccess::sync();
        Admin::create(['username' => 'script', 'name' => 'Script', 'password' => 'Secret-pass1', 'is_active' => true])->assignRole('admin');

        $login = $this->postJson(self::ADMIN.'/auth/login', ['login' => 'script', 'password' => 'Secret-pass1'])->assertOk();
        $this->assertEqualsWithDelta(now()->addMinutes(120)->timestamp, strtotime($login->json('expires_at')), 5);
        $this->as($login->json('token'))->getJson(self::ADMIN.'/auth/me')->assertOk();

        $this->travel(121)->minutes();
        $this->as($login->json('token'))->getJson(self::ADMIN.'/auth/me')->assertUnauthorized();
    }

    public function test_the_default_admin_lifetime_is_twelve_hours_and_zero_means_never(): void
    {
        $this->assertSame(720, config('sanctum.admin_token_minutes'));
        $this->assertNull(config('sanctum.customer_token_minutes'));   // customers have no tokens at all

        AdminAccess::sync();
        Admin::create(['username' => 'script', 'name' => 'Script', 'password' => 'Secret-pass1', 'is_active' => true])->assignRole('admin');
        config(['sanctum.admin_token_minutes' => 0]);
        $forever = $this->postJson(self::ADMIN.'/auth/login', ['login' => 'script', 'password' => 'Secret-pass1'])->assertOk();
        $this->assertNull($forever->json('expires_at'));
        $this->travel(5)->years();
        $this->as($forever->json('token'))->getJson(self::ADMIN.'/auth/me')->assertOk();
    }

    public function test_expired_tokens_are_pruned_by_the_scheduled_command(): void
    {
        config(['sanctum.admin_token_minutes' => 60]);
        AdminAccess::sync();
        Admin::create(['username' => 'script', 'name' => 'Script', 'password' => 'Secret-pass1', 'is_active' => true])->assignRole('admin');
        $this->postJson(self::ADMIN.'/auth/login', ['login' => 'script', 'password' => 'Secret-pass1'])->assertOk();
        $this->assertSame(1, DB::table('personal_access_tokens')->count());

        $this->travel(61)->minutes();
        $this->artisan('sanctum:prune-expired --hours=24')->assertSuccessful();
        $this->assertSame(1, DB::table('personal_access_tokens')->count());   // expired less than a day ago: kept

        $this->travel(2)->days();
        $this->artisan('sanctum:prune-expired --hours=24')->assertSuccessful();
        $this->assertSame(0, DB::table('personal_access_tokens')->count());

        $scheduled = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
        $this->assertStringContainsString('sanctum:prune-expired', $scheduled);
    }

    // ------------------------------------------------------------------ passwords

    public function test_admin_passwords_are_hashed_and_never_returned(): void
    {
        $admin = $this->actingAsAdmin();
        $this->assertStringStartsWith('$2y$', $admin->fresh()->password);   // bcrypt
        $this->assertTrue(Hash::check('secret-pass', $admin->fresh()->password));
        $this->assertStringNotContainsString('password', json_encode($this->getJson(self::ADMIN.'/auth/me')->json()));
    }

    // ------------------------------------------------------------------ CORS

    public function test_cors_allows_the_configured_storefront_origins_only(): void
    {
        $preflight = fn (string $origin) => $this->call('OPTIONS', self::STORE.'/products', [], [], [], [
            'HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);

        // two storefronts: each is echoed back, a stranger gets no CORS answer at all
        config(['cors.allowed_origins' => ['https://shop.example.com', 'https://www.example.com']]);
        $this->assertSame('https://shop.example.com', $preflight('https://shop.example.com')->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('https://www.example.com', $preflight('https://www.example.com')->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($preflight('https://evil.example.org')->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($this->getJson(self::STORE.'/settings', ['Origin' => 'https://evil.example.org'])->headers->get('Access-Control-Allow-Origin'));

        // one storefront: the header names that one origin, never the caller's (the browser blocks the stranger)
        config(['cors.allowed_origins' => ['https://shop.example.com']]);
        $this->assertNotSame('https://evil.example.org', $preflight('https://evil.example.org')->headers->get('Access-Control-Allow-Origin'));

        // never a wildcard (credentials are allowed for the admin panel session); the idempotency header is readable by the storefront
        $shipped = require config_path('cors.php');
        $this->assertNotContains('*', $shipped['allowed_origins']);
        $this->assertTrue($shipped['supports_credentials']);
        $this->assertContains('Idempotency-Replayed', $shipped['exposed_headers']);
    }

    // ------------------------------------------------------------------ rate limits

    public function test_every_store_and_admin_route_has_a_rate_limit(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/store') && ! str_starts_with($uri, 'api/v1/admin')) {
                continue;
            }
            $this->assertTrue(collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')), "{$route->methods()[0]} $uri has no rate limit");
            $checked++;
        }
        $this->assertGreaterThan(50, $checked);
    }

    public function test_the_public_order_endpoints_have_their_own_limits_and_the_honeypot(): void
    {
        $find = fn (string $uri, string $method) => collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true));

        $order = $find('api/v1/store/orders', 'POST')->gatherMiddleware();
        $quote = $find('api/v1/store/checkout/quote', 'POST')->gatherMiddleware();
        $lookup = $find('api/v1/store/orders/{orderNumber}', 'GET')->gatherMiddleware();

        $this->assertContains('throttle:checkout', $order);
        $this->assertContains('honeypot', $order);
        $this->assertContains('throttle:quote', $quote);
        $this->assertContains('throttle:order-lookup', $lookup);
    }

    public function test_login_is_limited_per_name_and_ip(): void
    {
        AdminAccess::sync();
        Admin::create(['username' => 'script', 'name' => 'Script', 'password' => 'Secret-pass1', 'is_active' => true])->assignRole('admin');

        foreach (range(1, 8) as $i) {
            $this->postJson(self::ADMIN.'/auth/login', ['login' => 'script', 'password' => 'wrong'])->assertStatus(422);
        }
        $this->postJson(self::ADMIN.'/auth/login', ['login' => 'script', 'password' => 'Secret-pass1'])->assertStatus(429);   // even the right password waits
        $this->postJson(self::ADMIN.'/auth/login', ['login' => 'nobody', 'password' => 'x'])->assertStatus(422);               // another name is not blocked
    }

    public function test_public_reads_and_the_admin_api_are_throttled(): void
    {
        RateLimiter::for('public', fn () => Limit::perMinute(3)->by('test'));
        foreach (range(1, 3) as $i) {
            $this->getJson(self::STORE.'/settings')->assertOk();
        }
        $this->getJson(self::STORE.'/settings')->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson(self::STORE.'/banners')->assertStatus(429);   // the limit is shared by the public reads

        RateLimiter::for('admin', fn () => Limit::perMinute(2)->by('test'));
        $this->actingAsAdmin();
        $this->getJson(self::ADMIN.'/dashboard')->assertOk();
        $this->getJson(self::ADMIN.'/dashboard')->assertOk();
        $this->getJson(self::ADMIN.'/dashboard')->assertStatus(429);
    }

    public function test_the_quote_is_limited_per_ip(): void
    {
        config(['shop.rate.quote_per_minute' => 3]);
        $variant = $this->variantOf($this->shopProduct());

        foreach (range(1, 3) as $i) {
            $this->quote([$variant => 1])->assertOk();
        }
        $this->quote([$variant => 1])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_placing_orders_is_limited_per_ip_per_minute(): void
    {
        config(['shop.rate.checkout_per_minute' => 3]);
        $variant = $this->variantOf($this->shopProduct());

        foreach (range(1, 3) as $i) {
            $this->placeOrder($this->orderBody([$variant => 1]))->assertCreated();
        }
        $this->placeOrder($this->orderBody([$variant => 1]))->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(3, DB::table('orders')->count());
    }

    public function test_placing_orders_is_limited_per_ip_per_hour(): void
    {
        config(['shop.rate.checkout_per_hour' => 2]);
        $variant = $this->variantOf($this->shopProduct());

        $this->placeOrder($this->orderBody([$variant => 1]))->assertCreated();
        $this->placeOrder($this->orderBody([$variant => 1]))->assertCreated();
        $this->placeOrder($this->orderBody([$variant => 1]))->assertStatus(429);
    }

    public function test_one_phone_number_cannot_flood_the_shop(): void
    {
        config(['shop.rate.checkout_per_phone_hour' => 2]);
        $variant = $this->variantOf($this->shopProduct());
        $contact = ['name' => 'Alex', 'phone' => '+1 555 010 0100'];

        $this->placeOrder($this->orderBody([$variant => 1], ['customer' => $contact]))->assertCreated();
        $this->placeOrder($this->orderBody([$variant => 1], ['customer' => ['phone' => '+1-555-010-0100'] + $contact]))->assertCreated();   // the same number, typed differently
        $this->placeOrder($this->orderBody([$variant => 1], ['customer' => $contact]))->assertStatus(429);
        $this->placeOrder($this->orderBody([$variant => 1], ['customer' => ['phone' => '+1 555 010 0199'] + $contact]))->assertCreated();   // another number is fine
    }

    public function test_the_order_lookup_is_limited_per_ip_and_per_order(): void
    {
        $order = $this->orderFor(['phone' => '+1 555 010 0100']);

        config(['shop.rate.lookup_per_minute' => 4]);
        foreach (range(1, 4) as $i) {
            $this->lookup($order->order_number, '+1 555 010 0100')->assertOk();
        }
        $this->lookup($order->order_number, '+1 555 010 0100')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_guessing_the_phone_of_one_order_runs_into_the_per_order_limit(): void
    {
        $order = $this->orderFor(['phone' => '+1 555 010 0100']);
        config(['shop.rate.lookup_per_order_hour' => 3]);

        foreach (range(1, 3) as $i) {
            $this->lookup($order->order_number, '+1 555 010 01'.sprintf('%02d', $i))->assertNotFound();
        }
        $this->lookup($order->order_number, '+1 555 010 0100')->assertStatus(429);   // even the right phone waits
    }

    // ------------------------------------------------------------------ production configuration

    public function test_debug_is_forced_off_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true]);

        try {
            (new AppServiceProvider($this->app))->boot();
        } finally {
            Model::preventLazyLoading(true);   // boot() resets this global switch for production: put the tests' setting back
        }

        $this->assertFalse(config('app.debug'));
    }

    public function test_errors_show_no_details_when_debug_is_off(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('api/v1/store/boom', fn () => throw new \RuntimeException('secret database password leaked'));

        $res = $this->getJson(self::STORE.'/boom')->assertStatus(500);

        $this->assertSame('Server Error', $res->json('message'));
        $this->assertArrayNotHasKey('exception', $res->json());
        $this->assertArrayNotHasKey('trace', $res->json());
        $this->assertStringNotContainsString('secret database password', $res->getContent());
    }

    public function test_the_config_is_safe_by_default(): void
    {
        // a missing APP_DEBUG means "off", cookies are HTTPS-only in production, sessions are not readable by scripts
        $app = file_get_contents(config_path('app.php'));
        $this->assertStringContainsString("'debug' => (bool) env('APP_DEBUG', false)", $app);
        $session = file_get_contents(config_path('session.php'));
        $this->assertStringContainsString("env('APP_ENV') === 'production'", $session);
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }
}
