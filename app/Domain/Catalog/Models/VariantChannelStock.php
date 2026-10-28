<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VariantChannelStock extends Model
{
    protected $table = 'variant_channel_stock';

    protected $fillable = ['variant_id', 'channel_id', 'stock_qty', 'reserved_qty', 'low_stock_threshold', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'stock_qty' => 'integer', 'reserved_qty' => 'integer'];
    }

    /** Units that can still be sold. */
    public function available(): int
    {
        return max(0, $this->stock_qty - $this->reserved_qty);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'channel_id');
    }
}
