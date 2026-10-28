<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/** Where a product is sold: `standard` or `instadent` (login-only). */
class SalesChannel extends Model
{
    public const STANDARD = 'standard';
    public const INSTADENT = 'instadent';

    protected $fillable = ['code', 'name', 'is_active', 'requires_login', 'delivery_days'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'requires_login' => 'boolean'];
    }

    /** code => id (there are only a few channels, so this is one tiny query). */
    public static function idsByCode(): array
    {
        return static::query()->pluck('id', 'code')->all();
    }
}
