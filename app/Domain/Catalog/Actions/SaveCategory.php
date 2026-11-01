<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\CategoryTree;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\SubCategory;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** Creates / updates a category or a sub-category (admin side), including icon and image uploads. */
class SaveCategory
{
    public function createCategory(array $data, ?UploadedFile $icon, ?UploadedFile $image, Admin $admin): Category
    {
        $this->guardTopLimit(null, $data);
        $category = new Category($this->fields($data));
        $category->sort_order = $data['sort_order'] ?? (Category::max('sort_order') + 1);
        $category->slug = Category::uniqueSlug($data['name']);
        $category->save();

        $this->attachImages($category, $icon, $image);
        Audit::log($admin, 'category.created', $category);
        CategoryTree::forget();

        return $category;
    }

    public function updateCategory(Category $category, array $data, ?UploadedFile $icon, ?UploadedFile $image, Admin $admin): Category
    {
        $this->guardTopLimit($category, $data);
        $this->applyUpdate($category, $data, $icon, $image, $admin, 'category.updated');

        return $category;
    }

    public function createSubCategory(Category $category, array $data, ?UploadedFile $icon, ?UploadedFile $image, Admin $admin): SubCategory
    {
        return $this->newSub($category->id, $data, $icon, $image, $admin);
    }

    private function newSub(int $categoryId, array $data, ?UploadedFile $icon, ?UploadedFile $image, Admin $admin): SubCategory
    {
        $sub = new SubCategory($this->fields($data));
        $sub->category_id = $categoryId;
        $sub->sort_order = $data['sort_order'] ?? (SubCategory::where('category_id', $categoryId)->max('sort_order') + 1);
        $sub->slug = SubCategory::uniqueSlug($data['name']);
        $sub->save();

        $this->attachImages($sub, $icon, $image);
        Audit::log($admin, 'subcategory.created', $sub);
        CategoryTree::forget();

        return $sub;
    }

    public function updateSubCategory(SubCategory $sub, array $data, ?UploadedFile $icon, ?UploadedFile $image, Admin $admin): SubCategory
    {
        $this->applyUpdate($sub, $data, $icon, $image, $admin, 'subcategory.updated');

        return $sub;
    }

    private function applyUpdate(Category|SubCategory $model, array $data, ?UploadedFile $icon, ?UploadedFile $image, Admin $admin, string $action): void
    {
        // The slug follows the name.
        if (isset($data['name']) && $data['name'] !== $model->name) {
            $model->slug = $model::uniqueSlug($data['name'], $model->id);
        }
        $model->fill($this->fields($data))->save();

        if (($data['remove_icon'] ?? false) && ! $icon) {
            $model->clearMediaCollection('icon');
        }
        if (($data['remove_image'] ?? false) && ! $image) {
            $model->clearMediaCollection('image');
        }
        $this->attachImages($model, $icon, $image);

        Audit::log($admin, $action, $model, $model->getChanges());
        CategoryTree::forget();
    }

    /** At most Category::MAX_TOP categories can be shown on the home screen. */
    private function guardTopLimit(?Category $category, array $data): void
    {
        if (empty($data['is_top']) || $category?->is_top) {
            return;
        }
        if (Category::where('is_top', true)->count() >= Category::MAX_TOP) {
            throw ValidationException::withMessages(['is_top' => 'Only '.Category::MAX_TOP.' categories can be top categories. Remove one first.']);
        }
    }

    /** Column values only (files and remove flags are handled separately; an empty sort_order keeps the current one). */
    private function fields(array $data): array
    {
        $fields = collect($data)->except(['icon', 'image', 'remove_icon', 'remove_image'])->all();
        if (array_key_exists('sort_order', $fields) && $fields['sort_order'] === null) {
            unset($fields['sort_order']);
        }

        return $fields;
    }

    private function attachImages(Category|SubCategory $model, ?UploadedFile $icon, ?UploadedFile $image): void
    {
        if ($icon) {
            $model->addMedia($icon)->toMediaCollection('icon');
        }
        if ($image) {
            $model->addMedia($image)->toMediaCollection('image');
        }
    }
}
