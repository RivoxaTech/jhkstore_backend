<?php

namespace App\Http\Requests\Store;

use App\Domain\Customer\Phone;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /store/wholesale-requests: a guest asks for a bulk price. Name and phone are required; the product is a catalogue product (and option)
 * or free text (`product_name`); quantity at least 1.
 */
class WholesaleRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'phone', 'email', 'business_name', 'product_name', 'message'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:25', function (string $attribute, mixed $value, Closure $fail) {
                if (! Phone::isValid((string) $value)) {
                    $fail('Enter a valid phone number (7 to 15 digits, optionally starting with +).');
                }
            }],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'business_name' => ['nullable', 'string', 'max:150'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'variant_id' => ['nullable', 'integer', 'required_with:product_id', Rule::exists('product_variants', 'id')->where('product_id', $this->input('product_id'))],
            'product_name' => ['nullable', 'required_without:product_id', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000000'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_name.required_without' => 'Tell us which product you want, or choose one from the shop.',
            'variant_id.required_with' => 'Choose the option of the product.',
            'variant_id.exists' => 'That option does not belong to the chosen product.',
            'quantity.min' => 'The quantity must be at least 1.',
        ];
    }
}
