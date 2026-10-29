<?php

namespace App\Domain\Order\Enums;

/** Online methods (card, wallet ...) plug in here later; only cash on delivery exists today. */
enum PaymentMethod: string
{
    case Cod = 'cod';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Cod => 'Cash on delivery',
        };
    }
}
