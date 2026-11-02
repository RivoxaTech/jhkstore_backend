<?php

use App\Domain\Auth\Models\Admin;

/*
| Only the two admin accounts ever log in. Customers have no account: they are a contact record (App\Domain\Customer\Models\Customer).
| The admin panel uses the `admin` session guard; scripts use Sanctum bearer tokens (see config/sanctum.php).
*/
return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'admin'),
        'passwords' => 'admins',
    ],

    'guards' => [
        // Also the guard name used by spatie/laravel-permission. API scripts use Sanctum tokens.
        'admin' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],
    ],

    'providers' => [
        'admins' => [
            'driver' => 'eloquent',
            'model' => Admin::class,
        ],
    ],

    // There is no password-reset flow (nobody but the two admins can log in; the developer resets a password with the seeder or tinker).
    'passwords' => [],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
