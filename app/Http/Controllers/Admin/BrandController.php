<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\SaveBrand;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Support\Settings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveBrandRequest;
use App\Http\Resources\BrandResource;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BrandController extends Controller
{
    public function __construct(Settings $settings)
    {
        abort_unless($settings->brandsEnabled(), 404, 'Brands are not enabled. Turn on "Enable brands" in Settings.');
    }

    /** Query: ?active=1 (only active brands) ?q= ?per_page= */
    public function index(Request $request)
    {
        $request->validate([
            'active' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $brands = Brand::query()
            ->with('media')
            ->withCount('products')
            ->when($request->boolean('active'), fn ($q) => $q->storefront())
            ->when($request->query('q'), fn ($q, $term) => $q->where('name', 'like', "%$term%"))
            ->orderBy('sort_order')->orderBy('name')
            ->paginate((int) $request->query('per_page', 50))
            ->withQueryString();

        return BrandResource::collection($brands);
    }

    public function show(Brand $brand): BrandResource
    {
        return new BrandResource($brand->load('media')->loadCount('products'));
    }

    /** Create a brand. multipart/form-data when sending logo/banner images. */
    public function store(SaveBrandRequest $request, SaveBrand $save): JsonResponse
    {
        $brand = $save->create($request->brandData(), $request->file('logo'), $request->file('banner'), $request->user());

        return (new BrandResource($brand->load('media')))->response()->setStatusCode(201);
    }

    /**
     * Update a brand. Use POST with `_method=PUT` when uploading files (multipart limitation).
     * Send `remove_logo=1` to delete the brand's logo.
     */
    public function update(SaveBrandRequest $request, Brand $brand, SaveBrand $save): BrandResource
    {
        return new BrandResource($save->update($brand, $request->brandData(), $request->file('logo'), $request->file('banner'), $request->user())->load('media'));
    }

    public function destroy(Request $request, Brand $brand): JsonResponse
    {
        if (DB::table('products')->where('brand_id', $brand->id)->whereNull('deleted_at')->exists()) {
            return response()->json(['message' => 'This brand has products. Deactivate it instead of deleting.'], 422);
        }
        $brand->delete();
        Audit::log($request->user(), 'brand.deleted', $brand);

        return response()->json(['message' => 'Brand deleted.']);
    }
}
