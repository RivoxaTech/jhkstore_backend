<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `images[]` = the files; `variant_id` (optional) = put them in the gallery of that option instead of the product's own. */
class UploadProductImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function attributes(): array
    {
        return ['images' => 'pictures', 'images.*' => 'picture'];
    }

    public function messages(): array
    {
        return [
            'images.*.max' => 'Each picture must be 8 MB or smaller.',
            'images.*.mimes' => 'Pictures must be PNG, JPG or WEBP.',
            'images.*.dimensions' => 'Each picture must be between 100 and 8000 px on each side.',
        ];
    }

    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:12'],
            'images.*' => ['image', 'mimes:png,jpg,jpeg,webp', 'max:8192', 'dimensions:min_width=100,min_height=100,max_width=8000,max_height=8000'],
            'variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $this->route('product')->id)],
        ];
    }
}
