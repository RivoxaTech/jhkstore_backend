<?php

namespace App\Domain\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Key/value settings editable from the admin panel (delivery fee, free-delivery limit, ...). */
class SiteSetting extends Model
{
    protected $fillable = ['group_key', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function read(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("setting:$key", fn () => static::where('key', $key)->first()?->value) ?? $default;
    }

    public static function write(string $group, string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['group_key' => $group, 'value' => $value]);
        Cache::forget("setting:$key");
    }
}
