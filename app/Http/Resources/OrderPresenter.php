<?php

namespace App\Http\Resources;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderAddress;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderStatusHistory;
use App\Domain\Reward\Actions\RewardLedger;
use App\Domain\Support\Settings;

/** The pieces of an order answer that the customer and the admin resources share, so both always show the same numbers. */
class OrderPresenter
{
    public static function statusLabel(OrderStatus $status): string
    {
        return ucfirst($status->value);
    }

    public static function paymentMethodLabel(?string $method): ?string
    {
        return $method ? (PaymentMethod::tryFrom($method)?->label() ?? strtoupper($method)) : null;
    }

    /** Price breakdown. `subtotal` = items at MRP - product discount - bulk offer; `grand_total` = subtotal - coins discount + delivery. */
    public static function totals(Order $o): array
    {
        $settings = app(Settings::class);
        $totals = [
            'items_total' => (float) $o->mrp_total,
            'product_discount' => (float) $o->product_discount_total,
            'bulk_discount' => (float) $o->bulk_discount_total,
            'subtotal' => (float) $o->items_subtotal,
            'delivery_fee' => (float) $o->shipping_fee,
            'grand_total' => (float) $o->grand_total,
            'tax_total' => (float) $o->tax_total,
            'tax_inclusive' => (bool) $o->tax_inclusive,
            'tax_label' => (string) $settings->get('tax_label'),
            'tax_note' => $o->tax_inclusive ? 'Inclusive of all taxes' : 'Tax is added to the total',
            'total_savings' => round((float) $o->product_discount_total + (float) $o->bulk_discount_total, 2),
        ];
        if (app(RewardLedger::class)->enabled()) {
            // reward points exist only while the feature is on
            $totals['coins_redeemed'] = $o->coins_redeemed;
            $totals['coins_discount'] = (float) $o->coins_discount;
            $totals['coins_to_earn'] = $o->coins_to_earn;
            $totals['total_savings'] = round($totals['total_savings'] + (float) $o->coins_discount, 2);
        }

        return $totals;
    }

    public static function item(OrderItem $i): array
    {
        $item = [
            'id' => $i->id,
            'product_id' => $i->product_id,
            'variant_id' => $i->variant_id,
            'name' => $i->name,
            'option_name' => $i->option_name,
            'sku' => $i->sku,
            'image_url' => $i->image_url,
            'qty' => $i->qty,
            'mrp' => (float) $i->mrp,
            'price' => $i->base_price !== null ? (float) $i->base_price : (float) $i->unit_price,  // the option's own price
            'unit_price' => (float) $i->unit_price,                                              // what was charged per unit
            'bulk_applied' => $i->bulk_min_qty ? ['min_qty' => $i->bulk_min_qty, 'price' => (float) $i->unit_price] : null,
            'discount' => (float) $i->discount,
            'bulk_discount' => (float) $i->bulk_discount,
            'line_total' => (float) $i->line_total,
            'tax_rate' => (float) $i->tax_rate,
            'tax_amount' => (float) $i->tax_amount,
            'expiry_date' => $i->expiry_date?->toDateString(),
            'status' => $i->status,
        ];
        if (app(RewardLedger::class)->enabled()) {
            $item['coins_earned'] = $i->coins_earned;
        }

        return $item;
    }

    public static function address(?OrderAddress $a): ?array
    {
        if (! $a) {
            return null;
        }

        return [
            'name' => $a->name,
            'phone' => $a->phone,
            'alt_phone' => $a->alt_phone,
            'line1' => $a->line1,
            'area' => $a->area,
            'landmark' => $a->landmark,
            'city' => $a->city,
            'state' => $a->state,
            'postcode' => $a->postcode,
            'country' => $a->country,
            'tax_number' => $a->tax_number,
            'type' => $a->address_type,
            'latitude' => $a->latitude,
            'longitude' => $a->longitude,
        ];
    }

    public static function tracking(Order $o): ?array
    {
        $s = $o->shipments->last();

        return $s ? ['courier' => $s->carrier, 'awb_number' => $s->awb_number, 'tracking_url' => $s->tracking_url, 'shipped_at' => $s->shipped_at] : null;
    }

    public static function step(OrderStatusHistory $h, ?array $by = null): array
    {
        return [
            'from' => $h->from_status,
            'status' => $h->to_status,
            'label' => ucfirst($h->to_status),
            'note' => $h->note,
            'by' => $by ?? ['type' => $h->changed_by_type],
            'at' => $h->created_at,
        ];
    }
}
