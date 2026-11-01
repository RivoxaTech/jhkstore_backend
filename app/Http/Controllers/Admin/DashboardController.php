<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tag;
use App\Domain\Order\Models\Order;
use App\Domain\Support\Models\AuditLog;
use App\Domain\Support\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Numbers and lists for the admin dashboard. A few small aggregate queries + one short list,
     * so it stays fast however many products and orders exist.
     */
    public function __invoke(Settings $settings): JsonResponse
    {
        $brandsOn = $settings->brandsEnabled();
        $brands = $brandsOn ? Brand::query()->selectRaw('COUNT(*) as total, COALESCE(SUM(is_active), 0) as active')->first() : null;

        $activity = AuditLog::query()->latest('id')->limit(8)->get();
        $adminNames = Admin::whereIn('id', $activity->where('actor_type', 'admin')->pluck('actor_id')->filter()->unique())->pluck('name', 'id');
        $brandNames = Brand::withTrashed()->whereIn('id', $activity->where('subject_type', 'Brand')->pluck('subject_id')->filter()->unique())->pluck('name', 'id');
        $productNames = Product::withTrashed()->whereIn('id', $activity->where('subject_type', 'Product')->pluck('subject_id')->filter()->unique())->pluck('name', 'id');
        $orderNumbers = Order::whereIn('id', $activity->where('subject_type', 'Order')->pluck('subject_id')->filter()->unique())->pluck('order_number', 'id');
        $tagNames = Tag::whereIn('id', $activity->where('subject_type', 'Tag')->pluck('subject_id')->filter()->unique())->pluck('name', 'id');

        // Orders: three small indexed aggregates (today's orders and revenue, orders waiting for a decision, options running low)
        $today = Order::query()->toBase()->where('created_at', '>=', today())->where('status', '!=', 'cancelled')
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(grand_total), 0) as revenue')->first();
        $lowStock = DB::table('product_variants')->where('is_active', true)->whereRaw('stock_qty - reserved_qty <= low_stock_threshold')->count();

        return response()->json([
            'orders' => [
                'today' => (int) $today->total,
                'revenue_today' => (float) $today->revenue,
                'pending' => Order::query()->where('status', 'pending')->count(),
                'low_stock' => $lowStock,
            ],
            'brands' => $brands ? ['total' => (int) $brands->total, 'active' => (int) $brands->active] : null,
            'recent_activity' => $activity->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor_type === 'admin' ? ($adminNames[$log->actor_id] ?? 'Admin') : ucfirst($log->actor_type ?? 'system'),
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'subject_name' => match ($log->subject_type) {
                    'Brand' => $brandNames[$log->subject_id] ?? null,
                    'Product' => $productNames[$log->subject_id] ?? null,
                    'Tag' => $tagNames[$log->subject_id] ?? null,
                    'Order' => $orderNumbers[$log->subject_id] ?? null,
                    default => null,
                },
                'created_at' => $log->created_at,
            ])->values(),
        ]);
    }
}
