<?php

return [

    'paths' => ['api/*', 'storage/*'],

    'allowed_methods' => ['*'],

    // Comma separated list in .env, e.g. FRONTEND_URLS=http://localhost:5173,http://localhost:3000
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('FRONTEND_URLS', 'http://localhost:5173'))))),

    // Regular expressions, comma separated in .env, e.g. FRONTEND_URL_PATTERNS=#^https://[a-z0-9-]+\.pages\.dev$# for the preview sites of Cloudflare Pages
    'allowed_origins_patterns' => array_values(array_filter(array_map('trim', explode(',', (string) env('FRONTEND_URL_PATTERNS', ''))))),

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Idempotency-Replayed', 'X-Cart-Token'],

    // How long the browser may remember the answer to a preflight (the OPTIONS request that comes before every JSON call with our headers: cart token,
    // Idempotency-Key). 0 made every cart, quote and order call cost two round trips. Chrome caps it at 7200 (2 hours), Firefox at 86400.
    'max_age' => (int) env('CORS_MAX_AGE', 7200),

    // The admin panel logs in with a session cookie. Origins above are explicit (no "*"), as credentials require.
    // The storefront calls are public (no login), so credentials are not needed for them.
    'supports_credentials' => true,

];
