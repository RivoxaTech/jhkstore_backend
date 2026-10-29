<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order for its owner. As a list row it needs `items` (for the thumbnails) loaded; the detail also needs `addresses`, `history`,
 * `payment`, `shipments` and `channel` (see Store\OrderController). Money is in the shop currency, tax is included in all prices.
 */
class StoreOrderResource extends JsonResource
{
    /** @param  bool  $detail  false = the short list row */
    public function __construct($resource, private readonly bool $detail = true)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $o = $this->resource;
        $row = [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'status' => $o->status->value,
            'status_label' => OrderPresenter::statusLabel($o->status),
            'can_cancel' => $o->status->isCancellable(),
            'channel' => $o->relationLoaded('channel') ? $o->channel->code : null,
            'placed_at' => $o->placed_at,
            'estimated_delivery_date' => $o->estimated_delivery_date?->toDateString(),
            'items_count' => $o->items_count,
            'grand_total' => (float) $o->grand_total,
            'payment_method' => $o->payment_method,
            'payment_method_label' => OrderPresenter::paymentMethodLabel($o->payment_method),
            'payment_status' => $o->payment_status->value,
        ];

        if (! $this->detail) {
            return $row + ['preview' => $o->items->take(4)->map(fn ($i) => ['name' => $i->option_name ?: $i->name, 'image_url' => $i->image_url])->values()];
        }

        return $row + [
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
