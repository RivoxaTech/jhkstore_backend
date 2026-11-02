<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order for the guest who placed it (answer of POST /store/orders and GET /store/orders/{number}?phone=). Needs items, addresses, history,
 * payment and shipments loaded (see Store\OrderController). Money is in the shop currency.
 */
class StoreOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $o = $this->resource;

        return [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'status' => $o->status->value,
            'status_label' => OrderPresenter::statusLabel($o->status),
            'placed_at' => $o->placed_at,
            'estimated_delivery_date' => $o->estimated_delivery_date?->toDateString(),
            'items_count' => $o->items_count,
            'grand_total' => (float) $o->grand_total,
            'payment_method' => $o->payment_method,
            'payment_method_label' => OrderPresenter::paymentMethodLabel($o->payment_method),
            'payment_status' => $o->payment_status->value,
            'customer' => ['name' => $o->customer_name, 'phone' => $o->customer_phone, 'email' => $o->customer_email],
            'customer_note' => $o->customer_note,
            'cancel_reason' => $o->cancel_reason,
            'items' => $o->items->map(fn ($i) => OrderPresenter::item($i))->values(),
            'shipping_address' => OrderPresenter::address($o->shippingAddress()),
            'billing_address' => OrderPresenter::address($o->billingAddress()),
            'totals' => OrderPresenter::totals($o),
            'tracking' => OrderPresenter::tracking($o),
            'timeline' => $o->history->where('is_customer_visible', true)->map(fn ($h) => OrderPresenter::step($h))->values(),
        ];
    }
}
