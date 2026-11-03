<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /store/checkout/quote { items: [{variant_id, qty}] | cart_token, coupon_code?, address?: {city, area, postcode}, phone? }
 * Stateless: nothing is stored. `items` or `cart_token` (the persistent cart).
 */
class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return CheckoutItemsRules::rules() + CheckoutItemsRules::quoteAddressRules();
    }

    public function messages(): array
    {
        return CheckoutItemsRules::messages();
    }
}
