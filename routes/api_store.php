<?php

use App\Http\Controllers\Store\AuthController;
use App\Http\Controllers\Store\BrandController;
use App\Http\Controllers\Store\CategoryController;
use App\Http\Controllers\Store\CompanyRegistrationController;
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
Route::middleware(['auth:sanctum', 'actor:customer'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
});

// Public site settings (delivery charge, free-delivery limit, delivery days)
Route::get('settings/delivery', [SettingController::class, 'delivery']);

// Brands (customer side)
Route::get('brands', [BrandController::class, 'index']);
Route::get('brands/{slug}', [BrandController::class, 'show']);

// Categories (customer side): active tree with sub-categories, cached
Route::get('categories', [CategoryController::class, 'index']);
Route::get('categories/{slug}', [CategoryController::class, 'show']);

// Products. `channel` middleware: ?channel=standard (default) | instadent (guests get 401)
Route::middleware('channel')->group(function () {
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{slug}', [ProductController::class, 'show']);
});
Route::get('tags', [TagController::class, 'index']);
Route::get('products/{slug}/reviews', [ReviewController::class, 'index']);
Route::post('products/{slug}/reviews', [ReviewController::class, 'store'])
    ->middleware(['auth:sanctum', 'actor:customer', 'throttle:reviews']);

// "Sell on Dentalkart" seller registration
// Dropdown data: one call for all three lists ...
Route::get('company-registration/options', [CompanyRegistrationController::class, 'options']);
// ... or one call per dropdown
Route::get('lookups/registration-types', [CompanyRegistrationController::class, 'registrationTypes']);
Route::get('lookups/vendor-types', [CompanyRegistrationController::class, 'vendorTypes']);
Route::get('lookups/countries', [CompanyRegistrationController::class, 'countries']);
Route::post('company-registrations', [CompanyRegistrationController::class, 'store'])->middleware('throttle:forms');
