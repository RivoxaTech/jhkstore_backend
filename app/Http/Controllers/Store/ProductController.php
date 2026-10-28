<?php

namespace App\Http\Controllers\Store;

use App\Domain\Catalog\ProductListing;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoreProductCardResource;
use App\Http\Resources\StoreProductResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Product pages of the customer site. `channel` middleware: ?channel=standard (default) | instadent (login required). */
class ProductController extends Controller
{
    /**
     * Product cards. Homepage sections are this endpoint with a tag, e.g. ?tag=best-seller&per_page=10.
     * Query: ?tag ?category ?sub_category ?brand (slugs) ?q ?min_price ?max_price ?in_stock=1 ?rating=4
     * ?channel ?sort=popularity|newest|price_asc|price_desc|discount|rating ?page ?per_page (max 100)
     */
    public function index(Request $request, ProductListing $listing)
    {
        $filters = $request->validate([
            'tag' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:180'],
            'sub_category' => ['nullable', 'string', 'max:180'],
            'brand' => ['nullable', 'string', 'max:180'],
            'q' => ['nullable', 'string', 'max:100'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'in_stock' => ['nullable', 'boolean'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'sort' => ['nullable', Rule::in(ProductListing::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return StoreProductCardResource::collection(
            $listing->paginate($filters, $request->attributes->get('channel'), (int) ($filters['per_page'] ?? 24))
        );
    }

    /** Product page: card fields + description, content tabs, variants (stock of the channel), images and related products. */
    public function show(Request $request, string $slug, ProductListing $listing): StoreProductResource
    {
        return new StoreProductResource($listing->find($slug, $request->attributes->get('channel')));
    }
}
