<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Admin;
use App\Domain\Auth\Models\User;
use App\Providers\AppServiceProvider;
use Database\Seeders\AdminSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = '/api/v1/store';
    private const ADMIN = '/api/v1/admin';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBasics();
    }

    private function register(string $email = 'ayesha@example.test'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::STORE.'/auth/register', ['full_name' => 'Ayesha Khan', 'email' => $email, 'mobile' => '03001234567', 'password' => 'Secret-pass1', 'password_confirmation' => 'Secret-pass1']);
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();   // one app serves every request of a test: drop the cached user

        return $this->withToken($token);
    }

    // ------------------------------------------------------------------ customer tokens expire

    public function test_a_customer_token_expires_after_the_configured_time(): void
    {
        config(['sanctum.customer_token_minutes' => 60]);
        $login = $this->register()->assertCreated();
        $token = $login->json('token');

        $this->assertEqualsWithDelta(now()->addMinutes(60)->timestamp, strtotime($login->json('expires_at')), 5);
        $this->as($token)->getJson(self::STORE.'/auth/me')->assertOk();

        $this->travel(59)->minutes();
        $this->as($token)->getJson(self::STORE.'/auth/me')->assertOk();

        $this->travel(2)->minutes();
        $this->as($token)->getJson(self::STORE.'/auth/me')->assertUnauthorized();   // the storefront asks the customer to log in again
        $this->as($token)->getJson(self::STORE.'/orders')->assertUnauthorized();
    }

    public function test_the_default_lifetime_is_seven_days_and_zero_means_never(): void
    {
        $this->assertSame(10080, config('sanctum.customer_token_minutes'));
        $this->assertSame(720, config('sanctum.admin_token_minutes'));

        User::create(['full_name' => 'A', 'email' => 'a@example.test', 'mobile' => '03111111111', 'password' => 'Secret-pass1', 'is_active' => true]);
        $login = $this->postJson(self::STORE.'/auth/login', ['login' => 'a@example.test', 'password' => 'Secret-pass1'])->assertOk();
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, strtotime($login->json('expires_at')), 5);

        config(['sanctum.customer_token_minutes' => 0]);
        $forever = $this->postJson(self::STORE.'/auth/login', ['login' => 'a@example.test', 'password' => 'Secret-pass1'])->assertOk();
        $this->assertNull($forever->json('expires_at'));
        $this->travel(5)->years();
        $this->as($forever->json('token'))->getJson(self::STORE.'/auth/me')->assertOk();
    }

    public function test_login_and_register_return_the_expiry(): void
    {
        $this->register('new@example.test')->assertCreated()->assertJsonStructure(['token', 'expires_at', 'user']);
        $this->postJson(self::STORE.'/auth/login', ['login' => 'new@example.test', 'password' => 'Secret-pass1'])
            ->assertOk()->assertJsonStructure(['token', 'expires_at', 'user', 'cart_lines_merged']);
        $this->assertNotNull(DB::table('personal_access_tokens')->whereNotNull('expires_at')->first());
    }

    public function test_a_token_is_refreshed_before_it_expires(): void
    {
        config(['sanctum.customer_token_minutes' => 60]);
        $old = $this->register()->json('token');
        $this->travel(50)->minutes();

        $res = $this->as($old)->postJson(self::STORE.'/auth/refresh')->assertOk()->assertJsonStructure(['token', 'expires_at']);
        $new = $res->json('token');
        $this->assertNotSame($old, $new);
        $this->assertEqualsWithDelta(now()->addMinutes(60)->timestamp, strtotime($res->json('expires_at')), 5);

        $this->as($old)->getJson(self::STORE.'/auth/me')->assertUnauthorized();    // the old token is gone at once
        $this->as($new)->getJson(self::STORE.'/auth/me')->assertOk();

        $this->travel(20)->minutes();                                               // 70 min after the first token: only the new one lives
        $this->as($new)->getJson(self::STORE.'/auth/me')->assertOk();
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    public function test_an_expired_token_cannot_be_refreshed_and_other_callers_cannot_refresh(): void
    {
        config(['sanctum.customer_token_minutes' => 60]);
        $token = $this->register()->json('token');
        $this->app['auth']->forgetGuards();
        $this->postJson(self::STORE.'/auth/refresh')->assertUnauthorized();          // no token

        $adminToken = $this->makeAdmin()->createToken('x')->plainTextToken;
        $this->as($adminToken)->postJson(self::STORE.'/auth/refresh')->assertUnauthorized();   // an admin token is not a customer's

        $this->travel(61)->minutes();
        $this->as($token)->postJson(self::STORE.'/auth/refresh')->assertUnauthorized();        // log in again
    }

    public function test_logging_in_again_after_expiry_works(): void
    {
        config(['sanctum.customer_token_minutes' => 30]);
        $first = $this->register()->json('token');
        $this->travel(31)->minutes();
        $this->as($first)->getJson(self::STORE.'/auth/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $again = $this->postJson(self::STORE.'/auth/login', ['login' => 'ayesha@example.test', 'password' => 'Secret-pass1'])->assertOk()->json('token');
        $this->as($again)->getJson(self::STORE.'/auth/me')->assertOk();
    }

    public function test_admin_api_tokens_expire_too(): void
    {
        config(['sanctum.admin_token_minutes' => 120]);
        Admin::create(['username' => 'script', 'name' => 'Script', 'password' => 'Secret-pass1', 'is_active' => true])->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'admin'));

        $login = $this->postJson(self::ADMIN.'/auth/login', ['login' => 'script', 'password' => 'Secret-pass1'])->assertOk();
        $this->assertEqualsWithDelta(now()->addMinutes(120)->timestamp, strtotime($login->json('expires_at')), 5);
        $this->as($login->json('token'))->getJson(self::ADMIN.'/auth/me')->assertOk();

        $this->travel(121)->minutes();
        $this->as($login->json('token'))->getJson(self::ADMIN.'/auth/me')->assertUnauthorized();
    }

    public function test_expired_tokens_are_pruned_by_the_scheduled_command(): void
    {
        config(['sanctum.customer_token_minutes' => 60]);
        $this->register();
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

    public function test_passwords_are_hashed_and_never_returned(): void
    {
        $res = $this->register()->assertCreated();
        $stored = User::query()->where('email', 'ayesha@example.test')->value('password');

        $this->assertNotSame('Secret-pass1', $stored);
        $this->assertStringStartsWith('$2y$', $stored);                 // bcrypt
        $this->assertTrue(Hash::check('Secret-pass1', $stored));
        $this->assertStringNotContainsString('password', json_encode($res->json('user')));
        $this->as($res->json('token'))->getJson(self::STORE.'/auth/me')->assertOk()->assertJsonMissingPath('user.password');

        $admin = $this->actingAsAdmin();
        $this->assertStringStartsWith('$2y$', $admin->fresh()->password);
        $this->assertTrue(Hash::check('secret-pass', $admin->fresh()->password));
        $this->assertStringNotContainsString('password', json_encode($this->getJson(self::ADMIN.'/auth/me')->json()));
    }

    public function test_the_seeder_refuses_a_weak_admin_password_in_production(): void
    {
        $this->app['env'] = 'production';
        putenv('SEED_ADMIN_PASSWORD=admin');

        try {
            $this->expectException(\RuntimeException::class);
            (new AdminSeeder)->run();
        } finally {
            putenv('SEED_ADMIN_PASSWORD');
        }
    }

    // ------------------------------------------------------------------ CORS

    public function test_cors_allows_the_configured_storefront_origins_only(): void
    {
        $preflight = fn (string $origin) => $this->call('OPTIONS', self::STORE.'/products', [], [], [], [
            'HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
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

        // never a wildcard (credentials are allowed for the admin panel session)
        $shipped = require config_path('cors.php');
        $this->assertNotContains('*', $shipped['allowed_origins']);
        $this->assertTrue($shipped['supports_credentials']);
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
        $this->assertGreaterThan(70, $checked);
    }

    public function test_login_is_limited_per_name_and_ip(): void
    {
        User::create(['full_name' => 'A', 'email' => 'a@example.test', 'mobile' => '03111111111', 'password' => 'Secret-pass1', 'is_active' => true]);

        foreach (range(1, 8) as $i) {
            $this->postJson(self::STORE.'/auth/login', ['login' => 'a@example.test', 'password' => 'wrong'])->assertStatus(422);
        }
        $this->postJson(self::STORE.'/auth/login', ['login' => 'a@example.test', 'password' => 'Secret-pass1'])->assertStatus(429);   // even the right password waits
        $this->postJson(self::ADMIN.'/auth/login', ['login' => 'nobody', 'password' => 'x'])->assertStatus(422);                         // another name is not blocked
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
