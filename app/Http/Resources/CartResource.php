<?php

namespace App\Http\Resources;

use App\Domain\Cart\CartPricing;
use App\Domain\Cart\PricedCart;
use App\Domain\Cart\PricedLine;
use App\Domain\Catalog\Models\SalesChannel;
use App\Domain\Catalog\ProductImages;
use App\Domain\Support\CommerceSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The cart answer of every cart endpoint: lines with their live prices, bulk-offer hints and warnings, and the summary.
 * `token` is the guest cart token (null for a logged-in customer). The resource is a PricedCart (see CartPricing).
 * Amounts are numbers in the shop currency; prices already include all taxes.
 */
class CartResource extends JsonResource
{
    public function __construct(PricedCart $priced, private readonly SalesChannel $channel, private readonly ?string $token = null)
    {
        parent::__construct($priced);
    }

    public function toArray(Request $request): array
    {
        /** @var PricedCart $c */
        $c = $this->resource;
        $pricing = app(CartPricing::class);
        $settings = app(CommerceSettings::class);
        $money = fn (int $cents) => CommerceSettings::money($cents);
        $symbol = $settings->currencySymbol();

        $lines = array_map(fn (PricedLine $l) => $this->line($l, $pricing, $money), $c->lines);
        $issues = [];
        foreach ($c->lines as $l) {
            foreach ($l->issues as $issue) {
                if ($issue['blocking']) {
                    $issues[] = ['variant_id' => $l->variant->id, 'code' => $issue['code'], 'message' => $issue['message']];
                }
            }
        }
        if ($c->countedLines() !== [] && ! $c->meetsMinimum()) {
            $issues[] = ['variant_id' => null, 'code' => 'below_min_order',
                'message' => 'The minimum order amount is '.$pricing->fmt($c->minOrderAmount, $symbol).'. Add items worth '.$pricing->fmt($c->minOrderAmount - $c->subtotal, $symbol).' more.'];
        }

        $coins = $c->coins;

        return [
            'token' => $this->token,
            'channel' => $this->channel->code,
            'currency' => $settings->currencyCode(),
            'currency_symbol' => $symbol,
            'items_count' => $c->unitsCount,
            'lines_count' => count($lines),
            'items' => $lines,
            'summary' => [
                'items_total' => $money($c->itemsTotal),               // everything at MRP
                'product_discount' => $money($c->productDiscount),     // MRP -> option price
                'bulk_discount' => $money($c->bulkDiscount),           // option price -> bulk price ("Buy 5+ for ...")
                'subtotal' => $money($c->subtotal),
                'coins' => [
                    'requested' => $coins['requested'],
                    'applied' => $coins['applied'],
                    'discount' => $money($c->coinsDiscount),
                    'coin_value' => $money($coins['value']),           // money per coin
                    'wallet_balance' => $coins['wallet'],
                    'max_redeemable' => $coins['max'],
                    'limited_by' => $coins['limited_by'],              // wallet_balance | order_amount | max_per_order | order_percent_cap | balance_percent_cap
                ],
                'coins_discount' => $money($c->coinsDiscount),
                'delivery_fee' => $money($c->deliveryFee),
                'delivery_free' => $c->deliveryFree,
                'free_delivery_min_cart' => $money($c->freeDeliveryMin),   // 0 = no free delivery
                'amount_for_free_delivery' => ($c->freeDeliveryMin > 0 && ! $c->deliveryFree && $c->countedLines() !== []) ? $money($c->freeDeliveryMin - $c->subtotal) : null,
                'grand_total' => $money($c->grandTotal),
                'coins_to_earn' => $c->coinsToEarn,
                'total_savings' => $money($c->totalSavings()),         // product + bulk + coins discounts
                'tax_included' => $money($c->taxIncluded),
                'tax_note' => 'Inclusive of all taxes',
                'min_order_amount' => $money($c->minOrderAmount),      // 0 = no minimum
            ],
            'can_checkout' => $c->canCheckout(),
            'issues' => $issues,
        ];
    }

    private function line(PricedLine $l, CartPricing $pricing, callable $money): array
    {
        $v = $l->variant;
        $p = $l->product;
        $own = ProductImages::gallery($p, $v->id)[0] ?? null;
        $primary = $own ? null : ProductImages::primary($p);
        $offer = fn (array $tier) => 'Buy '.$tier['min_qty'].'+ for '.$pricing->fmt($tier['price']).' each';
        $extra = fn (array $tier) => $l->basePrice > 0 ? round(($l->basePrice - $tier['price']) / $l->basePrice * 100, 2) : 0.0;

        return [
            'variant_id' => $v->id,
            'product_id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->name,
            'option_name' => $v->name,
            'sku' => $v->sku,
            'image_url' => $own['medium_url'] ?? $primary['image_url'],
            'thumb_url' => $own['thumb_url'] ?? $primary['thumb_url'],
            'brand' => ['name' => $p->brand->name, 'slug' => $p->brand->slug],
            'qty' => $l->qty,
            'min_order_qty' => (int) $p->min_order_qty,
            'max_order_qty' => (int) $p->max_order_qty,
            'available_qty' => $l->available,
            'mrp' => $money($l->mrp),
            'price' => $money($l->basePrice),                       // the option's own price
            'unit_price' => $money($l->unitPrice),                  // what one unit costs now (bulk price when reached)
            'bulk_applied' => $l->bulk ? ['min_qty' => $l->bulk['min_qty'], 'price' => $money($l->bulk['price']), 'extra_discount_percent' => $extra($l->bulk), 'label' => $offer($l->bulk)] : null,
            'next_offer' => $l->nextOffer ? ['min_qty' => $l->nextOffer['min_qty'], 'price' => $money($l->nextOffer['price']), 'add_qty' => $l->nextOffer['add_qty'], 'extra_discount_percent' => $extra($l->nextOffer), 'hint' => $offer($l->nextOffer)] : null,
            'line_mrp_total' => $money($l->mrpTotal()),
            'line_total' => $money($l->lineTotal()),
            'product_saving' => $money($l->productDiscount()),
            'bulk_saving' => $money($l->bulkDiscount()),
            'saving' => $money($l->productDiscount() + $l->bulkDiscount()),
            'reward_points' => (int) $v->reward_points,              // coins per unit
            'coins_to_earn' => $l->coinsToEarn(),
            'gst_rate' => (float) $p->gst_rate,
            'in_totals' => $l->counted,                              // false = the option can no longer be bought; it is shown with a warning, not counted
            'can_buy' => $l->counted && ! $l->isBlocking(),
            'price_changed' => $l->snapshotPrice !== $l->basePrice ? ['from' => $money($l->snapshotPrice), 'to' => $money($l->basePrice)] : null,
            'warnings' => $l->issues,
        ];
    }
}
