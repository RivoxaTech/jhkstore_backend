<?php

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    // lookup table: `countries`
    protected $fillable = ['code', 'name', 'dial_code', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
