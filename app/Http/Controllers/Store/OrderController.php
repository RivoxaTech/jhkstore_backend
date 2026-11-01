<?php

namespace App\Http\Controllers\Store;

use App\Domain\Cart\CartPricing;
use App\Domain\Order\Actions\ChangeOrderStatus;
use App\Domain\Order\Actions\PlaceOrder;
use App\Domain\Order\Actions\ReorderOrder;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CancelOrderRequest;
use App\Http\Requests\Store\PlaceOrderRequest;
use App\Http\Resources\CartResource;
use App\Http\Resources\StoreOrderListResource;
use App\Http\Resources\StoreOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Orders of the logged-in customer. Every query is scoped to the customer: someone else's order answers 404. */
class OrderController extends Controller
{
    private const DETAIL = ['items', 'addresses', 'history', 'payment', 'shipments'];

    /**
     * Place the order from the cart. Body: address_id (or an inline `address`), payment_method (cod), coins_to_redeem,
     * customer_note, expected_total. Send an `Idempotency-Key` header (any unique string, max 64) so a double tap or a retry returns
     * the same order (200 + `Idempotency-Replayed: true`) instead of a second one. 201 = new order.
     */
    public function store(PlaceOrderRequest $request, PlaceOrder $place): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key !== '' && ! preg_match('/^[A-Za-z0-9._\-:]{8,64}$/', $key)) {
            return response()->json(['message' => 'The Idempotency-Key must be 8 to 64 letters, digits or . _ - :', 'code' => 'idempotency_key_invalid', 'errors' => ['idempotency_key' => ['The Idempotency-Key must be 8 to 64 letters, digits or . _ - :']]], 422);
        }

        [$order, $replayed] = $place->handle($request->user(), $request->validated(), $key ?: null);
        $order->loadMissing(self::DETAIL);

        $response = (new StoreOrderResource($order))->response()->setStatusCode($replayed ? 200 : 201);

        return $replayed ? $response->header('Idempotency-Replayed', 'true') : $response;
    }

    /** My orders, newest first. Query: ?status= ?per_page= (max 100) ?page= */
    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(OrderStatus::values())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return StoreOrderListResource::collection(
            Order::query()->where('user_id', $request->user()->id)
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->with('items')
                ->orderByDesc('id')
                ->paginate((int) $request->query('per_page', 10))->withQueryString()
        );
    }

    public function show(Request $request, string $orderNumber): StoreOrderResource
    {
        return new StoreOrderResource($this->mine($request, $orderNumber, self::DETAIL));
    }

    /** Cancel before the parcel ships (pending / confirmed / packed). Stock goes back and redeemed coins are refunded. Body: { reason? } */
    public function cancel(CancelOrderRequest $request, string $orderNumber, ChangeOrderStatus $status): StoreOrderResource
    {
        $order = $this->mine($request, $orderNumber, []);

        return new StoreOrderResource($status->handle($order, OrderStatus::Cancelled, $request->user(), ['reason' => $request->validated('reason')]));
    }

    /** Order again: adds the options of this order to the cart; answers the cart plus the lines that could not be added. */
    public function reorder(Request $request, string $orderNumber, ReorderOrder $reorder, CartPricing $pricing): JsonResponse
    {
        $order = $this->mine($request, $orderNumber, ['items']);
        [$cart, $skipped, $added] = $reorder->handle($request->user(), $order);

        return (new CartResource($pricing->forCart($cart, $request->user())))
            ->additional(['reorder' => ['added' => $added, 'skipped' => $skipped]])
            ->response();
    }

    private function mine(Request $request, string $orderNumber, array $with): Order
    {
        return Order::query()->where('user_id', $request->user()->id)->where('order_number', $orderNumber)->with($with)->firstOrFail();
    }
}
