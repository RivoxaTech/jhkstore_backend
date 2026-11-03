<?php

namespace App\Domain\Cart;

/**
 * The fully calculated order (amounts in cents):
 *   items_total (all lines at MRP) - product_discount (MRP -> option price) - bulk_discount (option price -> bulk price)
 *   - discount_total (automatic discounts) - coupon_discount = subtotal
 *   grand_total = subtotal + delivery_fee (+ tax_total when prices are tax exclusive)
 * `deliveryFee` is 0 while it is not known (`deliveryPending` = the owner confirms it later, `deliveryNeedsAddress` = the zone needs an address).
 */
final class PricedCart
{
    /**
     * @param  list<PricedLine>  $lines
     * @param  list<int>  $unknownVariantIds  option ids that were asked for but do not exist
     * @param  array{id:int, name:string, eta_text:?string}|null  $deliveryZone
     * @param  array{code:string, message:string}|null  $couponError
     */
    public function __construct(
        public readonly array $lines,
        public readonly int $itemsTotal,
        public readonly int $productDiscount,
        public readonly int $bulkDiscount,
        public readonly int $subtotal,
        public readonly int $deliveryFee,
        public readonly bool $deliveryFree,
        public readonly int $freeDeliveryMin,
        public readonly int $grandTotal,
        public readonly int $taxTotal,
        public readonly bool $taxInclusive,
        public readonly int $unitsCount,
        public readonly int $minOrderAmount,
        public readonly array $unknownVariantIds = [],
        public readonly int $discountTotal = 0,
        public readonly int $couponDiscount = 0,
        public readonly ?string $couponCode = null,        // the code that was applied (null = none, or it was refused: see couponError)
        public readonly ?int $couponId = null,
        public readonly bool $couponFreeDelivery = false,
        public readonly ?array $couponError = null,
        public readonly int $subtotalBeforeCoupon = 0,     // items after bulk prices and automatic discounts: what the minimum order is checked against
        public readonly string $deliveryMethod = 'flat',
        public readonly bool $deliveryPending = false,
        public readonly bool $deliveryNeedsAddress = false,
        public readonly bool $deliveryUnavailable = false,  // the address is outside every zone and the store refuses those
        public readonly ?array $deliveryZone = null,
        public readonly ?int $deliveryZoneId = null,
        public readonly ?string $deliveryEtaText = null,
        public readonly bool $couponRequested = false,
    ) {}

    /** @return list<PricedLine> the lines that take part in the totals */
    public function countedLines(): array
    {
        return array_values(array_filter($this->lines, fn (PricedLine $l) => $l->counted));
    }

    public function hasBlockingIssue(): bool
    {
        if ($this->unknownVariantIds !== []) {
            return true;
        }
        foreach ($this->lines as $line) {
            if ($line->isBlocking()) {
                return true;
            }
        }

        return false;
    }

    /** The minimum order is checked after bulk prices and automatic discounts, not after a coupon (a coupon never makes an order too small). */
    public function meetsMinimum(): bool
    {
        return $this->subtotalBeforeCoupon >= $this->minOrderAmount;
    }

    public function totalSavings(): int
    {
        return $this->productDiscount + $this->bulkDiscount + $this->discountTotal + $this->couponDiscount;
    }

    /** Is the delivery fee a known number? */
    public function deliveryFeeKnown(): bool
    {
        return ! $this->deliveryPending && ! $this->deliveryNeedsAddress && ! $this->deliveryUnavailable;
    }

    public function canCheckout(): bool
    {
        return $this->countedLines() !== [] && ! $this->hasBlockingIssue() && $this->meetsMinimum() && ! $this->deliveryUnavailable;
    }
}
