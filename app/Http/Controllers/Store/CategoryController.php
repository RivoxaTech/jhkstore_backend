<?php

namespace App\Http\Controllers\Store;

use App\Domain\Catalog\CategoryTree;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Active categories with their active sub-categories and sub-sub-categories (`children`). Cached 10 minutes.
     * `?top=1` = only the top categories (home screen).
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => CategoryTree::storefront($request->boolean('top'))]);
    }

    /** One active category by slug, with its active sub-categories and their children. */
    public function show(string $slug): JsonResponse
    {
        $category = CategoryTree::find($slug);
        abort_unless($category, 404);

        return response()->json(['data' => $category]);
    }
}
