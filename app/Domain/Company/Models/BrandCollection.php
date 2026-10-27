<?php

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Model;

class BrandCollection extends Model
{
    protected $fillable = ['brand_id', 'collection', 'sort_order'];
}
