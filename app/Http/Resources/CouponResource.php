<?php

namespace App\Http\Resources;

/** A coupon for the admin screens (see DiscountResource). `used_count` = uses that were not given back. */
class CouponResource extends DiscountResource
{
    public function toArray($request): array
    {
        $m = $this->resource;
        $base = parent::toArray($request);
        unset($base['priority']);

        return ['code' => $m->code, 'description' => $m->description] + $base;
    }

    protected function extra($m): array
    {
        return [
            'free_delivery' => $m->free_delivery,
            'min_order' => $m->min_order === null ? null : (float) $m->min_order,
            'max_discount' => $m->max_discount === null ? null : (float) $m->max_discount,
            'usage_limit' => $m->usage_limit,
            'per_customer_limit' => $m->per_customer_limit,
            'first_order_only' => $m->first_order_only,
            'used_count' => $m->used_count,
        ];
    }
}
