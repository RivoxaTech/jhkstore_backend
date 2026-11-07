<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    protected $fillable = [
        'name', 'sku', 'is_default', 'attributes', 'mrp', 'selling_price', 'discount_percent',
        'weight_grams', 'is_cod_available', 'is_active', 'sort_order', 'stock_qty', 'reserved_qty', 'low_stock_threshold',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'is_cod_available' => 'boolean',
            'attributes' => 'array',
            'mrp' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'stock_qty' => 'integer',
            'reserved_qty' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    /** discount_percent = round((mrp - selling_price) / mrp * 100, 2). The single place this is calculated. */
    public static function discountFor(float|string $mrp, float|string $price): float
    {
        $mrp = (float) $mrp;

        return $mrp > 0 ? round(($mrp - (float) $price) / $mrp * 100, 2) : 0.0;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Quantity prices ("Buy 5+ for ... each"), cheapest-quantity first. */
    public function priceTiers(): HasMany
    {
        return $this->hasMany(VariantPriceTier::class, 'variant_id')->orderBy('min_qty');
    }

    /** Units that can still be sold (stock minus what is reserved). */
    public function available(): int
    {
        return max(0, $this->stock_qty - $this->reserved_qty);
    }
}
