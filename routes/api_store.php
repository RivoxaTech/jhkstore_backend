<?php

use App\Http\Controllers\Store\AddressController;
use App\Http\Controllers\Store\AuthController;
use App\Http\Controllers\Store\BrandController;
use App\Http\Controllers\Store\CartController;
use App\Http\Controllers\Store\CategoryController;
use App\Http\Controllers\Store\OrderController;
use App\Http\Controllers\Store\ProductController;
use App\Http\Controllers\Store\ReviewController;
use App\Http\Controllers\Store\SettingController;
use App\Http\Controllers\Store\TagController;
use Illuminate\Support\Facades\Route;

/*
| Customer API  –  prefix /api/v1/store
*/

// Auth
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:forms');
Route::middleware(['auth:sanctum', 'actor:customer'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
});

// Public store settings (profile, currency, tax, delivery, enabled features)
Route::get('settings', [SettingController::class, 'show']);

// Brands (customer side)
Route::get('brands', [BrandController::class, 'index']);
Route::get('brands/{slug}', [BrandController::class, 'show']);

// Categories (customer side): active tree with sub-categories, cached
Route::get('categories', [CategoryController::class, 'index']);
Route::get('categories/{slug}', [CategoryController::class, 'show']);

// Products
Route::get('products', [ProductController::class, 'index']);
Route::get('products/{slug}', [ProductController::class, 'show']);
Route::get('tags', [TagController::class, 'index']);
Route::get('products/{slug}/reviews', [ReviewController::class, 'index']);
Route::post('products/{slug}/reviews', [ReviewController::class, 'store'])
    ->middleware(['auth:sanctum', 'actor:customer', 'throttle:reviews']);

// Cart (stored in the database). Guest: X-Cart-Token header; customer: Bearer token.
Route::middleware(['throttle:cart'])->prefix('cart')->group(function () {
    Route::get('/', [CartController::class, 'show']);
    Route::delete('/', [CartController::class, 'clear']);
    Route::post('items', [CartController::class, 'add']);
    Route::put('items/{variantId}', [CartController::class, 'update'])->whereNumber('variantId');
    Route::delete('items/{variantId}', [CartController::class, 'remove'])->whereNumber('variantId');
    Route::put('coins', [CartController::class, 'coins'])->middleware(['auth:sanctum', 'actor:customer']);
});

// Address book, checkout and orders: customer token required. Everything is scoped to the logged-in customer.
Route::middleware(['auth:sanctum', 'actor:customer'])->group(function () {
    Route::middleware('throttle:account')->group(function () {
        Route::get('addresses', [AddressController::class, 'index']);
        Route::post('addresses', [AddressController::class, 'store']);
        Route::get('addresses/{id}', [AddressController::class, 'show'])->whereNumber('id');
        Route::match(['put', 'patch'], 'addresses/{id}', [AddressController::class, 'update'])->whereNumber('id');
        Route::post('addresses/{id}/default', [AddressController::class, 'makeDefault'])->whereNumber('id');
        Route::delete('addresses/{id}', [AddressController::class, 'destroy'])->whereNumber('id');

        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{orderNumber}', [OrderController::class, 'show']);
    });

    Route::middleware('throttle:orders')->group(function () {
        Route::post('orders', [OrderController::class, 'store']);
        Route::post('orders/{orderNumber}/cancel', [OrderController::class, 'cancel']);
        Route::post('orders/{orderNumber}/reorder', [OrderController::class, 'reorder']);
    });
});
