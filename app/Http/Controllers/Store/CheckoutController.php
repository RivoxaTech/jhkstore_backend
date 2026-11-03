<?php

namespace App\Http\Controllers\Store;

use App\Domain\Cart\Actions\QuoteCheckout;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CouponValidateRequest;
use App\Http\Requests\Store\QuoteRequest;
use App\Http\Resources\QuoteResource;
use Illuminate\Http\JsonResponse;

/** The storefront keeps the cart (or uses the persistent one); this prices it. */
class CheckoutController extends Controller
{
    /**
     * Price a cart: body { items: [{variant_id, qty}] | cart_token, coupon_code?, address?: {city, area, postcode}, phone? }. Answers the
     * server-priced lines (bulk prices and discounts applied), the totals (tax, delivery fee, savings, coupon), the next bulk offer per line and the
     * warnings (out of stock, quantity limits, minimum order). Nothing is stored or reserved. A refused coupon is reported in
     * `summary.coupon_error`; it does not block the quote.
     */
    public function quote(QuoteRequest $request, QuoteCheckout $quote): QuoteResource
    {
        return new QuoteResource($quote->handle($request->validated()));
    }

    /**
     * POST coupons/validate { code, items | cart_token, address?, phone? }: the quote with the coupon applied (the same engine as the quote and
     * the order). A coupon that does not work answers 422 `coupon_invalid` with `coupon_error { code, message }`.
     */
    public function validateCoupon(CouponValidateRequest $request, QuoteCheckout $quote): QuoteResource|JsonResponse
    {
        $priced = $quote->handle($request->validated(), $request->validated('code'));
        if ($priced->couponError) {
            return response()->json([
                'message' => $priced->couponError['message'], 'code' => 'coupon_invalid', 'coupon_error' => $priced->couponError,
                'errors' => ['code' => [$priced->couponError['message']]],
            ], 422);
        }

        return new QuoteResource($priced);
    }
}
