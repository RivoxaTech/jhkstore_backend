<?php

/*
| Limits of the public checkout (guest orders: nobody logs in, so these are the protection against abuse). All can be set in .env.
| The per-minute / per-hour request limits are defined in App\Providers\AppServiceProvider.
*/
return [

    // Most different products (lines) in one order or quote.
    'max_items_per_order' => (int) env('SHOP_MAX_ITEMS_PER_ORDER', 30),

    // Most units of one line. A product's own max_order_qty is checked as well; this is the ceiling for products that allow more.
    'max_qty_per_line' => (int) env('SHOP_MAX_QTY_PER_LINE', 500),

    // Name of the hidden form field that real visitors leave empty. A request that fills it is refused (bots fill every field).
    // No queue worker seen: send the mails and pushes of an order right after the response instead of leaving them waiting in the queue.
    'jobs_without_worker' => (bool) env('SHOP_JOBS_WITHOUT_WORKER', true),

    // Always send the mails and pushes of an order right after the response, even when a worker exists. For a host where the worker is started by a
    // cron job once a minute: a job would wait up to 60 seconds for it, the owner would hear of an order a minute late. Costs no standing process.
    'jobs_after_response' => (bool) env('SHOP_JOBS_AFTER_RESPONSE', false),

    'honeypot_field' => env('SHOP_HONEYPOT_FIELD', 'hp_extra'),

    // The two admin accounts the seeder creates (docs/ACCESS_CONTROL.md). They are read through config(), never env(): once `php artisan config:cache` has run,
    // env() outside the config folder returns only its default, and the seeder would see 'developer' / 'developer' and refuse (or create weak accounts).
    'seed' => [
        'developer' => [
            'username' => env('SEED_DEVELOPER_USERNAME', 'developer'),
            'password' => env('SEED_DEVELOPER_PASSWORD', 'developer'),
            'name' => env('SEED_DEVELOPER_NAME', 'Developer'),
            'email' => env('SEED_DEVELOPER_EMAIL'),
        ],
        'admin' => [
            'username' => env('SEED_ADMIN_USERNAME', 'admin'),
            'password' => env('SEED_ADMIN_PASSWORD', 'admin'),
            'name' => env('SEED_ADMIN_NAME', 'Administrator'),
            'email' => env('SEED_ADMIN_EMAIL'),
        ],
    ],

    // Request limits of the public endpoints (the limiters are defined in App\Providers\AppServiceProvider). Per IP unless stated.
    'rate' => [
        'public_per_minute' => (int) env('PUBLIC_PER_MINUTE', 240),               // reads of the catalogue
        'admin_per_minute' => (int) env('ADMIN_PER_MINUTE', 600),                 // per admin
        'quote_per_minute' => (int) env('QUOTE_PER_MINUTE', 60),
        'checkout_per_minute' => (int) env('CHECKOUT_PER_MINUTE', 10),
        'checkout_per_hour' => (int) env('CHECKOUT_PER_HOUR', 60),
        'checkout_per_phone_hour' => (int) env('CHECKOUT_PER_PHONE_HOUR', 10),   // per phone number, whatever the IP
        'lookup_per_minute' => (int) env('ORDER_LOOKUP_PER_MINUTE', 20),
        'lookup_per_order_hour' => (int) env('ORDER_LOOKUP_PER_ORDER_HOUR', 15),  // per order number, whatever the IP
        'cart_per_minute' => (int) env('CART_PER_MINUTE', 60),                    // every cart call (reads and changes) per IP
        'coupon_per_minute' => (int) env('COUPON_PER_MINUTE', 30),                // coupon checks per IP (a code cannot be guessed quickly)
        'proof_per_minute' => (int) env('PAYMENT_PROOF_PER_MINUTE', 10),          // payment proof uploads per IP
        'proof_per_order_hour' => (int) env('PAYMENT_PROOF_PER_ORDER_HOUR', 10),  // per order number, whatever the IP
        'wholesale_per_hour' => (int) env('WHOLESALE_PER_HOUR', 5),               // wholesale requests per IP
        'wholesale_per_phone_hour' => (int) env('WHOLESALE_PER_PHONE_HOUR', 5),   // per phone number, whatever the IP
    ],

    // Product videos (uploads): mp4 / webm. The PHP settings upload_max_filesize and post_max_size on the server must be at least max_kb.
    'video' => [
        'max_kb' => (int) env('PRODUCT_VIDEO_MAX_KB', 51200),         // 50 MB each
        'poster_max_kb' => (int) env('PRODUCT_VIDEO_POSTER_MAX_KB', 2048),
        'max_files' => 2,
    ],

    // Cache headers of the PUBLIC catalogue reads (settings, banners, categories, products, tags, sitemap) for Cloudflare and browsers. Everything else is no-store.
    // On a developer machine (APP_ENV=local) nothing is kept: a product, a price or a category changed in the admin panel is there on the next click.
    'cache' => [
        'max_age' => (int) env('STORE_CACHE_MAX_AGE', env('APP_ENV') === 'local' ? 0 : 60),                  // browsers
        's_maxage' => (int) env('STORE_CACHE_S_MAXAGE', env('APP_ENV') === 'local' ? 0 : 300),               // shared caches (Cloudflare)
        'stale_while_revalidate' => (int) env('STORE_CACHE_SWR', env('APP_ENV') === 'local' ? 0 : 60),
    ],

    // Bank transfer screenshot: kept on the PRIVATE disk (never a public URL), shown to the admin through an authenticated route only.
    'payment_proof' => [
        'max_kb' => (int) env('PAYMENT_PROOF_MAX_KB', 4096),
        'min_px' => 100,
        'max_px' => 6000,
    ],
];
