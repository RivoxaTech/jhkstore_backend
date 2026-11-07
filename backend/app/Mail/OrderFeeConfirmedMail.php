<?php

namespace App\Mail;

/** Customer e-mail: the delivery fee was confirmed, here is the final total. */
class OrderFeeConfirmedMail extends OrderMail
{
    protected function template(): string
    {
        return 'order-fee-confirmed';
    }

    protected function title(): string
    {
        return 'Delivery fee confirmed';
    }
}
