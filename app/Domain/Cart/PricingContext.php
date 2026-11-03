<?php

namespace App\Domain\Cart;

/**
 * What CartPricing needs to know besides the items: the coupon code, the address (city, area, postcode: delivery zones) and the customer's
 * normalised phone (per-customer and first-order coupon rules; unknown in a quote, so those two rules are only checked when it is given).
 * `lockCoupon` = read the coupon row FOR UPDATE (only the order transaction does, so two orders cannot take the last use together).
 */
final class PricingContext
{
    /** @param  array{city?:?string, area?:?string, postcode?:?string}  $address */
    public function __construct(
        public readonly ?string $couponCode = null,
        public readonly array $address = [],
        public readonly ?string $phone = null,
        public readonly bool $lockCoupon = false,
    ) {}
}
