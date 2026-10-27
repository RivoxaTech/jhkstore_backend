<?php

namespace App\Domain\Catalog\Models;

use App\Support\GeneratesUniqueSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;

class Category extends Model implements HasMedia
{
    use GeneratesUniqueSlug, HasCatalogImages, SoftDeletes;

    /** How many categories may be marked 'top' (shown on the home screen). */
    public const MAX_TOP = 12;

    protected $fillable = [
        'name', 'description', 'color', 'sort_order', 'is_active', 'is_top', 'meta_title', 'meta_description',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_top' => 'boolean'];
    }

    /** Adds `sub_categories_count` and `products_count` (live products only) to each row. */
    public function scopeWithProductCounts(Builder $query): Builder
    {
        return $query->withCount('subCategories')->addSelect(['products_count' => DB::table('products')
            ->selectRaw('count(*)')->whereColumn('products.category_id', 'categories.id')->whereNull('products.deleted_at')]);
    }

    /** First-level sub-categories only; their own children come from SubCategory::children(). */
    public function subCategories(): HasMany
    {
        return $this->hasMany(SubCategory::class)->whereNull('parent_id')->orderBy('sort_order')->orderBy('name');
    }
}
