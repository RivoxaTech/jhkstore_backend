<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Company\Actions\SaveBrand;
use App\Domain\Company\Models\Brand;
use App\Domain\Company\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveBrandRequest;
use App\Http\Resources\BrandResource;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BrandController extends Controller
{
    /** Brands of one company (shown when the company is opened in the admin panel). */
    public function forCompany(Company $company)
    {
        return BrandResource::collection(
            $company->brands()->with(['media', 'originCountry', 'company.media'])->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    /**
     * All brands across companies, e.g. for the product form dropdown.
     * Query: ?company_id= ?active=1 (only live brands of approved companies) ?q= ?per_page=
     */
    public function index(Request $request)
    {
        $request->validate([
            'company_id' => ['nullable', 'integer'],
            'active' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $brands = Brand::query()
            ->with(['media', 'company.media', 'originCountry'])
            ->when($request->query('company_id'), fn ($q, $id) => $q->where('company_id', $id))
            ->when($request->boolean('active'), fn ($q) => $q->storefront())
            ->when($request->query('q'), fn ($q, $term) => $q->where('name', 'like', "%$term%"))
            ->orderBy('name')
            ->paginate((int) $request->query('per_page', 50))
            ->withQueryString();

        return BrandResource::collection($brands);
    }

    public function show(Brand $brand): BrandResource
    {
        return new BrandResource($brand->load(['media', 'company.media', 'originCountry']));
    }

    /** Add a brand to a company. multipart/form-data when sending logo/banner images. */
    public function store(SaveBrandRequest $request, Company $company, SaveBrand $save): JsonResponse
    {
        $brand = $save->create($company, $request->brandData(), $request->file('logo'), $request->file('banner'), $request->user());

        return (new BrandResource($brand->load(['media', 'company.media'])))->response()->setStatusCode(201);
    }

    /**
     * Update a brand. Use POST with `_method=PUT` when uploading files (multipart limitation).
     * Send `remove_logo=1` to delete the brand's own logo (it then shows the company logo again).
     */
    public function update(SaveBrandRequest $request, Brand $brand, SaveBrand $save): BrandResource
    {
        return new BrandResource($save->update($brand, $request->brandData(), $request->file('logo'), $request->file('banner'), $request->user())->load(['media', 'company.media']));
    }

    public function destroy(Request $request, Brand $brand): JsonResponse
    {
        // A company must always keep at least one brand (it is the brands that customers see).
        if (Brand::where('company_id', $brand->company_id)->count() <= 1) {
            return response()->json(['message' => 'A company needs at least one brand. Add another brand first, or delete the company.'], 422);
        }
        if (DB::table('products')->where('brand_id', $brand->id)->exists()) {
            return response()->json(['message' => 'This brand has products. Deactivate it instead of deleting.'], 422);
        }
        $brand->delete();
        Audit::log($request->user(), 'brand.deleted', $brand);

        return response()->json(['message' => 'Brand deleted.']);
    }
}
