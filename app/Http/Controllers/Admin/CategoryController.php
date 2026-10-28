<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\DeleteCategory;
use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\SubCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCategoryRequest;
use App\Http\Resources\CategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Categories (paginated: ?page ?per_page, default 15), each with its sub-categories and counts; `counts` has the totals of everything.
     * Query: ?q= matches a category name, or the name of one of its sub-categories or sub-sub-categories.
     */
    public function index(Request $request)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        $categories = Category::query()
            ->withProductCounts()
            ->with(['media', 'subCategories' => fn ($q) => $q->withProductCounts()->with(['media', 'children' => fn ($c) => $c->withProductCounts()->with('media')])])
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%$term%")
                ->orWhereHas('subCategories', fn ($s) => $s->where('name', 'like', "%$term%")
                    ->orWhereHas('children', fn ($c) => $c->where('name', 'like', "%$term%")))))
            ->orderBy('sort_order')->orderBy('name')
            ->paginate((int) $request->query('per_page', 15))
            ->withQueryString();

        return CategoryResource::collection($categories)->additional(['counts' => [
            'categories' => Category::count(),
            'top' => Category::where('is_top', true)->count(),
            'sub_categories' => SubCategory::whereNull('parent_id')->count(),
            'children' => SubCategory::whereNotNull('parent_id')->count(),
        ]]);
    }

    /** Light tree of ACTIVE categories, sub-categories and sub-sub-categories (`children`), for dropdowns (e.g. the product form). */
    public function options(): JsonResponse
    {
        $tree = Category::query()
            ->where('is_active', true)
            ->with(['subCategories' => fn ($q) => $q->where('is_active', true)->select('id', 'category_id', 'parent_id', 'name', 'sort_order')
                ->with(['children' => fn ($c) => $c->where('is_active', true)->select('id', 'category_id', 'parent_id', 'name', 'sort_order')])])
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'sub_categories' => $category->subCategories->map(fn ($sub) => [
                    'id' => $sub->id,
                    'name' => $sub->name,
                    'children' => $sub->children->map(fn ($child) => ['id' => $child->id, 'name' => $child->name])->values(),
                ])->values(),
            ]);

        return response()->json(['data' => $tree]);
    }

    public function show(Category $category): CategoryResource
    {
        return new CategoryResource($this->loaded($category->id));
    }

    /** Create a category. multipart/form-data when sending `icon` / `image`. */
    public function store(SaveCategoryRequest $request, SaveCategory $save): JsonResponse
    {
        $category = $save->createCategory($request->categoryData(), $request->file('icon'), $request->file('image'), $request->user());

        return (new CategoryResource($this->loaded($category->id)))->response()->setStatusCode(201);
    }

    /**
     * Update a category (send only the fields to change). Use POST with `_method=PUT` when uploading files.
     * `remove_icon=1` / `remove_image=1` delete a picture.
     */
    public function update(SaveCategoryRequest $request, Category $category, SaveCategory $save): CategoryResource
    {
        $save->updateCategory($category, $request->categoryData(), $request->file('icon'), $request->file('image'), $request->user());

        return new CategoryResource($this->loaded($category->id));
    }

    /** 422 while the category still has sub-categories or products (deactivate it instead). */
    public function destroy(Request $request, Category $category, DeleteCategory $delete): JsonResponse
    {
        $delete->category($category, $request->user());

        return response()->json(['message' => 'Category deleted.']);
    }

    private function loaded(int $id): Category
    {
        return Category::query()
            ->withProductCounts()
            ->with(['media', 'subCategories' => fn ($q) => $q->withProductCounts()->with(['media', 'children' => fn ($c) => $c->withProductCounts()->with('media')])])
            ->findOrFail($id);
    }
}
