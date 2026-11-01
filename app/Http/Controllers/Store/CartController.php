<?php

namespace App\Http\Controllers\Store;

use App\Domain\Cart\Actions\CartResolver;
use App\Domain\Cart\Actions\ManageCartItems;
use App\Domain\Cart\CartPricing;
use App\Domain\Cart\Models\Cart;
use App\Domain\Order\Exceptions\CommerceException;
use App\Domain\Reward\Actions\RewardLedger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\AddCartItemRequest;
use App\Http\Requests\Store\SetCartCoinsRequest;
use App\Http\Requests\Store\SetCartQtyRequest;
use App\Http\Resources\CartResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The cart, stored in the database.
 * A logged-in customer (Bearer token) owns the cart; a guest is identified by the `X-Cart-Token` header, which the server returns
 * (header + `data.token`) the first time the guest adds something. Every answer is the whole cart, recalculated from live prices.
 */
class CartController extends Controller
{
    public function __construct(
        private readonly CartResolver $resolver,
        private readonly ManageCartItems $items,
        private readonly CartPricing $pricing,
        private readonly RewardLedger $ledger,
    ) {}

    /** The cart with lines, bulk-offer hints, stock/price warnings and the summary (items total, discounts, delivery, grand total). */
    public function show(Request $request): JsonResponse
    {
        return $this->respond($request, $this->resolver->find($request));
    }

    /** Add an option: body { variant_id, qty }. Adding an option that is already in the cart adds to its quantity. */
    public function add(AddCartItemRequest $request): JsonResponse
    {
        $cart = $this->resolver->findOrCreate($request);
        $this->items->add($cart, (int) $request->validated('variant_id'), (int) $request->validated('qty'));

        return $this->respond($request, $cart, 201);
    }

    /** Set the quantity of a line (increase or decrease): body { qty }. */
    public function update(SetCartQtyRequest $request, int $variantId): JsonResponse
    {
        $cart = $this->resolver->find($request) ?? throw new CommerceException('not_in_cart', 'This item is not in your cart.', 'variant_id', 404);
        $this->items->setQty($cart, $variantId, (int) $request->validated('qty'));

        return $this->respond($request, $cart);
    }

    /** Remove a line. */
    public function remove(Request $request, int $variantId): JsonResponse
    {
        if ($cart = $this->resolver->find($request)) {
            $this->items->remove($cart, $variantId);
        }

        return $this->respond($request, $cart);
    }

    /** Empty the cart (the points choice is reset too). */
    public function clear(Request $request): JsonResponse
    {
        if ($cart = $this->resolver->find($request)) {
            $this->items->clear($cart);
        }

        return $this->respond($request, $cart);
    }

    /** Use reward points on this cart (customer token): body { coins_to_redeem }. The answer shows how many were applied after the limits. */
    public function coins(SetCartCoinsRequest $request): JsonResponse
    {
        abort_unless($this->ledger->enabled(), 404, 'Reward points are not enabled.');
        $cart = $this->resolver->findOrCreate($request);
        $this->items->setCoins($cart, (int) $request->validated('coins_to_redeem'));

        return $this->respond($request, $cart);
    }

    private function respond(Request $request, ?Cart $cart, int $status = 200): JsonResponse
    {
        $user = $this->resolver->customer($request);
        $priced = $cart
            ? $this->pricing->forCart($cart, $user)
            : $this->pricing->price(collect(), [], 0, $user ? $this->ledger->spendable($user->id) : 0);
        $token = $user ? null : $cart?->guest_token;

        $response = (new CartResource($priced, $token))->response()->setStatusCode($status);
        if ($token) {
            $response->header(CartResolver::HEADER, $token);
        }

        return $response;
    }
}
