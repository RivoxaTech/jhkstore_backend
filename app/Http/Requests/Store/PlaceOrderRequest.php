<?php

namespace App\Http\Requests\Store;

use App\Domain\Order\Enums\PaymentMethod;
use App\Http\Requests\AddressPayloadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('address'))) {
            $this->merge(['address' => AddressPayloadRules::normalise($this->input('address'))]);
        }
    }

    public function rules(): array
    {
        return [
            'address_id' => ['nullable', 'integer', 'required_without:address'],
            'address' => ['nullable', 'array', 'required_without:address_id'],
            'save_address' => ['sometimes', 'boolean'],
            'billing_address_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', Rule::in(PaymentMethod::values())],
            'coins_to_redeem' => ['nullable', 'integer', 'min:0'],
            'customer_note' => ['nullable', 'string', 'max:500'],
            'expected_total' => ['nullable', 'numeric', 'min:0'],
        ] + ($this->filled('address') ? AddressPayloadRules::rules('address.') : []);
    }

    public function messages(): array
    {
        return [
            'address_id.required_without' => 'Choose a delivery address.',
            'address.required_without' => 'Choose a delivery address.',
            'payment_method.in' => 'Choose a valid payment method (cod).',
            'coins_to_redeem.min' => 'Coins to redeem cannot be negative.',
            'customer_note.max' => 'The note can be at most 500 characters.',
        ] + AddressPayloadRules::messages('address.');
    }
}
