<?php

namespace App\Http\Resources;

use App\Domain\Catalog\ProductImages;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full admin view of a product (everything the edit form needs). Needs category, subCategory, tags,
 * contentTabs, media, remoteImages, related (with media + remoteImages) and variants.priceTiers loaded.
 * Every product has at least one option (variant); the cheapest one gives the starting price.
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ref = fn ($relation) => $this->{$relation} ? ['id' => $this->{$relation}->id, 'name' => $this->{$relation}->name] : null;

        return [
            'id' => $this->id,
            'category' => $ref('category'),
            'sub_category' => $ref('subCategory'),
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'badge_text' => $this->badge_text,
            'tax_rate' => $this->tax_rate === null ? null : (float) $this->tax_rate,   // null = the store's default rate
            'min_order_qty' => $this->min_order_qty,
            'max_order_qty' => $this->max_order_qty,
            'is_cod_available' => $this->is_cod_available,
            'status' => $this->status->value,
            'published_at' => $this->published_at,
            'min_price' => $this->min_price === null ? null : (float) $this->min_price,
            'max_discount_percent' => (float) $this->max_discount_percent,
            'is_in_stock' => $this->is_in_stock,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'variants' => $this->variants->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'sku' => $v->sku,
                'is_default' => $v->is_default,
                'mrp' => (float) $v->mrp,
                'selling_price' => (float) $v->selling_price,
                'discount_percent' => (float) $v->discount_percent,
                'weight_grams' => $v->weight_grams,
                'is_active' => $v->is_active,
                'sort_order' => $v->sort_order,
                'price_tiers' => $v->priceTiers->map(fn ($t) => ['min_qty' => $t->min_qty, 'price' => (float) $t->price])->values(),
                'stock' => (int) $v->stock_qty,
                'reserved' => (int) $v->reserved_qty,
                'low_stock_threshold' => (int) $v->low_stock_threshold,
            ])->values(),
            'related_ids' => $this->related->pluck('id')->values(),
            'related' => $this->related->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'sku' => $r->sku, 'status' => $r->status->value, 'thumb_url' => ProductImages::primary($r)['thumb_url'],
            ])->values(),
            'tag_ids' => $this->tags->pluck('id')->values(),
            'tags' => $this->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'color' => $t->color])->values(),
            'content_tabs' => $this->contentTabs->map(fn ($t) => [
                'tab_key' => $t->tab_key, 'label' => $t->label(), 'format' => $t->format, 'content' => $t->content, 'manual_url' => $t->manual_url,
            ])->values(),
            'images' => ProductImages::all($this->resource),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
