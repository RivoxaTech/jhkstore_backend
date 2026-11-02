<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\Models\Admin;
use App\Domain\Order\Actions\ChangeOrderStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Enums\PaymentStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\OrderSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeOrderStatusRequest;
use App\Http\Requests\Admin\UpdateOrderNoteRequest;
use App\Http\Resources\AdminOrderListResource;
use App\Http\Resources\AdminOrderResource;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    private const DETAIL = ['items', 'addresses', 'history', 'payment', 'shipments'];

    /** Values for the filter boxes of the orders screen. */
    public function options(): JsonResponse
    {
        return response()->json([
            'statuses' => OrderStatus::values(),
            'payment_statuses' => PaymentStatus::values(),
            'payment_methods' => array_map(fn (PaymentMethod $m) => ['code' => $m->value, 'name' => $m->label()], PaymentMethod::cases()),
        ]);
    }

    /**
     * Paginated orders, newest first. Filters: ?q (order number, customer name, phone, email) ?status ?payment_status ?payment_method
     * ?from ?to (dates, placed) ?customer_id ?page ?per_page (max 100). `counts` = orders per status for the same filters (without the status filter).
     */
    public function index(Request $request)
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(OrderStatus::values())],
            'payment_status' => ['nullable', Rule::in(PaymentStatus::values())],
            'payment_method' => ['nullable', Rule::in(PaymentMethod::values())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'customer_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filtered = fn (): Builder => Order::query()
            ->when($request->query('q'), fn ($q, $term) => OrderSearch::apply($q, $term))
            ->when($request->query('payment_status'), fn ($q, $v) => $q->where('orders.payment_status', $v))
            ->when($request->query('payment_method'), fn ($q, $v) => $q->where('orders.payment_method', $v))
            ->when($request->query('customer_id'), fn ($q, $v) => $q->where('orders.customer_id', $v))
            ->when($request->query('from'), fn ($q, $d) => $q->where('orders.created_at', '>=', $d.' 00:00:00'))
            ->when($request->query('to'), fn ($q, $d) => $q->where('orders.created_at', '<', date('Y-m-d', strtotime($d.' +1 day')).' 00:00:00'));

        $counts = $filtered()->toBase()->selectRaw('orders.status, COUNT(*) as total')->groupBy('orders.status')->pluck('total', 'status');

        $orders = $filtered()
            ->when($request->query('status'), fn ($q, $v) => $q->where('orders.status', $v))
            ->orderByDesc('orders.created_at')->orderByDesc('orders.id')
            ->paginate((int) $request->query('per_page', 25))->withQueryString();

        return AdminOrderListResource::collection($orders)->additional(['counts' => $counts]);
    }

    public function show(Order $order): AdminOrderResource
    {
        return $this->full($order->load(self::DETAIL));
    }

    /**
     * Move the order one step: { status: confirmed|packed|shipped|delivered|cancelled, note?, reason? (cancel), courier + awb_number (ship), tracking_url? }.
     * Allowed moves: pending -> confirmed -> packed -> shipped -> delivered; cancel while pending / confirmed / packed.
     */
    public function status(ChangeOrderStatusRequest $request, Order $order, ChangeOrderStatus $change): AdminOrderResource
    {
        $changed = $change->handle($order, OrderStatus::from($request->validated('status')), $request->user(), $request->validated());

        return $this->full($changed);
    }

    /** Internal note (never shown to the customer). */
    public function note(UpdateOrderNoteRequest $request, Order $order): AdminOrderResource
    {
        $order->update(['admin_note' => $request->validated('admin_note') ?: null]);
        Audit::log($request->user(), 'order.note_updated', $order);

        return $this->full($order->load(self::DETAIL));
    }

    private function full(Order $order): AdminOrderResource
    {
        $order->loadMissing(self::DETAIL);
        $customer = $order->customer()->first(['id', 'orders_count', 'first_order_at']);

        return new AdminOrderResource($order, [
            'orders_count' => $customer?->orders_count,
            'first_order_at' => $customer?->first_order_at?->toDateString(),
            'admins' => Admin::query()->whereIn('id', $order->history->where('changed_by_type', 'admin')->pluck('changed_by_id')->unique())->pluck('name', 'id')->all(),
        ]);
    }
}
