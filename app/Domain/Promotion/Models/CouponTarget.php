<?php

namespace App\Domain\Promotion\Models;

use Illuminate\Database\Eloquent\Model;

class CouponTarget extends Model
{
    public $timestamps = false;

    protected $fillable = ['coupon_id', 'type', 'target_id'];
}
