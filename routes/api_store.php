<?php

use App\Http\Controllers\Store\BannerController;
use App\Http\Controllers\Store\CategoryController;
use App\Http\Controllers\Store\CheckoutController;
use App\Http\Controllers\Store\OrderController;
use App\Http\Controllers\Store\ProductController;
use App\Http\Controllers\Store\SettingController;
use App\Http\Controllers\Store\TagController;
use Illuminate\Support\Facades\Route;

/*
| Storefront API  –  prefix /api/v1/store. Everything is public: customers have no account and never log in.
| The storefront keeps the cart (variant ids and quantities) and calls the quote and order endpoints below.
| Every route has a per-IP rate limit; the two order endpoints have stricter, dedicated ones (see AppServiceProvider and config/shop.php).
*/

// Public reads, rate limited per IP (PUBLIC_PER_MINUTE, default 240 a minute)
Route::middleware('throttle:public')->group(function () {
    // Public store settings (profile, branding, footer, SEO, currency, tax, delivery)
    Route::get('settings', [SettingController::class, 'show']);

    // Banners: the live ones of a placement, in order
    Route::get('banners', [BannerController::class, 'index']);

    // Categories: active tree with sub-categories, cached
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{slug}', [CategoryController::class, 'show']);

    // Products
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{slug}', [ProductController::class, 'show']);
    Route::get('tags', [TagController::class, 'index']);
});

// Checkout without an account. `honeypot` refuses requests that fill the hidden field (see RejectHoneypot).
Route::post('checkout/quote', [CheckoutController::class, 'quote'])->middleware(['throttle:quote', 'honeypot']);
Route::post('orders', [OrderController::class, 'store'])->middleware(['throttle:checkout', 'honeypot']);
Route::get('orders/{orderNumber}', [OrderController::class, 'show'])->middleware('throttle:order-lookup');
