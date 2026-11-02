<?php

use App\Http\Controllers\Admin\AccessControlController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CustomerController;
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
| Every route needs a logged-in admin (`actor:admin`) AND a permission (`admin.can:<permission>`), see App\Domain\Auth\AdminAccess:
| two accounts (developer, admin), permissions per section and per Settings tab. A missing permission answers 403. The developer
| can never pass `orders.*`. Only login / logout / me are open to any logged-in admin.
*/

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// `admin` = session cookie (admin panel), `sanctum` = bearer token (Postman / scripts)
Route::middleware(['auth:admin,sanctum', 'actor:admin', 'throttle:admin'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    Route::get('dashboard', DashboardController::class)->middleware('admin.can:dashboard.view');

    // Categories and sub-categories (the product form also needs the list of categories to choose from)
    Route::get('categories/options', [CategoryController::class, 'options'])->middleware('admin.can:categories.view,products.view');
    Route::middleware('admin.can:categories.view')->group(function () {
        Route::get('categories', [CategoryController::class, 'index']);
        Route::get('categories/{category}', [CategoryController::class, 'show']);
        Route::get('sub-categories/{subCategory}', [SubCategoryController::class, 'show']);
    });
    Route::middleware('admin.can:categories.manage')->group(function () {
        Route::post('categories', [CategoryController::class, 'store']);
        Route::match(['put', 'patch', 'post'], 'categories/{category}', [CategoryController::class, 'update']);
        Route::delete('categories/{category}', [CategoryController::class, 'destroy']);
        Route::post('categories/{category}/sub-categories', [SubCategoryController::class, 'store']);
        Route::match(['put', 'patch', 'post'], 'sub-categories/{subCategory}', [SubCategoryController::class, 'update']);
        Route::delete('sub-categories/{subCategory}', [SubCategoryController::class, 'destroy']);
    });

    // Products and tags (variants with stock, tags, content tabs in the JSON body; pictures separately)
    Route::middleware('admin.can:products.view')->group(function () {
        Route::get('products/options', [ProductController::class, 'options']);
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/{product}', [ProductController::class, 'show']);
        Route::get('tags', [TagController::class, 'index']);
        Route::get('tags/{tag}', [TagController::class, 'show']);
    });
    Route::middleware('admin.can:products.manage')->group(function () {
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

    // Banners live in the Settings > Banners tab (multipart for the pictures; reorder = the whole order of a placement)
    Route::middleware('admin.can:settings.banners')->group(function () {
        Route::get('banners/options', [BannerController::class, 'options']);
        Route::get('banners', [BannerController::class, 'index']);
        Route::get('banners/{banner}', [BannerController::class, 'show'])->whereNumber('banner');
        Route::put('banners/reorder', [BannerController::class, 'reorder']);
        Route::post('banners', [BannerController::class, 'store']);
        Route::match(['put', 'patch', 'post'], 'banners/{banner}', [BannerController::class, 'update'])->whereNumber('banner');
        Route::delete('banners/{banner}', [BannerController::class, 'destroy'])->whereNumber('banner');
    });

    // Orders (never reachable by the developer)
    Route::middleware('admin.can:orders.view')->group(function () {
        Route::get('orders/options', [OrderController::class, 'options']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
    });
    Route::middleware('admin.can:orders.manage')->group(function () {
        Route::post('orders/{order}/status', [OrderController::class, 'status'])->whereNumber('order');
        Route::patch('orders/{order}/note', [OrderController::class, 'note'])->whereNumber('order');
    });

    // Customers (read-only list and CSV export; contains personal data)
    Route::get('customers', [CustomerController::class, 'index'])->middleware('admin.can:customers.view');
    Route::get('customers/export', [CustomerController::class, 'export'])->middleware('admin.can:customers.export');

    // Settings: each tab has its own permission (settings.<tab>); the controller checks it per key, here any tab opens the page
    Route::get('settings', [SettingController::class, 'show'])->middleware('admin.can:settings.general,settings.branding,settings.banners,settings.footer,settings.currency_tax,settings.orders_delivery,settings.seo');
    Route::put('settings', [SettingController::class, 'update'])->middleware('admin.can:settings.general,settings.branding,settings.footer,settings.currency_tax,settings.orders_delivery,settings.seo');
    // pictures: logo, favicon (Branding tab) and share-image (SEO tab); the controller checks the tab of the picture
    Route::post('settings/{slot}', [SettingController::class, 'uploadFile'])->whereIn('slot', ['logo', 'favicon', 'share-image'])->middleware('admin.can:settings.branding,settings.seo');
    Route::delete('settings/{slot}', [SettingController::class, 'deleteFile'])->whereIn('slot', ['logo', 'favicon', 'share-image'])->middleware('admin.can:settings.branding,settings.seo');

    // Access Control (developer only): what the admin may see
    Route::middleware('admin.can:access.manage')->group(function () {
        Route::get('access-control', [AccessControlController::class, 'show']);
        Route::put('access-control/{role}', [AccessControlController::class, 'update']);
    });
});
