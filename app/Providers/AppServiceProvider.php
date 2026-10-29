<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Catch N+1 queries while developing.
        Model::preventLazyLoading(! $this->app->isProduction());

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

        // Public forms (seller registration, enquiries): 10 per hour per IP (more on a developer machine so testing is not blocked).
        // Override with FORMS_PER_HOUR in .env.
        RateLimiter::for('forms', fn (Request $request) => Limit::perHour(
            (int) env('FORMS_PER_HOUR', $this->app->isLocal() ? 500 : 10)
        )->by($request->ip()));
    }
}
