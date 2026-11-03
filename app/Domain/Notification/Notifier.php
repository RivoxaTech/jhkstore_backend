<?php

namespace App\Domain\Notification;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Support\Settings;
use App\Domain\Wholesale\Models\WholesaleRequest;
use App\Mail\OrderCancelledMail;
use App\Mail\OrderConfirmedMail;
use App\Mail\OrderDeliveredMail;
use App\Mail\OrderFeeConfirmedMail;
use App\Mail\OrderPlacedMail;
use App\Mail\OrderShippedMail;
use App\Mail\OwnerAlertMail;
use App\Mail\PaymentProofRejectedMail;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The one place that tells people about something that happened: the customer by e-mail, the store owner by e-mail, the admins by browser push.
 * Called by the actions AFTER their transaction has committed. Every step is isolated: a mail server that is down, a missing queue table or a
 * push service that refuses never fails or rolls back an order (the error is logged and the next step still runs).
 *
 * Mail goes through the queue (see App\Mail). Customer mails need `mail_enabled`, the switch of the event and an e-mail on the order; owner
 * alerts need `mail_enabled` and `notify_admin_email`; push needs `push_enabled` and the VAPID keys. Push is sent to admins who hold
 * `orders.view` (orders, payment proofs) or `wholesale.view` (wholesale requests): never to the developer account for orders.
 */
class Notifier
{
    public function __construct(private readonly Settings $settings) {}

    // ------------------------------------------------------------------ events

    public function orderPlaced(Order $order): void
    {
        $this->customerMail($order, 'mail_on_placed', fn () => new OrderPlacedMail($order));
        $this->ownerAlert(OwnerAlertMail::NEW_ORDER, $order);
        $this->push('orders.view', 'New order '.$order->order_number, $this->total($order), $order, 'order-'.$order->id);
    }

    /** The status the order has just moved to (pending is the start, not a move). */
    public function orderStatusChanged(Order $order): void
    {
        match ($order->status) {
            OrderStatus::Confirmed => $this->customerMail($order, 'mail_on_confirmed', fn () => new OrderConfirmedMail($order)),
            OrderStatus::Shipped => $this->customerMail($order, 'mail_on_shipped', fn () => new OrderShippedMail($order)),
            OrderStatus::Delivered => $this->customerMail($order, 'mail_on_delivered', fn () => new OrderDeliveredMail($order)),
            OrderStatus::Cancelled, OrderStatus::Rejected => $this->customerMail($order, 'mail_on_cancelled', fn () => new OrderCancelledMail($order)),
            default => null,
        };
    }

    /** The owner set the delivery fee of an order that was placed without one: the customer gets the final total. Follows `mail_on_placed`. */
    public function deliveryFeeConfirmed(Order $order): void
    {
        $this->customerMail($order, 'mail_on_placed', fn () => new OrderFeeConfirmedMail($order));
    }

    /** The customer uploaded a payment screenshot: the owner is told to check it. */
    public function proofSubmitted(Order $order): void
    {
        $this->ownerAlert(OwnerAlertMail::PAYMENT_PROOF, $order);
        $this->push('orders.view', 'Payment proof to check', $order->order_number.' · '.$this->total($order), $order, 'proof-'.$order->id);
    }

    /** The shop sent the screenshot back: the customer is asked to upload a new one. */
    public function proofRejected(Order $order): void
    {
        $this->customerMail($order, 'mail_on_payment_proof_rejected', fn () => new PaymentProofRejectedMail($order));
    }

    public function wholesaleRequested(WholesaleRequest $request): void
    {
        $this->ownerAlert(OwnerAlertMail::WHOLESALE, $request);
        $this->safely('push', function () use ($request) {
            $this->dispatchPush('wholesale.view', ['title' => 'New wholesale request', 'body' => $request->reference.' · '.$request->quantity.' × '.$request->product_name, 'url' => '/admin/wholesale', 'tag' => 'wholesale-'.$request->id]);
        });
    }

    // ------------------------------------------------------------------ channels

    private function customerMail(Order $order, string $switch, Closure $make): void
    {
        $this->safely('customer mail '.$switch, function () use ($order, $switch, $make) {
            if (! $this->settings->get('mail_enabled') || ! $this->settings->get($switch) || trim((string) $order->customer_email) === '') {
                return;
            }
            Mail::to($order->customer_email)->queue($make());
        });
    }

    private function ownerAlert(string $kind, Order|WholesaleRequest $about): void
    {
        $this->safely('owner alert '.$kind, function () use ($kind, $about) {
            $to = trim((string) $this->settings->get('notify_admin_email'));
            if (! $this->settings->get('mail_enabled') || $to === '') {
                return;
            }
            Mail::to($to)->queue(new OwnerAlertMail($kind, $about));
        });
    }

    private function push(string $permission, string $title, string $body, Order $order, string $tag): void
    {
        $this->safely('push', fn () => $this->dispatchPush($permission, ['title' => $title, 'body' => $body, 'url' => '/admin/orders/'.$order->id, 'tag' => $tag]));
    }

    private function dispatchPush(string $permission, array $message): void
    {
        if (! $this->settings->get('push_enabled') || ! WebPushSender::configured()) {
            return;
        }
        SendPushNotification::dispatch($permission, $message);
    }

    private function total(Order $order): string
    {
        return $this->settings->currencySymbol().number_format((float) $order->grand_total, $this->settings->decimals());
    }

    /** Runs one step; whatever it throws is logged and swallowed. */
    private function safely(string $what, Closure $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            Log::warning("Notification [$what] failed: ".$e->getMessage());
        }
    }
}
