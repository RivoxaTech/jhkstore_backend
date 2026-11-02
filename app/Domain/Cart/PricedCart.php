<?php

namespace App\Domain\Cart;

/**
 * The fully calculated order (amounts in cents):
 *   items_total (all lines at MRP) - product_discount (MRP -> option price) - bulk_discount (option price -> bulk price) = subtotal
 *   grand_total = subtotal + delivery_fee (+ tax_total when prices are tax exclusive)
 */
final class PricedCart
{
    /**
     * @param  list<PricedLine>  $lines
     * @param  list<int>  $unknownVariantIds  option ids that were asked for but do not exist
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

    public function meetsMinimum(): bool
    {
        return $this->subtotal >= $this->minOrderAmount;
    }

    public function totalSavings(): int
    {
        return $this->productDiscount + $this->bulkDiscount;
    }

    public function canCheckout(): bool
    {
        return $this->countedLines() !== [] && ! $this->hasBlockingIssue() && $this->meetsMinimum();
    }
}
