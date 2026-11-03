<?php

namespace App\Domain\Delivery\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A delivery zone: a fee for the addresses it matches. `match_type` says which part of the address is compared with `match_values`:
 *   city      case-insensitive equality with the city          area      the same with the area
 *   postcode  exact match, or a prefix when the entry ends with `*` (e.g. `54*`); spaces and case are ignored
 * The first active zone (by `sort_order`) that matches wins (see ZoneMatcher).
 */
class DeliveryZone extends Model
{
    public const MATCH_TYPES = ['city', 'area', 'postcode'];

    protected $fillable = ['name', 'match_type', 'match_values', 'fee', 'free_above', 'min_order', 'eta_text', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'match_values' => 'array', 'fee' => 'decimal:2', 'free_above' => 'decimal:2', 'min_order' => 'decimal:2',
            'sort_order' => 'integer', 'is_active' => 'boolean',
        ];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** @param  array{city?:?string, area?:?string, postcode?:?string}  $address */
    public function matches(array $address): bool
    {
        $values = array_values(array_filter(array_map(fn ($v) => $this->normalise((string) $v), (array) $this->match_values), fn ($v) => $v !== ''));
        $given = $this->normalise((string) ($address[$this->match_type] ?? ''));
        if ($given === '' || $values === []) {
            return false;
        }

        if ($this->match_type !== 'postcode') {
            return in_array($given, $values, true);
        }
        foreach ($values as $value) {
            if (str_ends_with($value, '*') ? str_starts_with($given, rtrim($value, '*')) : $given === $value) {
                return true;
            }
        }

        return false;
    }

    private function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return $this->match_type === 'postcode' ? str_replace(' ', '', $value) : (string) preg_replace('/\s+/', ' ', $value);
    }
}
