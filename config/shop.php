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
    'honeypot_field' => env('SHOP_HONEYPOT_FIELD', 'website'),

    // Request limits of the public endpoints (the limiters are defined in App\Providers\AppServiceProvider). Per IP unless stated.
    'rate' => [
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
    ],

    // Bank transfer screenshot: kept on the PRIVATE disk (never a public URL), shown to the admin through an authenticated route only.
    'payment_proof' => [
        'max_kb' => (int) env('PAYMENT_PROOF_MAX_KB', 4096),
        'min_px' => 100,
        'max_px' => 6000,
    ],
];
