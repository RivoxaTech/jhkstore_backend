<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\SettingController;
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

    // Settings
    Route::get('settings/delivery', [SettingController::class, 'showDelivery']);
    Route::put('settings/delivery', [SettingController::class, 'updateDelivery']);
});
