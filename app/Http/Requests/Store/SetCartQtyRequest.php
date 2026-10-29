<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/** Body of PUT cart/items/{variant}: the new quantity of the line (to take the line out use DELETE). */
class SetCartQtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['qty' => ['required', 'integer', 'min:1', 'max:1000']];
    }

    public function messages(): array
    {
        return [
            'qty.required' => 'Enter a quantity.',
            'qty.min' => 'The quantity must be at least 1. Remove the item to take it out of the cart.',
            'qty.integer' => 'The quantity must be a whole number.',
        ];
    }
}
