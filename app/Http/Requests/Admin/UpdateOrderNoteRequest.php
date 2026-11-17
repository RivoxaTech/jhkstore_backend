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
        // `present`: a request without the field is refused instead of silently clearing the note (send null or "" to clear it on purpose)
        return ['admin_note' => ['present', 'nullable', 'string', 'max:2000']];
    }

    public function messages(): array
    {
        return [
            'admin_note.present' => 'Send the note in the admin_note field (null or empty clears it).',
            'admin_note.max' => 'The note can be at most 2000 characters.',
        ];
    }
}
