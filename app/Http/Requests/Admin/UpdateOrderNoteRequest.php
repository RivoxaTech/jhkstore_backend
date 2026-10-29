<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['admin_note' => ['nullable', 'string', 'max:2000']];
    }

    public function messages(): array
    {
        return ['admin_note.max' => 'The note can be at most 2000 characters.'];
    }
}
