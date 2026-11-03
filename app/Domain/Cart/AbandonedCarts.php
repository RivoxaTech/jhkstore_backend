<?php

namespace App\Domain\Cart;

use App\Domain\Cart\Models\Cart;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Carts a customer left behind, so the shop can follow up (a tab of the Customers screen, `customers.view`): the customer saved a phone or an
 * e-mail on the cart, it holds items, nothing happened for two hours and no order was placed since (by that phone or e-mail). The value is the
 * sum of the options' current selling prices x quantities (before bulk prices, discounts and coupons: a hint, not a quote).
 * The cart token is never part of the answer.
 */
class AbandonedCarts
{
    public const AFTER_HOURS = 2;

    public static function query(?string $search = null): Builder
    {
        $cutoff = now()->subHours(self::AFTER_HOURS);
        $term = trim((string) $search);

        return Cart::query()->active()
            ->where(fn ($q) => $q->whereNotNull('customer_phone')->orWhereNotNull('customer_email'))
            ->where('last_activity_at', '<', $cutoff)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('cart_items')->whereColumn('cart_items.cart_id', 'carts.id')->where('cart_items.qty', '>', 0))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('orders')
                ->whereColumn('orders.created_at', '>=', 'carts.last_activity_at')
                ->where(fn ($w) => $w->whereColumn('orders.customer_phone', 'carts.customer_phone')->orWhereColumn('orders.customer_email', 'carts.customer_email')))
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('customer_name', 'like', $like)->orWhere('customer_phone', 'like', $like)->orWhere('customer_email', 'like', $like));
            })
            ->select('carts.*')
            ->selectSub(DB::table('cart_items')->whereColumn('cart_items.cart_id', 'carts.id')->selectRaw('COALESCE(SUM(qty), 0)'), 'items_qty')
            ->selectSub(DB::table('cart_items')->join('product_variants', 'product_variants.id', '=', 'cart_items.variant_id')
                ->whereColumn('cart_items.cart_id', 'carts.id')->selectRaw('COALESCE(SUM(cart_items.qty * product_variants.selling_price), 0)'), 'value')
            ->orderByDesc('last_activity_at')->orderByDesc('carts.id');
    }

    /** @return array<string, mixed> one row for the screen and the CSV (no token) */
    public static function row(Cart $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->customer_name,
            'phone' => $c->customer_phone,
            'email' => $c->customer_email,
            'items' => (int) $c->items_qty,
            'value' => round((float) $c->value, 2),
            'last_activity_at' => $c->last_activity_at,
        ];
    }
}
