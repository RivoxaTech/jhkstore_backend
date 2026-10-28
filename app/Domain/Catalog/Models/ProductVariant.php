<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    protected $fillable = [
        'name', 'sku', 'is_default', 'attributes', 'mrp', 'selling_price', 'discount_percent', 'reward_points',
        'expiry_date', 'barcode', 'weight_grams', 'is_cod_available', 'is_active', 'sort_order',
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
            'expiry_date' => 'date',
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

    /** One row per sales channel (quantity is separate per channel). */
    public function stocks(): HasMany
    {
        return $this->hasMany(VariantChannelStock::class, 'variant_id');
    }
}
