<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * When an API token stops working. Customer tokens (the storefront) and admin tokens (scripts; the admin panel uses a session cookie)
 * expire after the minutes set in .env: CUSTOMER_TOKEN_MINUTES (default 7 days) and ADMIN_TOKEN_MINUTES (default 12 hours); 0 = never.
 * An expired token answers 401: the storefront refreshes it (POST /store/auth/refresh, before it expires) or asks the customer to log in again.
 */
class TokenLifetime
{
    public static function expiresAt(string $actor): ?CarbonInterface
    {
        $minutes = (int) config("sanctum.{$actor}_token_minutes", 0);

        return $minutes > 0 ? now()->addMinutes($minutes) : null;
    }
}
