<?php

namespace App\Mail;

/** Customer e-mail: Your order is confirmed. */
class OrderConfirmedMail extends OrderMail
{
    protected function template(): string
    {
        return 'order-confirmed';
    }

    protected function title(): string
    {
        return 'Your order is confirmed';
    }
}
