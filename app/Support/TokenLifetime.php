<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * When an admin API token stops working (the admin panel itself uses a session cookie): ADMIN_TOKEN_MINUTES in .env, default 12 hours, 0 = never.
 * An expired token answers 401 and the script logs in again.
 */
class TokenLifetime
{
    public static function expiresAt(string $actor): ?CarbonInterface
    {
        $minutes = (int) config("sanctum.{$actor}_token_minutes", 0);

        return $minutes > 0 ? now()->addMinutes($minutes) : null;
    }
}
