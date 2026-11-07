<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The store's time zone (`APP_TIMEZONE`). Dates are stored as the shop's wall-clock time, `now()` is the shop's time, and the API sends
 * every date with its offset (see AppServiceProvider), so "23:59 shop time" is the same instant whatever the zone is and whatever the
 * browser of the admin says. A date typed in the panel without an offset is shop time; one with an offset is converted to shop time.
 */
class ShopTime
{
    public static function zone(): string
    {
        return (string) config('app.timezone');
    }

    /** Parses a date typed in the panel (shop time unless it carries an offset). Null for empty or invalid input. */
    public static function parse(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse(trim($value), self::zone())->setTimezone(self::zone());
        } catch (\Throwable) {
            return null;
        }
    }

    /** The value to store: `Y-m-d H:i:s` in shop time. Unparseable input is returned as it is (validation reports it). */
    public static function normalise(mixed $value): mixed
    {
        return self::parse($value)?->format('Y-m-d H:i:s') ?? $value;
    }
}
