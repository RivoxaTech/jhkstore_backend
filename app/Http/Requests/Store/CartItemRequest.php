<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/** POST /store/cart/items { variant_id, qty } (add) and PUT /store/cart/items/{variantId} { qty } (set). */
class CartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variant_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'qty' => ['required', 'integer', 'min:1', 'max:'.(int) config('shop.max_qty_per_line')],
        ];
    }

    public function messages(): array
    {
        return [
            'qty.min' => 'The quantity must be at least 1.',
            'qty.max' => 'The quantity of one item can be at most '.(int) config('shop.max_qty_per_line').'.',
        ];
    }
}
