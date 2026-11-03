<?php

/*
| Admin push notifications (Web Push, VAPID). Generate a key pair with `php artisan shop:vapid` and put the three values in .env.
| Without keys the feature is off (the panel's bell button says so) and the sidebar badges (polled every 30 seconds) still work.
| VAPID_SUBJECT is a contact for the push service: mailto:you@example.com or the https address of the store.
*/
return [
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', ''),
        'public_key' => env('VAPID_PUBLIC_KEY', ''),
        'private_key' => env('VAPID_PRIVATE_KEY', ''),
    ],
];
