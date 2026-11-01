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

/** A sub-category: the second (and last) level of the category tree. */
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

    /** Adds `products_count` (live products only) to each row. */
    public function scopeWithProductCounts(Builder $query): Builder
    {
        return $query->addSelect(['products_count' => DB::table('products')
            ->selectRaw('count(*)')
            ->whereNull('products.deleted_at')
            ->whereColumn('products.sub_category_id', 'sub_categories.id'),
        ]);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
