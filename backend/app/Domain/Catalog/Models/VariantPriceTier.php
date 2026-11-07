<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/** Quantity price: "Buy {min_qty}+ for {price} each". */
class VariantPriceTier extends Model
{
    protected $fillable = ['variant_id', 'min_qty', 'price'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'min_qty' => 'integer'];
    }
}
