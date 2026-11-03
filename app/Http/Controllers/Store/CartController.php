<?php

namespace App\Http\Controllers\Store;

use App\Domain\Cart\Actions\ManageCart;
use App\Domain\Cart\Actions\QuoteCheckout;
use App\Domain\Cart\Models\Cart;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CartContactRequest;
use App\Http\Requests\Store\CartCouponRequest;
use App\Http\Requests\Store\CartItemRequest;
use App\Http\Resources\QuoteResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The persistent cart. Customers never log in: the cart belongs to a random token the browser keeps and sends as the `X-Cart-Token` header.
 * Without the header (or with an unknown or expired token) a NEW cart is created; its token comes back in `data.token` and in the
 * `X-Cart-Token` response header. Every answer is the priced cart: the same shape as the quote plus `token` and the saved `contact`.
 * Prices and stock are checked again on every read; a line that became unavailable stays with a blocking warning.
 */
class CartController extends Controller
{
    public function __construct(private readonly ManageCart $carts, private readonly QuoteCheckout $quote) {}

    /** GET cart (optional ?city=&area=&postcode= to get the delivery fee of a zone). */
    public function show(Request $request): JsonResponse
    {
        $request->validate(['city' => ['nullable', 'string', 'max:100'], 'area' => ['nullable', 'string', 'max:255'], 'postcode' => ['nullable', 'string', 'max:20']]);

        return $this->answer($this->carts->resolve($request->header('X-Cart-Token')), $request->only(['city', 'area', 'postcode']));
    }

    /** POST cart/items { variant_id, qty }: adds the quantity to the option. */
    public function add(CartItemRequest $request): JsonResponse
    {
        $cart = $this->carts->add($this->carts->resolve($request->header('X-Cart-Token')), (int) $request->validated('variant_id'), (int) $request->validated('qty'));

        return $this->answer($cart);
    }

    /** PUT cart/items/{variantId} { qty }: sets the quantity of a line. */
    public function update(CartItemRequest $request, int $variantId): JsonResponse
    {
        $cart = $this->carts->setQty($this->carts->resolve($request->header('X-Cart-Token')), $variantId, (int) $request->validated('qty'));

        return $this->answer($cart);
    }

    /** DELETE cart/items/{variantId} */
    public function remove(Request $request, int $variantId): JsonResponse
    {
        return $this->answer($this->carts->remove($this->carts->resolve($request->header('X-Cart-Token')), $variantId));
    }

    /** DELETE cart: empties the cart (items and coupon). */
    public function clear(Request $request): JsonResponse
    {
        return $this->answer($this->carts->empty($this->carts->resolve($request->header('X-Cart-Token'))));
    }

    /** PUT cart/contact { name?, phone?, email? }: kept so the shop can follow up an abandoned cart. */
    public function contact(CartContactRequest $request): JsonResponse
    {
        return $this->answer($this->carts->saveContact($this->carts->resolve($request->header('X-Cart-Token')), $request->validated()));
    }

    /** PUT cart/coupon { code }: a code that does not work answers 422 `coupon_invalid` and is not saved. */
    public function setCoupon(CartCouponRequest $request): JsonResponse
    {
        $cart = $this->carts->resolve($request->header('X-Cart-Token'));
        $priced = $this->quote->handle(['cart_token' => $cart->token], $request->validated('code'));
        if ($priced->couponError) {
            return response()->json([
                'message' => $priced->couponError['message'], 'code' => 'coupon_invalid', 'coupon_error' => $priced->couponError,
                'errors' => ['code' => [$priced->couponError['message']]],
            ], 422)->header('X-Cart-Token', $cart->token);
        }

        return $this->answer($this->carts->setCoupon($cart, $request->validated('code')));
    }

    /** DELETE cart/coupon */
    public function removeCoupon(Request $request): JsonResponse
    {
        return $this->answer($this->carts->setCoupon($this->carts->resolve($request->header('X-Cart-Token')), null));
    }

    private function answer(Cart $cart, array $address = []): JsonResponse
    {
        $cart = $cart->fresh();
        $priced = $this->quote->handle(['cart_token' => $cart->token, 'address' => $address]);
        $resource = new QuoteResource($priced, [
            'token' => $cart->token,
            'contact' => ['name' => $cart->customer_name, 'phone' => $cart->customer_phone, 'email' => $cart->customer_email],
            'expires_at' => $cart->expires_at,
        ]);

        return $resource->response()->header('X-Cart-Token', $cart->token);
    }
}
