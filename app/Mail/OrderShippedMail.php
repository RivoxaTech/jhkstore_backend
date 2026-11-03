<?php

namespace App\Mail;

/** Customer e-mail: the order is on its way, with the courier and the tracking number. */
class OrderShippedMail extends OrderMail
{
    protected function template(): string
    {
        return 'order-shipped';
    }

    protected function title(): string
    {
        return 'Your order has shipped';
    }

    protected function extra(): array
    {
        $this->order->loadMissing('shipments');

        return ['shipment' => $this->order->shipments->last()];
    }
}
