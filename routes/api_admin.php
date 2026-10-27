<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SubCategoryController;
use Illuminate\Support\Facades\Route;

/*
| Admin API  –  prefix /api/v1/admin
*/

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// `admin` = session cookie (admin panel), `sanctum` = bearer token (Postman / scripts)
Route::middleware(['auth:admin,sanctum', 'actor:admin'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    Route::get('dashboard', DashboardController::class);

    // Companies (registration requests + created companies)
    Route::get('companies/options', [CompanyController::class, 'options']);
    Route::get('companies', [CompanyController::class, 'index']);
    Route::post('companies', [CompanyController::class, 'store']);
    Route::get('companies/{company}', [CompanyController::class, 'show']);
    Route::match(['put', 'patch', 'post'], 'companies/{company}', [CompanyController::class, 'update']);
    Route::delete('companies/{company}', [CompanyController::class, 'destroy']);
    Route::post('companies/{company}/approve', [CompanyController::class, 'approve']);
    Route::post('companies/{company}/reject', [CompanyController::class, 'reject']);
    Route::post('companies/{company}/suspend', [CompanyController::class, 'suspend']);
    Route::get('companies/{company}/catalogue/{media}', [CompanyController::class, 'catalogue'])->name('companies.catalogue');

    // Brands
    Route::get('companies/{company}/brands', [BrandController::class, 'forCompany']);
    Route::post('companies/{company}/brands', [BrandController::class, 'store']);
    Route::get('brands', [BrandController::class, 'index']);
    Route::get('brands/{brand}', [BrandController::class, 'show']);
    Route::match(['put', 'patch', 'post'], 'brands/{brand}', [BrandController::class, 'update']);
    Route::delete('brands/{brand}', [BrandController::class, 'destroy']);

    // Categories and sub-categories
    Route::get('categories/options', [CategoryController::class, 'options']);
    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('categories', [CategoryController::class, 'store']);
    Route::get('categories/{category}', [CategoryController::class, 'show']);
    Route::match(['put', 'patch', 'post'], 'categories/{category}', [CategoryController::class, 'update']);
    Route::delete('categories/{category}', [CategoryController::class, 'destroy']);
    Route::post('categories/{category}/sub-categories', [SubCategoryController::class, 'store']);
    Route::post('sub-categories/{parent}/children', [SubCategoryController::class, 'storeChild']);
    Route::get('sub-categories/{subCategory}', [SubCategoryController::class, 'show']);
    Route::match(['put', 'patch', 'post'], 'sub-categories/{subCategory}', [SubCategoryController::class, 'update']);
    Route::delete('sub-categories/{subCategory}', [SubCategoryController::class, 'destroy']);

    // Settings
    Route::get('settings/delivery', [SettingController::class, 'showDelivery']);
    Route::put('settings/delivery', [SettingController::class, 'updateDelivery']);
});
