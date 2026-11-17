<?php

namespace App\Domain\Order\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ordered option, as it was when the order was placed (name, SKU, picture, prices, bulk price used, tax rate). */
class OrderItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'mrp' => 'decimal:2', 'base_price' => 'decimal:2', 'unit_price' => 'decimal:2', 'discount' => 'decimal:2', 'bulk_discount' => 'decimal:2',
            'tax_rate' => 'decimal:2', 'tax_amount' => 'decimal:2', 'line_total' => 'decimal:2',
            'qty' => 'integer', 'bulk_min_qty' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
