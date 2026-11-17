<?php

namespace App\Domain\Promotion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A coupon: the customer types the `code` (case-insensitive, 3 to 32 of A-Z 0-9 - _). Same value / scope rules as a discount, plus
 * limits: `min_order` (items total after bulk and automatic discounts), `max_discount` (cap), `usage_limit` (total), `per_customer_limit`
 * (by normalised phone), `first_order_only`, and `free_delivery` (no value: the delivery fee becomes 0).
 * `used_count` counts the uses that are not given back; it only changes inside an order transaction with the coupon row locked.
 */
class Coupon extends Model
{
    use TargetsProducts;

    public const CODE_PATTERN = '/^[A-Z0-9_-]{3,32}$/';

    protected $fillable = [
        'code', 'name', 'description', 'type', 'value', 'scope', 'starts_at', 'ends_at', 'is_active', 'min_order', 'max_discount',
        'usage_limit', 'per_customer_limit', 'first_order_only', 'free_delivery',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2', 'min_order' => 'decimal:2', 'max_discount' => 'decimal:2',
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean', 'first_order_only' => 'boolean', 'free_delivery' => 'boolean',
            'usage_limit' => 'integer', 'per_customer_limit' => 'integer', 'used_count' => 'integer',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(CouponTarget::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    public static function normaliseCode(string $code): string
    {
        return strtoupper(trim($code));
    }
}
