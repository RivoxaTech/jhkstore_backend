<?php

namespace App\Mail;

/** Customer e-mail: We received your order. */
class OrderPlacedMail extends OrderMail
{
    protected function template(): string
    {
        return 'order-placed';
    }

    protected function title(): string
    {
        return 'We received your order';
    }
}
