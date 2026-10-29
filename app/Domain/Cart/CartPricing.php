<?php

namespace App\Domain\Cart;

use App\Domain\Auth\Models\User;
use App\Domain\Cart\Models\Cart;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\SalesChannel;
use App\Domain\Catalog\Models\VariantChannelStock;
use App\Domain\Reward\Actions\RewardLedger;
use App\Domain\Support\CommerceSettings;
use Illuminate\Support\Collection;

/**
 * THE price calculator of the shop. The cart screen and the checkout both use it, so what the customer sees is what the order gets.
 * Nothing the client sends is trusted: every price comes from the option, its bulk prices and the settings.
 *
 *  unit price   = the option price, or the bulk price of the highest tier whose min_qty <= qty
 *  items total  = sum(MRP x qty)           product discount = sum((MRP - option price) x qty)
 *  bulk offer   = sum((option price - unit price) x qty)       subtotal = items total - product discount - bulk offer
 *  coins        = requested, cut down to the wallet, the subtotal and the caps in the settings (see clampCoins)
 *  delivery     = one flat fee; free when the subtotal reaches free_delivery_min_cart (0 = never free); nothing for an empty cart
 *  grand total  = subtotal - coins discount + delivery
 */
class CartPricing
{
    public function __construct(private readonly CommerceSettings $settings, private readonly RewardLedger $ledger) {}

    /** Relations the calculation needs (and the cart screen shows). Stock rows are limited to the channel. */
    public static function relations(SalesChannel $channel): array
    {
        return [
            // a product, brand or company deleted after the customer added it must still load (the line is then reported as unavailable)
            'variant.product' => fn ($q) => $q->withTrashed()->select(['id', 'name', 'slug', 'sku', 'brand_id', 'company_id', 'status', 'gst_rate', 'hsn_code', 'min_order_qty', 'max_order_qty', 'is_cod_available']),
            'variant.product.brand' => fn ($q) => $q->withTrashed()->select(['id', 'name', 'slug']),
            'variant.product.company' => fn ($q) => $q->withTrashed()->select(['id', 'name']),
            'variant.product.media', 'variant.product.remoteImages',
            'variant.priceTiers',
            'variant.stocks' => fn ($q) => $q->where('channel_id', $channel->id),
        ];
    }

    /** Loads a cart's lines and prices them. `$user` supplies the coin wallet (guests have none). */
    public function forCart(Cart $cart, SalesChannel $channel, ?User $user, ?int $coins = null, ?Collection $lockedStocks = null, ?int $walletCoins = null): PricedCart
    {
        $items = $cart->items()->with(self::relations($channel))->get();

        $productIds = $items->map(fn ($i) => $i->variant->product_id)->unique()->values();
        $visible = $productIds->isEmpty() ? collect() : Product::query()->storefront($channel->code)->whereIn('products.id', $productIds)->pluck('products.id');

        $wallet = $walletCoins ?? ($user ? $this->ledger->spendable($user->id) : 0);

        return $this->price($items, $channel, $visible->all(), (int) ($coins ?? $cart->coins_to_redeem), $wallet, $lockedStocks);
    }

