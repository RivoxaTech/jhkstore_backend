<?php

namespace App\Http\Resources;

use App\Domain\Catalog\Models\ProductContentTab;
use App\Domain\Catalog\ProductImages;
use App\Domain\Catalog\ProductVideos;
use App\Domain\Support\Settings;
use Illuminate\Http\Request;

/**
 * Product detail for the customer site: the card fields plus description, content tabs, variants (own name, SKU, pictures,
 * quantity prices, stock), the product gallery, delivery estimate and related products
 * (set by the controller as the `related` relation).
 * With ONE option (`has_single_option`) the page is that option: its name, SKU, price, bulk prices and add-to-cart come first
 * and its pictures lead the gallery; with several options the parent's name and the list of options are shown.
 */
class StoreProductResource extends StoreProductCardResource
{
    public function toArray(Request $request): array
    {
        $images = collect(ProductImages::all($this->resource))->map(fn ($i) => [
            'variant_id' => $i['variant_id'], 'url' => $i['url'], 'medium_url' => $i['medium_url'], 'thumb_url' => $i['thumb_url'], 'is_primary' => $i['is_primary'],
            'width' => $i['width'], 'height' => $i['height'], 'medium_width' => $i['medium_width'], 'medium_height' => $i['medium_height'],
        ]);
        $strip = fn ($list) => $list->map(fn ($i) => ['url' => $i['url'], 'medium_url' => $i['medium_url'], 'thumb_url' => $i['thumb_url'], 'is_primary' => $i['is_primary'],
            'width' => $i['width'], 'height' => $i['height'], 'medium_width' => $i['medium_width'], 'medium_height' => $i['medium_height']])->values();

        $card = parent::toArray($request);
        $days = (int) app(Settings::class)->get('delivery_days');

        return $card + [
            'product_name' => $this->name,   // the parent's name (a single-option product's `name` is its option's)
            'display_mode' => $card['has_single_option'] ? 'single_option' : 'multiple_options',
            'sku' => $card['has_single_option'] ? $this->variants->first()->sku : $this->sku,
            'sub_category' => $this->subCategory ? ['name' => $this->subCategory->name, 'slug' => $this->subCategory->slug] : null,
            'description' => $this->description,
            'min_order_qty' => $this->min_order_qty,
            'max_order_qty' => $this->max_order_qty,
            'is_cod_available' => $this->is_cod_available,
            'tax_rate' => $this->tax_rate === null ? (float) app(Settings::class)->get('tax_rate') : (float) $this->tax_rate,
            'delivery' => [
                'days' => $days,
                'arrives_by' => now()->addDays($days)->toDateString(),   // "Arrives by Oct 30, 2026"
            ],
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            // what a page title / description / share picture should be: the product's own SEO fields, else its name / short description / picture, else the store defaults
            'seo' => [
                'title' => $this->meta_title ?: $this->name,
                'description' => $this->meta_description ?: ($this->short_description ?: (string) app(Settings::class)->get('seo_meta_description')),
                'image_url' => $card['image_url'] ?? app(Settings::class)->fileUrl('seo_share_image'),
            ],
            // category > sub-category > product, as slugs (the storefront builds its own URLs and adds "Home")
            'breadcrumbs' => array_values(array_filter([
                ['type' => 'category', 'name' => $this->category->name, 'slug' => $this->category->slug],
                $this->subCategory ? ['type' => 'sub_category', 'name' => $this->subCategory->name, 'slug' => $this->subCategory->slug, 'category_slug' => $this->category->slug] : null,
                ['type' => 'product', 'name' => $this->name, 'slug' => $this->slug],
            ])),
            'content_tabs' => $this->contentTabs->map(fn (ProductContentTab $t) => [
                'key' => $t->tab_key, 'title' => $t->label(), 'format' => $t->format, 'content' => $t->content, 'manual_url' => $t->manual_url,
            ])->values(),
            'variants' => $this->variants->map(function ($v) use ($images, $strip, $card) {
                $qty = $v->available();
                $threshold = (int) $v->low_stock_threshold;
                $own = $strip($images->where('variant_id', $v->id));
                $shown = $this->display($v);

                return [
                    'id' => $v->id,
                    'name' => $v->name,
                    'sku' => $v->sku,
                    'is_default' => $v->is_default,
                    'mrp' => (float) $v->mrp,
                    'price' => $shown['price'],                      // after a live discount
                    'compare_price' => $shown['compare_price'],
                    'discount_label' => $shown['discount_label'],
                    'option_price' => (float) $v->selling_price,     // the option's own price, before any discount
                    'discount_percent' => (float) $v->discount_percent,
                    'is_cod_available' => $v->is_cod_available ?? $this->is_cod_available,
                    'in_stock' => ! $this->is_sold_out && $qty > 0,
                    'qty' => $this->is_sold_out ? 0 : $qty,
                    'low_stock' => ! $this->is_sold_out && $qty > 0 && $qty <= $threshold,   // "Only 2 left"
                    // "Buy 5+ for 152 each - extra 5% off"
                    'price_tiers' => $v->priceTiers->map(fn ($t) => [
                        'min_qty' => $t->min_qty,
                        'price' => (float) $t->price,
                        'extra_discount_percent' => $v->selling_price > 0 ? round(($v->selling_price - $t->price) / $v->selling_price * 100, 2) : 0.0,
                    ])->values(),
                    // the option's own pictures; image_url / thumb_url = its first one, else the product's primary picture
                    'image_url' => $own->first()['medium_url'] ?? $card['image_url'],
                    'thumb_url' => $own->first()['thumb_url'] ?? $card['thumb_url'],
                    'images' => $own,
                ];
            })->values(),
            // the product gallery (its own pictures; when it has none, the pictures of its options)
            // a single option's own pictures come first
            'images' => $strip($card['has_single_option']
                ? $images->where('variant_id', $this->variants->first()->id)->concat(collect(ProductImages::gallery($this->resource)))->unique('url')
                : collect(ProductImages::gallery($this->resource))),
            // uploaded videos (with their poster) and the video link; the storefront plays an upload in <video>, an embed in an <iframe>
            'videos' => ProductVideos::forStore($this->resource),
            'related' => StoreProductCardResource::collection($this->related),
        ];
    }
}
