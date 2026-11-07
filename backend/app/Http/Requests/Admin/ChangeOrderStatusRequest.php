<?php

namespace App\Http\Requests\Admin;

use App\Domain\Order\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Moves an order one step: confirmed | packed | shipped (courier + AWB) | delivered | cancelled (reason) | rejected (reason, only from pending). */
class ChangeOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_diff(OrderStatus::values(), [OrderStatus::Pending->value]))],
            'note' => ['nullable', 'string', 'max:255'],
            'reason' => ['required_if:status,cancelled,rejected', 'nullable', 'string', 'max:300'],
            'courier' => ['required_if:status,shipped', 'nullable', 'string', 'max:60'],
            'awb_number' => ['required_if:status,shipped', 'nullable', 'string', 'max:60'],
            'tracking_url' => ['nullable', 'url', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Choose the new status.',
            'status.in' => 'Choose confirmed, packed, shipped, delivered, cancelled or rejected.',
            'reason.required_if' => 'Enter the reason for cancelling or rejecting this order.',
            'reason.max' => 'The reason can be at most 300 characters.',
            'courier.required_if' => 'Enter the courier to ship this order.',
            'awb_number.required_if' => 'Enter the AWB / tracking number to ship this order.',
            'tracking_url.url' => 'Enter a valid tracking link (starting with http).',
        ];
    }
}