    /**
     * @param  Collection<int, \App\Domain\Cart\Models\CartItem>  $items  with CartPricing::relations() loaded
     * @param  list<int>  $visibleProductIds  products the website currently shows on this channel
     * @param  Collection<int, VariantChannelStock>|null  $stocks  variant_id => row, when the caller holds the row locks (checkout)
     */
    public function price(Collection $items, SalesChannel $channel, array $visibleProductIds, int $requestedCoins, int $walletCoins, ?Collection $stocks = null): PricedCart
    {
        $symbol = $this->settings->currencySymbol();
        $lines = [];

        foreach ($items as $item) {
            $variant = $item->variant;
            $product = $variant->product;
            $stock = $stocks ? $stocks->get($variant->id) : $variant->stocks->first();

            $qty = (int) $item->qty;
            $mrp = CommerceSettings::cents($variant->mrp);
            $base = CommerceSettings::cents($variant->selling_price);
            [$bulk, $next] = $this->tiers($variant->priceTiers, $base, $qty, (int) $product->max_order_qty);
            $unit = $bulk['price'] ?? $base;

            $sellable = $variant->is_active && in_array($product->id, $visibleProductIds, true) && $stock && $stock->is_active;
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
            $snapshot = CommerceSettings::cents($item->unit_price);
            if ($snapshot !== $base) {
                $word = $base > $snapshot ? 'increased' : 'dropped';
                $issues[] = $this->issue('price_changed', "The price of \"$name\" has $word from ".$this->fmt($snapshot, $symbol).' to '.$this->fmt($base, $symbol).'.', false);
            }

            $lines[] = new PricedLine($variant, $product, $qty, $mrp, $base, $unit, $bulk, $next, $available, $snapshot, $sellable && $available > 0, $issues);
        }

        $counted = array_filter($lines, fn (PricedLine $l) => $l->counted);
        $itemsTotal = array_sum(array_map(fn (PricedLine $l) => $l->mrpTotal(), $counted));
        $productDiscount = array_sum(array_map(fn (PricedLine $l) => $l->productDiscount(), $counted));
        $bulkDiscount = array_sum(array_map(fn (PricedLine $l) => $l->bulkDiscount(), $counted));
        $subtotal = $itemsTotal - $productDiscount - $bulkDiscount;

        $coins = $this->clampCoins($requestedCoins, $walletCoins, $subtotal);
        $coinsDiscount = $coins['applied'] * $coins['value'];

        $freeMin = $this->settings->freeDeliveryMinCents();
        $deliveryFree = $counted !== [] && $freeMin > 0 && $subtotal >= $freeMin;
        $deliveryFee = ($counted === [] || $deliveryFree) ? 0 : $this->settings->deliveryFeeCents();

        return new PricedCart(
            lines: $lines,
            itemsTotal: $itemsTotal,
            productDiscount: $productDiscount,
            bulkDiscount: $bulkDiscount,
            subtotal: $subtotal,
            coins: $coins,
            coinsDiscount: $coinsDiscount,
            deliveryFee: $deliveryFee,
            deliveryFree: $deliveryFree,
            freeDeliveryMin: $freeMin,
            grandTotal: $subtotal - $coinsDiscount + $deliveryFee,
            coinsToEarn: array_sum(array_map(fn (PricedLine $l) => $l->coinsToEarn(), $counted)),
            taxIncluded: array_sum(array_map(fn (PricedLine $l) => $l->taxIncluded(), $counted)),
            unitsCount: array_sum(array_map(fn (PricedLine $l) => $l->qty, $counted)),
            minOrderAmount: $this->settings->minOrderCents(),
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
            $price = CommerceSettings::cents($tier->price);
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

    /**
     * Coins the customer wants to use, cut down to what is allowed. The smallest of:
     *   the wallet (spendable, not expired) - the subtotal (coins never pay for delivery) - max_redeem_coins_per_order
     *   - redeem_cap_order_percent of the subtotal - redeem_cap_balance_percent of the wallet.
     * `limited_by` names the limit that cut the request (null when the whole request was applied).
     *
     * @return array{requested:int, applied:int, max:int, wallet:int, value:int, limited_by:?string}
     */
    private function clampCoins(int $requested, int $wallet, int $subtotal): array
    {
        $value = $this->settings->coinValueCents();
        $requested = max(0, $requested);

        $limits = [
            'wallet_balance' => $wallet,
            'order_amount' => intdiv($subtotal, $value),
            'max_per_order' => (int) $this->settings->get('max_redeem_coins_per_order'),
            'order_percent_cap' => (int) floor($subtotal * (float) $this->settings->get('redeem_cap_order_percent') / 100 / $value),
            'balance_percent_cap' => (int) floor($wallet * (float) $this->settings->get('redeem_cap_balance_percent') / 100),
        ];

        $max = max(0, min($limits));
        $applied = min($requested, $max);
        $limitedBy = null;
        if ($applied < $requested) {
            $limitedBy = array_search($max, $limits, true) ?: null;
        }

        return ['requested' => $requested, 'applied' => $applied, 'max' => $max, 'wallet' => $wallet, 'value' => $value, 'limited_by' => $limitedBy];
    }

    private function issue(string $code, string $message, bool $blocking): array
    {
        return ['code' => $code, 'message' => $message, 'blocking' => $blocking];
    }

    /** "Rs 152" / "Rs 1,520.50" */
    public function fmt(int $cents, ?string $symbol = null): string
    {
        $symbol ??= $this->settings->currencySymbol();
        $amount = $cents % 100 === 0 ? number_format($cents / 100) : number_format($cents / 100, 2);

        return "$symbol $amount";
    }
}
