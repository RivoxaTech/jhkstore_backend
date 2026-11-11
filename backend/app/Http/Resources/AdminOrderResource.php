<?php

namespace App\Http\Resources;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full order for the admin panel. Needs items, addresses, history, payment, shipments loaded.
 * `$context` = ['orders_count' => int, 'first_order_at' => ?date, 'admins' => [id => name]] (looked up once by the controller).
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
        $bank = $o->payment_method === PaymentMethod::BankTransfer->value;
        $proofStatus = $payment?->proof_status ?? 'none';

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
            'rejected_at' => $o->rejected_at,
            'estimated_delivery_date' => $o->estimated_delivery_date?->toDateString(),
            'source' => $o->source,
            'customer' => [
                'id' => $o->customer_id,
                'name' => $o->customer_name,
                'email' => $o->customer_email,
                'phone' => $o->customer_phone,
                'orders_count' => $this->context['orders_count'] ?? null,
                'first_order_at' => $this->context['first_order_at'] ?? null,
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
                'proof_status' => $proofStatus,
                'proof_reference' => $payment?->proof_reference,
                'proof_reject_reason' => $payment?->proof_reject_reason,
                'has_proof' => $bank && $payment && $payment->getFirstMedia('proof') !== null,
                'proof_url' => $bank && $payment && $payment->getFirstMedia('proof') ? url("/api/v1/admin/orders/{$o->id}/payment/proof") : null,   // needs the admin login: the file is private
                'verified_at' => $payment?->verified_at,
                'bank_accounts' => $bank ? ($payment?->bank_snapshot['accounts'] ?? []) : [],      // what the customer was shown when ordering (a snapshot)
                'bank_instructions' => $bank ? (string) ($payment?->bank_snapshot['instructions'] ?? '') : null,
            ],
            'delivery' => ['fee_pending' => (bool) $o->delivery_fee_pending, 'zone_id' => $o->delivery_zone_id],
            'coupon' => $o->coupon_code ? ['code' => $o->coupon_code, 'id' => $o->coupon_id, 'discount' => (float) $o->coupon_discount] : null,
            'shipment' => OrderPresenter::tracking($o),
            'customer_note' => $o->customer_note,
            'admin_note' => $o->admin_note,
            'cancel_reason' => $o->cancel_reason,
            'cancelled_by' => $o->cancelled_by,
            'reject_reason' => $o->reject_reason,
            'timeline' => $o->history->map(fn ($h) => OrderPresenter::step($h, [
                'type' => $h->changed_by_type,
                'name' => $h->changed_by_type === 'admin' ? ($admins[$h->changed_by_id] ?? 'Admin') : ($h->changed_by_type === 'customer' ? $o->customer_name : 'System'),
            ]))->values(),
            // what the buttons of the admin screen may do from here
            'actions' => [
                'next_status' => $next?->value,
                // every status this order may be set to now (the panel's status menu): forward steps first, then cancel / reject
                'allowed_statuses' => array_map(fn (OrderStatus $s) => $s->value, $o->status->next()),
                'next_label' => $next ? ucfirst($next->value) : null,
                'needs_shipping_details' => $next === OrderStatus::Shipped,
                'can_cancel' => $o->status->isCancellable(),
                'can_reject' => $o->status->isRejectable(),
                'can_verify_payment' => $bank && ! $o->status->isVoid() && $proofStatus === 'submitted',   // verify or reject the proof
                'needs_delivery_fee' => (bool) $o->delivery_fee_pending && ! $o->status->isVoid(),
                'blocked_reason' => $o->status === OrderStatus::Pending
                    ? ($o->delivery_fee_pending ? 'delivery_fee_pending' : ($bank && $o->payment_status !== PaymentStatus::Paid ? 'payment_not_verified' : null))
                    : null,   // why the order cannot be confirmed yet
                'allowed' => array_map(fn (OrderStatus $s) => $s->value, $o->status->next()),
            ],
        ];
    }
}
