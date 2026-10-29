<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** The shop rules of the Settings screen: delivery, coins, cash on delivery and the minimum order. */
class UpdateCommerceSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delivery_fee' => ['required', 'numeric', 'min:0', 'max:100000'],
            'free_delivery_min_cart' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'coin_value' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'cod_max_amount' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'min_order_amount' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'max_redeem_coins_per_order' => ['required', 'integer', 'min:0', 'max:100000000'],
            'redeem_cap_order_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'redeem_cap_balance_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'channels' => ['sometimes', 'array'],
            'channels.*.code' => ['required', 'string', 'exists:sales_channels,code'],
            'channels.*.delivery_days' => ['required', 'integer', 'min:0', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'delivery_fee.required' => 'Enter the delivery charge (0 for free delivery on every order).',
            'free_delivery_min_cart.required' => 'Enter the free-delivery limit (0 = delivery is never free).',
            'coin_value.required' => 'Enter how much one coin is worth.',
            'coin_value.min' => 'One coin must be worth at least 0.01.',
            'cod_max_amount.required' => 'Enter the cash-on-delivery limit (0 = no limit).',
            'min_order_amount.required' => 'Enter the minimum order amount (0 = no minimum).',
            'max_redeem_coins_per_order.required' => 'Enter the most coins one order may use.',
            'redeem_cap_order_percent.max' => 'A percentage cannot be more than 100.',
            'redeem_cap_balance_percent.max' => 'A percentage cannot be more than 100.',
        ];
    }
}
