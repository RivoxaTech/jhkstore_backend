<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Model;

/** One step of the order timeline (who changed what, when). `changed_by_type` = admin | customer | system. */
class OrderStatusHistory extends Model
{
    protected $table = 'order_status_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_customer_visible' => 'boolean'];
    }
}
