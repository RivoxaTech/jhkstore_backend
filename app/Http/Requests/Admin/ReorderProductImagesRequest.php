<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReorderProductImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['regex:/^r?\d+$/', 'distinct']]; // media id, or r<id> for an imported picture
    }
}
