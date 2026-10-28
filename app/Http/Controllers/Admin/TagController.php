<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Actions\SaveTag;
use App\Domain\Catalog\Models\Tag;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveTagRequest;
use App\Http\Resources\TagResource;
use App\Support\FullTextSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    /** Tags with their product count, paginated (?page ?per_page, default 15; ?q= start of the name). The product form dropdown uses `products/options` instead. */
    public function index(Request $request)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:60'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return TagResource::collection(
            Tag::query()->withCount('products')
                ->when($request->query('q'), fn ($q, $term) => $q->where('name', 'like', FullTextSearch::like($term).'%'))
                ->orderBy('sort_order')->orderBy('name')
                ->paginate((int) $request->query('per_page', 15))->withQueryString()
        );
    }

    public function show(Tag $tag): TagResource
    {
        return new TagResource($tag->loadCount('products'));
    }

    public function store(SaveTagRequest $request, SaveTag $save): JsonResponse
    {
        $tag = $save->create($request->validated(), $request->user());

        return (new TagResource($tag->loadCount('products')))->response()->setStatusCode(201);
    }

    public function update(SaveTagRequest $request, Tag $tag, SaveTag $save): TagResource
    {
        return new TagResource($save->update($tag, $request->validated(), $request->user())->loadCount('products'));
    }

    /** 422 for a system tag. A custom tag is removed from the products that carry it. */
    public function destroy(Request $request, Tag $tag, SaveTag $save): JsonResponse
    {
        $save->delete($tag, $request->user());

        return response()->json(['message' => 'Tag deleted.']);
    }
}
