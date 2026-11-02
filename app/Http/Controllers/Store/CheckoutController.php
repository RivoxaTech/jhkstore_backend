<?php

namespace App\Http\Controllers\Store;

use App\Domain\Cart\CartPricing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\QuoteRequest;
use App\Http\Resources\QuoteResource;

/** The storefront keeps the cart; this prices it. */
class CheckoutController extends Controller
{
    /**
     * Price a cart: body { items: [{variant_id, qty}] }. Answers the server-priced lines (bulk prices applied), the totals (tax, delivery fee,
     * savings), the next bulk offer per line and the warnings (out of stock, quantity limits, minimum order). Nothing is stored or reserved.
     */
    public function quote(QuoteRequest $request, CartPricing $pricing): QuoteResource
    {
        return new QuoteResource($pricing->forItems($request->validated('items')));
    }
}
