<?php

namespace App\Mail;

/** Customer e-mail: the payment screenshot was not accepted, please upload a new one. */
class PaymentProofRejectedMail extends OrderMail
{
    protected function template(): string
    {
        return 'payment-proof-rejected';
    }

    protected function title(): string
    {
        return 'Please send your payment proof again';
    }

    protected function extra(): array
    {
        $this->order->loadMissing('payment');

        return ['reason' => $this->order->payment?->proof_reject_reason];
    }
}
