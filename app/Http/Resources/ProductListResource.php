<?php

namespace App\Http\Resources;

use App\Domain\Catalog\ProductImages;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact product row for the admin list. Needs: brand, category, subCategory, tags, media, remoteImages,
 * channels and variants.stocks.channel eager loaded (the controller does it), so a page costs a fixed number of queries.
 */
class ProductListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ref = fn ($relation) => $this->{$relation} ? ['id' => $this->{$relation}->id, 'name' => $this->{$relation}->name] : null;
        $cheapest = $this->variants->where('is_active', true)->sortBy('selling_price')->first();

        // quantity per channel (summed over variants); null = the product is not offered on that channel
        $stock = [];
        foreach ($this->channels as $channel) {
            $stock[$channel->code] = $this->variants->sum(fn ($v) => $v->stocks->where('channel.code', $channel->code)->sum('stock_qty'));
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'status' => $this->status->value,
            'thumb_url' => ProductImages::primary($this->resource)['thumb_url'],
            'brand' => $ref('brand'),
            'category' => $ref('category'),
            'sub_category' => $ref('subCategory'),
            'min_price' => $this->min_price === null ? null : (float) $this->min_price,
            'mrp' => $cheapest ? (float) $cheapest->mrp : null,
            'discount_percent' => (float) $this->max_discount_percent,
            'variants_count' => $this->variants->count(),
            'is_in_stock' => $this->is_in_stock,
            'stock' => $stock,
            'channels' => $this->channels->map(fn ($c) => ['code' => $c->code, 'is_active' => (bool) $c->pivot->is_active])->values(),
            'tags' => $this->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'color' => $t->color])->values(),
            'rating_avg' => $this->rating_avg,
            'rating_count' => $this->rating_count,
            'updated_at' => $this->updated_at,
        ];
    }
}
