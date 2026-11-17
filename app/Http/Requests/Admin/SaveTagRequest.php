<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST /tags) or update (tag in URL) a tag. */
class SaveTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tag = $this->route('tag');

        return [
            'name' => [$tag ? 'sometimes' : 'required', 'string', 'max:60', Rule::unique('tags', 'name')->ignore($tag?->id)],
            'color' => ['nullable', 'string', 'regex:/^#([0-9a-f]{3}|[0-9a-f]{6})$/i'],
            'style' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
