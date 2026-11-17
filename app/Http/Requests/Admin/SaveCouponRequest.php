<?php

namespace App\Http\Requests\Admin;

use App\Domain\Promotion\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A coupon: the code (3 to 32 of A-Z 0-9 - _, stored in capitals, unique whatever the case), the discount (percentage, fixed amount, or free delivery)
 * with its scope, the date window and the limits (minimum order, maximum discount, total uses, uses per customer, first order only).
 */
class SaveCouponRequest extends FormRequest
{
    use PromotionRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareTargets();
        if ($this->has('code')) {
            $this->merge(['code' => Coupon::normaliseCode((string) $this->input('code'))]);
        }
    }

    public function rules(): array
    {
        $coupon = $this->route('coupon');

        return $this->commonRules(requireValue: false) + [
            'code' => ['required', 'string', 'regex:'.Coupon::CODE_PATTERN, Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'min_order' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'max_discount' => ['nullable', 'numeric', 'gt:0', 'max:1000000000'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'first_order_only' => ['sometimes', 'boolean'],
            'free_delivery' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Enter the coupon code.',
            'code.regex' => 'The code needs 3 to 32 letters, digits, dashes or underscores.',
            'code.unique' => 'This code is already used by another coupon.',
            'name.required' => 'Enter a name for the coupon.',
            'type.required_unless' => 'Choose a percentage or a fixed amount, or switch on free delivery.',
            'value.required_unless' => 'Enter the value, or switch on free delivery.',
            'max_discount.gt' => 'The maximum discount must be more than 0.',
            'usage_limit.min' => 'The total number of uses must be at least 1.',
            'per_customer_limit.min' => 'The uses per customer must be at least 1.',
        ] + $this->commonMessages();
    }
}
