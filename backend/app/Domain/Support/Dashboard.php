<?php

namespace App\Domain\Support;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\ProductImages;
use App\Domain\Order\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The numbers of the admin dashboard for one date range, in the store's time zone (everything is stored as shop time, so a "day" is a shop day).
 * Each method is one section; the controller calls only the sections the admin's permissions allow (orders.view, products.view, wholesale.view,
 * customers.view), so a section the admin may not see is never even queried. Revenue leaves out cancelled and rejected orders.
 * Every query is a small aggregate over an indexed date column.
 */
class Dashboard
{
    public const RANGES = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'month' => 'This month', 'all' => 'All time'];

    public const VOID = ['cancelled', 'rejected'];

    /** Money that has been RECEIVED: the order is accepted (not pending, not cancelled / rejected) and paid - a verified bank transfer, or cash on delivery once delivered. */
    public const RECEIVED = "payment_status = 'paid' AND status NOT IN ('pending', 'cancelled', 'rejected')";

    /** @return array{key:string, label:string, from:?CarbonInterface, to:CarbonInterface} `from` null = all time; both in shop time */
    public function range(string $key): array
    {
        $key = array_key_exists($key, self::RANGES) ? $key : '30d';
        $now = now();
        $from = match ($key) {
            'today' => $now->copy()->startOfDay(),
            '7d' => $now->copy()->subDays(6)->startOfDay(),
            '30d' => $now->copy()->subDays(29)->startOfDay(),
            'month' => $now->copy()->startOfMonth(),
            default => null,
        };

        return ['key' => $key, 'label' => self::RANGES[$key], 'from' => $from, 'to' => $now->copy()->endOfDay()];
    }

    /** The five KPI cards, the chart series, the top sellers and the newest orders. */
    public function orders(array $range): array
    {
        $orders = fn () => DB::table('orders')->when($range['from'], fn ($q, $from) => $q->where('orders.created_at', '>=', $from->format('Y-m-d H:i:s')))
            ->where('orders.created_at', '<=', $range['to']->format('Y-m-d H:i:s'));
        $live = fn () => $orders()->whereNotIn('orders.status', self::VOID);

        // ONE pass over the range for all the cards (count, cancelled / rejected, pending, the money of the live orders): each extra query is a pass over the index
        $all = $orders()->selectRaw('COUNT(*) as total, SUM(status IN (\'cancelled\', \'rejected\')) as void, SUM(status = \'pending\') as pending, COALESCE(SUM(CASE WHEN status = \'pending\' THEN grand_total ELSE 0 END), 0) as pending_amount, COALESCE(SUM(CASE WHEN status NOT IN (\'cancelled\', \'rejected\') THEN grand_total ELSE 0 END), 0) as sales, COALESCE(SUM(CASE WHEN ' . self::RECEIVED . ' THEN grand_total ELSE 0 END), 0) as revenue')->first();
        $paid = (object) ['total' => (int) $all->total - (int) $all->void, 'sales' => $all->sales, 'revenue' => $all->revenue];

        $awaitingProof = $orders()->where('orders.status', 'pending')->where('orders.payment_method', 'bank_transfer')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('payments')->whereColumn('payments.payable_id', 'orders.id')->where('payments.payable_type', Payment::ORDER)
                ->whereIn('payments.proof_status', [Payment::PROOF_NONE, Payment::PROOF_REJECTED]))->count();

        $today = DB::table('orders')->where('created_at', '>=', now()->startOfDay()->format('Y-m-d H:i:s'))->whereNotIn('status', self::VOID)
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(grand_total), 0) as revenue')->first();

        return [
            // what the first dashboard showed: today, and the live number of pending orders
            'today' => (int) $today->total,
            'revenue_today' => (float) $today->revenue,
            'pending' => (int) DB::table('orders')->where('status', 'pending')->count(),
            'kpis' => [
                'sales' => (float) $paid->sales,       // what was ordered (cancelled and rejected orders left out)
                'revenue' => (float) $paid->revenue,   // what was received: a bank transfer that was verified on a confirmed order, cash on delivery once delivered
                'pending_amount' => (float) $all->pending_amount,
                'total_orders' => (int) $all->total,
                'average_order_value' => $paid->total > 0 ? round((float) $paid->sales / $paid->total, 2) : 0.0,
                'pending_count' => (int) $all->pending,
                'cancelled_rejected_count' => (int) $all->void,
                'awaiting_proof_count' => $awaitingProof,
            ],
            'comparison' => $this->comparison($range, ['sales' => (float) $paid->sales, 'revenue' => (float) $paid->revenue, 'total_orders' => (int) $all->total]),
            'series' => $this->series($live, $range),
            'top_products' => $this->topProducts($range),
            'recent' => $this->recent($orders),
        ];
    }

    /** Low stock options (at or below their alert level, fewest left first) and the product counts per status. */
    public function stock(): array
    {
        $low = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')->whereNull('p.deleted_at')
            ->where('v.is_active', true)->whereRaw('v.stock_qty - v.reserved_qty <= v.low_stock_threshold')
            ->orderByRaw('v.stock_qty - v.reserved_qty')->orderBy('v.id');

        $counts = DB::table('products')->whereNull('deleted_at')->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'low_stock' => (clone $low)->count(),
            'products' => (int) $counts->sum(),
            'low_stock_items' => $low->limit(8)->get(['v.id', 'v.product_id', 'p.name as product_name', 'v.name as option_name', 'v.sku', 'v.stock_qty', 'v.reserved_qty', 'v.low_stock_threshold'])
                ->map(fn ($r) => [
                    'variant_id' => $r->id, 'product_id' => $r->product_id, 'product_name' => $r->product_name,
                    'option_name' => $r->option_name && $r->option_name !== $r->product_name ? $r->option_name : null, 'sku' => $r->sku,
                    'available' => max(0, (int) $r->stock_qty - (int) $r->reserved_qty), 'threshold' => (int) $r->low_stock_threshold,
                ])->values()->all(),
            'by_status' => collect(ProductStatus::values())->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all(),
        ];
    }

    public function wholesale(array $range): array
    {
        return [
            'new' => (int) DB::table('wholesale_requests')->where('status', 'new')->count(),
            'in_range' => (int) DB::table('wholesale_requests')->when($range['from'], fn ($q, $f) => $q->where('created_at', '>=', $f->format('Y-m-d H:i:s')))->count(),
        ];
    }

    public function customers(array $range): array
    {
        return ['new' => (int) DB::table('customers')->when($range['from'], fn ($q, $f) => $q->where('created_at', '>=', $f->format('Y-m-d H:i:s')))->count()];
    }

    // ------------------------------------------------------------------ pieces

    /** The period right before this one, of the same length (`all` has none). `month` compares with the same number of days of the month before. */
    public function previousRange(array $range): ?array
    {
        $from = $range['from'];
        if ($from === null) {
            return null;
        }
        $before = $from->copy()->subDay()->endOfDay();
        if ($range['key'] === 'month') {
            $start = $from->copy()->subMonthNoOverflow()->startOfMonth();
            $end = $start->copy()->addDays((int) $from->copy()->startOfDay()->diffInDays($range['to']->copy()->startOfDay()))->endOfDay();

            return ['from' => $start, 'to' => $end->lt($before) ? $end : $before];
        }
        $days = (int) $from->copy()->startOfDay()->diffInDays($range['to']->copy()->startOfDay()) + 1;

        return ['from' => $from->copy()->subDays($days)->startOfDay(), 'to' => $before];
    }

    /** Sales (ordered, not counting cancelled or rejected), revenue (received) and orders placed in a period. */
    private function figures(array $range): array
    {
        $base = fn () => DB::table('orders')->when($range['from'], fn ($q, $from) => $q->where('created_at', '>=', $from->format('Y-m-d H:i:s')))->where('created_at', '<=', $range['to']->format('Y-m-d H:i:s'));
        $row = $base()->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN status NOT IN (\'cancelled\', \'rejected\') THEN grand_total ELSE 0 END), 0) as sales, COALESCE(SUM(CASE WHEN ' . self::RECEIVED . ' THEN grand_total ELSE 0 END), 0) as revenue')->first();

        return ['sales' => (float) $row->sales, 'revenue' => (float) $row->revenue, 'total_orders' => (int) $row->total];
    }

    /** @return array{from:string, to:string, previous:array, change:array}|null  the figures of the previous period and the change in percent (null where the previous period had none) */
    private function comparison(array $range, array $current): ?array
    {
        $previousRange = $this->previousRange($range);
        if ($previousRange === null) {
            return null;
        }
        $previous = $this->figures($previousRange);
        $change = [];
        foreach ($current as $key => $value) {
            $change[$key] = $previous[$key] > 0 ? round(($value - $previous[$key]) / $previous[$key] * 100, 1) : null;
        }

        return ['from' => $previousRange['from']->toDateString(), 'to' => $previousRange['to']->toDateString(), 'previous' => $previous, 'change' => $change];
    }

    /** One point per shop day (per month for a long "all time"), zeros included so the chart has no gaps. */
    private function series(\Closure $live, array $range): array
    {
        $first = $range['from'] ? null : $live()->min('orders.created_at');
        $from = $range['from'] ?? ($first ? \Illuminate\Support\Carbon::parse($first, config('app.timezone')) : $range['to']->copy()->startOfDay());
        $monthly = $from->diffInDays($range['to']) > 120;
        $format = $monthly ? '%Y-%m' : '%Y-%m-%d';

        // one query for the orders and the money of every day
        $buckets = $live()->selectRaw("DATE_FORMAT(orders.created_at, '$format') as bucket, COUNT(*) as orders, COALESCE(SUM(grand_total), 0) as revenue")
            ->groupBy('bucket')->get()->keyBy('bucket');
        $rows = $buckets->pluck('revenue', 'bucket');
        $counts = $buckets->pluck('orders', 'bucket');

        $out = [];
        $cursor = $monthly ? $from->copy()->startOfMonth() : $from->copy()->startOfDay();
        $end = $range['to']->copy();
        while ($cursor->lte($end)) {
            $key = $cursor->format($monthly ? 'Y-m' : 'Y-m-d');
            $out[] = ['date' => $key, 'orders' => (int) ($counts[$key] ?? 0), 'revenue' => round((float) ($rows[$key] ?? 0), 2)];
            $monthly ? $cursor->addMonth() : $cursor->addDay();
        }

        return ['granularity' => $monthly ? 'month' : 'day', 'points' => $out];
    }

    /** The five products that sold the most units in the range (cancelled and rejected orders do not count). */
    private function topProducts(array $range): array
    {
        $rows = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->when($range['from'], fn ($q, $f) => $q->where('o.created_at', '>=', $f->format('Y-m-d H:i:s')))
            ->where('o.created_at', '<=', $range['to']->format('Y-m-d H:i:s'))
            ->whereNotIn('o.status', self::VOID)->where('i.status', '!=', 'cancelled')
            ->groupBy('i.product_id')->orderByDesc(DB::raw('SUM(i.qty)'))->orderByDesc(DB::raw('SUM(i.line_total)'))->limit(5)
            ->selectRaw('i.product_id, MAX(i.name) as name, SUM(i.qty) as qty, COALESCE(SUM(i.line_total), 0) as revenue')->get();

        // thumbnail and the current price (the cheapest active option) of each product that is still in the catalogue
        $products = Product::query()->with(['media', 'remoteImages'])->whereIn('id', $rows->pluck('product_id'))->get()->keyBy('id');
        $prices = DB::table('product_variants')->where('is_active', true)->whereIn('product_id', $rows->pluck('product_id'))->groupBy('product_id')->selectRaw('product_id, MIN(selling_price) as price')->pluck('price', 'product_id');

        return $rows->map(fn ($r) => [
            'product_id' => $r->product_id, 'name' => $r->name, 'qty' => (int) $r->qty, 'revenue' => round((float) $r->revenue, 2),
            'thumb_url' => isset($products[$r->product_id]) ? ProductImages::primary($products[$r->product_id])['thumb_url'] : null,
            'price' => isset($prices[$r->product_id]) ? round((float) $prices[$r->product_id], 2) : null,
        ])->values()->all();
    }

    private function recent(\Closure $orders): array
    {
        return $orders()->orderByDesc('orders.id')->limit(8)
            ->get(['orders.id', 'orders.order_number', 'orders.customer_name', 'orders.grand_total', 'orders.status', 'orders.payment_method', 'orders.payment_status', 'orders.created_at'])
            ->map(fn ($o) => [
                'id' => $o->id, 'order_number' => $o->order_number, 'customer_name' => $o->customer_name, 'total' => (float) $o->grand_total,
                'status' => $o->status, 'payment_method' => $o->payment_method, 'payment_status' => $o->payment_status,
                'placed_at' => \Illuminate\Support\Carbon::parse($o->created_at, config('app.timezone'))->toIso8601String(),
            ])->values()->all();
    }
}
