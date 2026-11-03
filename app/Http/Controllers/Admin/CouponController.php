<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\AdminAccess;
use App\Domain\Order\Models\Order;
use App\Domain\Promotion\Actions\SaveCoupon;
use App\Domain\Promotion\Models\Coupon;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCouponRequest;
use App\Http\Resources\CouponResource;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Coupons (`coupons.view` / `coupons.manage`). */
class CouponController extends Controller
{
    /** Paginated list. Filters: ?q (code or name) ?status=live|scheduled|expired|inactive ?per_page. */
    public function index(Request $request)
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['live', 'scheduled', 'expired', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Coupon::query()->withCount('targets')
            ->when($request->query('q'), function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';

                return $q->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like));
            })
            ->when($request->query('status'), fn ($q, $status) => match ($status) {
                'inactive' => $q->where('is_active', false),
                'scheduled' => $q->where('is_active', true)->where('starts_at', '>', now()),
                'expired' => $q->where('is_active', true)->where('ends_at', '<', now()),
                default => $q->where('is_active', true)
                    ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', now())),
            })
            ->orderByDesc('is_active')->orderByDesc('id');

        return CouponResource::collection($query->paginate((int) $request->query('per_page', 25))->withQueryString());
    }

    public function show(Coupon $coupon): CouponResource
    {
        return (new CouponResource($coupon->load('targets')))->withTargetNames();
    }

    public function store(SaveCouponRequest $request, SaveCoupon $save): JsonResponse
    {
        return ((new CouponResource($save->handle($request->validated(), $request->user())))->withTargetNames())->response()->setStatusCode(201);
    }

    public function update(SaveCouponRequest $request, Coupon $coupon, SaveCoupon $save): CouponResource
    {
        return (new CouponResource($save->handle($request->validated(), $request->user(), $coupon)))->withTargetNames();
    }

    /** PATCH coupons/{id}/active { is_active } */
    public function active(Request $request, Coupon $coupon): CouponResource
    {
        $coupon->update($request->validate(['is_active' => ['required', 'boolean']]));
        Audit::log($request->user(), 'coupon.toggled', $coupon, ['code' => $coupon->code, 'is_active' => $coupon->is_active]);

        return new CouponResource($coupon->loadCount('targets'));
    }

    /** A coupon that was used on an order cannot be deleted (422 `coupon_has_usages`): deactivate it. */
    public function destroy(Request $request, Coupon $coupon, SaveCoupon $save): JsonResponse
    {
        $save->delete($coupon, $request->user());

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * GET coupons/{id}/usages: when and for how much the coupon was used. The order number and the customer's phone are order data: they are only
     * sent to an admin who holds `orders.view` (the developer gets the dates and amounts, never the orders).
     */
    public function usages(Request $request, Coupon $coupon): JsonResponse
    {
        $withOrders = AdminAccess::allows($request->user(), 'orders.view');
        $usages = $coupon->usages()->latest('id')->paginate((int) min(100, max(1, (int) $request->query('per_page', 25))));
        $numbers = $withOrders ? Order::query()->whereIn('id', $usages->pluck('order_id'))->pluck('order_number', 'id') : collect();

        return response()->json([
            'data' => $usages->getCollection()->map(fn ($u) => [
                'id' => $u->id,
                'used_at' => $u->created_at,
                'amount' => (float) $u->amount,
                'released' => $u->released_at !== null,
                'released_at' => $u->released_at,
            ] + ($withOrders ? ['order_id' => $u->order_id, 'order_number' => $numbers[$u->order_id] ?? null, 'phone' => $u->phone] : []))->values(),
            'meta' => ['current_page' => $usages->currentPage(), 'last_page' => $usages->lastPage(), 'per_page' => $usages->perPage(), 'total' => $usages->total()],
        ]);
    }
}
