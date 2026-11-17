<?php

namespace App\Domain\Promotion\Models;

use Illuminate\Database\Eloquent\Model;

class DiscountTarget extends Model
{
    public $timestamps = false;

    protected $fillable = ['discount_id', 'type', 'target_id'];
}
