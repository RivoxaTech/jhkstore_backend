<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Delivery\Models\DeliveryZone;
use App\Support\Audit;

/** Creates or updates a delivery zone (new zones go to the end of the list). Every change is audited. */
class SaveDeliveryZone
{
    /** @param  array<string,mixed>  $data  validated by SaveDeliveryZoneRequest */
    public function handle(array $data, Admin $actor, ?DeliveryZone $zone = null): DeliveryZone
    {
        $data['match_values'] = array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), $data['match_values'] ?? []), fn ($v) => $v !== '')));

        $creating = $zone === null;
        $zone ??= new DeliveryZone(['sort_order' => (int) DeliveryZone::query()->max('sort_order') + 1]);
        $zone->fill($data)->save();

        Audit::log($actor, $creating ? 'delivery_zone.created' : 'delivery_zone.updated', $zone, ['name' => $zone->name, 'match_type' => $zone->match_type, 'fee' => $zone->fee]);

        return $zone->refresh();   // the defaults of the database (is_active, ...) are on the model
    }
}
