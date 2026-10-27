<?php

namespace App\Domain\Catalog\Models;

use App\Support\GeneratesUniqueSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;

/**
 * A sub-category. A first-level one (`parent_id` null) may have children - the sub-sub-categories - which
 * have the same fields and images but never children of their own. `category_id` is always the top category.
 */
class SubCategory extends Model implements HasMedia
{
    use GeneratesUniqueSlug, HasCatalogImages, SoftDeletes;

    protected $table = 'sub_categories';

    protected $fillable = [
        'name', 'description', 'color', 'sort_order', 'is_active', 'meta_title', 'meta_description',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Adds `products_count` (live products only; a parent also counts the products of its children) to each row. */
    public function scopeWithProductCounts(Builder $query): Builder
    {
        return $query->addSelect(['products_count' => DB::table('products')
            ->selectRaw('count(*)')
            ->whereNull('products.deleted_at')
            ->where(fn ($q) => $q
                ->whereColumn('products.sub_category_id', 'sub_categories.id')
                ->orWhereIn('products.sub_category_id', DB::table('sub_categories as kids')
                    ->select('kids.id')->whereColumn('kids.parent_id', 'sub_categories.id')->whereNull('kids.deleted_at'))),
        ]);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function isChild(): bool
    {
        return $this->parent_id !== null;
    }
}
