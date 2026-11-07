<?php

namespace App\Domain\Catalog\Models;

use App\Support\GeneratesUniqueSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A label on a product (best-seller, hot-selling, ...). System tags drive the homepage sections and cannot be deleted. */
class Tag extends Model
{
    use GeneratesUniqueSlug;

    protected $fillable = ['name', 'color', 'style', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_active' => 'boolean'];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_tag');
    }
}
