<?php

namespace App\Http\Resources;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One row of the admin orders table (no joins; the customer is the snapshot on the order). */
class AdminOrderListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'placed_at' => $this->placed_at,
            'customer' => ['id' => $this->customer_id, 'name' => $this->customer_name, 'email' => $this->customer_email, 'phone' => $this->customer_phone],
            'items_count' => $this->items_count,
            'grand_total' => (float) $this->grand_total,
            'payment_method' => $this->payment_method,
            'payment_method_label' => OrderPresenter::paymentMethodLabel($this->payment_method),
            'payment_status' => $this->payment_status->value,
            'delivery_fee_pending' => (bool) $this->delivery_fee_pending,
            'status' => $this->status->value,
            'status_label' => OrderPresenter::statusLabel($this->status),
            // what the status dropdown of the list may offer (the same rules as the order page: ChangeOrderStatus decides in the end)
            'next_statuses' => array_map(fn (OrderStatus $s) => $s->value, $this->status->next()),
            'blocked_reason' => $this->status === OrderStatus::Pending
                ? ($this->delivery_fee_pending ? 'delivery_fee_pending' : ($this->payment_method === PaymentMethod::BankTransfer->value && $this->payment_status !== PaymentStatus::Paid ? 'payment_not_verified' : null))
                : null,
        ];
    }
}
