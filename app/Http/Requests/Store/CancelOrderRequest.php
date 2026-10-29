<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:255']];
    }

    public function messages(): array
    {
        return ['reason.max' => 'The reason can be at most 255 characters.'];
    }
}
