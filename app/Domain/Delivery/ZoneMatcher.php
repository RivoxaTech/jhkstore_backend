<?php

namespace App\Domain\Delivery;

use App\Domain\Delivery\Models\DeliveryZone;

/** Finds the delivery zone of an address: the first active zone, by `sort_order`, that matches. One query per call. */
class ZoneMatcher
{
    /** @param  array{city?:?string, area?:?string, postcode?:?string}  $address */
    public function match(array $address): ?DeliveryZone
    {
        if (! self::hasAddress($address)) {
            return null;
        }

        return DeliveryZone::query()->where('is_active', true)->ordered()->get()->first(fn (DeliveryZone $zone) => $zone->matches($address));
    }

    /** Is there anything to match on (a city, an area or a postcode)? */
    public static function hasAddress(array $address): bool
    {
        foreach (DeliveryZone::MATCH_TYPES as $part) {
            if (trim((string) ($address[$part] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
