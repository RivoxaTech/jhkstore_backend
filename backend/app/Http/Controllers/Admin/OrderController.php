<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\Models\Admin;
use App\Domain\Order\Actions\ChangeOrderStatus;
use App\Domain\Order\Actions\ConfirmDeliveryFee;
use App\Domain\Order\Actions\HandlePaymentProof;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Enums\PaymentStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\Payment;
use App\Domain\Order\OrderSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeOrderStatusRequest;
use App\Http\Requests\Admin\RejectPaymentProofRequest;
use App\Http\Requests\Admin\SetDeliveryFeeRequest;
use App\Http\Requests\Admin\UpdateOrderNoteRequest;
use App\Http\Resources\AdminOrderListResource;
use App\Http\Resources\AdminOrderResource;
use App\Http\Resources\OrderPresenter;
use App\Support\Audit;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    private const DETAIL = ['items', 'addresses', 'history', 'payment', 'payment.media', 'shipments'];

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
     * Paginated orders, newest first. Filters: ?search (or ?q: order number, customer name, phone, email) ?status ?payment_status ?payment_method
     * ?from ?to (dates, placed) ?customer_id ?page ?per_page (max 100). `counts` = orders per status for the same filters (without the status
     * filter), for the status tabs.
     */
    public function index(Request $request)
    {
        $filtered = $this->filters($request);

        $counts = $filtered()->toBase()->selectRaw('orders.status, COUNT(*) as total')->groupBy('orders.status')->pluck('total', 'status');

        $orders = $filtered()
            ->when($request->query('status'), fn ($q, $v) => $q->where('orders.status', $v))
            ->orderByDesc('orders.created_at')->orderByDesc('orders.id')
            ->paginate((int) $request->query('per_page', 25))->withQueryString();

        return AdminOrderListResource::collection($orders)->additional(['counts' => $counts]);
    }

    /**
     * The orders screen's filters as a query factory (the list, its status counts and the CSV export all use it). Dates are shop dates: orders are
     * stored in shop time (see ShopTime), so `from` and `to` are whole shop days.
     *
     * @return \Closure(): Builder
     */
    private function filters(Request $request): \Closure
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(OrderStatus::values())],
            'payment_status' => ['nullable', Rule::in(PaymentStatus::values())],
            'payment_method' => ['nullable', Rule::in(PaymentMethod::values())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'customer_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $term = $request->query('search') ?: $request->query('q');

        return fn (): Builder => Order::query()
            ->when($term, fn ($q, $t) => OrderSearch::apply($q, $t))
            ->when($request->query('payment_status'), fn ($q, $v) => $q->where('orders.payment_status', $v))
            ->when($request->query('payment_method'), fn ($q, $v) => $q->where('orders.payment_method', $v))
            ->when($request->query('customer_id'), fn ($q, $v) => $q->where('orders.customer_id', $v))
            ->when($request->query('from'), fn ($q, $d) => $q->where('orders.created_at', '>=', $d.' 00:00:00'))
            ->when($request->query('to'), fn ($q, $d) => $q->where('orders.created_at', '<', date('Y-m-d', strtotime($d.' +1 day')).' 00:00:00'));
    }

    /** The number behind the sidebar badge: pending orders plus bank transfer payment proofs waiting to be checked. */
    public function pendingCount(): JsonResponse
    {
        $pending = Order::query()->where('status', 'pending')->count();
        $proofs = Payment::query()->where('payable_type', Payment::ORDER)->where('proof_status', Payment::PROOF_SUBMITTED_STATUS)
            ->whereIn('payable_id', Order::query()->where('status', 'pending')->select('id'))->count();

        // `version` changes whenever an order is placed or changes (status, payment proof): the panel polls this light call every few seconds and
        // reloads the list it shows when the version moved, so new orders and changes appear without a reload.
        $o = Order::query()->selectRaw('count(*) as c, max(id) as m, max(updated_at) as u')->first();
        $p = Payment::query()->where('payable_type', Payment::ORDER)->max('updated_at');

        return response()->json(['count' => $pending + $proofs, 'pending' => $pending, 'proofs' => $proofs, 'version' => md5($o->c.'|'.$o->m.'|'.$o->u.'|'.$p)]);
    }

    /**
     * GET orders/export: the CSV of the orders the filters of the list select (same query string), `orders.export`, audited. Columns: order number,
     * date, customer, phone, status, payment method and status, items, totals, city.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = ($this->filters($request))()->with('addresses')->when($request->query('status'), fn ($q, $v) => $q->where('orders.status', $v))->orderByDesc('orders.id');
        Audit::log($request->user(), 'orders.exported', null, $request->query());

        return Csv::download('orders-'.now()->format('Y-m-d').'.csv',
            ['Order number', 'Date', 'Customer', 'Phone', 'Status', 'Payment method', 'Payment status', 'Items', 'Items subtotal', 'Discounts', 'Delivery fee', 'Tax', 'Total', 'City'],
            (function () use ($query) {
                foreach ($query->lazyById(500, 'orders.id', 'id') as $o) {
                    yield [
                        $o->order_number, $o->created_at?->format('Y-m-d H:i'), $o->customer_name, $o->customer_phone, $o->status->value,
                        OrderPresenter::paymentMethodLabel($o->payment_method), $o->payment_status->value, $o->items_count,
                        $o->items_subtotal, round((float) $o->discount_total + (float) $o->coupon_discount, 2), $o->shipping_fee ?? '', $o->tax_total, $o->grand_total,
                        $o->shippingAddress()?->city,
                    ];
                }
            })());
    }

    public function show(Order $order): AdminOrderResource
    {
        return $this->full($order->load(self::DETAIL));
    }

    /**
     * Move the order one step: { status: confirmed|packed|shipped|delivered|cancelled|rejected, note?, reason? (cancel, reject), courier + awb_number (ship),
     * tracking_url? }. Allowed moves: pending -> confirmed -> packed -> shipped -> delivered; cancel while pending / confirmed / packed; reject
     * (with a reason the customer sees) only while pending. Confirming needs the delivery fee set and a bank transfer verified.
     */
    public function status(ChangeOrderStatusRequest $request, Order $order, ChangeOrderStatus $change): AdminOrderResource
    {
        $changed = $change->handle($order, OrderStatus::from($request->validated('status')), $request->user(), $request->validated());

        return $this->full($changed);
    }

    /** Internal note (never shown to the customer). The field `admin_note` must be present (null or "" clears the note). */
    public function note(UpdateOrderNoteRequest $request, Order $order): AdminOrderResource
    {
        $order->update(['admin_note' => $request->validated('admin_note') ?: null]);
        Audit::log($request->user(), 'order.note_updated', $order);

        return $this->full($order->load(self::DETAIL));
    }

    /** POST orders/{id}/payment/verify: the bank transfer arrived (needs an uploaded proof). The payment and the order become paid. */
    public function verifyPayment(Request $request, Order $order, HandlePaymentProof $proofs): AdminOrderResource
    {
        return $this->full($proofs->verify($order, $request->user()));
    }

    /** POST orders/{id}/payment/reject-proof { reason }: the proof is not good, the customer uploads a new one. */
    public function rejectProof(RejectPaymentProofRequest $request, Order $order, HandlePaymentProof $proofs): AdminOrderResource
    {
        return $this->full($proofs->reject($order, $request->user(), $request->validated('reason')));
    }

    /** PUT orders/{id}/delivery-fee { fee }: confirms the fee of an order placed without one; the total is recalculated. */
    public function deliveryFee(SetDeliveryFeeRequest $request, Order $order, ConfirmDeliveryFee $confirm): AdminOrderResource
    {
        return $this->full($confirm->handle($order, $request->validated('fee'), $request->user()));
    }

    /** GET orders/{id}/payment/proof: the customer's screenshot. It is on the private disk, so only this authenticated route can show it. */
    public function proof(Order $order): BinaryFileResponse
    {
        $media = Payment::query()->where('payable_type', Payment::ORDER)->where('payable_id', $order->id)->first()?->getFirstMedia('proof');
        abort_unless($media, 404, 'There is no payment proof for this order.');

        return response()->file($media->getPath(), [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => 'inline; filename="payment-proof.'.pathinfo($media->file_name, PATHINFO_EXTENSION).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
