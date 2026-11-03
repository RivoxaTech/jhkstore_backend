<?php

namespace App\Http\Requests\Store;

/**
 * The parts shared by the quote, the coupon check and the order request: option ids and quantities (or a cart token instead), a coupon code
 * and the address fields the delivery zones match on, with the abuse limits of config/shop.php.
 */
class CheckoutItemsRules
{
    public static function rules(): array
    {
        return [
            'items' => ['required_without:cart_token', 'nullable', 'array', 'min:1', 'max:'.(int) config('shop.max_items_per_order')],
            'items.*' => ['array'],
            'items.*.variant_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:'.(int) config('shop.max_qty_per_line')],
            'cart_token' => ['nullable', 'string', 'size:40'],
            'coupon_code' => ['nullable', 'string', 'max:32'],
        ];
    }

    /** The optional `address` of a quote: only what the delivery zones match on. */
    public static function quoteAddressRules(): array
    {
        return [
            'address' => ['nullable', 'array'],
            'address.city' => ['nullable', 'string', 'max:100'],
            'address.area' => ['nullable', 'string', 'max:255'],
            'address.postcode' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:25'],   // lets the quote check the per-customer and first-order rules of a coupon
        ];
    }

    public static function messages(): array
    {
        $max = (int) config('shop.max_items_per_order');

        return [
            'items.required_without' => 'Your order has no items.',
            'items.min' => 'Your order has no items.',
            'items.max' => "An order can have at most $max different items.",
            'items.*.variant_id.distinct' => 'The same option is listed twice. Send one line with the total quantity.',
            'items.*.qty.max' => 'The quantity of one item can be at most '.(int) config('shop.max_qty_per_line').'.',
            'items.*.qty.min' => 'The quantity must be at least 1.',
            'cart_token.size' => 'The cart token is not valid.',
        ];
    }
}
