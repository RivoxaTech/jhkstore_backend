<?php

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyRegistrationType extends Model
{
    protected $fillable = ['name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
