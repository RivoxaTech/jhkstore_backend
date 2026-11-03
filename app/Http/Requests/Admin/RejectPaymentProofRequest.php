<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** POST /admin/orders/{id}/payment/reject-proof { reason }: the customer must upload a new proof. */
class RejectPaymentProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:300']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Tell the customer why the proof was not accepted.', 'reason.max' => 'The reason can be at most 300 characters.'];
    }
}
