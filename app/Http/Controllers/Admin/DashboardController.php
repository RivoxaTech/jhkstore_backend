<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\AdminAccess;
use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tag;
use App\Domain\Content\Models\Banner;
use App\Domain\Order\Models\Order;
use App\Domain\Support\Models\AuditLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Numbers and lists for the admin dashboard. A few small aggregate queries + one short list,
     * so it stays fast however many products and orders exist.
     *
     * Order figures (orders today, revenue, pending) and every activity line about an order are sent ONLY to an admin who holds
     * `orders.view`: the developer gets none of them, not even as an order number in the activity list.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $withOrders = AdminAccess::allows($request->user(), 'orders.view');

        $activity = AuditLog::query()
            ->when(! $withOrders, fn ($q) => $q->where(fn ($w) => $w->whereNull('subject_type')->orWhere('subject_type', '!=', 'Order'))
                ->where('action', 'not like', 'order.%')->where('action', 'not like', 'customers.%'))
            ->latest('id')->limit(8)->get();
        $adminNames = Admin::whereIn('id', $activity->where('actor_type', 'admin')->pluck('actor_id')->filter()->unique())->pluck('name', 'id');
        $productNames = Product::withTrashed()->whereIn('id', $activity->where('subject_type', 'Product')->pluck('subject_id')->filter()->unique())->pluck('name', 'id');
        $orderNumbers = $withOrders ? Order::whereIn('id', $activity->where('subject_type', 'Order')->pluck('subject_id')->filter()->unique())->pluck('order_number', 'id') : collect();
        $bannerTitles = Banner::whereIn('id', $activity->where('subject_type', 'Banner')->pluck('subject_id')->filter()->unique())->pluck('title', 'id');
        $tagNames = Tag::whereIn('id', $activity->where('subject_type', 'Tag')->pluck('subject_id')->filter()->unique())->pluck('name', 'id');

        $out = [
            'catalog' => [
                'low_stock' => DB::table('product_variants')->where('is_active', true)->whereRaw('stock_qty - reserved_qty <= low_stock_threshold')->count(),
                'products' => Product::query()->count(),
            ],
            'recent_activity' => $activity->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor_type === 'admin' ? ($adminNames[$log->actor_id] ?? 'Admin') : ucfirst($log->actor_type ?? 'system'),
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'subject_name' => match ($log->subject_type) {
                    'Product' => $productNames[$log->subject_id] ?? null,
                    'Tag' => $tagNames[$log->subject_id] ?? null,
                    'Banner' => $bannerTitles[$log->subject_id] ?? null,
                    'Order' => $orderNumbers[$log->subject_id] ?? null,
                    default => null,
                },
                'created_at' => $log->created_at,
            ])->values(),
        ];

        if ($withOrders) {
            // today's orders and revenue, orders waiting for a decision: small indexed aggregates
            $today = Order::query()->toBase()->where('created_at', '>=', today())->where('status', '!=', 'cancelled')
                ->selectRaw('COUNT(*) as total, COALESCE(SUM(grand_total), 0) as revenue')->first();
            $out['orders'] = [
                'today' => (int) $today->total,
                'revenue_today' => (float) $today->revenue,
                'pending' => Order::query()->where('status', 'pending')->count(),
            ];
        }

        return response()->json($out);
    }
}
