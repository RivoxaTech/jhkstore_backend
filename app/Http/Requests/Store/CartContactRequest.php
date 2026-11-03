<?php

namespace App\Http\Requests\Store;

use App\Domain\Customer\Phone;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** PUT /store/cart/contact { name?, phone?, email? }: saved so the shop can follow up an abandoned cart. Only the keys that are sent change. */
class CartContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:25', function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== null && $value !== '' && ! Phone::isValid((string) $value)) {
                    $fail('Enter a valid phone number (7 to 15 digits, optionally starting with +).');
                }
            }],
            'email' => ['nullable', 'email:rfc', 'max:190'],
        ];
    }
}
