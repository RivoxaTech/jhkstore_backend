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

        // Public forms (seller registration, enquiries): 10 per hour per IP (more on a developer machine so testing is not blocked).
        // Override with FORMS_PER_HOUR in .env.
        RateLimiter::for('forms', fn (Request $request) => Limit::perHour(
            (int) env('FORMS_PER_HOUR', $this->app->isLocal() ? 500 : 10)
        )->by($request->ip()));
    }
}
