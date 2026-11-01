<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create or update (brand in URL) a brand. Multipart so a logo/banner can be sent. */
class SaveBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $brand = $this->route('brand');
        $req = $brand ? 'sometimes' : 'required';

        return [
            'name' => [
                $req, 'string', 'max:160',
                Rule::unique('brands', 'name')->whereNull('deleted_at')->ignore($brand?->id),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'remove_logo' => ['sometimes', 'boolean'],
            'banner' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
        ];
    }

    public function brandData(): array
    {
        return collect($this->validated())->except(['logo', 'banner'])->all(); // `remove_logo` is read by SaveBrand
    }
}
