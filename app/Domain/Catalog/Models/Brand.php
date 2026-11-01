<?php

namespace App\Domain\Catalog\Models;

use App\Support\GeneratesUniqueSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Brand extends Model implements HasMedia
{
    use GeneratesUniqueSlug, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'name', 'description', 'is_active', 'sort_order', 'meta_title', 'meta_description',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile()->useDisk('public');
        $this->addMediaCollection('banner')->singleFile()->useDisk('public');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(256)->height(256)->performOnCollections('logo')->nonQueued();
    }

    public function collections(): HasMany
    {
        return $this->hasMany(BrandCollection::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Brands that customers may see. */
    public function scopeStorefront(Builder $query): Builder
    {
        return $query->where('brands.is_active', true);
    }
}
