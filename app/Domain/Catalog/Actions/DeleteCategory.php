<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\CategoryTree;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\SubCategory;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Deletes a category / sub-category, refusing (422) while it still has sub-categories or products. */
class DeleteCategory
{
    public function category(Category $category, Admin $admin): void
    {
        $subs = $category->subCategories()->count();
        if ($subs > 0) {
            $this->refuse("This category has {$subs} sub-categor".($subs === 1 ? 'y' : 'ies').'. Deactivate it instead of deleting.');
        }
        if ($this->products('category_id', $category->id) > 0) {
            $this->refuse('This category has products. Deactivate it instead of deleting.');
        }

        $category->delete();
        Audit::log($admin, 'category.deleted', $category);
        CategoryTree::forget();
    }

    public function subCategory(SubCategory $sub, Admin $admin): void
    {
        $children = $sub->children()->count();
        if ($children > 0) {
            $this->refuse("This sub-category has {$children} sub-categor".($children === 1 ? 'y' : 'ies').'. Deactivate it instead of deleting.');
        }
        if ($this->products('sub_category_id', $sub->id) > 0) {
            $this->refuse('This sub-category has products. Deactivate it instead of deleting.');
        }

        $sub->delete();
        Audit::log($admin, 'subcategory.deleted', $sub);
        CategoryTree::forget();
    }

    private function products(string $column, int $id): int
    {
        return DB::table('products')->where($column, $id)->whereNull('deleted_at')->count();
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['category' => $message]);
    }
}
