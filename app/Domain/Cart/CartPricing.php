<?php

namespace App\Domain\Cart;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Delivery\ZoneMatcher;
use App\Domain\Promotion\Models\Coupon;
use App\Domain\Promotion\Promotions;
use App\Domain\Support\Settings;
use Illuminate\Support\Collection;

/**
 * THE price calculator of the shop. The quote endpoint, the persistent cart, the coupon check and the checkout all use it, so what the customer
 * is shown is what the order gets. Nothing the client sends about prices is trusted: every price comes from the option, its bulk prices, the
 * discounts and coupons and the settings. All maths run in whole minor units.
 *
 *  1 unit price   = the option price, or the bulk price of the highest tier whose min_qty <= qty
 *  2 discount     = per line, the live automatic discount with the biggest saving (ties: higher priority). Discounts are never stacked.
 *                   A fixed amount is taken off the eligible lines once, split by line total (the remainder goes to the last lines).
 *  3 coupon       = optional code. For each eligible line the better of (automatic discount, coupon) applies, never both. `min_order` is checked
 *                   against the items after bulk prices and automatic discounts; `max_discount` caps the coupon's total; a free-delivery coupon
 *                   takes nothing off the items and sets the delivery fee to 0.
 *  4 tax          = on what is paid after discounts: part of the price when tax inclusive, added when exclusive
 *  5 delivery     = by the store's method (flat | free | free_above | zones | owner_confirms), see delivery()
 *  grand total    = items - discounts + delivery (+ tax when prices are tax exclusive); an unknown delivery fee is not counted
 */
