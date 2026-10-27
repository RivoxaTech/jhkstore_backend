<?php

use App\Http\Controllers\Store\AuthController;
use App\Http\Controllers\Store\BrandController;
use App\Http\Controllers\Store\CategoryController;
use App\Http\Controllers\Store\CompanyRegistrationController;
use App\Http\Controllers\Store\SettingController;
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

// "Sell on Dentalkart" seller registration
// Dropdown data: one call for all three lists ...
Route::get('company-registration/options', [CompanyRegistrationController::class, 'options']);
// ... or one call per dropdown
Route::get('lookups/registration-types', [CompanyRegistrationController::class, 'registrationTypes']);
Route::get('lookups/vendor-types', [CompanyRegistrationController::class, 'vendorTypes']);
Route::get('lookups/countries', [CompanyRegistrationController::class, 'countries']);
Route::post('company-registrations', [CompanyRegistrationController::class, 'store'])->middleware('throttle:forms');
