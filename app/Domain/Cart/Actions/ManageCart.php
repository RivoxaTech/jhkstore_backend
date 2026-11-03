<?php

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Phone;
use App\Domain\Order\Exceptions\CommerceException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The persistent server cart. Customers never log in, so a cart belongs to a random token the browser keeps (`X-Cart-Token`). A cart expires
 * 30 days after its last activity (sliding: every call renews it); `php artisan shop:prune-carts` deletes the expired ones.
 * Prices are never stored here, only option ids and quantities: CartPricing prices the cart again on every read.
 * A line whose option was deleted or switched off stays in the cart (the read shows it with a blocking warning); it is never removed silently.
 */
class ManageCart
{
    /** The live cart of this token, or a new empty one (a missing, unknown or expired token starts a new cart). Renews the expiry. */
    public function resolve(?string $token): Cart
    {
        $cart = $this->find($token);
        if (! $cart) {
            return Cart::query()->create(['token' => Str::random(40), 'last_activity_at' => now(), 'expires_at' => now()->addDays(Cart::LIFETIME_DAYS)]);
        }

        return $this->renew($cart);
    }

    /** The live (not expired) cart of this token, or null. Does not change it. */
    public function find(?string $token): ?Cart
    {
        $token = trim((string) $token);

        return strlen($token) === 40 ? Cart::query()->where('token', $token)->active()->first() : null;
    }

    public function renew(Cart $cart): Cart
    {
        $cart->forceFill(['last_activity_at' => now(), 'expires_at' => now()->addDays(Cart::LIFETIME_DAYS)])->save();

        return $cart;
    }

    /** Adds the quantity to the option (a new line, or on top of the existing one). */
    public function add(Cart $cart, int $variantId, int $qty): Cart
    {
        return DB::transaction(function () use ($cart, $variantId, $qty) {
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);   // two quick taps on "add" are applied one after the other
            $this->assertSellable($variantId);

            $line = $cart->items()->where('variant_id', $variantId)->first();
            if (! $line && $cart->items()->count() >= (int) config('shop.max_items_per_order')) {
                throw new CommerceException('cart_limit', 'A cart can have at most '.(int) config('shop.max_items_per_order').' different items.', 'variant_id');
            }
            $total = ($line?->qty ?? 0) + $qty;
            $this->assertQty($total);

            $line ? $line->update(['qty' => $total]) : CartItem::query()->create(['cart_id' => $cart->id, 'variant_id' => $variantId, 'qty' => $qty]);

            return $this->renew($cart);
        });
    }

    /** Sets the quantity of a line that is in the cart. */
    public function setQty(Cart $cart, int $variantId, int $qty): Cart
    {
        $this->assertQty($qty);
        $line = $cart->items()->where('variant_id', $variantId)->first();
        if (! $line) {
            throw new CommerceException('not_in_cart', 'This item is not in your cart.', 'variant_id', 404);
        }
        $line->update(['qty' => $qty]);

        return $this->renew($cart);
    }

    public function remove(Cart $cart, int $variantId): Cart
    {
        $cart->items()->where('variant_id', $variantId)->delete();

        return $this->renew($cart);
    }

    /** Takes everything out of the cart (items and coupon); the contact details stay. */
    public function empty(Cart $cart): Cart
    {
        $cart->items()->delete();
        $cart->coupon_code = null;

        return $this->renew($cart);
    }

    /** @param  array{name?:?string, phone?:?string, email?:?string}  $contact only the keys that are sent are changed */
    public function saveContact(Cart $cart, array $contact): Cart
    {
        if (array_key_exists('name', $contact)) {
            $cart->customer_name = trim((string) $contact['name']) ?: null;
        }
        if (array_key_exists('phone', $contact)) {
            $cart->customer_phone = trim((string) $contact['phone']) !== '' ? Phone::normalise((string) $contact['phone']) : null;
        }
        if (array_key_exists('email', $contact)) {
            $cart->customer_email = trim((string) $contact['email']) !== '' ? strtolower(trim((string) $contact['email'])) : null;
        }

        return $this->renew($cart);
    }

    public function setCoupon(Cart $cart, ?string $code): Cart
    {
        $cart->coupon_code = $code !== null && trim($code) !== '' ? strtoupper(trim($code)) : null;

        return $this->renew($cart);
    }

    /** Deletes the carts that expired. Returns how many. */
    public function prune(): int
    {
        $deleted = 0;
        Cart::query()->expired()->select('id')->chunkById(500, function ($carts) use (&$deleted) {
            $deleted += Cart::query()->whereKey($carts->pluck('id'))->delete();   // the cart_items go with them (foreign key)
        });

        return $deleted;
    }

    /** Only an existing option of a product the website shows can be added. */
    private function assertSellable(int $variantId): void
    {
        $variant = ProductVariant::query()->find($variantId);
        $visible = $variant && $variant->is_active && Product::query()->storefront()->whereKey($variant->product_id)->exists();
        if (! $visible) {
            throw new CommerceException('unavailable', 'This item is not available.', 'variant_id');
        }
    }

    private function assertQty(int $qty): void
    {
        $max = (int) config('shop.max_qty_per_line');
        if ($qty < 1 || $qty > $max) {
            throw new CommerceException('cart_limit', $qty < 1 ? 'The quantity must be at least 1.' : "The quantity of one item can be at most $max.", 'qty');
        }
    }
}
