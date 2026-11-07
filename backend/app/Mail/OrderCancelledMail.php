<?php

namespace App\Mail;

/** Customer e-mail: the order was cancelled or rejected, with the reason. */
class OrderCancelledMail extends OrderMail
{
    protected function template(): string
    {
        return 'order-cancelled';
    }

    protected function title(): string
    {
        return $this->order->status->value === 'rejected' ? 'Your order could not be accepted' : 'Your order was cancelled';
    }

    protected function extra(): array
    {
        $rejected = $this->order->status->value === 'rejected';

        return ['rejected' => $rejected, 'reason' => $rejected ? $this->order->reject_reason : $this->order->cancel_reason];
    }
}
