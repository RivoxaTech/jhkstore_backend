<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/** Audit trail of every stock change (admin edit, order, return ...). */
class InventoryMovement extends Model
{
    protected $fillable = [
        'variant_id', 'channel_id', 'type', 'qty_change', 'balance_after', 'reference_type', 'reference_id', 'admin_id', 'note',
    ];
}
