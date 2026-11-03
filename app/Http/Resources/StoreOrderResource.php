<?php

namespace App\Http\Resources;

use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Support\Settings;
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
        $settings = app(Settings::class);
        $payment = $o->payment;
        $bank = $o->payment_method === PaymentMethod::BankTransfer->value;
        $digits = preg_replace('/\D+/', '', (string) $settings->get('whatsapp_number'));

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
            'payment' => [
                'method' => $o->payment_method,
                'method_label' => OrderPresenter::paymentMethodLabel($o->payment_method),
                'status' => $o->payment_status->value,
                'proof_status' => $payment?->proof_status ?? 'none',       // none | submitted | verified | rejected (bank transfer)
                'reject_reason' => $payment?->proof_reject_reason,          // why the last proof was rejected: upload a new one
                'can_upload_proof' => $bank && $o->status->value === 'pending' && in_array($payment?->proof_status ?? 'none', ['none', 'rejected'], true),
            ] + ($bank && $o->payment_status->value !== 'paid' && ! $o->status->isVoid() ? ['instructions' => (string) $settings->get('pay_bank_instructions'), 'accounts' => $settings->publicAccounts()] : []),
            'delivery_fee_pending' => (bool) $o->delivery_fee_pending,       // "delivery fee to be confirmed"
            'reject_reason' => $o->reject_reason,
            'store_phone' => (string) $settings->get('store_phone'),
            'whatsapp_url' => $digits !== '' ? 'https://wa.me/'.$digits : null,
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
