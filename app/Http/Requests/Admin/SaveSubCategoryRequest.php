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
        // The names of siblings must differ: same category and same parent (null = first level).
        $categoryId = $sub?->category_id ?? $this->route('parent')?->category_id ?? $this->route('category')->id;
        $parentId = $sub ? $sub->parent_id : $this->route('parent')?->id;

        return [
            'name' => [
                $sub ? 'sometimes' : 'required', 'string', 'max:160',
                Rule::unique('sub_categories', 'name')->where('category_id', $categoryId)
                    ->where(fn ($q) => $parentId ? $q->where('parent_id', $parentId) : $q->whereNull('parent_id'))
                    ->whereNull('deleted_at')->ignore($sub?->id),
            ],
        ] + $this->categoryRules();
    }
}
