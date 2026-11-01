<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SubCategoryController;
use App\Http\Controllers\Admin\TagController;
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

    // Categories and sub-categories
    Route::get('categories/options', [CategoryController::class, 'options']);
    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('categories', [CategoryController::class, 'store']);
    Route::get('categories/{category}', [CategoryController::class, 'show']);
    Route::match(['put', 'patch', 'post'], 'categories/{category}', [CategoryController::class, 'update']);
    Route::delete('categories/{category}', [CategoryController::class, 'destroy']);
    Route::post('categories/{category}/sub-categories', [SubCategoryController::class, 'store']);
    Route::get('sub-categories/{subCategory}', [SubCategoryController::class, 'show']);
    Route::match(['put', 'patch', 'post'], 'sub-categories/{subCategory}', [SubCategoryController::class, 'update']);
    Route::delete('sub-categories/{subCategory}', [SubCategoryController::class, 'destroy']);

    // Products (variants with stock, tags, content tabs in the JSON body; pictures separately)
    Route::get('products/options', [ProductController::class, 'options']);
    Route::get('products', [ProductController::class, 'index']);
    Route::post('products', [ProductController::class, 'store']);
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::match(['put', 'patch'], 'products/{product}', [ProductController::class, 'update']);
    Route::delete('products/{product}', [ProductController::class, 'destroy']);
    Route::patch('products/{product}/status', [ProductController::class, 'status']);
    Route::put('products/{product}/tags', [ProductController::class, 'tags']);
    Route::post('products/{product}/images', [ProductController::class, 'addImages']);
    Route::put('products/{product}/images/order', [ProductController::class, 'reorderImages']);
    Route::put('products/{product}/images/{image}/primary', [ProductController::class, 'primaryImage']);
    Route::delete('products/{product}/images/{image}', [ProductController::class, 'deleteImage']);

    // Banners (multipart for the pictures; reorder = the whole order of a placement)
    Route::get('banners/options', [BannerController::class, 'options']);
    Route::put('banners/reorder', [BannerController::class, 'reorder']);
    Route::get('banners', [BannerController::class, 'index']);
    Route::post('banners', [BannerController::class, 'store']);
    Route::get('banners/{banner}', [BannerController::class, 'show'])->whereNumber('banner');
    Route::match(['put', 'patch', 'post'], 'banners/{banner}', [BannerController::class, 'update'])->whereNumber('banner');
    Route::delete('banners/{banner}', [BannerController::class, 'destroy'])->whereNumber('banner');

    // Tags (system tags cannot be deleted)
    Route::get('tags', [TagController::class, 'index']);
    Route::post('tags', [TagController::class, 'store']);
    Route::get('tags/{tag}', [TagController::class, 'show']);
    Route::match(['put', 'patch'], 'tags/{tag}', [TagController::class, 'update']);
    Route::delete('tags/{tag}', [TagController::class, 'destroy']);

    // Orders
    Route::get('orders/options', [OrderController::class, 'options']);
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
    Route::post('orders/{order}/status', [OrderController::class, 'status'])->whereNumber('order');
    Route::patch('orders/{order}/note', [OrderController::class, 'note'])->whereNumber('order');

    // Settings
    Route::get('settings', [SettingController::class, 'show']);
    Route::put('settings', [SettingController::class, 'update']);
    // pictures: logo, favicon, share-image (stored with medialibrary)
    Route::post('settings/{slot}', [SettingController::class, 'uploadFile'])->whereIn('slot', ['logo', 'favicon', 'share-image']);
    Route::delete('settings/{slot}', [SettingController::class, 'deleteFile'])->whereIn('slot', ['logo', 'favicon', 'share-image']);
});
