<?php

namespace App\Domain\Order\Enums;

/** Cash on delivery and bank transfer (the customer uploads a screenshot, the admin verifies it). Card is only a "coming soon" label. */
enum PaymentMethod: string
{
    case Cod = 'cod';
    case BankTransfer = 'bank_transfer';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Cod => 'Cash on delivery',
            self::BankTransfer => 'Bank transfer',
        };
    }
}
