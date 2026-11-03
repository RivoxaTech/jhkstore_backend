<?php

namespace App\Domain\Order;

use App\Domain\Customer\Phone;
use App\Support\FullTextSearch;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin order search, answered by indexes only:
 *   "ORD-2026-0001" -> order_number starts with it (a prefix of letters/digits, then a dash; unique index)
 *   "ali@x.com"      -> customer_email starts with it (index)
 *   "0300 123" digits -> order id (primary key) or customer_phone starts with the digits, with or without the leading + (index)
 *   anything else    -> customer name: FULLTEXT word starts ("ahm kha"), LIKE for words shorter than 3 letters
 */
class OrderSearch
{
    public static function apply(Builder $query, string $term): Builder
    {
        $t = trim($term);
        if ($t === '') {
            return $query;
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9]{0,11}-/', $t)) {
            return $query->where('orders.order_number', 'like', FullTextSearch::like(strtoupper($t)).'%');
        }
        if (str_contains($t, '@')) {
            return $query->where('orders.customer_email', 'like', FullTextSearch::like(strtolower($t)).'%');
        }
        if (preg_match('/^[0-9+\s\-]+$/', $t)) {
            $digits = preg_replace('/\D/', '', $t);
            $international = ltrim(Phone::normalise($t), '+');   // 0300… -> 92300… when the store has a default phone country code

            return $query->where(fn ($q) => $q
                ->where('orders.customer_phone', 'like', $digits.'%')
                ->orWhere('orders.customer_phone', 'like', '+'.$digits.'%')
                ->when($international !== $digits, fn ($w) => $w->orWhere('orders.customer_phone', 'like', '+'.$international.'%'))
                ->when(strlen($digits) <= 9, fn ($w) => $w->orWhere('orders.id', (int) $digits)));
        }

        if ($boolean = FullTextSearch::booleanQuery($t)) {
            return $query->whereRaw('MATCH(orders.customer_name) AGAINST (? IN BOOLEAN MODE)', [$boolean]);
        }

        return $query->where('orders.customer_name', 'like', '%'.FullTextSearch::like($t).'%');
    }
}
