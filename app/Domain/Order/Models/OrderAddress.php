<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Model;

/** Snapshot of the shipping or billing address of an order (`type`), copied from the checkout request. */
class OrderAddress extends Model
{
    /** The address fields of the checkout request that are stored. */
    public const FIELDS = ['name', 'phone', 'line1', 'area', 'landmark', 'city', 'state', 'postcode', 'country'];

    protected $guarded = ['id'];
}
