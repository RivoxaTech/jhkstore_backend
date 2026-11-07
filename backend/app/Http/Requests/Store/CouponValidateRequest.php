<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/** POST /store/coupons/validate { code, items | cart_token, address?, phone? }: the quote with the coupon applied. */
class CouponValidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:32']] + CheckoutItemsRules::rules() + CheckoutItemsRules::quoteAddressRules();
    }

    public function messages(): array
    {
        return ['code.required' => 'Enter the coupon code.'] + CheckoutItemsRules::messages();
    }
}