class CartPricing
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Promotions $promotions,
        private readonly ZoneMatcher $zones,
    ) {}

    /** Relations the calculation needs. */
    public static function relations(): array
    {
        return [
            // a product deleted after the customer added it must still load (the line is then reported as unavailable)
            'product' => fn ($q) => $q->withTrashed()->select(['id', 'category_id', 'sub_category_id', 'name', 'slug', 'sku', 'status', 'tax_rate', 'min_order_qty', 'max_order_qty', 'is_cod_available']),
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
    public function forItems(array $items, ?Collection $locked = null, ?PricingContext $context = null): PricedCart
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

        return $this->price($lines, $visible, $locked, array_values(array_diff(array_keys($qtys), $variants->keys()->all())), $context);
    }

    /**
     * @param  list<array{variant: ProductVariant, qty:int}>  $items  variants with CartPricing::relations() loaded
     * @param  list<int>  $visibleProductIds  products the website currently shows
     * @param  list<int>  $unknownVariantIds  option ids that do not exist (they block the order)
     */
    public function price(array $items, array $visibleProductIds, ?Collection $locked = null, array $unknownVariantIds = [], ?PricingContext $context = null): PricedCart
    {
        $context ??= new PricingContext;
        $inclusive = (bool) $this->settings->get('tax_inclusive');
        $drafts = [];

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

            $drafts[] = [
                'variant' => $variant, 'product' => $product, 'qty' => $qty, 'mrp' => $mrp, 'base' => $base, 'unit' => $unit, 'bulk' => $bulk, 'next' => $next,
                'available' => $available, 'counted' => $sellable && $available > 0, 'issues' => $issues,
                'rate' => $product->tax_rate !== null ? (float) $product->tax_rate : (float) $this->settings->get('tax_rate'),
            ];
        }

        // what each counted line costs after bulk prices (index in $drafts => cents)
        $gross = [];
        foreach ($drafts as $i => $d) {
            if ($d['counted']) {
                $gross[$i] = $d['unit'] * $d['qty'];
            }
        }

        $auto = $this->automaticDiscounts($drafts, $gross);
        $afterAuto = array_sum($gross) - array_sum(array_column($auto, 'amount'));
        $coupon = $this->coupon($context, $drafts, $gross, $auto, $afterAuto);

        $lines = [];
        foreach ($drafts as $i => $d) {
            $discount = 0;
            $source = null;
            $sourceId = null;
            if (isset($coupon['savings'][$i])) {
                [$discount, $source, $sourceId] = [$coupon['savings'][$i], 'coupon', $coupon['coupon']->id];
            } elseif (isset($auto[$i])) {
                [$discount, $source, $sourceId] = [$auto[$i]['amount'], 'discount', $auto[$i]['id']];
            }
            $paid = $d['unit'] * $d['qty'] - $discount;
            $tax = $inclusive ? (int) round($paid * $d['rate'] / (100 + $d['rate'])) : (int) round($paid * $d['rate'] / 100);

            $lines[] = new PricedLine($d['variant'], $d['product'], $d['qty'], $d['mrp'], $d['base'], $d['unit'], $d['bulk'], $d['next'], $d['available'],
                $d['counted'], $d['issues'], $d['rate'], $tax, $discount, $source, $sourceId);
        }

        $counted = array_filter($lines, fn (PricedLine $l) => $l->counted);
        $itemsTotal = array_sum(array_map(fn (PricedLine $l) => $l->mrpTotal(), $counted));
        $productDiscount = array_sum(array_map(fn (PricedLine $l) => $l->productDiscount(), $counted));
        $bulkDiscount = array_sum(array_map(fn (PricedLine $l) => $l->bulkDiscount(), $counted));
        $discountTotal = array_sum(array_map(fn (PricedLine $l) => $l->discountSource === 'discount' ? $l->discount : 0, $counted));
        $couponDiscount = array_sum(array_map(fn (PricedLine $l) => $l->discountSource === 'coupon' ? $l->discount : 0, $counted));
        $subtotal = $itemsTotal - $productDiscount - $bulkDiscount - $discountTotal - $couponDiscount;
        $taxTotal = array_sum(array_map(fn (PricedLine $l) => $l->tax(), $counted));

        $delivery = $this->delivery($context, $subtotal, $counted !== [], $coupon['free_delivery']);
        $fee = $delivery['pending'] || $delivery['needs_address'] || $delivery['unavailable'] ? 0 : $delivery['fee'];
        $minOrder = $delivery['min_order'] ?? $this->settings->minOrderCents();

        return new PricedCart(
            lines: $lines,
            itemsTotal: $itemsTotal,
            productDiscount: $productDiscount,
            bulkDiscount: $bulkDiscount,
            subtotal: $subtotal,
            deliveryFee: $fee,
            deliveryFree: $delivery['free'],
            freeDeliveryMin: $delivery['free_min'],
            grandTotal: $subtotal + $fee + ($inclusive ? 0 : $taxTotal),
            taxTotal: $taxTotal,
            taxInclusive: $inclusive,
            unitsCount: array_sum(array_map(fn (PricedLine $l) => $l->qty, $counted)),
            minOrderAmount: $minOrder,
            unknownVariantIds: $unknownVariantIds,
            discountTotal: $discountTotal,
            couponDiscount: $couponDiscount,
            couponCode: $coupon['coupon']?->code,
            couponId: $coupon['coupon']?->id,
            couponFreeDelivery: $coupon['free_delivery'],
            couponError: $coupon['error'],
            subtotalBeforeCoupon: $afterAuto,
            deliveryMethod: $delivery['method'],
            deliveryPending: $delivery['pending'],
            deliveryNeedsAddress: $delivery['needs_address'],
            deliveryUnavailable: $delivery['unavailable'],
            deliveryZone: $delivery['zone'],
            deliveryZoneId: $delivery['zone']['id'] ?? null,
            deliveryEtaText: $delivery['eta'],
            couponRequested: trim((string) $context->couponCode) !== '',
        );
    }

    // ------------------------------------------------------------------ automatic discounts

    /**
     * The best live discount per line. Every discount is worked out for the lines it covers (a fixed amount is split by line total), then each
     * line keeps the biggest saving; the discounts come ordered by priority, so on a tie the higher priority wins.
     *
     * @param  array<int, int>  $gross  line index => line total after bulk prices (cents), counted lines only
     * @return array<int, array{amount:int, id:int}>
     */
    private function automaticDiscounts(array $drafts, array $gross): array
    {
        $best = [];
        if ($gross === []) {
            return $best;
        }

        foreach ($this->promotions->liveDiscounts() as $discount) {
            $eligible = $this->eligible($discount, $drafts, $gross);
            foreach ($this->savings($discount->type, (float) $discount->value, $eligible) as $i => $amount) {
                if ($amount > 0 && (! isset($best[$i]) || $amount > $best[$i]['amount'])) {
                    $best[$i] = ['amount' => $amount, 'id' => $discount->id];
                }
            }
        }

        return $best;
    }

    /** @return array<int, int> the counted lines a discount or coupon covers (index => line total) */
    private function eligible(object $promotion, array $drafts, array $gross): array
    {
        $out = [];
        foreach ($gross as $i => $total) {
            $product = $drafts[$i]['product'];
            if ($promotion->appliesTo((int) $product->id, (int) $product->category_id, $product->sub_category_id !== null ? (int) $product->sub_category_id : null)) {
                $out[$i] = $total;
            }
        }

        return $out;
    }

    /**
     * The saving of a percent or fixed value on the eligible lines.
     *
     * @param  array<int, int>  $eligible  line index => line total (cents)
     * @return array<int, int>
     */
    private function savings(string $type, float $value, array $eligible): array
    {
        if ($eligible === []) {
            return [];
        }
        if ($type === 'percent') {
            return array_map(fn (int $total) => min($total, (int) round($total * $value / 100)), $eligible);
        }

        return $this->split(min($this->settings->cents($value), array_sum($eligible)), $eligible);
    }

    /**
     * Splits an amount over lines in proportion to their weights, in whole cents: the remainder goes to the last lines, one cent at a time,
     * and no line gets more than its own weight (a line can never cost less than nothing).
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    private function split(int $amount, array $weights): array
    {
        $sum = array_sum($weights);
        $out = array_fill_keys(array_keys($weights), 0);
        if ($amount <= 0 || $sum <= 0) {
            return $out;
        }

        $given = 0;
        foreach ($weights as $k => $w) {
            $out[$k] = (int) floor($amount * $w / $sum);
            $given += $out[$k];
        }
        $rest = $amount - $given;
        $keys = array_reverse(array_keys($weights));
        while ($rest > 0) {
            $moved = false;
            foreach ($keys as $k) {
                if ($rest > 0 && $out[$k] < $weights[$k]) {
                    $out[$k]++;
                    $rest--;
                    $moved = true;
                }
            }
            if (! $moved) {
                break;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ coupon

    /**
     * Checks the coupon and works out its saving per line ("best one wins": a line keeps its automatic discount when that is bigger).
     * A coupon that fails any rule answers an error (quote: shown, order: refused with 422 coupon_invalid) and changes nothing.
     *
     * @return array{coupon: ?Coupon, savings: array<int,int>, error: ?array{code:string, message:string}, free_delivery: bool}
     */
    private function coupon(PricingContext $context, array $drafts, array $gross, array $auto, int $afterAuto): array
    {
        $out = ['coupon' => null, 'savings' => [], 'error' => null, 'free_delivery' => false];
        $code = Coupon::normaliseCode((string) $context->couponCode);
        if ($code === '') {
            return $out;
        }
        $fail = fn (string $reason, string $message) => ['error' => ['code' => $reason, 'message' => $message]] + $out;

        $coupon = $this->promotions->findCoupon($code, $context->lockCoupon);
        if (! $coupon) {
            return $fail('not_found', 'This coupon code does not exist.');
        }
        if (! $coupon->is_active) {
            return $fail('inactive', 'This coupon is not active.');
        }
        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            return $fail('not_started', 'This coupon is not valid yet.');
        }
        if ($coupon->ends_at && $coupon->ends_at->isPast()) {
            return $fail('expired', 'This coupon has expired.');
        }
        $eligible = $this->eligible($coupon, $drafts, $gross);
        if ($eligible === []) {
            return $fail('no_eligible_items', 'This coupon does not apply to the items in your cart.');
        }
        $min = $coupon->min_order !== null ? $this->settings->cents($coupon->min_order) : 0;
        if ($min > 0 && $afterAuto < $min) {
            return $fail('min_order', 'This coupon needs an order of at least '.$this->fmt($min).'. Add items worth '.$this->fmt($min - $afterAuto).' more.');
        }
        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            return $fail('usage_limit', 'This coupon has been used up.');
        }
        if ($context->phone !== null && $context->phone !== '') {
            if ($coupon->per_customer_limit !== null && $this->promotions->usesByPhone($coupon, $context->phone) >= $coupon->per_customer_limit) {
                return $fail('per_customer_limit', 'You have already used this coupon.');
            }
            if ($coupon->first_order_only && $this->promotions->hasEarlierOrder($context->phone)) {
                return $fail('first_order_only', 'This coupon is for first orders only.');
            }
        }

        $out['coupon'] = $coupon;
        if ($coupon->free_delivery) {
            return ['free_delivery' => true] + $out;
        }

        $won = [];
        foreach ($this->savings($coupon->type, (float) $coupon->value, $eligible) as $i => $amount) {
            if ($amount > ($auto[$i]['amount'] ?? 0)) {
                $won[$i] = $amount;     // the coupon beats the automatic discount on this line
            }
        }
        $cap = $coupon->max_discount !== null ? $this->settings->cents($coupon->max_discount) : null;
        if ($cap !== null && array_sum($won) > $cap) {
            $won = $this->split($cap, $won);
        }
        $won = array_filter($won, fn (int $amount) => $amount > 0);
        if ($won === []) {
            return $fail('no_eligible_items', 'Your items already have a better offer than this coupon.');
        }

        return ['savings' => $won] + $out;
    }

    // ------------------------------------------------------------------ delivery

    /**
     * Delivery by the store's method. Returns the fee in cents and what is known about it:
     *  flat / free / free_above   a number (free_above: free from `free_delivery_min_cart`, 0 = never free)
     *  zones                      the fee of the first matching zone (free above the zone's own amount); no address yet = needs_address;
     *                             an address outside every zone follows `delivery_unmatched` (block | owner_confirms | flat)
     *  owner_confirms             pending: the owner sets the fee after the order
     * A free-delivery coupon makes the fee 0 in every case except a refused address.
     *
     * @return array{method:string, fee:int, free:bool, free_min:int, pending:bool, needs_address:bool, unavailable:bool, zone:?array, min_order:?int, eta:?string}
     */
    private function delivery(PricingContext $context, int $subtotal, bool $hasItems, bool $freeCoupon): array
    {
        $method = $this->settings->deliveryMethod();
        $r = ['method' => $method, 'fee' => 0, 'free' => false, 'free_min' => 0, 'pending' => false, 'needs_address' => false, 'unavailable' => false, 'zone' => null, 'min_order' => null, 'eta' => null];
        $eta = trim((string) $this->settings->get('delivery_eta_text'));
        $r['eta'] = $eta !== '' ? $eta : null;
        if (! $hasItems) {
            return $r;
        }

        $flat = $this->settings->deliveryFeeCents();
        switch ($method) {
            case 'free':
                $r['free'] = true;
                break;
            case 'owner_confirms':
                $r['pending'] = true;
                break;
            case 'zones':
                if (! ZoneMatcher::hasAddress($context->address)) {
                    $r['needs_address'] = true;
                } elseif ($zone = $this->zones->match($context->address)) {
                    $freeAbove = $zone->free_above !== null ? $this->settings->cents($zone->free_above) : 0;
                    $r['fee'] = $this->settings->cents($zone->fee);
                    $r['free_min'] = $freeAbove;
                    $r['zone'] = ['id' => $zone->id, 'name' => $zone->name, 'eta_text' => $zone->eta_text];
                    $r['min_order'] = $zone->min_order !== null ? $this->settings->cents($zone->min_order) : null;
                    $r['eta'] = $zone->eta_text ?: $r['eta'];
                    if ($freeAbove > 0 && $subtotal >= $freeAbove) {
                        [$r['fee'], $r['free']] = [0, true];
                    }
                } else {
                    match ((string) $this->settings->get('delivery_unmatched')) {
                        'owner_confirms' => $r['pending'] = true,
                        'flat' => $r['fee'] = $flat,
                        default => $r['unavailable'] = true,
                    };
                }
                break;
            case 'free_above':
                $r['free_min'] = $this->settings->freeDeliveryMinCents();
                if ($r['free_min'] > 0 && $subtotal >= $r['free_min']) {
                    $r['free'] = true;
                } else {
                    $r['fee'] = $flat;
                }
                break;
            default: // flat
                $r['fee'] = $flat;
        }

        if ($freeCoupon) {
            [$r['fee'], $r['free'], $r['pending'], $r['needs_address']] = [0, true, false, false];
        }

        return $r;
    }

    // ------------------------------------------------------------------ product lists

    /**
     * The display price of ONE unit for the product lists and pages, from the same live discounts the cart uses: a percent discount lowers the
     * price; a fixed amount is taken off the order, not off a unit, so it only gives the label. Returns the final price, the label and the discount id.
     *
     * @return array{price:int, label:?string, discount_id:?int}
     */
    public function displayPrice(int $productId, int $categoryId, ?int $subCategoryId, int $priceCents): array
    {
        $best = null;
        foreach ($this->promotions->liveDiscounts() as $discount) {
            if (! $discount->appliesTo($productId, $categoryId, $subCategoryId)) {
                continue;
            }
            $saving = $discount->type === 'percent'
                ? min($priceCents, (int) round($priceCents * (float) $discount->value / 100))
                : min($priceCents, $this->settings->cents($discount->value));
            if ($saving > 0 && (! $best || $saving > $best['saving'])) {
                $best = ['saving' => $saving, 'discount' => $discount];
            }
        }
        if (! $best) {
            return ['price' => $priceCents, 'label' => null, 'discount_id' => null];
        }

        $d = $best['discount'];
        $percent = rtrim(rtrim(number_format((float) $d->value, 2, '.', ''), '0'), '.');

        return $d->type === 'percent'
            ? ['price' => $priceCents - $best['saving'], 'label' => $percent.'% off', 'discount_id' => $d->id]
            : ['price' => $priceCents, 'label' => $this->fmt($this->settings->cents($d->value)).' off', 'discount_id' => $d->id];
    }

    // ------------------------------------------------------------------ helpers

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
