<?php

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Order\Exceptions\CommerceException;
use Illuminate\Support\Facades\DB;

/**
 * Changes the lines of a cart. Every write checks the option live: it must be active and shown on the website,
 * and the quantity must respect min_order_qty, max_order_qty and the stock (stock_qty - reserved_qty).
 * One line per option; adding an option that is already in the cart adds to its quantity. The cart row is locked while it changes,
 * so two quick taps on "+" cannot create two lines or lose a click.
 */
class ManageCartItems
{
    public function __construct(private readonly CartResolver $resolver) {}

    /**
     * Adds `$qty` units of an option. `$clamp` (used by "reorder") cuts the quantity down to what max_order_qty and the stock allow
     * instead of refusing it; the quantity actually in the cart afterwards is returned.
     */
    public function add(Cart $cart, int $variantId, int $qty, bool $clamp = false): int
    {
        return DB::transaction(function () use ($cart, $variantId, $qty, $clamp) {
            Cart::query()->whereKey($cart->id)->lockForUpdate()->first();
            [$variant, $available] = $this->purchasable($variantId);

            $line = CartItem::query()->where('cart_id', $cart->id)->where('variant_id', $variantId)->first();
            if (! $line && $cart->items()->count() >= Cart::MAX_LINES) {
                throw new CommerceException('cart_full', 'A cart can hold at most '.Cart::MAX_LINES.' different items.', 'variant_id');
            }

            $total = ($line->qty ?? 0) + $qty;
            if ($clamp && $available > 0) {
                $total = min($total, (int) $variant->product->max_order_qty, $available);
            }
            $this->assertQty($variant, $available, $total);

            CartItem::query()->updateOrCreate(
                ['cart_id' => $cart->id, 'variant_id' => $variantId],
                ['qty' => $total, 'unit_price' => $variant->selling_price],
            );
            $this->resolver->touch($cart);

            return $total;
        });
    }

    /** Sets the quantity of an existing line (increase or decrease). */
    public function setQty(Cart $cart, int $variantId, int $qty): void
    {
        DB::transaction(function () use ($cart, $variantId, $qty) {
            Cart::query()->whereKey($cart->id)->lockForUpdate()->first();

            $line = CartItem::query()->where('cart_id', $cart->id)->where('variant_id', $variantId)->first();
            if (! $line) {
                throw new CommerceException('not_in_cart', 'This item is not in your cart.', 'variant_id', 404);
            }

            [$variant, $available] = $this->purchasable($variantId);
            $this->assertQty($variant, $available, $qty);

            $line->update(['qty' => $qty, 'unit_price' => $variant->selling_price]);
            $this->resolver->touch($cart);
        });
    }

    /** Removes a line (nothing happens when it is not there). */
    public function remove(Cart $cart, int $variantId): void
    {
        CartItem::query()->where('cart_id', $cart->id)->where('variant_id', $variantId)->delete();
        $this->resolver->touch($cart);
    }

    public function clear(Cart $cart): void
    {
        DB::transaction(function () use ($cart) {
            CartItem::query()->where('cart_id', $cart->id)->delete();
            $cart->forceFill(['coins_to_redeem' => 0])->save();
            $this->resolver->touch($cart);
        });
    }

    /** Remembers how many coins the customer wants to use (the cart answer shows how many were really applied). */
    public function setCoins(Cart $cart, int $coins): void
    {
        $cart->forceFill(['coins_to_redeem' => max(0, $coins)])->save();
    }

    /**
     * @return array{0: ProductVariant, 1: int}  the option (with its product) and the units that can still be sold
     */
    private function purchasable(int $variantId): array
    {
        $variant = ProductVariant::query()
            ->whereKey($variantId)->where('is_active', true)
            ->whereIn('product_id', Product::query()->storefront()->select('products.id'))
            ->with('product:id,name,min_order_qty,max_order_qty')
            ->first();

        if (! $variant) {
            throw new CommerceException('unavailable', 'This item is not available.', 'variant_id');
        }

        return [$variant, $variant->available()];
    }

    private function assertQty(ProductVariant $variant, int $available, int $qty): void
    {
        $product = $variant->product;
        $name = $variant->name ?: $product->name;

        if ($available <= 0) {
            throw new CommerceException('out_of_stock', "\"$name\" is out of stock.", 'variant_id');
        }
        if ($qty < $product->min_order_qty) {
            throw new CommerceException('below_min_qty', "The minimum order quantity of \"$name\" is {$product->min_order_qty}.", 'qty');
        }
        if ($qty > $product->max_order_qty) {
            throw new CommerceException('above_max_qty', "You can buy at most {$product->max_order_qty} of \"$name\".", 'qty');
        }
        if ($qty > $available) {
            throw new CommerceException('insufficient_stock', "Only $available left in stock for \"$name\".", 'qty');
        }
    }
}
