<?php

namespace App\Http\Resources;

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
        ];
    }
}
