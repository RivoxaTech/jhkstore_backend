<?php

namespace App\Http\Requests;

/** Fields shared by the category and sub-category forms (name rules differ, so they are added by each request). */
trait CategoryPayloadRules
{
    protected function categoryRules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:5000'],
            'color' => ['nullable', 'string', 'regex:/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
            'remove_icon' => ['sometimes', 'boolean'],
            'remove_image' => ['sometimes', 'boolean'],
        ];
    }

    /** Column values plus the remove_* flags; the uploaded files are read with $request->file(). */
    public function categoryData(): array
    {
        return collect($this->validated())->except(['icon', 'image'])->all();
    }
}
