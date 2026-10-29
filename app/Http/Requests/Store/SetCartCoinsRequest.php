<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/** Body of PUT cart/coins: how many coins the customer wants to use (0 = none). The server clamps it. */
class SetCartCoinsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['coins_to_redeem' => ['required', 'integer', 'min:0', 'max:10000000']];
    }

    public function messages(): array
    {
        return [
            'coins_to_redeem.required' => 'Enter how many coins to use.',
            'coins_to_redeem.integer' => 'Coins must be a whole number.',
            'coins_to_redeem.min' => 'Coins cannot be negative.',
        ];
    }
}
