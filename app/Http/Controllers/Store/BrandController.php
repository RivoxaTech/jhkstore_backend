<?php

namespace App\Http\Controllers\Store;

use App\Domain\Company\Models\Brand;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoreBrandResource;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    /**
     * Brands shown on the storefront (active brand of an approved company).
     * Query: ?collection=top|featured|new|suggested  ?q=search  ?per_page=1..100  ?page=
     */
    public function index(Request $request)
    {
        $request->validate([
            'collection' => ['nullable', 'in:top,featured,new,suggested'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $brands = Brand::query()
            ->storefront()
            ->with(['media', 'collections', 'company.media'])
            ->when($request->query('collection'), function ($query, $collection) {
                $query->join('brand_collections', function ($join) use ($collection) {
                    $join->on('brand_collections.brand_id', '=', 'brands.id')->where('brand_collections.collection', $collection);
                })->orderBy('brand_collections.sort_order')->select('brands.*');
            })
            ->when($request->query('q'), fn ($query, $q) => $query->where('brands.name', 'like', '%'.$q.'%'))
            ->orderBy('brands.sort_order')
            ->orderBy('brands.name')
            ->paginate((int) $request->query('per_page', 30))
            ->withQueryString();

        return StoreBrandResource::collection($brands);
    }

    public function show(string $slug)
    {
        $brand = Brand::query()->storefront()->with(['media', 'collections', 'company.media'])->where('brands.slug', $slug)->firstOrFail();

        return new StoreBrandResource($brand);
    }
}
