<?php

return [

    'paths' => ['api/*', 'storage/*'],

    'allowed_methods' => ['*'],

    // Comma separated list in .env, e.g. FRONTEND_URLS=http://localhost:5173
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('FRONTEND_URLS', 'http://localhost:5173'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // The admin panel logs in with a session cookie. Origins above are explicit (no "*"), as credentials require.
    // Customer site calls use bearer tokens and are not affected.
    'supports_credentials' => true,

];
