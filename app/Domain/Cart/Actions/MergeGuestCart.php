<?php

namespace App\Domain\Cart\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Called when a guest logs in or registers: the guest cart (found by its token) is merged into the customer's cart.
 * The same option is added up (quantities summed) and capped by max_order_qty and the stock; an option that is no longer sold is skipped.
 * The guest cart is deleted afterwards, so a token works once.
 */
class MergeGuestCart
{
    public function __construct(private readonly CartResolver $resolver) {}

    /** @return int number of lines now in the customer's cart that came from the guest cart */
    public function handle(User $user, ?string $token): int
    {
        if (! $token || strlen($token) !== 40) {
            return 0;
        }

        return DB::transaction(function () use ($user, $token) {
            $guest = Cart::query()->where('guest_token', $token)->whereNull('user_id')->where('status', Cart::ACTIVE)->lockForUpdate()->first();
            if (! $guest) {
                return 0;
            }

            $cart = $this->resolver->forUser($user) ?? $this->resolver->createForUser($user);
            Cart::query()->whereKey($cart->id)->lockForUpdate()->first();

            $guestLines = CartItem::query()->where('cart_id', $guest->id)->get();
            $variants = ProductVariant::query()
                ->whereIn('id', $guestLines->pluck('variant_id'))->where('is_active', true)
                ->whereIn('product_id', Product::query()->storefront()->select('products.id'))
                ->with('product:id,min_order_qty,max_order_qty')
                ->get()->keyBy('id');
            $mine = CartItem::query()->where('cart_id', $cart->id)->get()->keyBy('variant_id');

            $merged = 0;
            foreach ($guestLines as $line) {
                $variant = $variants->get($line->variant_id);
                if (! $variant) {
                    continue;
                }

                $available = $variant->available();
                $qty = min(($mine->get($line->variant_id)->qty ?? 0) + $line->qty, (int) $variant->product->max_order_qty, $available);
                if ($qty <= 0 || $qty < $variant->product->min_order_qty) {
                    continue;
                }
                if (! $mine->has($line->variant_id) && $mine->count() + $merged >= Cart::MAX_LINES) {
                    continue;
                }

                CartItem::query()->updateOrCreate(['cart_id' => $cart->id, 'variant_id' => $line->variant_id], ['qty' => $qty, 'unit_price' => $variant->selling_price]);
                $merged++;
            }

            $guest->delete();   // its lines go with it (cascade)
            $this->resolver->touch($cart);

            return $merged;
        });
    }
}
