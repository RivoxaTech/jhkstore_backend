<?php

namespace App\Http\Resources;

use App\Domain\Cart\CartPricing;
use App\Domain\Cart\PricedCart;
use App\Domain\Cart\PricedLine;
use App\Domain\Catalog\ProductImages;
use App\Domain\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The answer of POST /store/checkout/quote: the storefront's cart priced by the server. Lines with their live prices, bulk-offer hints and
 * warnings, and the summary (what the order total would be). The resource is a PricedCart (see CartPricing); the persistent cart adds its
 * `token` through `$extra`. Amounts are numbers in the shop currency (`delivery_fee` is null while the fee is not known yet).
 */
class QuoteResource extends JsonResource
{
    /** @param  array<string,mixed>  $extra  added to the answer as it is (the cart token) */
    public function __construct(PricedCart $priced, private readonly array $extra = [])
    {
        parent::__construct($priced);
    }

    public function toArray(Request $request): array
    {
        /** @var PricedCart $c */
        $c = $this->resource;
        $pricing = app(CartPricing::class);
        $settings = app(Settings::class);
        $money = fn (int $cents) => $settings->money($cents);
        $symbol = $settings->currencySymbol();

        $lines = array_map(fn (PricedLine $l) => $this->line($l, $pricing, $money), $c->lines);
        $issues = [];
        foreach ($c->unknownVariantIds as $id) {
            $issues[] = ['variant_id' => $id, 'code' => 'unavailable', 'message' => 'One of the items is no longer available.'];
        }
        foreach ($c->lines as $l) {
            foreach ($l->issues as $issue) {
                if ($issue['blocking']) {
                    $issues[] = ['variant_id' => $l->variant->id, 'code' => $issue['code'], 'message' => $issue['message']];
                }
            }
        }
        if ($c->countedLines() !== [] && ! $c->meetsMinimum()) {
            $issues[] = ['variant_id' => null, 'code' => 'below_min_order',
                'message' => 'The minimum order amount is '.$pricing->fmt($c->minOrderAmount, $symbol).'. Add items worth '.$pricing->fmt($c->minOrderAmount - $c->subtotalBeforeCoupon, $symbol).' more.'];
        }
        if ($c->deliveryUnavailable) {
            $issues[] = ['variant_id' => null, 'code' => 'delivery_not_available', 'message' => 'Sorry, we do not deliver to this address.'];
        }

        return $this->extra + [
            'currency' => $settings->currencyCode(),
            'currency_symbol' => $symbol,
            'items_count' => $c->unitsCount,
            'lines_count' => count($lines),
            'items' => $lines,
            'summary' => [
                'items_total' => $money($c->itemsTotal),               // everything at MRP
                'product_discount' => $money($c->productDiscount),     // MRP -> option price
                'bulk_discount' => $money($c->bulkDiscount),           // option price -> bulk price ("Buy 5+ for ...")
                'discount_total' => $money($c->discountTotal),          // automatic discounts
                'coupon_code' => $c->couponCode,                       // the coupon that was applied, or null
                'coupon_discount' => $money($c->couponDiscount),
                'coupon_free_delivery' => $c->couponFreeDelivery,
                'coupon_error' => $c->couponError,                     // { code, message } when the coupon was refused (the quote itself is not blocked)
                'subtotal' => $money($c->subtotal),
                'delivery_method' => $c->deliveryMethod,
                'delivery_fee' => $c->deliveryFeeKnown() ? $money($c->deliveryFee) : null,   // null: not known yet
                'delivery_pending' => $c->deliveryPending,             // the owner confirms the fee after the order
                'delivery_needs_address' => $c->deliveryNeedsAddress,  // zones: send the address to get the fee
                'delivery_zone' => $c->deliveryZone,                   // { id, name, eta_text } or null
                'delivery_eta_text' => $c->deliveryEtaText,
                'delivery_free' => $c->deliveryFree,
                'free_delivery_min_cart' => $money($c->freeDeliveryMin),   // 0 = no free delivery
                'amount_for_free_delivery' => ($c->freeDeliveryMin > 0 && ! $c->deliveryFree && $c->countedLines() !== [] && $c->deliveryFeeKnown()) ? $money($c->freeDeliveryMin - $c->subtotal) : null,
                'grand_total' => $money($c->grandTotal),
                'total_savings' => $money($c->totalSavings()),         // product + bulk + automatic discounts + coupon
                'tax_total' => $money($c->taxTotal),
                'tax_inclusive' => $c->taxInclusive,
                'tax_label' => (string) $settings->get('tax_label'),
                'tax_note' => $c->taxInclusive ? 'Inclusive of all taxes' : 'Tax is added to the total',
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
            'discount_amount' => $money($l->discount),             // what a discount or the coupon takes off this line
            'discount_source' => $l->savingSource(),               // discount | coupon | bulk | null
            'discount_id' => $l->discountSource === 'discount' ? $l->discountSourceId : null,
            'coupon_id' => $l->discountSource === 'coupon' ? $l->discountSourceId : null,
            'saving' => $money($l->productDiscount() + $l->bulkDiscount() + $l->discount),
            'tax_rate' => $l->taxRate,
            'tax' => $money($l->tax()),
            'in_totals' => $l->counted,                              // false = the option can no longer be bought; it is shown with a warning, not counted
            'can_buy' => $l->counted && ! $l->isBlocking(),
            'warnings' => $l->issues,
        ];
    }
}
