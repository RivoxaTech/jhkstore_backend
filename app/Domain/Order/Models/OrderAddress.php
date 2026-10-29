<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Model;

/** Snapshot of the shipping or billing address of an order (`type`). `address_type` = clinic | home | other. */
class OrderAddress extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float'];
    }
}
