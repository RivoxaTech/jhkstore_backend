<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** `value` = true or false: the switch of PATCH products/{id}/sold-out and PATCH products/{id}/featured. */
class SetProductFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['value' => ['required', 'boolean']];
    }
}
