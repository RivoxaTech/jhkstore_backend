<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Support\Settings;
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
        'category_id', 'sub_category_id', 'name', 'sku', 'short_description', 'description',
        'badge_text', 'tax_rate', 'min_order_qty', 'max_order_qty',
        'is_cod_available', 'status', 'meta_title', 'meta_description', 'is_sold_out', 'is_featured', 'video_url',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'tax_rate' => 'decimal:2',
            'is_cod_available' => 'boolean',
            'is_in_stock' => 'boolean',
            'is_sold_out' => 'boolean',
            'is_featured' => 'boolean',
            'min_price' => 'decimal:2',
            'max_discount_percent' => 'decimal:2',
            'published_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')->useDisk('public');
        $this->addMediaCollection('video')->useDisk('public');          // uploaded product videos (at most config shop.video.max_files), see ProductVideos
        $this->addMediaCollection('video_poster')->useDisk('public');   // the poster picture of an uploaded video (custom property video_id)
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(256)->height(256)->performOnCollections('images')->nonQueued();
        $this->addMediaConversion('medium')->width(512)->height(512)->performOnCollections('images')->nonQueued();
    }

    // ------------------------------------------------------------------ relations

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
     * What customers may see: an active product whose category and sub-category are
     * active and that has at least one active option.
     */
    public function scopeStorefront(Builder $query): Builder
    {
        return $query
            ->where('products.status', ProductStatus::Active->value)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('product_variants as av')   // at least one active option to sell
                ->whereColumn('av.product_id', 'products.id')->where('av.is_active', true))
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->where(fn ($q) => $q->whereNull('products.sub_category_id')->orWhereHas('subCategory', fn ($s) => $s->where('is_active', true)));
    }

    /** Products carrying an active tag with this slug (one indexed lookup through product_tag). */
    public function scopeWithTagSlug(Builder $query, string $slug): Builder
    {
        return $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('product_tag')
            ->join('tags', 'tags.id', '=', 'product_tag.tag_id')
            ->whereColumn('product_tag.product_id', 'products.id')
            ->where('tags.slug', $slug)->where('tags.is_active', true));
    }

    /** Products with at least one sellable unit (a product switched to "sold out" has none, whatever the stock says). */
    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('products.is_sold_out', false)->whereExists(fn ($q) => $q->select(DB::raw(1))->from('product_variants as v')
            ->whereColumn('v.product_id', 'products.id')
            ->where('v.is_active', true)
            ->whereRaw('v.stock_qty - v.reserved_qty > 0'));
    }
}
