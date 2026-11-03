<?php

namespace App\Domain\Order\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Notifier;
use App\Domain\Order\Exceptions\CommerceException;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderStatusHistory;
use App\Domain\Order\Models\Payment;
use App\Domain\Support\Settings;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Delivery fee confirmation: an order placed while the fee was unknown (`delivery_fee_pending`: the owner confirms it, or an address outside
 * the zones) gets its fee here. Grand total = items + fee (+ tax when prices are tax exclusive; delivery itself is not taxed), the payment
 * amount and the customer's total follow. The customer sees "delivery fee to be confirmed" until then, then the final total.
 */
class ConfirmDeliveryFee
{
    public function __construct(private readonly Settings $settings, private readonly Notifier $notifier) {}

    public function handle(Order $order, float|int|string $fee, Admin $actor): Order
    {
        $confirmed = DB::transaction(function () use ($order, $fee, $actor) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $order->delivery_fee_pending) {
                throw new CommerceException('delivery_fee_not_pending', 'The delivery fee of this order is already set.', 'fee');
            }
            if ($order->status->isVoid()) {
                throw new CommerceException('order_closed', 'This order is '.$order->status->value.'.', 'fee');
            }

            $cents = $this->settings->cents($fee);
            $total = $this->settings->cents($order->items_subtotal) + $cents + ($order->tax_inclusive ? 0 : $this->settings->cents($order->tax_total));
            $difference = $this->settings->money($total) - (float) $order->grand_total;

            $order->forceFill([
                'shipping_fee' => $this->settings->money($cents), 'delivery_fee_pending' => false, 'grand_total' => $this->settings->money($total),
            ])->save();
            Payment::query()->where('payable_type', Payment::ORDER)->where('payable_id', $order->id)
                ->update(['amount' => $this->settings->money($total), 'updated_at' => now()]);
            if ($customer = Customer::query()->whereKey($order->customer_id)->lockForUpdate()->first()) {
                $customer->forceFill(['total_spent' => round((float) $customer->total_spent + $difference, 2)])->save();
            }

            $note = 'Delivery fee confirmed: '.number_format($this->settings->money($cents), $this->settings->decimals());
            OrderStatusHistory::create([
                'order_id' => $order->id, 'from_status' => $order->status->value, 'to_status' => $order->status->value, 'note' => $note,
                'changed_by_type' => 'admin', 'changed_by_id' => $actor->id, 'is_customer_visible' => true,
            ]);
            Audit::log($actor, 'order.delivery_fee_set', $order, ['fee' => $this->settings->money($cents), 'grand_total' => $this->settings->money($total)]);

            return $order->load(['items', 'addresses', 'history', 'payment', 'shipments']);
        });

        $this->notifier->deliveryFeeConfirmed($confirmed);   // the customer gets the final total by e-mail (if e-mails are on)

        return $confirmed;
    }
}
