<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

class BrandCollection extends Model
{
    protected $fillable = ['brand_id', 'collection', 'sort_order'];
}
