<?php

namespace App\Domain\Order\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Actions\RefreshProductSummary;
use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Actions\UpsertCustomer;
use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Notifier;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Enums\PaymentStatus;
use App\Domain\Order\Exceptions\CommerceException;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderStatusHistory;
use App\Domain\Order\Models\Payment;
use App\Domain\Order\Models\Shipment;
use App\Domain\Promotion\Models\Coupon;
use App\Domain\Promotion\Models\CouponUsage;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * The only way an order changes status (the admin panel). One transaction per change, the order row is locked.
 *
 *   pending -> confirmed -> packed -> shipped -> delivered        one step at a time, nobody can skip a step
 *   pending | confirmed | packed -> cancelled                     a shipped parcel cannot be cancelled
 *   pending -> rejected                                           the shop turns the order down (a reason is required, the customer sees it)
 *
 * Confirming is refused while the delivery fee is still to be confirmed (`delivery_fee_pending`) and while a bank transfer is not verified.
 * Side effects:  shipped = courier + AWB are required and a shipment is created   delivered = the COD payment is marked paid
 * cancelled / rejected = stock goes back (movement `cancel`), the customer's totals are reduced, the coupon use is given back and a payment
 * that was not taken is voided.
 * Every change adds a history row (who, when, note) and an audit-log entry.
 */
class ChangeOrderStatus
{
    public function __construct(private readonly UpsertCustomer $customers, private readonly RefreshProductSummary $refreshProduct, private readonly Notifier $notifier) {}

    /** @param  array{note?:?string, reason?:?string, courier?:?string, awb_number?:?string, tracking_url?:?string}  $data */
    public function handle(Order $order, OrderStatus $to, Admin $actor, array $data = []): Order
    {
        $changed = DB::transaction(function () use ($order, $to, $actor, $data) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $order->status;

            if (! $from->canMoveTo($to)) {
                $message = match ($to) {
                    OrderStatus::Cancelled => "This order is {$from->value} and can no longer be cancelled.",
                    OrderStatus::Rejected => "This order is {$from->value}: only a pending order can be rejected.",
                    default => "An order that is {$from->value} cannot be moved to {$to->value}.",
                };
                throw new CommerceException('invalid_status_change', $message, 'status');
            }

            $note = $data['note'] ?? null;
            if ($to === OrderStatus::Confirmed) {
                $this->assertCanConfirm($order);
            }

            match ($to) {
                OrderStatus::Confirmed => $order->confirmed_at = now(),
                OrderStatus::Shipped => $note = $this->ship($order, $data),
                OrderStatus::Delivered => $this->deliver($order),
                OrderStatus::Cancelled => $note = $this->cancel($order, $actor, $data['reason'] ?? $note),
                OrderStatus::Rejected => $note = $this->reject($order, $actor, $data['reason'] ?? null),
                default => null,
            };

            $order->status = $to;
            $order->save();

            OrderStatusHistory::create([
                'order_id' => $order->id, 'from_status' => $from->value, 'to_status' => $to->value, 'note' => $note ? mb_substr($note, 0, 255) : null,
                'changed_by_type' => 'admin', 'changed_by_id' => $actor->id, 'is_customer_visible' => true,
            ]);
            Audit::log($actor, match ($to) {
                OrderStatus::Cancelled => 'order.cancelled',
                OrderStatus::Rejected => 'order.rejected',
                default => 'order.status_changed',
            }, $order, ['from' => $from->value, 'to' => $to->value, 'note' => $note]);

            return $order->load(['items', 'addresses', 'history', 'payment', 'shipments']);
        });

        $this->notifier->orderStatusChanged($changed);   // after the commit; never fails the change

