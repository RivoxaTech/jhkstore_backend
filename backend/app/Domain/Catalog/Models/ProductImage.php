<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/** A remote image (url) of a product, used by imported/demo data. Uploaded pictures are spatie media on the product. */
class ProductImage extends Model
{
    protected $fillable = ['product_id', 'variant_id', 'type', 'url', 'alt', 'sort_order', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
