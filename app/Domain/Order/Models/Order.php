<?php

namespace App\Domain\Order\Models;

use App\Domain\Auth\Models\User;
use App\Domain\Catalog\Models\SalesChannel;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A placed order. Everything on it (prices, names, addresses, customer) is a snapshot taken when it was placed, so later edits of the
 * catalogue or the customer never change an old order.
 *
 * Amounts: mrp_total (at MRP) - product_discount_total - bulk_discount_total = items_subtotal; grand_total = items_subtotal - coins_discount
 * + shipping_fee (+ cod_fee). `tax_total` is the sales tax already inside the prices.
 */
class Order extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'mrp_total' => 'decimal:2', 'product_discount_total' => 'decimal:2', 'bulk_discount_total' => 'decimal:2',
            'items_subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'coins_discount' => 'decimal:2',
            'shipping_fee' => 'decimal:2', 'cod_fee' => 'decimal:2', 'tax_total' => 'decimal:2', 'grand_total' => 'decimal:2',
            'coins_redeemed' => 'integer', 'coins_to_earn' => 'integer', 'items_count' => 'integer',
            'estimated_delivery_date' => 'date',
            'placed_at' => 'datetime', 'confirmed_at' => 'datetime', 'shipped_at' => 'datetime',
            'delivered_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'channel_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /** The payment row of this order (COD today; online payments later). */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class, 'payable_id')->where('payable_type', Payment::ORDER);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class)->orderBy('id');
    }

    public function shippingAddress(): ?OrderAddress
    {
        return $this->addresses->firstWhere('type', 'shipping');
    }

    public function billingAddress(): ?OrderAddress
    {
        return $this->addresses->firstWhere('type', 'billing');
    }
}
