<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\CategoryPayloadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST /categories) or update (category in URL) a category. Multipart so an icon/image can be sent. */
class SaveCategoryRequest extends FormRequest
{
    use CategoryPayloadRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name' => [
                $category ? 'sometimes' : 'required', 'string', 'max:160',
                Rule::unique('categories', 'name')->whereNull('deleted_at')->ignore($category?->id),
            ],
            'is_top' => ['sometimes', 'boolean'], // shown on the home screen (max 12, checked by SaveCategory)
        ] + $this->categoryRules();
    }
}
