<?php

namespace App\Mail;

/** Customer e-mail: Your order was delivered. */
class OrderDeliveredMail extends OrderMail
{
    protected function template(): string
    {
        return 'order-delivered';
    }

    protected function title(): string
    {
        return 'Your order was delivered';
    }
}
