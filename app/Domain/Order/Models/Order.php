<?php

namespace App\Domain\Order\Models;

use App\Domain\Customer\Models\Customer;
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
 * Amounts: mrp_total (at MRP) - product_discount_total - bulk_discount_total - discount_total - coupon_discount = items_subtotal; grand_total =
 * items_subtotal + shipping_fee (null while `delivery_fee_pending`: the owner confirms it later) (+ tax_total when prices are tax exclusive). `tax_total` is the sales tax (already inside the prices when `tax_inclusive`).
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
            'items_subtotal' => 'decimal:2', 'shipping_fee' => 'decimal:2', 'discount_total' => 'decimal:2', 'coupon_discount' => 'decimal:2',
            'delivery_fee_pending' => 'boolean', 'rejected_at' => 'datetime', 'tax_total' => 'decimal:2', 'grand_total' => 'decimal:2',
            'tax_inclusive' => 'boolean', 'items_count' => 'integer',
            'estimated_delivery_date' => 'date',
            'placed_at' => 'datetime', 'confirmed_at' => 'datetime', 'shipped_at' => 'datetime',
            'delivered_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
