<?php

namespace App\Domain\Cart\Models;

use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'variant_id' => 'integer'];
    }
}
