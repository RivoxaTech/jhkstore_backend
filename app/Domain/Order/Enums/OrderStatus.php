<?php

namespace App\Domain\Order\Enums;

/**
 * pending -> confirmed, then any later step: packed, shipped or delivered directly (the shop may skip steps once it has accepted the order), plus cancelled
 * (only before shipping) and rejected (only from pending). A pending order is accepted (confirmed) first.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Packed = 'packed';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Statuses an order can move to from this one (any later step once it is confirmed, or cancel while it has not shipped). */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled, self::Rejected],
            self::Confirmed => [self::Packed, self::Shipped, self::Delivered, self::Cancelled],
            self::Packed => [self::Shipped, self::Delivered, self::Cancelled],
            self::Shipped => [self::Delivered],
            self::Delivered, self::Cancelled, self::Rejected => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** An order may be cancelled only before the parcel leaves. */
    public function isCancellable(): bool
    {
        return $this->canMoveTo(self::Cancelled);
    }

    /** The shop turns an order down: only while it is still pending. */
    public function isRejectable(): bool
    {
        return $this->canMoveTo(self::Rejected);
    }

    /** Cancelled and rejected orders: the stock, the customer's totals and the coupon use were given back, nothing can change any more. */
    public function isVoid(): bool
    {
        return $this === self::Cancelled || $this === self::Rejected;
    }

    /** The status the order normally moves to next (the button label in the admin panel). */
    public function forwardStep(): ?self
    {
        foreach ($this->next() as $to) {
            if ($to !== self::Cancelled && $to !== self::Rejected) {
                return $to;
            }
        }

        return null;
    }
}
