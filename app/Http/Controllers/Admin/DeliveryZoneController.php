<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Delivery\Actions\SaveDeliveryZone;
use App\Domain\Delivery\Models\DeliveryZone;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveDeliveryZoneRequest;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The delivery zone manager inside Settings > Delivery (permission `settings.delivery`). Zones are matched in this order: the first active zone
 * whose city, area or postcode matches the address wins, so the order is part of the data (PUT reorder).
 */
class DeliveryZoneController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DeliveryZone::query()->ordered()->get()->map(fn (DeliveryZone $z) => $this->row($z))->values()]);
    }

    public function show(DeliveryZone $zone): JsonResponse
    {
        return response()->json(['data' => $this->row($zone)]);
    }

    public function store(SaveDeliveryZoneRequest $request, SaveDeliveryZone $save): JsonResponse
    {
        return response()->json(['data' => $this->row($save->handle($request->validated(), $request->user()))], 201);
    }

    public function update(SaveDeliveryZoneRequest $request, DeliveryZone $zone, SaveDeliveryZone $save): JsonResponse
    {
        return response()->json(['data' => $this->row($save->handle($request->validated() + $zone->only(['name', 'match_type', 'match_values', 'fee']), $request->user(), $zone))]);
    }

    /** PATCH delivery-zones/{id}/active { is_active } */
    public function active(Request $request, DeliveryZone $zone): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $zone->update(['is_active' => $data['is_active']]);
        Audit::log($request->user(), 'delivery_zone.toggled', $zone, ['is_active' => $zone->is_active]);

        return response()->json(['data' => $this->row($zone)]);
    }

    /** PUT delivery-zones/reorder { ids: [3, 1, 2] }: the new order of ALL zones. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct', 'exists:delivery_zones,id']]);
        foreach (array_values($data['ids']) as $position => $id) {
            DeliveryZone::query()->whereKey($id)->update(['sort_order' => $position + 1]);
        }
        Audit::log($request->user(), 'delivery_zone.reordered', null, ['ids' => $data['ids']]);

        return $this->index();
    }

    public function destroy(Request $request, DeliveryZone $zone): JsonResponse
    {
        Audit::log($request->user(), 'delivery_zone.deleted', $zone, ['name' => $zone->name]);
        $zone->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    private function row(DeliveryZone $z): array
    {
        return [
            'id' => $z->id, 'name' => $z->name, 'match_type' => $z->match_type, 'match_values' => $z->match_values,
            'fee' => (float) $z->fee, 'free_above' => $z->free_above === null ? null : (float) $z->free_above,
            'min_order' => $z->min_order === null ? null : (float) $z->min_order, 'eta_text' => $z->eta_text,
            'sort_order' => $z->sort_order, 'is_active' => $z->is_active,
        ];
    }
}
