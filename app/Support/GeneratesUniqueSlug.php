<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Builds a URL slug from a source value and keeps it unique within the model's table. */
trait GeneratesUniqueSlug
{
    public static function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'item';
        $slug = $base;
        $i = 2;

        while (static::query()
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->withTrashed()
            ->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
