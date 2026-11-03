<?php

namespace App\Http\Resources;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\SubCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A discount or a coupon for the admin screens. The list gives `targets_count` (no extra queries); the detail (`->withTargetNames()`, `targets` loaded)
 * gives the chosen items with their names, for the searchable multi-select of the form.
 * (Not a constructor argument: `::collection()` passes the item's key as the second constructor argument.)
 */
class DiscountResource extends JsonResource
{
    private bool $withNames = false;

    public function withTargetNames(): static
    {
        $this->withNames = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $m = $this->resource;

        return [
            'id' => $m->id,
            'name' => $m->name,
            'type' => $m->type,
            'value' => $m->value === null ? null : (float) $m->value,
            'scope' => $m->scope,
            'starts_at' => $m->starts_at,
            'ends_at' => $m->ends_at,
            'is_active' => $m->is_active,
            'state' => $m->state(),
            'priority' => $m->priority,
            'targets_count' => $m->targets_count ?? ($m->relationLoaded('targets') ? $m->targets->count() : null),
        ] + $this->extra($m) + ($this->withNames ? ['target_ids' => $m->targets->pluck('target_id')->values(), 'targets' => $this->targets($m)] : []);
    }

    /** Fields only the coupon has (the coupon resource overrides this). */
    protected function extra($m): array
    {
        return [];
    }

    /** @return list<array{id:int, name:string}> */
    protected function targets($m): array
    {
        $ids = $m->targets->pluck('target_id')->all();
        if ($ids === []) {
            return [];
        }
        $names = match ($m->scope) {
            'products' => Product::query()->withTrashed()->whereIn('id', $ids)->pluck('name', 'id'),
            'categories' => Category::query()->withTrashed()->whereIn('id', $ids)->pluck('name', 'id'),
            default => SubCategory::query()->withTrashed()->whereIn('id', $ids)->pluck('name', 'id'),
        };

        return collect($ids)->map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? '#'.$id])->values()->all();
    }
}
