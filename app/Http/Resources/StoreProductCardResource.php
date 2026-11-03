<?php

namespace App\Http\Resources;

use App\Domain\Cart\CartPricing;
use App\Domain\Catalog\ProductImages;
use App\Domain\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Product card for the customer site (lists, homepage sections, related products).
 * Eager load (see ProductListing::cardRelations): category, tags, media, remoteImages and
 * the active variants. `price` / `mrp` are those of
 * the cheapest variant, so they always belong together.
 *
 * A product with ONE option is shown as that option (its name, a direct price, "3 Offers" = its bulk prices, or "1 Option"
 * when it has none); a product with several options shows the parent's name, "Starting at <cheapest>" and "N Options".
 *
 * `price` is what the customer pays for one unit: the option price with a live percent discount applied (the same discounts the cart uses, see
 * CartPricing::displayPrice); `compare_price` is the price to show struck through (the MRP, or the price before the discount) and `discount_label`
 * says why ("20% off"). A fixed-amount discount is taken off the order, so it only gives the label. Bulk prices are not part of it.
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
        $shown = $cheapest ? $this->display($cheapest) : ['price' => null, 'compare_price' => null, 'discount_label' => null];

        return [
            'id' => $this->id,
            'name' => $single ? ($cheapest->name ?: $this->name) : $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'image_url' => $images['image_url'],
            'thumb_url' => $images['thumb_url'],
            'category' => ['name' => $this->category->name, 'slug' => $this->category->slug],
            'options_count' => $variants->count(),
            'has_single_option' => $single,
            'offers_count' => $offers,
            'show_starting_at' => ! $single,                                    // "Starting at $ 265" vs a direct price
            'badge_label' => $single ? ($offers > 0 ? $offers.' '.str('Offer')->plural($offers) : '1 Option') : $variants->count().' Options',
            'price' => $shown['price'],                                         // the starting price: the cheapest option, after a live discount
            'compare_price' => $shown['compare_price'],
            'discount_label' => $shown['discount_label'],
            'mrp' => $cheapest ? (float) $cheapest->mrp : null,
            'discount_percent' => $cheapest ? (float) $cheapest->discount_percent : 0.0,
            'badge_text' => $this->badge_text,
            'tags' => $this->tags->pluck('slug')->values(),
            'in_stock' => $variants->contains(fn ($v) => $v->available() > 0),
        ];
    }

    /**
     * The display price of one option after the live discounts.
     *
     * @return array{price: float, compare_price: ?float, discount_label: ?string}
     */
    protected function display($variant): array
    {
        $settings = app(Settings::class);
        $cents = $settings->cents($variant->selling_price);
        $d = app(CartPricing::class)->displayPrice($this->id, (int) $this->category_id, $this->sub_category_id !== null ? (int) $this->sub_category_id : null, $cents);
        $mrp = $settings->cents($variant->mrp);
        $compare = $mrp > $d['price'] ? $mrp : ($cents > $d['price'] ? $cents : null);   // the MRP, else the price before the discount

        return [
            'price' => $settings->money($d['price']),
            'compare_price' => $compare === null ? null : $settings->money($compare),
            'discount_label' => $d['label'],
        ];
    }
}
