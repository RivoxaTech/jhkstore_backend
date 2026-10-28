<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Company\Models\Brand;
use App\Domain\Company\Models\Company;
use App\Support\GeneratesUniqueSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends Model implements HasMedia
{
    use GeneratesUniqueSlug, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'company_id', 'brand_id', 'category_id', 'sub_category_id', 'name', 'sku', 'short_description', 'description',
        'badge_text', 'hsn_code', 'gst_rate', 'country_of_origin', 'warranty_text', 'min_order_qty', 'max_order_qty',
        'is_returnable', 'return_days', 'is_cod_available', 'is_professional_only', 'status', 'meta_title', 'meta_description',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'gst_rate' => 'decimal:2',
            'is_returnable' => 'boolean',
            'return_days' => 'integer',
            'is_cod_available' => 'boolean',
            'is_professional_only' => 'boolean',
            'is_in_stock' => 'boolean',
            'min_price' => 'decimal:2',
            'max_discount_percent' => 'decimal:2',
            'rating_avg' => 'float',
            'published_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')->useDisk('public');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(256)->height(256)->performOnCollections('images')->nonQueued();
        $this->addMediaConversion('medium')->width(512)->height(512)->performOnCollections('images')->nonQueued();
    }

    // ------------------------------------------------------------------ relations

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subCategory(): BelongsTo
    {
        return $this->belongsTo(SubCategory::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'product_tag')->withPivot('sort_order')->orderBy('tags.sort_order');
    }

    /** Channels this product is offered on (pivot `is_active` = the on/off toggle). */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(SalesChannel::class, 'product_channels', 'product_id', 'channel_id')
            ->withPivot('is_active')->withTimestamps();
    }

    public function remoteImages(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function contentTabs(): HasMany
    {
        return $this->hasMany(ProductContentTab::class)->orderBy('sort_order')->orderBy('id');
    }

    public function related(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_relations', 'product_id', 'related_product_id')
            ->wherePivot('type', 'related')->orderBy('product_relations.sort_order');
    }

    // ------------------------------------------------------------------ scopes

    /**
     * What customers may see: an active product whose brand is live (active brand of an approved company), whose
     * category and sub-category (and its parent) are active, and that is offered - and switched on - on the channel.
     * `$channelCode = null` means "on any active channel" (used where the channel does not matter, e.g. reviews).
     */
    public function scopeStorefront(Builder $query, ?string $channelCode = SalesChannel::STANDARD): Builder
    {
        return $query
            ->where('products.status', ProductStatus::Active->value)
            ->whereHas('brand', fn ($q) => $q->storefront())
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('product_variants as av')   // at least one active option to sell
                ->whereColumn('av.product_id', 'products.id')->where('av.is_active', true))
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->where(fn ($q) => $q->whereNull('products.sub_category_id')->orWhereHas('subCategory', fn ($s) => $s
                ->where('is_active', true)
                ->where(fn ($p) => $p->whereNull('parent_id')->orWhereHas('parent', fn ($x) => $x->where('is_active', true)))))
            ->whereExists(function ($q) use ($channelCode) {
                $q->select(DB::raw(1))->from('product_channels')
                    ->join('sales_channels', 'sales_channels.id', '=', 'product_channels.channel_id')
                    ->whereColumn('product_channels.product_id', 'products.id')
                    ->where('product_channels.is_active', true)
                    ->where('sales_channels.is_active', true)
                    ->when($channelCode, fn ($c) => $c->where('sales_channels.code', $channelCode));
            });
    }

    /** Products carrying an active tag with this slug (one indexed lookup through product_tag). */
    public function scopeWithTagSlug(Builder $query, string $slug): Builder
    {
        return $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('product_tag')
            ->join('tags', 'tags.id', '=', 'product_tag.tag_id')
            ->whereColumn('product_tag.product_id', 'products.id')
            ->where('tags.slug', $slug)->where('tags.is_active', true));
    }

    /** Products with at least one sellable unit on the channel. */
    public function scopeInStockOn(Builder $query, int $channelId): Builder
    {
        return $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('product_variants as v')
            ->join('variant_channel_stock as s', 's.variant_id', '=', 'v.id')
            ->whereColumn('v.product_id', 'products.id')
            ->where('v.is_active', true)->where('s.is_active', true)->where('s.channel_id', $channelId)
            ->whereRaw('s.stock_qty - s.reserved_qty > 0'));
    }
}
