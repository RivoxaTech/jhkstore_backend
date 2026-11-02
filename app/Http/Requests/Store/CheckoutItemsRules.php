<?php

namespace App\Http\Requests\Store;

/** The `items` part shared by the quote and the order request: option ids and quantities, with the abuse limits of config/shop.php. */
class CheckoutItemsRules
{
    public static function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.(int) config('shop.max_items_per_order')],
            'items.*' => ['array'],
            'items.*.variant_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:'.(int) config('shop.max_qty_per_line')],
        ];
    }

    public static function messages(): array
    {
        $max = (int) config('shop.max_items_per_order');

        return [
            'items.required' => 'Your order has no items.',
            'items.min' => 'Your order has no items.',
            'items.max' => "An order can have at most $max different items.",
            'items.*.variant_id.distinct' => 'The same option is listed twice. Send one line with the total quantity.',
            'items.*.qty.max' => 'The quantity of one item can be at most '.(int) config('shop.max_qty_per_line').'.',
            'items.*.qty.min' => 'The quantity must be at least 1.',
        ];
    }
}
