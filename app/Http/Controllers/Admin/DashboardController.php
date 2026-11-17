<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\AdminAccess;
use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tag;
use App\Domain\Content\Models\Banner;
use App\Domain\Order\Models\Order;
use App\Domain\Support\Dashboard;
use App\Domain\Support\Models\AuditLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\ShopTime;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    /**
     * `GET /admin/dashboard?range=today|7d|30d|month|all` (default 30d). Every section is sent only to an admin who holds its permission:
     *   orders.view     KPI cards, the chart series, the top sellers, the newest orders (order figures, revenue, order numbers)
     *   products.view   the low-stock options and the product counts
     *   wholesale.view  the number of new wholesale requests      customers.view  new customers in the range
     * A section the admin may not see is not even queried; the activity list leaves out order, customer and wholesale lines for them. The
     * developer account therefore gets the catalogue widgets only. Small aggregate queries, fast however many orders exist (see Dashboard).
     */
    public function __invoke(Request $request, Dashboard $dashboard): JsonResponse
    {
        $request->validate(['range' => ['nullable', Rule::in(array_keys(Dashboard::RANGES))]]);
        $range = $dashboard->range((string) $request->query('range', '30d'));
        $can = fn (string $permission) => AdminAccess::allows($request->user(), $permission);
        $withOrders = $can('orders.view');
        $withCustomers = $can('customers.view');
        $withWholesale = $can('wholesale.view');

        $activity = AuditLog::query()
            ->when(! $withOrders, fn ($q) => $q->where(fn ($w) => $w->whereNull('subject_type')->orWhere('subject_type', '!=', 'Order'))
                ->where('action', 'not like', 'order.%')->where('action', 'not like', 'orders.%'))
            ->when(! $withCustomers, fn ($q) => $q->where('action', 'not like', 'customers.%'))
            ->when(! $withWholesale, fn ($q) => $q->where('action', 'not like', 'wholesale.%')->where(fn ($w) => $w->whereNull('subject_type')->orWhere('subject_type', '!=', 'WholesaleRequest')))
            ->latest('id')->limit(8)->get();
        $adminNames = Admin::whereIn('id', $activity->where('actor_type', 'admin')->pluck('actor_id')->filter()->unique())->pluck('name', 'id');
        $productNames = Product::withTrashed()->whereIn('id', $activity->where('subject_type', 'Product')->pluck('subject_id')->filter()->unique())->pluck('name', 'id');
        $orderNumbers = $withOrders ? Order::whereIn('id', $activity->where('subject_type', 'Order')->pluck('subject_id')->filter()->unique())->pluck('order_number', 'id') : collect();
        $bannerTitles = Banner::whereIn('id', $activity->where('subject_type', 'Banner')->pluck('subject_id')->filter()->unique())->pluck('title', 'id');
        $tagNames = Tag::whereIn('id', $activity->where('subject_type', 'Tag')->pluck('subject_id')->filter()->unique())->pluck('name', 'id');

        $out = [
            'range' => ['key' => $range['key'], 'label' => $range['label'], 'from' => $range['from']?->toDateString(), 'to' => $range['to']->toDateString(), 'timezone' => ShopTime::zone(), 'options' => Dashboard::RANGES],
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

        if ($can('products.view')) {
            $out['catalog'] = $dashboard->stock();
        }
        if ($withOrders) {
            $out['orders'] = $dashboard->orders($range);
        }
        if ($withWholesale) {
            $out['wholesale'] = $dashboard->wholesale($range);
        }
        if ($withCustomers) {
            $out['customers'] = $dashboard->customers($range);
        }

        return response()->json($out);
    }
}
