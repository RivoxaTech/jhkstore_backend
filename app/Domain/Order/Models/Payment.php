<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Model;

/** Polymorphic payment row (`payable_type` = order today; membership purchases later). Cash on delivery: cod_pending -> cod_collected. */
class Payment extends Model
{
    public const ORDER = 'order';

    public const COD_PENDING = 'cod_pending';
    public const COD_COLLECTED = 'cod_collected';
    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'response' => 'array'];
    }
}
