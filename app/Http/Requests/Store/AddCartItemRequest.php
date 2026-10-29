<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/** Body of POST cart/items: which option and how many to add. */
class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'qty' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'variant_id.required' => 'Choose an option to add.',
            'variant_id.exists' => 'This item is not available.',
            'qty.required' => 'Enter a quantity.',
            'qty.min' => 'The quantity must be at least 1.',
            'qty.integer' => 'The quantity must be a whole number.',
        ];
    }
}
