<?php

namespace App\Domain\Review\Models;

use Illuminate\Database\Eloquent\Model;

/** Cached rating numbers of a product: average, count and the 1..5 distribution (c1..c5). */
class ProductRatingStat extends Model
{
    protected $primaryKey = 'product_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['product_id', 'avg', 'count', 'c1', 'c2', 'c3', 'c4', 'c5', 'updated_at'];

    protected function casts(): array
    {
        return ['avg' => 'float', 'updated_at' => 'datetime'];
    }
}
