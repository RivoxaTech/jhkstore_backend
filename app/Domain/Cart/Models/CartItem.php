<?php

namespace App\Domain\Cart\Models;

use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One cart line = one option (variant). `unit_price` is the option price when the customer last touched the line (for "price changed" warnings). */
class CartItem extends Model
{
    protected $fillable = ['cart_id', 'variant_id', 'qty', 'unit_price'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'unit_price' => 'decimal:2'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
