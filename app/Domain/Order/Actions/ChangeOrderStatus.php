<?php

namespace App\Domain\Order\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Auth\Models\User;
use App\Domain\Catalog\Actions\RefreshProductSummary;
use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentStatus;
use App\Domain\Order\Exceptions\CommerceException;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderStatusHistory;
use App\Domain\Order\Models\Payment;
use App\Domain\Order\Models\Shipment;
use App\Domain\Reward\Actions\RewardLedger;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * The only way an order changes status (admin panel and customer cancel). One transaction per change, the order row is locked.
 *
 *   pending -> confirmed -> packed -> shipped -> delivered        one step at a time, nobody can skip a step
 *   pending | confirmed | packed -> cancelled                     admin or the customer; a shipped parcel cannot be cancelled
 *   the customer may only cancel; the admin may do every allowed step
 *
 * Side effects:  shipped = courier + AWB are required and a shipment is created   delivered = COD payment is marked paid, earned coins are credited
 * cancelled = stock goes back (movement `cancel`), redeemed coins are refunded, the payment is voided.
 * Every change adds a history row (who, when, note) and an audit-log entry.
 */
class ChangeOrderStatus
{
    public function __construct(private readonly RewardLedger $ledger, private readonly RefreshProductSummary $refreshProduct) {}

    /** @param  array{note?:?string, reason?:?string, courier?:?string, awb_number?:?string, tracking_url?:?string}  $data */
    public function handle(Order $order, OrderStatus $to, Admin|User $actor, array $data = []): Order
    {
        return DB::transaction(function () use ($order, $to, $actor, $data) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $order->status;

            if ($actor instanceof User && ($order->user_id !== $actor->id || $to !== OrderStatus::Cancelled)) {
                throw new CommerceException('forbidden', 'You cannot change this order.', 'status', 403);
            }
            if (! $from->canMoveTo($to)) {
                $message = $to === OrderStatus::Cancelled
                    ? "This order is {$from->value} and can no longer be cancelled."
                    : "An order that is {$from->value} cannot be moved to {$to->value}.";
                throw new CommerceException('invalid_status_change', $message, 'status');
            }

            $note = $data['note'] ?? null;

            match ($to) {
                OrderStatus::Confirmed => $order->confirmed_at = now(),
                OrderStatus::Shipped => $note = $this->ship($order, $data),
                OrderStatus::Delivered => $this->deliver($order),
                OrderStatus::Cancelled => $note = $this->cancel($order, $actor, $data['reason'] ?? $note),
                default => null,
            };

            $order->status = $to;
            $order->save();

            OrderStatusHistory::create([
                'order_id' => $order->id, 'from_status' => $from->value, 'to_status' => $to->value, 'note' => $note ? mb_substr($note, 0, 255) : null,
                'changed_by_type' => $actor instanceof Admin ? 'admin' : 'customer', 'changed_by_id' => $actor->id, 'is_customer_visible' => true,
            ]);
            Audit::log($actor, $to === OrderStatus::Cancelled ? 'order.cancelled' : 'order.status_changed', $order, ['from' => $from->value, 'to' => $to->value, 'note' => $note]);

            return $order->load(['items', 'addresses', 'history', 'payment', 'shipments']);
        });
    }

    /** Courier and AWB are required; one shipment holds them. Returns the note for the timeline. */
    private function ship(Order $order, array $data): string
    {
        $courier = trim((string) ($data['courier'] ?? ''));
        $awb = trim((string) ($data['awb_number'] ?? ''));
        if ($courier === '' || $awb === '') {
            throw new CommerceException('shipping_details_required', 'Enter the courier and the AWB / tracking number to ship this order.', 'courier', 422);
        }

        Shipment::create([
            'order_id' => $order->id, 'shipment_number' => 'SH-'.$order->order_number, 'carrier' => $courier, 'awb_number' => $awb,
            'tracking_url' => $data['tracking_url'] ?? null, 'status' => 'in_transit', 'shipped_at' => now(),
            'estimated_delivery_date' => $order->estimated_delivery_date,
        ]);
        $order->shipped_at = now();
        $order->fulfillment_status = 'fulfilled';

        return "Shipped via $courier, AWB $awb";
    }

    private function deliver(Order $order): void
    {
        $order->delivered_at = now();

        // cash on delivery: the money is collected by the courier at the door
        if ($order->payment_status === PaymentStatus::CodPending) {
            $order->payment_status = PaymentStatus::Paid;
            Payment::query()->where('payable_type', Payment::ORDER)->where('payable_id', $order->id)
                ->update(['status' => Payment::COD_COLLECTED, 'paid_at' => now(), 'updated_at' => now()]);
        }
        Shipment::query()->where('order_id', $order->id)->update(['status' => 'delivered', 'delivered_at' => now(), 'updated_at' => now()]);

        $this->ledger->credit($order);   // coins earned are credited on delivery, never on placing
    }

    private function cancel(Order $order, Admin|User $actor, ?string $reason): ?string
    {
        $order->cancelled_at = now();
        $order->cancelled_by = $actor instanceof Admin ? 'admin' : 'customer';
        $order->cancel_reason = $reason ? mb_substr($reason, 0, 255) : null;

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
                'note' => "Order {$order->order_number} cancelled", 'admin_id' => $actor instanceof Admin ? $actor->id : null,
            ]);
            $item->update(['status' => 'cancelled', 'cancelled_qty' => $item->qty]);
        }

        $this->ledger->refund($order);

        $order->payment_status = PaymentStatus::Cancelled;
        Payment::query()->where('payable_type', Payment::ORDER)->where('payable_id', $order->id)
            ->update(['status' => Payment::CANCELLED, 'updated_at' => now()]);

        foreach (Product::query()->whereIn('id', $items->pluck('product_id')->unique())->get() as $product) {
            $this->refreshProduct->handle($product);
        }

        return $reason ? 'Cancelled: '.$reason : 'Order cancelled';
    }
}
