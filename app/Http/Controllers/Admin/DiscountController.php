<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\SubCategory;
use App\Domain\Promotion\Actions\SaveDiscount;
use App\Domain\Promotion\Models\Discount;
use App\Domain\Promotion\Models\TargetsProducts;
use App\Domain\Promotion\Promotions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveDiscountRequest;
use App\Http\Resources\DiscountResource;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Automatic discounts (`discounts.view` / `discounts.manage`). */
class DiscountController extends Controller
{
    /** Paginated list. Filters: ?q (name) ?status=live|scheduled|expired|inactive ?per_page. */
    public function index(Request $request)
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['live', 'scheduled', 'expired', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Discount::query()->withCount('targets')
            ->when($request->query('q'), fn ($q, $term) => $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->when($request->query('status'), fn ($q, $status) => match ($status) {
                'inactive' => $q->where('is_active', false),
                'scheduled' => $q->where('is_active', true)->where('starts_at', '>', now()),
                'expired' => $q->where('is_active', true)->where('ends_at', '<', now()),
                default => $q->live(),
            })
            ->orderByDesc('is_active')->orderByDesc('priority')->orderByDesc('id');

        return DiscountResource::collection($query->paginate((int) $request->query('per_page', 25))->withQueryString());
    }

    public function show(Discount $discount): DiscountResource
    {
        return (new DiscountResource($discount->load('targets')))->withTargetNames();
    }

    public function store(SaveDiscountRequest $request, SaveDiscount $save): JsonResponse
    {
        $discount = $save->handle($request->validated(), $request->user());

        return ((new DiscountResource($discount))->withTargetNames())->response()->setStatusCode(201);
    }

    public function update(SaveDiscountRequest $request, Discount $discount, SaveDiscount $save): DiscountResource
    {
        return (new DiscountResource($save->handle($request->validated(), $request->user(), $discount)))->withTargetNames();
    }

    /** PATCH discounts/{id}/active { is_active } */
    public function active(Request $request, Discount $discount): DiscountResource
    {
        $discount->update($request->validate(['is_active' => ['required', 'boolean']]));
        Audit::log($request->user(), 'discount.toggled', $discount, ['is_active' => $discount->is_active]);

        return new DiscountResource($discount->loadCount('targets'));
    }

    public function destroy(Request $request, Discount $discount): JsonResponse
    {
        Audit::log($request->user(), 'discount.deleted', $discount, ['name' => $discount->name]);
        $discount->delete();   // the targets go with it (foreign key)
        app(Promotions::class)->flush();

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * GET promotion-targets?type=product|category|sub_category&q=&ids[]=: the searchable lists of the discount and coupon forms (up to 20 matches,
     * or the given ids). Needs the discounts or the coupons screen, not the products or categories screens.
     */
    public function targets(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['product', 'category', 'sub_category'])],
            'q' => ['nullable', 'string', 'max:100'],
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['integer'],
        ]);
        $query = match ($data['type']) {
            'product' => Product::query()->select(['id', 'name']),
            'category' => Category::query()->select(['id', 'name']),
            default => SubCategory::query()->select(['id', 'name']),
        };
        $query->when($data['ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->orderBy('name')->limit(empty($data['ids']) ? 20 : 500);

        return response()->json(['data' => $query->get()->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values()]);
    }
}
