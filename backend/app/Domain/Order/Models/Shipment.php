<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Model;

/** The parcel of an order: courier, AWB / tracking number and link. Created when the admin ships the order. */
class Shipment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['shipped_at' => 'datetime', 'delivered_at' => 'datetime', 'estimated_delivery_date' => 'date'];
    }
}
