<?php

use App\Http\Controllers\Admin\AdminUserController;
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
|
| Every route needs a logged-in admin (`actor:admin`) AND a permission (`admin.can:<permission>`), see App\Domain\Auth\AdminAccess
| for the roles (super_admin, manager, staff) and what each may do. A missing permission answers 403.
*/

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// `admin` = session cookie (admin panel), `sanctum` = bearer token (Postman / scripts)
Route::middleware(['auth:admin,sanctum', 'actor:admin', 'throttle:admin'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    Route::get('dashboard', DashboardController::class)->middleware('admin.can:dashboard.view');

    // Catalogue: categories, sub-categories, products, tags
    Route::middleware('admin.can:catalog.view')->group(function () {
        Route::get('categories/options', [CategoryController::class, 'options']);
        Route::get('categories', [CategoryController::class, 'index']);
        Route::get('categories/{category}', [CategoryController::class, 'show']);
        Route::get('sub-categories/{subCategory}', [SubCategoryController::class, 'show']);
        Route::get('products/options', [ProductController::class, 'options']);
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/{product}', [ProductController::class, 'show']);
        Route::get('tags', [TagController::class, 'index']);
        Route::get('tags/{tag}', [TagController::class, 'show']);
    });
    Route::middleware('admin.can:catalog.manage')->group(function () {
        Route::post('categories', [CategoryController::class, 'store']);
        Route::match(['put', 'patch', 'post'], 'categories/{category}', [CategoryController::class, 'update']);
        Route::delete('categories/{category}', [CategoryController::class, 'destroy']);
        Route::post('categories/{category}/sub-categories', [SubCategoryController::class, 'store']);
        Route::match(['put', 'patch', 'post'], 'sub-categories/{subCategory}', [SubCategoryController::class, 'update']);
        Route::delete('sub-categories/{subCategory}', [SubCategoryController::class, 'destroy']);

        // Products (variants with stock, tags, content tabs in the JSON body; pictures separately)
        Route::post('products', [ProductController::class, 'store']);
        Route::match(['put', 'patch'], 'products/{product}', [ProductController::class, 'update']);
        Route::delete('products/{product}', [ProductController::class, 'destroy']);
        Route::patch('products/{product}/status', [ProductController::class, 'status']);
        Route::put('products/{product}/tags', [ProductController::class, 'tags']);
        Route::post('products/{product}/images', [ProductController::class, 'addImages']);
        Route::put('products/{product}/images/order', [ProductController::class, 'reorderImages']);
        Route::put('products/{product}/images/{image}/primary', [ProductController::class, 'primaryImage']);
        Route::delete('products/{product}/images/{image}', [ProductController::class, 'deleteImage']);

        // Tags (system tags cannot be deleted)
        Route::post('tags', [TagController::class, 'store']);
        Route::match(['put', 'patch'], 'tags/{tag}', [TagController::class, 'update']);
        Route::delete('tags/{tag}', [TagController::class, 'destroy']);
    });

    // Banners (multipart for the pictures; reorder = the whole order of a placement)
    Route::middleware('admin.can:banners.view')->group(function () {
        Route::get('banners/options', [BannerController::class, 'options']);
        Route::get('banners', [BannerController::class, 'index']);
        Route::get('banners/{banner}', [BannerController::class, 'show'])->whereNumber('banner');
    });
    Route::middleware('admin.can:banners.manage')->group(function () {
        Route::put('banners/reorder', [BannerController::class, 'reorder']);
        Route::post('banners', [BannerController::class, 'store']);
        Route::match(['put', 'patch', 'post'], 'banners/{banner}', [BannerController::class, 'update'])->whereNumber('banner');
        Route::delete('banners/{banner}', [BannerController::class, 'destroy'])->whereNumber('banner');
    });

    // Orders
    Route::middleware('admin.can:orders.view')->group(function () {
        Route::get('orders/options', [OrderController::class, 'options']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
    });
    Route::middleware('admin.can:orders.manage')->group(function () {
        Route::post('orders/{order}/status', [OrderController::class, 'status'])->whereNumber('order');
        Route::patch('orders/{order}/note', [OrderController::class, 'note'])->whereNumber('order');
    });

    // Settings
    Route::get('settings', [SettingController::class, 'show'])->middleware('admin.can:settings.view');
    Route::middleware('admin.can:settings.manage')->group(function () {
        Route::put('settings', [SettingController::class, 'update']);
        // pictures: logo, favicon, share-image (stored with medialibrary)
        Route::post('settings/{slot}', [SettingController::class, 'uploadFile'])->whereIn('slot', ['logo', 'favicon', 'share-image']);
        Route::delete('settings/{slot}', [SettingController::class, 'deleteFile'])->whereIn('slot', ['logo', 'favicon', 'share-image']);
    });

    // Admin users and their roles
    Route::middleware('admin.can:admins.manage')->group(function () {
        Route::get('roles', [AdminUserController::class, 'roles']);
        Route::get('users', [AdminUserController::class, 'index']);
        Route::post('users', [AdminUserController::class, 'store']);
        Route::match(['put', 'patch'], 'users/{adminUser}', [AdminUserController::class, 'update'])->whereNumber('adminUser');
        Route::delete('users/{adminUser}', [AdminUserController::class, 'destroy'])->whereNumber('adminUser');
    });
});
