<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\CategoryPayloadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (category in URL) or update (sub-category in URL) a sub-category. Multipart so an icon/image can be sent. */
class SaveSubCategoryRequest extends FormRequest
{
    use CategoryPayloadRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $sub = $this->route('subCategory');
        // The names of sub-categories of one category must differ.
        $categoryId = $sub?->category_id ?? $this->route('category')->id;

        return [
            'name' => [
                $sub ? 'sometimes' : 'required', 'string', 'max:160',
                Rule::unique('sub_categories', 'name')->where('category_id', $categoryId)->whereNull('deleted_at')->ignore($sub?->id),
            ],
        ] + $this->categoryRules();
    }
}
