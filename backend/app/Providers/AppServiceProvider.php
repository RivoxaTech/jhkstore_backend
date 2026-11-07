<?php

namespace App\Providers;

use App\Domain\Customer\Phone;
use App\Domain\Notification\PushSender;
use App\Domain\Notification\QueueHealth;
use App\Domain\Notification\WebPushSender;
use App\Domain\Promotion\Promotions;
use App\Domain\Support\Settings;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One copy per request: the settings are read many times while pricing a cart.
        $this->app->singleton(Settings::class);
        $this->app->singleton(Promotions::class);   // the live discounts, loaded once per request
        $this->app->bind(PushSender::class, WebPushSender::class);   // tests swap it for a recorder
    }

    public function boot(): void
    {
        // Every date in an API answer carries its offset, in the shop's time zone (APP_TIMEZONE): 2026-11-03T23:59:00+05:00, not UTC.
        $withOffset = fn ($date) => $date->toIso8601String();
        Carbon::serializeUsing($withOffset);
        CarbonImmutable::serializeUsing($withOffset);

        // Catch N+1 queries while developing.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Debug output (stack traces, config values) must never reach customers: APP_DEBUG is ignored in production.
        if ($this->app->isProduction() && config('app.debug')) {
            config(['app.debug' => false]);
            Log::warning('APP_DEBUG is on while APP_ENV=production. It was switched off for this request: set APP_DEBUG=false in .env.');
        }

        // Public read endpoints (settings, banners, categories, products) and the quote: 240 a minute per IP (PUBLIC_PER_MINUTE).
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute((int) env('PUBLIC_PER_MINUTE', 240))->by($request->ip()));

        // Admin API: 600 a minute per admin (ADMIN_PER_MINUTE) - generous for the panel, a ceiling for a runaway script.
        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute((int) env('ADMIN_PER_MINUTE', 600))->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Login attempts: 8 per minute per login name + IP.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(8)->by(strtolower((string) $request->input('login')).'|'.$request->ip()));

        // Public checkout (no login, so these are the protection against abuse). Limits are in config/shop.php (.env).
        // Quote: priced from the database on every call, so it has its own, tighter limit than plain reads.
        RateLimiter::for('quote', fn (Request $request) => Limit::perMinute((int) config('shop.rate.quote_per_minute'))->by('quote|'.$request->ip()));

        // Placing an order: per IP per minute and per hour, and per phone number per hour (one number cannot be used to flood the admin).
        RateLimiter::for('checkout', function (Request $request) {
            $limits = [
                Limit::perMinute((int) config('shop.rate.checkout_per_minute'))->by('checkout-min|'.$request->ip()),
                Limit::perHour((int) config('shop.rate.checkout_per_hour'))->by('checkout-hour|'.$request->ip()),
            ];
            $phone = Phone::normalise((string) $request->input('customer.phone', ''));
            if ($phone !== '') {
                $limits[] = Limit::perHour((int) config('shop.rate.checkout_per_phone_hour'))->by('checkout-phone|'.$phone);
            }

            return $limits;
        });

        // Persistent cart: 60 calls a minute per IP (reads and changes alike; a read without a token creates a cart).
        RateLimiter::for('cart', fn (Request $request) => Limit::perMinute((int) config('shop.rate.cart_per_minute'))->by('cart|'.$request->ip()));

        // Coupon check: a code cannot be guessed quickly.
        RateLimiter::for('coupon', fn (Request $request) => Limit::perMinute((int) config('shop.rate.coupon_per_minute'))->by('coupon|'.$request->ip()));

        // Payment proof upload: per IP per minute, and per order number per hour.
        RateLimiter::for('payment-proof', fn (Request $request) => [
            Limit::perMinute((int) config('shop.rate.proof_per_minute'))->by('proof-min|'.$request->ip()),
            Limit::perHour((int) config('shop.rate.proof_per_order_hour'))->by('proof-order|'.strtoupper((string) $request->route('orderNumber'))),
        ]);

        // Wholesale request: per IP per hour and per phone number per hour (a form that anyone can send must not flood the admin).
        RateLimiter::for('wholesale', function (Request $request) {
            $limits = [Limit::perHour((int) config('shop.rate.wholesale_per_hour'))->by('wholesale-ip|'.$request->ip())];
            $phone = Phone::normalise((string) $request->input('phone', ''));
            if ($phone !== '') {
                $limits[] = Limit::perHour((int) config('shop.rate.wholesale_per_phone_hour'))->by('wholesale-phone|'.$phone);
            }

            return $limits;
        });

        // A worker that finishes or fails a job leaves a stamp: that is how the panel knows a queue worker is running (QueueHealth).
        Event::listen(JobProcessed::class, fn () => QueueHealth::recordActivity());
        Event::listen(JobFailed::class, fn () => QueueHealth::recordActivity());

        // The test push button: a handful a minute.
        RateLimiter::for('test-push', fn (Request $request) => Limit::perMinute(5)->by('test-push|'.$request->user()?->getAuthIdentifier()));

        // The rebuild button: a call to the hosting, so a few a minute.
        RateLimiter::for('rebuild', fn (Request $request) => Limit::perMinute(5)->by('rebuild|'.$request->user()?->getAuthIdentifier()));

        // The test e-mail button: a handful a minute.
        RateLimiter::for('test-email', fn (Request $request) => Limit::perMinute(5)->by('test-email|'.$request->user()?->getAuthIdentifier()));

        // Order lookup (order number + phone): guessing is slow. Per IP per minute, and per order number per hour.
        RateLimiter::for('order-lookup', fn (Request $request) => [
            Limit::perMinute((int) config('shop.rate.lookup_per_minute'))->by('lookup-min|'.$request->ip()),
            Limit::perHour((int) config('shop.rate.lookup_per_order_hour'))->by('lookup-order|'.strtoupper((string) $request->route('orderNumber'))),
        ]);
    }
}
