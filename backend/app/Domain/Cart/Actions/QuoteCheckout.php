<?php

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\CartPricing;
use App\Domain\Cart\PricedCart;
use App\Domain\Cart\PricingContext;
use App\Domain\Customer\Phone;
use Illuminate\Support\Arr;

/**
 * Prices what a storefront asks about: the items it sent, or the items of its persistent cart (`cart_token`, which also brings the cart's
 * coupon and saved phone unless the request has its own). Stateless: nothing is stored, reserved or changed (the cart is not renewed).
 * An unknown or expired cart token prices an empty cart.
 */
class QuoteCheckout
{
    public function __construct(private readonly CartPricing $pricing, private readonly ManageCart $carts) {}

    /**
     * @param  array{items?:?list<array>, cart_token?:?string, coupon_code?:?string, address?:?array, phone?:?string}  $input
     * @param  string|null  $coupon  a code that replaces the request's and the cart's (the coupon check)
     */
    public function handle(array $input, ?string $coupon = null): PricedCart
    {
        $items = $input['items'] ?? [];
        $code = $coupon ?? ($input['coupon_code'] ?? null);
        $phone = $input['phone'] ?? null;

        if ($items === [] && ! empty($input['cart_token']) && ($cart = $this->carts->find($input['cart_token']))) {
            $items = $cart->items()->get()->map(fn ($row) => ['variant_id' => $row->variant_id, 'qty' => $row->qty])->all();
            $code ??= $cart->coupon_code;
            $phone ??= $cart->customer_phone;
        }

        $context = new PricingContext(
            couponCode: $code,
            address: Arr::only((array) ($input['address'] ?? []), ['city', 'area', 'postcode']),
            phone: $phone !== null && trim($phone) !== '' ? Phone::normalise($phone) : null,
        );

        return $this->pricing->forItems($items, null, $context);
    }
}
