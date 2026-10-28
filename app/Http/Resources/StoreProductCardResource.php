<?php

namespace App\Http\Resources;

use App\Domain\Catalog\ProductImages;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Product card for the customer site (lists, homepage sections, related products).
 * Eager load (see ProductListing::cardRelations): brand.media, brand.company.media, category, tags, media, remoteImages and
 * the active variants with their stock rows of the requested channel. `price` / `mrp` / `reward_points` are those of
 * the cheapest variant, so the three always belong together.
 *
 * A product with ONE option is shown as that option (its name, a direct price, "3 Offers" = its bulk prices, or "1 Option"
 * when it has none); a product with several options shows the parent's name, "Starting at <cheapest>" and "N Options".
 */
class StoreProductCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variants = $this->variants; // already limited to active variants by the eager load
        $cheapest = $variants->sortBy('selling_price')->first();
        $images = ProductImages::primary($this->resource);
        $single = $variants->count() === 1;
        $offers = $single ? $variants->first()->priceTiers->count() : 0;

        return [
            'id' => $this->id,
            'name' => $single ? ($cheapest->name ?: $this->name) : $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'image_url' => $images['image_url'],
            'thumb_url' => $images['thumb_url'],
            'brand' => [
                'name' => $this->brand->name,
                'slug' => $this->brand->slug,
                'logo_thumb_url' => MediaUrl::brandLogo($this->brand, 'thumb')['url'],
            ],
            'category' => ['name' => $this->category->name, 'slug' => $this->category->slug],
            'options_count' => $variants->count(),
            'has_single_option' => $single,
            'offers_count' => $offers,
            'show_starting_at' => ! $single,                                    // "Starting at Rs 265" vs a direct price
            'badge_label' => $single ? ($offers > 0 ? $offers.' '.str('Offer')->plural($offers) : '1 Option') : $variants->count().' Options',
            'price' => $cheapest ? (float) $cheapest->selling_price : null,   // the starting price: the cheapest option
            'mrp' => $cheapest ? (float) $cheapest->mrp : null,
            'discount_percent' => $cheapest ? (float) $cheapest->discount_percent : 0.0,
            'reward_points' => $cheapest?->reward_points ?? 0,
            'rating_avg' => $this->rating_avg,
            'rating_count' => $this->rating_count,
            'badge_text' => $this->badge_text,
            'tags' => $this->tags->pluck('slug')->values(),
            'in_stock' => $variants->contains(fn ($v) => $v->stocks->contains(fn ($s) => $s->available() > 0)),
        ];
    }
}
