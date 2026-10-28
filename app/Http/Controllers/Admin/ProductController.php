<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\ManageProductImages;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductContentTab;
use App\Domain\Catalog\Models\SalesChannel;
use App\Domain\Catalog\Models\SubCategory;
use App\Domain\Catalog\Models\Tag;
use App\Domain\Catalog\ProductImages;
use App\Domain\Catalog\ProductSearch;
use App\Domain\Company\Models\Company;
use App\Domain\Support\Models\SiteSetting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderProductImagesRequest;
use App\Http\Requests\Admin\SaveProductRequest;
use App\Http\Requests\Admin\SetProductStatusRequest;
use App\Http\Requests\Admin\SetProductTagsRequest;
use App\Http\Requests\Admin\UploadProductImagesRequest;
use App\Http\Resources\ProductListResource;
use App\Http\Resources\ProductResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /** Relations the full product view needs. */
    private const FULL = [
        'company:id,name', 'brand:id,name,company_id', 'category:id,name', 'subCategory:id,name', 'tags', 'channels',
        'contentTabs', 'media', 'remoteImages', 'variants.stocks.channel', 'variants.priceTiers', 'related.media', 'related.remoteImages',
    ];

    /** What the dropdowns of the product form need: approved companies with their active brands, tags, channels, statuses. */
    public function options(): JsonResponse
    {
        return response()->json([
            'companies' => Company::query()->approved()->with('brands:id,company_id,name,is_active')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'brands' => $c->brands->where('is_active', true)->sortBy('name')->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(),
                ]),
            'tags' => Tag::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'slug', 'color', 'is_active']),
            'channels' => SalesChannel::query()->orderBy('id')->get(['code', 'name', 'requires_login']),
            'currency' => ['code' => SiteSetting::read('currency_code', 'PKR'), 'symbol' => SiteSetting::read('currency_symbol', 'Rs')],
            'statuses' => ProductStatus::values(),
            'content_tabs' => collect(ProductContentTab::TABS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    /**
     * Lean product list. Filters: ?q (name, SKU, variant SKU) ?status ?company_id ?brand_id ?category_id ?sub_category_id
     * ?tag (slug) ?channel (code) ?page ?per_page. Newest first; `counts` = products per status.
     */
    public function index(Request $request)
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(ProductStatus::values())],
            'company_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'sub_category_id' => ['nullable', 'integer'],
            'tag' => ['nullable', 'string', 'max:80'],
            'channel' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $products = Product::query()
            ->with(['company:id,name', 'brand:id,name,company_id', 'category:id,name', 'subCategory:id,name', 'tags', 'channels', 'media', 'remoteImages', 'variants.stocks.channel'])
            ->when($request->query('q'), fn ($q, $term) => ProductSearch::apply($q, $term, includeVariantSku: true))
            ->when($request->query('status'), fn ($q, $v) => $q->where('products.status', $v))
            ->when($request->query('company_id'), fn ($q, $v) => $q->where('products.company_id', $v))
            ->when($request->query('brand_id'), fn ($q, $v) => $q->where('products.brand_id', $v))
            ->when($request->query('category_id'), fn ($q, $v) => $q->where('products.category_id', $v))
            ->when($request->query('sub_category_id'), fn ($q, $id) => $q->whereIn('products.sub_category_id',
                SubCategory::query()->where('parent_id', $id)->pluck('id')->push((int) $id))) // a parent also lists its children's products
            ->when($request->query('tag'), fn ($q, $slug) => $q->whereHas('tags', fn ($t) => $t->where('tags.slug', $slug)))
            ->when($request->query('channel'), fn ($q, $code) => $q->whereHas('channels', fn ($c) => $c->where('sales_channels.code', $code)))
            ->latest('products.id')
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

    /** Create a product with its variants, tags, channels and content tabs (JSON). Pictures go to POST /products/{id}/images. */
    public function store(SaveProductRequest $request, SaveProduct $save): JsonResponse
    {
        $product = $save->create($request->productData(), $request->user());

        return (new ProductResource($this->loaded($product->id)))->response()->setStatusCode(201);
    }

    /** Update a product (send only what changes; `variants`, `tag_ids`, `channels`, `content_tabs` replace the whole list). */
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

    /** Send every uploaded picture id of ONE gallery (the product's, or one option's) in the wanted order (`ids`): the first becomes primary. */
    public function reorderImages(ReorderProductImagesRequest $request, Product $product, ManageProductImages $images): JsonResponse
    {
        $images->reorder($product, array_map('strval', $request->validated('ids')), $request->user());

        return response()->json(['data' => ProductImages::all($this->loaded($product->id))]);
    }

    private function loaded(int $id): Product
    {
        return Product::query()->with(self::FULL)->findOrFail($id);
    }
}
