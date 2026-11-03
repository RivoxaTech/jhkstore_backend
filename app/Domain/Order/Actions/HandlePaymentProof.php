<?php

namespace App\Domain\Order\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Notification\Notifier;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Enums\PaymentStatus;
use App\Domain\Order\Exceptions\CommerceException;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderStatusHistory;
use App\Domain\Order\Models\Payment;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The bank transfer flow of one order:
 *
 *   customer  submit()   uploads the screenshot of the transfer (private disk) + an optional reference -> proof_submitted
 *   admin     verify()   the money arrived: proof verified, payment and order paid, the order may now be confirmed
 *   admin     reject()   the proof is not good (reason): the customer uploads a new one -> awaiting_proof
 *
 * Until the payment is verified the order cannot move past `pending` (see ChangeOrderStatus). Every step writes a timeline row the customer
 * can see and an audit entry. The screenshot is never reachable by a public URL.
 */
class HandlePaymentProof
{
    public function __construct(private readonly Notifier $notifier) {}

    public function submit(Order $order, UploadedFile $proof, ?string $reference): Order
    {
        $submitted = DB::transaction(function () use ($order, $proof, $reference) {
            [$order, $payment] = $this->lock($order);

            if ($order->status->isVoid() || $order->status !== OrderStatus::Pending) {
                throw new CommerceException('order_closed', 'This order is already being processed, a payment proof can no longer be added.', 'proof');
            }
            if ($payment->proof_status === Payment::PROOF_SUBMITTED_STATUS) {
                throw new CommerceException('proof_already_submitted', 'Your payment proof was received and is being checked.', 'proof');
            }
            if ($payment->proof_status === Payment::PROOF_VERIFIED) {
                throw new CommerceException('payment_already_verified', 'This payment is already verified.', 'proof');
            }

            $payment->addMedia($proof)->toMediaCollection('proof');   // single file: a replaced (rejected) proof is deleted
            $payment->forceFill([
                'status' => Payment::PROOF_SUBMITTED, 'proof_status' => Payment::PROOF_SUBMITTED_STATUS,
                'proof_reference' => $reference !== null && trim($reference) !== '' ? mb_substr(trim($reference), 0, 120) : null,
                'proof_reject_reason' => null,
            ])->save();

            $this->step($order, 'Payment proof uploaded', 'customer', $order->customer_id);
            Audit::log(null, 'order.payment_proof_submitted', $order);

            return $order->load(['items', 'addresses', 'history', 'payment', 'shipments']);
        });

        $this->notifier->proofSubmitted($submitted);   // the owner is told; after the commit, never fails the upload

        return $submitted;
    }

    public function verify(Order $order, Admin $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            [$order, $payment] = $this->lock($order);

            if ($payment->proof_status === Payment::PROOF_VERIFIED) {
                throw new CommerceException('payment_already_verified', 'This payment is already verified.', 'payment');
            }
            if ($order->status->isVoid()) {
                throw new CommerceException('order_closed', 'This order is '.$order->status->value.'.', 'payment');
            }
            if ($payment->proof_status !== Payment::PROOF_SUBMITTED_STATUS) {
                throw new CommerceException('no_proof_submitted', 'There is no payment proof to verify yet.', 'payment');
            }

            $payment->forceFill([
                'status' => Payment::PAID, 'proof_status' => Payment::PROOF_VERIFIED, 'paid_at' => now(), 'verified_at' => now(), 'verified_by' => $actor->id,
            ])->save();
            $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();

            $this->step($order, 'Payment verified', 'admin', $actor->id);
            Audit::log($actor, 'order.payment_verified', $order);

            return $order->load(['items', 'addresses', 'history', 'payment', 'shipments']);
        });
    }

    public function reject(Order $order, Admin $actor, string $reason): Order
    {
        $rejected = DB::transaction(function () use ($order, $actor, $reason) {
            [$order, $payment] = $this->lock($order);

            if ($payment->proof_status !== Payment::PROOF_SUBMITTED_STATUS) {
                throw new CommerceException('no_proof_submitted', 'There is no payment proof to reject.', 'payment');
            }

            $reason = mb_substr(trim($reason), 0, 300);
            $payment->forceFill(['status' => Payment::AWAITING_PROOF, 'proof_status' => Payment::PROOF_REJECTED, 'proof_reject_reason' => $reason])->save();

            $this->step($order, 'Payment proof rejected: '.$reason, 'admin', $actor->id);
            Audit::log($actor, 'order.payment_proof_rejected', $order, ['reason' => $reason]);

            return $order->load(['items', 'addresses', 'history', 'payment', 'shipments']);
        });

        $this->notifier->proofRejected($rejected);   // the customer is asked for a new screenshot

        return $rejected;
    }

    /** @return array{0: Order, 1: Payment} the order and its bank transfer payment, both locked */
    private function lock(Order $order): array
    {
        $order = Order::query()->lockForUpdate()->findOrFail($order->id);
        $payment = Payment::query()->where('payable_type', Payment::ORDER)->where('payable_id', $order->id)->lockForUpdate()->first();
        if ($order->payment_method !== PaymentMethod::BankTransfer->value || ! $payment) {
            throw new CommerceException('not_bank_transfer', 'This order is not paid by bank transfer.', 'payment');
        }

        return [$order, $payment];
    }

    private function step(Order $order, string $note, string $byType, ?int $byId): void
    {
        OrderStatusHistory::create([
            'order_id' => $order->id, 'from_status' => $order->status->value, 'to_status' => $order->status->value, 'note' => mb_substr($note, 0, 255),
            'changed_by_type' => $byType, 'changed_by_id' => $byId, 'is_customer_visible' => true,
        ]);
    }
}
