<?php

namespace App\Http\Controllers\Store;

use App\Domain\Customer\Phone;
use App\Domain\Order\Actions\PlaceOrder;
use App\Domain\Order\Models\Order;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\PlaceOrderRequest;
use App\Http\Resources\StoreOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Guest orders: nobody logs in. A customer places an order and later looks it up with the order number and the phone number of the order. */
class OrderController extends Controller
{
    private const DETAIL = ['items', 'addresses', 'history', 'payment', 'shipments'];

    /**
     * Place an order. Body: customer { name, phone, email? }, address { line1, city, area?, landmark?, state?, postcode?, country?, name?, phone? },
     * items [{ variant_id, qty }], customer_note?, expected_total?. Send an `Idempotency-Key` header (any unique string, max 64) so a double tap or
     * a retry returns the same order (200 + `Idempotency-Replayed: true`) instead of a second one. 201 = new order; 409 `price_changed` = the
     * total the customer saw is no longer right.
     */
    public function store(PlaceOrderRequest $request, PlaceOrder $place): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key !== '' && ! preg_match('/^[A-Za-z0-9._\-:]{8,64}$/', $key)) {
            return response()->json(['message' => 'The Idempotency-Key must be 8 to 64 letters, digits or . _ - :', 'code' => 'idempotency_key_invalid', 'errors' => ['idempotency_key' => ['The Idempotency-Key must be 8 to 64 letters, digits or . _ - :']]], 422);
        }

        [$order, $replayed] = $place->handle($request->orderData(), $key ?: null);
        $order->loadMissing(self::DETAIL);

        $response = (new StoreOrderResource($order))->response()->setStatusCode($replayed ? 200 : 201);

        return $replayed ? $response->header('Idempotency-Replayed', 'true') : $response;
    }

    /**
     * Order status and timeline: GET orders/{orderNumber}?phone=... The phone must be the one of the order; a wrong phone answers 404, exactly
     * like an order that does not exist, so order numbers cannot be probed.
     */
    public function show(Request $request, string $orderNumber): StoreOrderResource
    {
        $request->validate(['phone' => ['required', 'string', 'max:25']]);

        $order = Order::query()->where('order_number', strtoupper($orderNumber))->with(self::DETAIL)->first();
        abort_unless(
            $order && Phone::normalise((string) $order->customer_phone) === Phone::normalise($request->query('phone')),
            404,
            'Order not found. Check the order number and the phone number.',
        );

        return new StoreOrderResource($order);
    }
}
