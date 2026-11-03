<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** PUT /admin/orders/{id}/delivery-fee { fee }: confirms the delivery fee of an order that was placed without one. */
class SetDeliveryFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['fee' => ['required', 'numeric', 'min:0', 'max:1000000']];
    }

    public function messages(): array
    {
        return ['fee.required' => 'Enter the delivery fee (0 for free delivery).', 'fee.numeric' => 'The delivery fee must be a number.', 'fee.min' => 'The delivery fee cannot be negative.'];
    }
}
