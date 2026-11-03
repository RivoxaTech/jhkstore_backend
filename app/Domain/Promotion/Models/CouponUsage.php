<?php

namespace App\Domain\Promotion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One use of a coupon by an order. `released_at` is set when the order was cancelled or rejected: the use is given back and no longer counts. */
class CouponUsage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'released_at' => 'datetime'];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
