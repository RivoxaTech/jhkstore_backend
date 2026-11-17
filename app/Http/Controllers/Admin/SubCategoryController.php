<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\DeleteCategory;
use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\SubCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveSubCategoryRequest;
use App\Http\Resources\SubCategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubCategoryController extends Controller
{
    public function show(SubCategory $subCategory): SubCategoryResource
    {
        return new SubCategoryResource($this->loaded($subCategory->id));
    }

    /** Add a sub-category to a category. multipart/form-data when sending `icon` / `image`. */
    public function store(SaveSubCategoryRequest $request, Category $category, SaveCategory $save): JsonResponse
    {
        $sub = $save->createSubCategory($category, $request->categoryData(), $request->file('icon'), $request->file('image'), $request->user());

        return (new SubCategoryResource($this->loaded($sub->id)))->response()->setStatusCode(201);
    }

    /** Update a sub-category. Use POST with `_method=PUT` when uploading files; `remove_icon=1` / `remove_image=1` delete a picture. */
    public function update(SaveSubCategoryRequest $request, SubCategory $subCategory, SaveCategory $save): SubCategoryResource
    {
        $save->updateSubCategory($subCategory, $request->categoryData(), $request->file('icon'), $request->file('image'), $request->user());

        return new SubCategoryResource($this->loaded($subCategory->id));
    }

    /** 422 while the sub-category still has products (deactivate it instead). */
    public function destroy(Request $request, SubCategory $subCategory, DeleteCategory $delete): JsonResponse
    {
        $delete->subCategory($subCategory, $request->user());

        return response()->json(['message' => 'Sub-category deleted.']);
    }

    private function loaded(int $id): SubCategory
    {
        return SubCategory::query()->withProductCounts()
            ->with(['media', 'category:id,name'])
            ->findOrFail($id);
    }
}
