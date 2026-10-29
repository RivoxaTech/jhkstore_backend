<?php

namespace App\Domain\Cart;

/**
 * The fully calculated cart (amounts in cents):
 *   items_total (all lines at MRP) - product_discount (MRP -> option price) - bulk_discount (option price -> bulk price) = subtotal
 *   grand_total = subtotal - coins_discount + delivery_fee      (prices already include sales tax)
 */
final class PricedCart
{
    /**
     * @param  list<PricedLine>  $lines
     * @param  array{requested:int, applied:int, max:int, wallet:int, value:int, limited_by:?string}  $coins
     */
    public function __construct(
        public readonly array $lines,
        public readonly int $itemsTotal,
        public readonly int $productDiscount,
        public readonly int $bulkDiscount,
        public readonly int $subtotal,
        public readonly array $coins,
        public readonly int $coinsDiscount,
        public readonly int $deliveryFee,
        public readonly bool $deliveryFree,
        public readonly int $freeDeliveryMin,
        public readonly int $grandTotal,
        public readonly int $coinsToEarn,
        public readonly int $taxIncluded,
        public readonly int $unitsCount,
        public readonly int $minOrderAmount,
    ) {}

    /** @return list<PricedLine> the lines that take part in the totals */
    public function countedLines(): array
    {
        return array_values(array_filter($this->lines, fn (PricedLine $l) => $l->counted));
    }

    public function hasBlockingIssue(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->isBlocking()) {
                return true;
            }
        }

        return false;
    }

    public function meetsMinimum(): bool
    {
        return $this->subtotal >= $this->minOrderAmount;
    }

    public function totalSavings(): int
    {
        return $this->productDiscount + $this->bulkDiscount + $this->coinsDiscount;
    }

    public function canCheckout(): bool
    {
        return $this->countedLines() !== [] && ! $this->hasBlockingIssue() && $this->meetsMinimum();
    }
}
