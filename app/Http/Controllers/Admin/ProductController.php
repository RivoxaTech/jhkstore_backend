<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\ManageProductImages;
use App\Domain\Catalog\Actions\ManageProductVideos;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductContentTab;
use App\Domain\Catalog\Models\Tag;
use App\Domain\Catalog\ProductImages;
use App\Domain\Catalog\ProductSearch;
use App\Domain\Catalog\ProductVideos;
use App\Domain\Support\Settings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderProductImagesRequest;
use App\Http\Requests\Admin\SaveProductRequest;
use App\Http\Requests\Admin\SetProductFlagRequest;
use App\Http\Requests\Admin\SetProductStatusRequest;
use App\Http\Requests\Admin\SetProductTagsRequest;
use App\Http\Requests\Admin\UploadProductImagesRequest;
use App\Http\Requests\Admin\UploadProductVideoRequest;
use App\Http\Resources\ProductListResource;
use App\Http\Resources\ProductResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /** Relations the full product view needs. */
    private const FULL = [
        'category:id,name', 'subCategory:id,name', 'tags',
        'contentTabs', 'media', 'remoteImages', 'variants.priceTiers', 'related.media', 'related.remoteImages',
    ];

    /** What the dropdowns of the product form need: tags, statuses. */
    public function options(Settings $settings): JsonResponse
    {
        return response()->json([
            'tags' => Tag::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'slug', 'color', 'is_active']),
            'currency' => ['code' => $settings->currencyCode(), 'symbol' => $settings->currencySymbol(), 'decimals' => $settings->decimals()],
            'statuses' => ProductStatus::values(),
            'video' => ['max_files' => (int) config('shop.video.max_files'), 'max_mb' => round(ProductVideos::effectiveMaxKb() / 1024, 1), 'poster_max_mb' => round((int) config('shop.video.poster_max_kb') / 1024, 1), 'types' => ['video/mp4', 'video/webm']],
            'content_tabs' => collect(ProductContentTab::TABS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    /**
     * Lean product list. Filters: ?q (name, SKU, variant SKU) ?status ?category_id ?sub_category_id
     * ?tag (slug) ?stock (in | low | out | sold_out) ?featured (1) ?page ?per_page. Newest first; `counts` = products per status.
     */
    public function index(Request $request)
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(ProductStatus::values())],
            'category_id' => ['nullable', 'integer'],
            'sub_category_id' => ['nullable', 'integer'],
            'tag' => ['nullable', 'string', 'max:80'],
            'stock' => ['nullable', Rule::in(['in', 'low', 'out', 'sold_out'])],
            'featured' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $products = Product::query()
            ->with(['category:id,name', 'subCategory:id,name', 'tags', 'media', 'remoteImages', 'variants'])
            ->when($request->query('q'), fn ($q, $term) => ProductSearch::apply($q, $term, includeVariantSku: true))
            ->when($request->query('status'), fn ($q, $v) => $q->where('products.status', $v))
            ->when($request->query('category_id'), fn ($q, $v) => $q->where('products.category_id', $v))
            ->when($request->query('sub_category_id'), fn ($q, $id) => $q->where('products.sub_category_id', $id))
            ->when($request->query('tag'), fn ($q, $slug) => $q->whereHas('tags', fn ($t) => $t->where('tags.slug', $slug)))
            ->when($request->query('stock'), fn ($q, $v) => match ($v) {
                'in' => $q->where('products.is_in_stock', true)->where('products.is_sold_out', false),
                'out' => $q->where('products.is_in_stock', false),
                'sold_out' => $q->where('products.is_sold_out', true),
                default => $q->whereExists(fn ($w) => $w->select(DB::raw(1))->from('product_variants as lv')->whereColumn('lv.product_id', 'products.id')
                    ->where('lv.is_active', true)->whereRaw('lv.stock_qty - lv.reserved_qty <= lv.low_stock_threshold')),
            })
            ->when($request->boolean('featured'), fn ($q) => $q->where('products.is_featured', true))
            ->orderBy('products.sort_order')->orderByDesc('products.id')   // the order the owner arranged (new products: first)
            ->paginate((int) $request->query('per_page', 20))
            ->withQueryString();

        return ProductListResource::collection($products)->additional([
            'counts' => Product::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($this->loaded($product->id));
    }

    /** Create a product with its variants, tags and content tabs (JSON). Pictures go to POST /products/{id}/images. */
    public function store(SaveProductRequest $request, SaveProduct $save): JsonResponse
    {
        $product = $save->create($request->productData(), $request->user());

        return (new ProductResource($this->loaded($product->id)))->response()->setStatusCode(201);
    }

    /** Update a product (send only what changes; `variants`, `tag_ids`, `content_tabs` replace the whole list). */
    public function update(SaveProductRequest $request, Product $product, SaveProduct $save): ProductResource
    {
        $save->update($product, $request->productData(), $request->user());

        return new ProductResource($this->loaded($product->id));
    }

    /** Soft delete. */
    public function destroy(Request $request, Product $product, SaveProduct $save): JsonResponse
    {
        $save->delete($product, $request->user());

        return response()->json(['message' => 'Product deleted.']);
    }

    /** Quick switch: draft | active | inactive | archived. */
    public function status(SetProductStatusRequest $request, Product $product, SaveProduct $save): ProductResource
    {
        $save->setStatus($product, $request->validated('status'), $request->user());

        return new ProductResource($this->loaded($product->id));
    }

    /** PATCH products/{id}/sold-out { value }: the product stays visible as "Sold out" and cannot be ordered. */
    public function soldOut(SetProductFlagRequest $request, Product $product, SaveProduct $save): ProductResource
    {
        $save->setSoldOut($product, $request->boolean('value'), $request->user());

        return new ProductResource($this->loaded($product->id));
    }

    /** PUT products/reorder { ids }: the products of one page of the list in their new order (drag and drop). */
    public function reorder(Request $request, SaveProduct $save): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:2', 'max:100'],
            'ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
        ]);
        $save->reorder(array_map('intval', $data['ids']), $request->user());

        return response()->json(['message' => 'Order saved.']);
    }

    /** PATCH products/{id}/featured { value } */
    public function featured(SetProductFlagRequest $request, Product $product, SaveProduct $save): ProductResource
    {
        $save->setFeatured($product, $request->boolean('value'), $request->user());

        return new ProductResource($this->loaded($product->id));
    }

    /** Quick edit of the tags (the list is replaced). */
    public function tags(SetProductTagsRequest $request, Product $product, SaveProduct $save): ProductResource
    {
        $save->setTags($product, $request->validated('tag_ids'), $request->user());

        return new ProductResource($this->loaded($product->id));
    }

    // ------------------------------------------------------------------ pictures

    /**
     * Upload one or more pictures (multipart: `images[]`). The first picture of a gallery is its primary one.
     * Add `variant_id` to put them in the gallery of that option (each option can have its own pictures).
     */
    public function addImages(UploadProductImagesRequest $request, Product $product, ManageProductImages $images): JsonResponse
    {
        $images->add($product, $request->file('images'), $request->user(), $request->filled('variant_id') ? (int) $request->input('variant_id') : null);

        return response()->json(['data' => ProductImages::all($this->loaded($product->id))], 201);
    }

    /** Delete a picture: the media id, or "r<id>" for an imported remote picture. */
    public function deleteImage(Request $request, Product $product, string $image, ManageProductImages $images): JsonResponse
    {
        $images->delete($product, $image, $request->user());

        return response()->json(['data' => ProductImages::all($this->loaded($product->id))]);
    }

    /** Make a picture the primary one of its gallery (the product's, or its option's). Same ids as the delete call. */
    public function primaryImage(Request $request, Product $product, string $image, ManageProductImages $images): JsonResponse
    {
        $images->makePrimary($product, $image, $request->user());

        return response()->json(['data' => ProductImages::all($this->loaded($product->id))]);
    }

    /** Send every uploaded picture id of ONE gallery (the product's, or one option's) in the wanted order (`ids`): the first becomes primary. */
    public function reorderImages(ReorderProductImagesRequest $request, Product $product, ManageProductImages $images): JsonResponse
    {
        $images->reorder($product, array_map('strval', $request->validated('ids')), $request->user());

        return response()->json(['data' => ProductImages::all($this->loaded($product->id))]);
    }

    // ------------------------------------------------------------------ videos

    /** Upload a video (multipart: `video`, optional `poster`). Up to config shop.video.max_files per product; mp4 / webm, no transcoding. */
    public function addVideo(UploadProductVideoRequest $request, Product $product, ManageProductVideos $videos): JsonResponse
    {
        $videos->add($product, $request->file('video'), $request->file('poster'), $request->user());

        return response()->json(['data' => ProductVideos::forAdmin($this->loaded($product->id))], 201);
    }

    /** Replace the poster picture of a video (multipart: `poster`). */
    public function videoPoster(UploadProductVideoRequest $request, Product $product, int $video, ManageProductVideos $videos): JsonResponse
    {
        $videos->setPoster($product, $video, $request->file('poster'), $request->user());

        return response()->json(['data' => ProductVideos::forAdmin($this->loaded($product->id))]);
    }

    public function deleteVideo(Request $request, Product $product, int $video, ManageProductVideos $videos): JsonResponse
    {
        $videos->delete($product, $video, $request->user());

        return response()->json(['data' => ProductVideos::forAdmin($this->loaded($product->id))]);
    }

    /** PUT products/{id}/videos/order { ids }: every uploaded video id once, in the wanted order. */
    public function reorderVideos(Request $request, Product $product, ManageProductVideos $videos): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:10'], 'ids.*' => ['integer']])['ids'];
        $videos->reorder($product, array_map('intval', $ids), $request->user());

        return response()->json(['data' => ProductVideos::forAdmin($this->loaded($product->id))]);
    }

    private function loaded(int $id): Product
    {
        return Product::query()->with(self::FULL)->findOrFail($id);
    }
}
