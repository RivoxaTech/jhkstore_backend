<?php

namespace App\Domain\Cart;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Support\Settings;
use Illuminate\Support\Collection;

/**
 * THE price calculator of the shop. The quote endpoint and the checkout both use it, so what the customer is shown is what the order gets.
 * The storefront keeps the cart itself (variant ids and quantities); nothing it sends about prices is trusted: every price comes from the
 * option, its bulk prices and the settings.
 *
 *  unit price   = the option price, or the bulk price of the highest tier whose min_qty <= qty
 *  items total  = sum(MRP x qty)           product discount = sum((MRP - option price) x qty)
 *  bulk offer   = sum((option price - unit price) x qty)       subtotal = items total - product discount - bulk offer
 *  delivery     = one flat fee; free when the subtotal reaches free_delivery_min_cart (0 = never free); nothing for an empty order
 *  grand total  = subtotal + delivery (+ tax when prices are tax exclusive)
 */
class CartPricing
{
    public function __construct(private readonly Settings $settings) {}

    /** Relations the calculation needs. */
    public static function relations(): array
    {
        return [
            // a product deleted after the customer added it must still load (the line is then reported as unavailable)
            'product' => fn ($q) => $q->withTrashed()->select(['id', 'name', 'slug', 'sku', 'status', 'tax_rate', 'min_order_qty', 'max_order_qty', 'is_cod_available']),
            'product.media', 'product.remoteImages',
            'priceTiers',
        ];
    }

    /**
     * Prices the lines the storefront sent. Lines of the same option are added up; an unknown option id is reported as unavailable.
     *
     * @param  list<array{variant_id:int|string, qty:int|string}>  $items
     * @param  Collection<int, ProductVariant>|null  $locked  variant_id => freshly locked row, when the caller holds the row locks (checkout)
     */
    public function forItems(array $items, ?Collection $locked = null): PricedCart
    {
        $qtys = [];
        foreach ($items as $item) {
            $id = (int) $item['variant_id'];
            $qtys[$id] = ($qtys[$id] ?? 0) + (int) $item['qty'];
        }

        $variants = ProductVariant::query()->whereIn('id', array_keys($qtys))->with(self::relations())->get()->keyBy('id');
        $productIds = $variants->pluck('product_id')->unique()->values();
        $visible = $productIds->isEmpty() ? [] : Product::query()->storefront()->whereIn('products.id', $productIds)->pluck('products.id')->all();

        $lines = [];
        foreach ($qtys as $id => $qty) {
            $variant = $variants->get($id);
            if ($variant) {
                $lines[] = ['variant' => $variant, 'qty' => $qty];
            }
        }

        return $this->price($lines, $visible, $locked, array_values(array_diff(array_keys($qtys), $variants->keys()->all())));
    }

