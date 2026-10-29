<?php

namespace App\Http\Resources;

use App\Domain\Order\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full order for the admin panel. Needs items, addresses, history, payment, shipments, channel loaded.
 * `$context` = ['orders_count' => int, 'member_since' => ?date, 'admins' => [id => name]] (looked up once by the controller).
 */
class AdminOrderResource extends JsonResource
{
    public function __construct($resource, private readonly array $context = [])
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $o = $this->resource;
        $admins = $this->context['admins'] ?? [];
        $payment = $o->payment;
        $next = $o->status->forwardStep();

        return [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'status' => $o->status->value,
            'status_label' => OrderPresenter::statusLabel($o->status),
            'placed_at' => $o->placed_at,
            'confirmed_at' => $o->confirmed_at,
            'shipped_at' => $o->shipped_at,
            'delivered_at' => $o->delivered_at,
            'cancelled_at' => $o->cancelled_at,
            'estimated_delivery_date' => $o->estimated_delivery_date?->toDateString(),
            'channel' => ['code' => $o->channel->code, 'name' => $o->channel->name],
            'source' => $o->source,
            'customer' => [
                'id' => $o->user_id,
                'name' => $o->customer_name,
                'email' => $o->customer_email,
                'phone' => $o->customer_phone,
                'gst_number' => $o->customer_gst_number,
                'orders_count' => $this->context['orders_count'] ?? null,
                'member_since' => $this->context['member_since'] ?? null,
            ],
            'shipping_address' => OrderPresenter::address($o->shippingAddress()),
            'billing_address' => OrderPresenter::address($o->billingAddress()),
            'items' => $o->items->map(fn ($i) => OrderPresenter::item($i))->values(),
            'totals' => OrderPresenter::totals($o),
            'payment' => [
                'method' => $o->payment_method,
                'method_label' => OrderPresenter::paymentMethodLabel($o->payment_method),
                'status' => $o->payment_status->value,
                'amount' => (float) $o->grand_total,
                'paid_at' => $payment?->paid_at,
                'collect_on_delivery' => $o->payment_method === 'cod' && $o->payment_status->value === 'cod_pending' ? (float) $o->grand_total : null,
            ],
            'shipment' => OrderPresenter::tracking($o),
            'customer_note' => $o->customer_note,
            'admin_note' => $o->admin_note,
            'cancel_reason' => $o->cancel_reason,
            'cancelled_by' => $o->cancelled_by,
            'timeline' => $o->history->map(fn ($h) => OrderPresenter::step($h, [
                'type' => $h->changed_by_type,
                'name' => $h->changed_by_type === 'admin' ? ($admins[$h->changed_by_id] ?? 'Admin') : ($h->changed_by_type === 'customer' ? $o->customer_name : 'System'),
            ]))->values(),
            // what the buttons of the admin screen may do from here
            'actions' => [
                'next_status' => $next?->value,
                'next_label' => $next ? ucfirst($next->value) : null,
                'needs_shipping_details' => $next === OrderStatus::Shipped,
                'can_cancel' => $o->status->isCancellable(),
                'allowed' => array_map(fn (OrderStatus $s) => $s->value, $o->status->next()),
            ],
        ];
    }
}
