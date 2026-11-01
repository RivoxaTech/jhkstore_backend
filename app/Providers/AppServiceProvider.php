<?php

namespace App\Providers;

use App\Domain\Auth\AdminAccess;
use App\Domain\Auth\Models\Admin;
use App\Domain\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One copy per request: the settings are read many times while pricing a cart.
        $this->app->singleton(Settings::class);
    }

    public function boot(): void
    {
        // Catch N+1 queries while developing.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Debug output (stack traces, config values) must never reach customers: APP_DEBUG is ignored in production.
        if ($this->app->isProduction() && config('app.debug')) {
            config(['app.debug' => false]);
            Log::warning('APP_DEBUG is on while APP_ENV=production. It was switched off for this request: set APP_DEBUG=false in .env.');
        }

        // The super admin passes every permission check (the other roles get exactly the permissions of App\Domain\Auth\AdminAccess).
        Gate::before(fn ($user) => $user instanceof Admin && $user->hasRole(AdminAccess::SUPER_ADMIN) ? true : null);

        // Public read endpoints (settings, banners, categories, products): 240 a minute per IP (PUBLIC_PER_MINUTE).
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute((int) env('PUBLIC_PER_MINUTE', 240))->by($request->ip()));

        // Admin API: 600 a minute per admin (ADMIN_PER_MINUTE) - generous for the panel, a ceiling for a runaway script.
        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute((int) env('ADMIN_PER_MINUTE', 600))->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Login attempts: 8 per minute per login name + IP.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(8)->by(strtolower((string) $request->input('login')).'|'.$request->ip()));

        // Reviews: 30 per hour per customer (REVIEWS_PER_HOUR in .env to change).
        RateLimiter::for('reviews', fn (Request $request) => Limit::perHour((int) env('REVIEWS_PER_HOUR', 30))->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Cart: 120 requests a minute per customer (or per guest cart token, else per IP).
        RateLimiter::for('cart', fn (Request $request) => Limit::perMinute((int) env('CART_PER_MINUTE', 120))
            ->by($request->user('sanctum')?->getAuthIdentifier() ?: ($request->header('X-Cart-Token') ?: $request->ip())));

        // Checkout, cancel and reorder: 20 a minute per customer.
        RateLimiter::for('orders', fn (Request $request) => Limit::perMinute((int) env('ORDERS_PER_MINUTE', 20))->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Address book and order history: 60 a minute per customer.
        RateLimiter::for('account', fn (Request $request) => Limit::perMinute((int) env('ACCOUNT_PER_MINUTE', 60))->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Public forms (customer registration): 10 per hour per IP (more on a developer machine so testing is not blocked).
        // Override with FORMS_PER_HOUR in .env.
        RateLimiter::for('forms', fn (Request $request) => Limit::perHour(
            (int) env('FORMS_PER_HOUR', $this->app->isLocal() ? 500 : 10)
        )->by($request->ip()));
    }
}
