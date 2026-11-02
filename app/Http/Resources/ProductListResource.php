<?php

namespace App\Http\Resources;

use App\Domain\Catalog\ProductImages;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact product row for the admin list. Needs: category, subCategory, tags, media, remoteImages,
 * and the variants eager loaded (the controller does it), so a page costs a fixed number of queries.
 */
class ProductListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ref = fn ($relation) => $this->{$relation} ? ['id' => $this->{$relation}->id, 'name' => $this->{$relation}->name] : null;
        $cheapest = $this->variants->where('is_active', true)->sortBy('selling_price')->first();

        $stock = (int) $this->variants->sum(fn ($v) => $v->stock_qty);   // units in stock over all options

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'status' => $this->status->value,
            'thumb_url' => ProductImages::primary($this->resource)['thumb_url'],
            'category' => $ref('category'),
            'sub_category' => $ref('subCategory'),
            'min_price' => $this->min_price === null ? null : (float) $this->min_price,
            'mrp' => $cheapest ? (float) $cheapest->mrp : null,
            'discount_percent' => (float) $this->max_discount_percent,
            'variants_count' => $this->variants->count(),
            'is_in_stock' => $this->is_in_stock,
            'stock' => $stock,
            'tags' => $this->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'color' => $t->color])->values(),
            'updated_at' => $this->updated_at,
        ];
    }
}