        return $changed;
    }

    /** A fee the owner still has to confirm must be set, and a bank transfer must be verified, before the order is confirmed. */
    private function assertCanConfirm(Order $order): void
    {
        if ($order->delivery_fee_pending) {
            throw new CommerceException('delivery_fee_pending', 'Set the delivery fee before you confirm this order.', 'status');
        }
        if ($order->payment_method === PaymentMethod::BankTransfer->value && $order->payment_status !== PaymentStatus::Paid) {
            throw new CommerceException('payment_not_verified', 'Verify the bank transfer payment before you confirm this order.', 'status');
        }
    }

    /** Courier and AWB are required; one shipment holds them. Returns the note for the timeline. */
    private function ship(Order $order, array $data): string
    {
        $courier = trim((string) ($data['courier'] ?? ''));
        $awb = trim((string) ($data['awb_number'] ?? ''));

        Shipment::create([
            'order_id' => $order->id, 'shipment_number' => 'SH-'.$order->order_number, 'carrier' => $courier !== '' ? $courier : null, 'awb_number' => $awb !== '' ? $awb : null,
            'tracking_url' => $data['tracking_url'] ?? null, 'status' => 'in_transit', 'shipped_at' => now(),
            'estimated_delivery_date' => $order->estimated_delivery_date,
        ]);
        $order->shipped_at = now();
        $order->fulfillment_status = 'fulfilled';

        return $courier === '' && $awb === '' ? 'Order shipped' : trim('Shipped'.($courier !== '' ? " via $courier" : '').($awb !== '' ? ", AWB $awb" : ''));
    }

    private function deliver(Order $order): void
    {
        $order->delivered_at = now();
        $order->shipped_at ??= now();   // marked delivered without a shipping step: it left and arrived
        $order->fulfillment_status = 'fulfilled';

        // cash on delivery: the money is collected by the courier at the door
        if ($order->payment_status === PaymentStatus::CodPending) {
            $order->payment_status = PaymentStatus::Paid;
            Payment::query()->where('payable_type', Payment::ORDER)->where('payable_id', $order->id)
                ->update(['status' => Payment::COD_COLLECTED, 'paid_at' => now(), 'updated_at' => now()]);
        }
        Shipment::query()->where('order_id', $order->id)->update(['status' => 'delivered', 'delivered_at' => now(), 'updated_at' => now()]);
    }

    private function cancel(Order $order, Admin $actor, ?string $reason): ?string
    {
        $order->cancelled_at = now();
        $order->cancelled_by = 'admin';
        $order->cancel_reason = $reason ? mb_substr($reason, 0, 255) : null;
        $this->giveBack($order, $actor, 'cancelled');

        return $reason ? 'Cancelled: '.$reason : 'Order cancelled';
    }

    /** The shop turns a pending order down. A reason is optional; when given, the customer sees it. */
    private function reject(Order $order, Admin $actor, ?string $reason): string
    {
        $reason = trim((string) $reason);
        $order->rejected_at = now();
        $order->reject_reason = $reason !== '' ? mb_substr($reason, 0, 300) : null;
        $this->giveBack($order, $actor, 'rejected');

        return $reason !== '' ? 'Rejected: '.$order->reject_reason : 'Order rejected';
    }

    /** What cancelling and rejecting have in common: the order is void, so everything it took is given back. */
    private function giveBack(Order $order, Admin $actor, string $word): void
    {
        // stock goes back, row by row in variant order (the same order PlaceOrder locks in)
        $items = $order->items()->get()->sortBy('variant_id');
        $stocks = ProductVariant::query()->whereIn('id', $items->pluck('variant_id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($items as $item) {
            if ($item->status === 'cancelled') {
                continue;
            }
            $stock = $stocks->get($item->variant_id);
            if (! $stock) {
                $item->update(['status' => 'cancelled', 'cancelled_qty' => $item->qty]);   // the option no longer exists: nothing to put back

                continue;
            }
            $stock->stock_qty += $item->qty;
            $stock->save();

            InventoryMovement::create([
                'variant_id' => $item->variant_id, 'type' => 'cancel', 'qty_change' => $item->qty,
                'balance_after' => $stock->stock_qty, 'reference_type' => 'order', 'reference_id' => $order->id,
                'note' => "Order {$order->order_number} {$word}", 'admin_id' => $actor->id,
            ]);
            $item->update(['status' => 'cancelled', 'cancelled_qty' => $item->qty]);
        }

        if ($customer = Customer::query()->whereKey($order->customer_id)->lockForUpdate()->first()) {
            $this->customers->recordOrder($customer, $order->grand_total, placed: false);
        }

        // the coupon use goes back to the coupon (and to the customer's own limit)
        $usage = CouponUsage::query()->where('order_id', $order->id)->whereNull('released_at')->lockForUpdate()->first();
        if ($usage) {
            Coupon::query()->whereKey($usage->coupon_id)->lockForUpdate()->first();
            $usage->update(['released_at' => now()]);
            Coupon::query()->whereKey($usage->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
        }

        // money that was not taken is voided; a bank transfer that was already verified stays paid (the shop refunds it by hand)
        if ($order->payment_status !== PaymentStatus::Paid) {
            $order->payment_status = PaymentStatus::Cancelled;
            Payment::query()->where('payable_type', Payment::ORDER)->where('payable_id', $order->id)
                ->update(['status' => Payment::CANCELLED, 'updated_at' => now()]);
        }

        foreach (Product::query()->whereIn('id', $items->pluck('product_id')->unique())->get() as $product) {
            $this->refreshProduct->handle($product);
        }
    }
}
