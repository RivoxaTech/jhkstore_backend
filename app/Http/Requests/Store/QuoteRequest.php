<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/** POST /store/checkout/quote { items: [{variant_id, qty}] } */
class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return CheckoutItemsRules::rules();
    }

    public function messages(): array
    {
        return CheckoutItemsRules::messages();
    }
}
