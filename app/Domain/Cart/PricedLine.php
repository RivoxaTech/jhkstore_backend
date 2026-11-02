<?php

namespace App\Domain\Cart;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;

/**
 * One order line after the server worked out its price (amounts in cents). Built by CartPricing, read by the quote resource and by PlaceOrder.
 * `counted` = the line takes part in the totals. A line that cannot be sold at all (switched off, out of stock) stays visible with a
 * warning but is not counted; a line whose quantity is wrong (too many, too few, more than the stock) is counted but blocks the checkout.
 */
final class PricedLine
{
    /**
     * @param  array{min_qty:int, price:int}|null  $bulk  the bulk price in use ("Buy 5+")
     * @param  array{min_qty:int, price:int, add_qty:int}|null  $nextOffer  the next bulk price the customer could reach
     * @param  list<array{code:string, message:string, blocking:bool}>  $issues
     */
    public function __construct(
        public readonly ProductVariant $variant,
        public readonly Product $product,
        public readonly int $qty,
        public readonly int $mrp,
        public readonly int $basePrice,
        public readonly int $unitPrice,
        public readonly ?array $bulk,
        public readonly ?array $nextOffer,
        public readonly int $available,
        public readonly bool $counted,
        public readonly array $issues,
        public readonly float $taxRate = 0.0,
        public readonly int $tax = 0,
    ) {}

    public function lineTotal(): int
    {
        return $this->unitPrice * $this->qty;
    }

    public function mrpTotal(): int
    {
        return $this->mrp * $this->qty;
    }

    /** MRP -> the option's own price, for the whole line. */
    public function productDiscount(): int
    {
        return ($this->mrp - $this->basePrice) * $this->qty;
    }

    /** The option's own price -> the bulk price, for the whole line. */
    public function bulkDiscount(): int
    {
        return ($this->basePrice - $this->unitPrice) * $this->qty;
    }

    /** The tax of the line: already inside the total when prices include tax, added on top otherwise (see CartPricing). */
    public function tax(): int
    {
        return $this->tax;
    }

    public function isBlocking(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue['blocking']) {
                return true;
            }
        }

        return false;
    }
}
