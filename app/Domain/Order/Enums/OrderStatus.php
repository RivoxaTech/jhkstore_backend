<?php

namespace App\Domain\Order\Enums;

/**
 * pending -> confirmed -> packed -> shipped -> delivered, plus cancelled (only before shipping).
 * `returned` is reserved for the returns module (a delivered order can move there later).
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Packed = 'packed';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Statuses an order can move to from this one (a single forward step, or cancel while it has not shipped). */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Packed, self::Cancelled],
            self::Packed => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered],
            self::Delivered => [],      // -> returned will come with the returns module
            self::Cancelled, self::Returned => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** The customer (and the admin) may cancel only before the parcel leaves. */
    public function isCancellable(): bool
    {
        return $this->canMoveTo(self::Cancelled);
    }

    /** The status the order normally moves to next (the button label in the admin panel). */
    public function forwardStep(): ?self
    {
        foreach ($this->next() as $to) {
            if ($to !== self::Cancelled) {
                return $to;
            }
        }

        return null;
    }
}
