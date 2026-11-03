<?php

namespace App\Domain\Promotion\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An automatic discount: no code, applies to every matching product while it is live. Never stacked with another discount (the best one per
 * cart line wins, see CartPricing). `value` is a percentage (0.01 to 100) or a fixed amount.
 */
class Discount extends Model
{
    use TargetsProducts;

    protected $fillable = ['name', 'type', 'value', 'scope', 'starts_at', 'ends_at', 'is_active', 'priority'];

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean', 'priority' => 'integer'];
    }

    protected static function booted(): void
    {
        $flush = fn () => app(\App\Domain\Promotion\Promotions::class)->flush();
        static::saved($flush);
        static::deleted($flush);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(DiscountTarget::class);
    }

    public function scopeLive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }
}
