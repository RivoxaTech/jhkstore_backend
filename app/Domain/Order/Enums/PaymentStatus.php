<?php

namespace App\Domain\Order\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';          // online payment not completed yet (later)
    case CodPending = 'cod_pending';   // cash on delivery, not collected yet
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';      // the order was cancelled before any money was taken
    case Refunded = 'refunded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