    /**
     * @param  list<array{variant: ProductVariant, qty:int}>  $items  variants with CartPricing::relations() loaded
     * @param  list<int>  $visibleProductIds  products the website currently shows
     * @param  list<int>  $unknownVariantIds  option ids that do not exist (they block the order)
     */
    public function price(array $items, array $visibleProductIds, ?Collection $locked = null, array $unknownVariantIds = []): PricedCart
    {
        $inclusive = (bool) $this->settings->get('tax_inclusive');
        $lines = [];

        foreach ($items as ['variant' => $variant, 'qty' => $qty]) {
            $product = $variant->product;
            $stock = $locked ? $locked->get($variant->id) : $variant;   // the row that holds the stock numbers

            $mrp = $this->settings->cents($variant->mrp);
            $base = $this->settings->cents($variant->selling_price);
            [$bulk, $next] = $this->tiers($variant->priceTiers, $base, $qty, (int) $product->max_order_qty);
            $unit = $bulk['price'] ?? $base;

            $sellable = $variant->is_active && in_array($product->id, $visibleProductIds, true) && $stock;
            $available = $sellable ? max(0, (int) $stock->stock_qty - (int) $stock->reserved_qty) : 0;
            $name = $variant->name ?: $product->name;
            $issues = [];

            if (! $sellable) {
                $issues[] = $this->issue('unavailable', "\"$name\" is no longer available.", true);
            } elseif ($available === 0) {
                $issues[] = $this->issue('out_of_stock', "\"$name\" is out of stock.", true);
            } elseif ($qty > $available) {
                $issues[] = $this->issue('insufficient_stock', "Only $available left in stock for \"$name\".", true);
            } elseif ($stock->low_stock_threshold > 0 && $available <= $stock->low_stock_threshold) {
                $issues[] = $this->issue('low_stock', "Only $available left.", false);
            }
            if ($sellable && $available > 0) {
                if ($qty < $product->min_order_qty) {
                    $issues[] = $this->issue('below_min_qty', "The minimum order quantity of \"$name\" is {$product->min_order_qty}.", true);
                }
                if ($qty > $product->max_order_qty) {
                    $issues[] = $this->issue('above_max_qty', "You can buy at most {$product->max_order_qty} of \"$name\".", true);
                }
            }

            $lineTotal = $unit * $qty;
            $taxRate = $product->tax_rate !== null ? (float) $product->tax_rate : (float) $this->settings->get('tax_rate');
            $tax = $inclusive ? (int) round($lineTotal * $taxRate / (100 + $taxRate)) : (int) round($lineTotal * $taxRate / 100);

            $lines[] = new PricedLine($variant, $product, $qty, $mrp, $base, $unit, $bulk, $next, $available, $sellable && $available > 0, $issues, $taxRate, $tax);
        }

        $counted = array_filter($lines, fn (PricedLine $l) => $l->counted);
        $itemsTotal = array_sum(array_map(fn (PricedLine $l) => $l->mrpTotal(), $counted));
        $productDiscount = array_sum(array_map(fn (PricedLine $l) => $l->productDiscount(), $counted));
        $bulkDiscount = array_sum(array_map(fn (PricedLine $l) => $l->bulkDiscount(), $counted));
        $subtotal = $itemsTotal - $productDiscount - $bulkDiscount;

        $taxTotal = array_sum(array_map(fn (PricedLine $l) => $l->tax(), $counted));
        $freeMin = $this->settings->freeDeliveryMinCents();
        $deliveryFree = $counted !== [] && $freeMin > 0 && $subtotal >= $freeMin;
        $deliveryFee = ($counted === [] || $deliveryFree) ? 0 : $this->settings->deliveryFeeCents();

        return new PricedCart(
            lines: $lines,
            itemsTotal: $itemsTotal,
            productDiscount: $productDiscount,
            bulkDiscount: $bulkDiscount,
            subtotal: $subtotal,
            deliveryFee: $deliveryFee,
            deliveryFree: $deliveryFree,
            freeDeliveryMin: $freeMin,
            grandTotal: $subtotal + $deliveryFee + ($inclusive ? 0 : $taxTotal),
            taxTotal: $taxTotal,
            taxInclusive: $inclusive,
            unitsCount: array_sum(array_map(fn (PricedLine $l) => $l->qty, $counted)),
            minOrderAmount: $this->settings->minOrderCents(),
            unknownVariantIds: $unknownVariantIds,
        );
    }

    /**
     * The bulk price in use and the next one in reach.
     *
     * @return array{0: ?array{min_qty:int, price:int}, 1: ?array{min_qty:int, price:int, add_qty:int}}
     */
    private function tiers(Collection $tiers, int $base, int $qty, int $maxQty): array
    {
        $bulk = null;
        $next = null;

        foreach ($tiers->sortBy('min_qty') as $tier) {
            $price = $this->settings->cents($tier->price);
            if ($price >= $base) {
                continue; // a tier that is not cheaper than the option price is no offer
            }
            if ($tier->min_qty <= $qty) {
                $bulk = ['min_qty' => (int) $tier->min_qty, 'price' => $price]; // the highest tier whose min_qty <= qty wins
            } elseif ($next === null && $tier->min_qty <= $maxQty) {
                $next = ['min_qty' => (int) $tier->min_qty, 'price' => $price, 'add_qty' => (int) $tier->min_qty - $qty];
            }
        }

        return [$bulk, $next];
    }

    private function issue(string $code, string $message, bool $blocking): array
    {
        return ['code' => $code, 'message' => $message, 'blocking' => $blocking];
    }

    /** "$ 152" / "$ 1,520.50" */
    public function fmt(int $cents, ?string $symbol = null): string
    {
        $symbol ??= $this->settings->currencySymbol();
        $decimals = $this->settings->decimals();
        $whole = $cents % (10 ** $decimals) === 0;   // "$ 152" when there is nothing after the point, "$ 1,520.50" otherwise
        $amount = number_format($this->settings->money($cents), $whole ? 0 : $decimals);

        return "$symbol $amount";
    }
}
