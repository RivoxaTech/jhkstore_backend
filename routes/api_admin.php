<?php

use App\Http\Controllers\Admin\AccessControlController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeliveryZoneController;
use App\Http\Controllers\Admin\DiscountController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\PushController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SubCategoryController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\Admin\WholesaleController;
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
        Route::patch('products/{product}/sold-out', [ProductController::class, 'soldOut']);
        Route::patch('products/{product}/featured', [ProductController::class, 'featured']);
        Route::post('products/{product}/videos', [ProductController::class, 'addVideo']);
        Route::put('products/{product}/videos/order', [ProductController::class, 'reorderVideos']);
        Route::post('products/{product}/videos/{video}/poster', [ProductController::class, 'videoPoster'])->whereNumber('video');
        Route::delete('products/{product}/videos/{video}', [ProductController::class, 'deleteVideo'])->whereNumber('video');
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
        Route::get('orders/pending-count', [OrderController::class, 'pendingCount']);   // the sidebar badge, polled every 30 seconds
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
        Route::get('orders/{order}/payment/proof', [OrderController::class, 'proof'])->whereNumber('order');   // the private screenshot
    });
    // the orders CSV is its own permission (never the developer's)
    Route::get('orders/export', [OrderController::class, 'export'])->middleware('admin.can:orders.export');
    Route::middleware('admin.can:orders.manage')->group(function () {
        Route::post('orders/{order}/status', [OrderController::class, 'status'])->whereNumber('order');
        Route::patch('orders/{order}/note', [OrderController::class, 'note'])->whereNumber('order');
        // bank transfer: verify the customer's proof or send it back; the delivery fee of an order that was placed without one
        Route::post('orders/{order}/payment/verify', [OrderController::class, 'verifyPayment'])->whereNumber('order');
        Route::post('orders/{order}/payment/reject-proof', [OrderController::class, 'rejectProof'])->whereNumber('order');
        Route::put('orders/{order}/delivery-fee', [OrderController::class, 'deliveryFee'])->whereNumber('order');
    });

    // Discounts (automatic) and coupons (code). The usage list of a coupon only shows order numbers to an admin who may see orders.
    Route::get('promotion-targets', [DiscountController::class, 'targets'])->middleware('admin.can:discounts.view,coupons.view');
    Route::middleware('admin.can:discounts.view')->group(function () {
        Route::get('discounts', [DiscountController::class, 'index']);
        Route::get('discounts/{discount}', [DiscountController::class, 'show'])->whereNumber('discount');
    });
    Route::middleware('admin.can:discounts.manage')->group(function () {
        Route::post('discounts', [DiscountController::class, 'store']);
        Route::match(['put', 'patch'], 'discounts/{discount}', [DiscountController::class, 'update'])->whereNumber('discount');
        Route::patch('discounts/{discount}/active', [DiscountController::class, 'active'])->whereNumber('discount');
        Route::delete('discounts/{discount}', [DiscountController::class, 'destroy'])->whereNumber('discount');
    });
    Route::middleware('admin.can:coupons.view')->group(function () {
        Route::get('coupons', [CouponController::class, 'index']);
        Route::get('coupons/{coupon}', [CouponController::class, 'show'])->whereNumber('coupon');
        Route::get('coupons/{coupon}/usages', [CouponController::class, 'usages'])->whereNumber('coupon');
    });
    Route::middleware('admin.can:coupons.manage')->group(function () {
        Route::post('coupons', [CouponController::class, 'store']);
        Route::match(['put', 'patch'], 'coupons/{coupon}', [CouponController::class, 'update'])->whereNumber('coupon');
        Route::patch('coupons/{coupon}/active', [CouponController::class, 'active'])->whereNumber('coupon');
        Route::delete('coupons/{coupon}', [CouponController::class, 'destroy'])->whereNumber('coupon');
    });

    // Delivery zones live in the Settings > Delivery tab (like the banners in their tab); the order of the zones is the order they are matched in
    Route::middleware('admin.can:settings.delivery')->group(function () {
        Route::get('delivery-zones', [DeliveryZoneController::class, 'index']);
        Route::put('delivery-zones/reorder', [DeliveryZoneController::class, 'reorder']);
        Route::get('delivery-zones/{zone}', [DeliveryZoneController::class, 'show'])->whereNumber('zone');
        Route::post('delivery-zones', [DeliveryZoneController::class, 'store']);
        Route::match(['put', 'patch'], 'delivery-zones/{zone}', [DeliveryZoneController::class, 'update'])->whereNumber('zone');
        Route::patch('delivery-zones/{zone}/active', [DeliveryZoneController::class, 'active'])->whereNumber('zone');
        Route::delete('delivery-zones/{zone}', [DeliveryZoneController::class, 'destroy'])->whereNumber('zone');
    });

    // Customers (read-only list and CSV export; contains personal data) and their abandoned carts
    Route::get('customers', [CustomerController::class, 'index'])->middleware('admin.can:customers.view');
    Route::get('customers/export', [CustomerController::class, 'export'])->middleware('admin.can:customers.export');
    Route::get('customers/abandoned-carts', [CustomerController::class, 'abandonedCarts'])->middleware('admin.can:customers.view');
    Route::get('customers/abandoned-carts/export', [CustomerController::class, 'exportAbandonedCarts'])->middleware('admin.can:customers.export');

    // Wholesale requests (personal data: off for the developer unless switched on)
    Route::middleware('admin.can:wholesale.view')->group(function () {
        Route::get('wholesale', [WholesaleController::class, 'index']);
        Route::get('wholesale/new-count', [WholesaleController::class, 'newCount']);   // the sidebar badge
        Route::get('wholesale/{wholesale}', [WholesaleController::class, 'show'])->whereNumber('wholesale');
    });
    Route::middleware('admin.can:wholesale.manage')->group(function () {
        Route::get('wholesale/export', [WholesaleController::class, 'export']);
        Route::match(['put', 'patch'], 'wholesale/{wholesale}', [WholesaleController::class, 'update'])->whereNumber('wholesale');
    });

    // The Notifications tab: queue and mail status, a test e-mail
    Route::middleware('admin.can:settings.notifications')->group(function () {
        Route::get('notifications/status', [NotificationController::class, 'status']);
        Route::post('notifications/test-email', [NotificationController::class, 'testEmail'])->middleware('throttle:test-email');
    });

    // Admin push (this browser): for admins who may get order or wholesale notifications; what is sent to whom is decided at send time
    Route::middleware('admin.can:orders.view,wholesale.view')->prefix('push')->group(function () {
        Route::get('status', [PushController::class, 'status']);
        Route::post('subscribe', [PushController::class, 'subscribe']);
        Route::post('unsubscribe', [PushController::class, 'unsubscribe']);
    });

    // Settings: each tab has its own permission (settings.<tab>); the controller checks it per key, here any tab opens the page
    Route::get('settings', [SettingController::class, 'show'])->middleware('admin.can:settings.general,settings.branding,settings.banners,settings.footer,settings.currency_tax,settings.orders_delivery,settings.seo,settings.theme,settings.store,settings.delivery,settings.payments,settings.notifications');
    Route::put('settings', [SettingController::class, 'update'])->middleware('admin.can:settings.general,settings.branding,settings.footer,settings.currency_tax,settings.orders_delivery,settings.seo,settings.theme,settings.store,settings.delivery,settings.payments,settings.notifications');
    // pictures: logo, favicon (Branding tab) and share-image (SEO tab); the controller checks the tab of the picture
    Route::post('settings/{slot}', [SettingController::class, 'uploadFile'])->whereIn('slot', ['logo', 'favicon', 'share-image'])->middleware('admin.can:settings.branding,settings.seo');
    Route::delete('settings/{slot}', [SettingController::class, 'deleteFile'])->whereIn('slot', ['logo', 'favicon', 'share-image'])->middleware('admin.can:settings.branding,settings.seo');

    // Access Control (developer only): what the admin may see
    Route::middleware('admin.can:access.manage')->group(function () {
        Route::get('access-control', [AccessControlController::class, 'show']);
        Route::put('access-control/{role}', [AccessControlController::class, 'update']);
    });
});
