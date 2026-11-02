<?php

namespace App\Domain\Customer\Models;

use App\Domain\Order\Models\Order;
use App\Support\FullTextSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A contact record of someone who ordered: name, phone (unique, normalised), optional e-mail and city, and order totals.
 * Customers never log in, there is no password. Created or updated by every order (see UpsertCustomer).
 */
class Customer extends Model
{
    protected $fillable = ['name', 'phone', 'email', 'city', 'first_order_at', 'last_order_at', 'orders_count', 'total_spent'];

    protected function casts(): array
    {
        return [
            'first_order_at' => 'datetime', 'last_order_at' => 'datetime',
            'orders_count' => 'integer', 'total_spent' => 'decimal:2',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Admin search: a name (words start with the text), a phone (any part of the number) or an e-mail address (starts with). */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $t = trim((string) $term);
        if ($t === '') {
            return $query;
        }
        if (str_contains($t, '@')) {
            return $query->where('email', 'like', FullTextSearch::like(strtolower($t)).'%');
        }
        if (preg_match('/^[0-9+\s\-()]+$/', $t)) {
            $digits = preg_replace('/\D/', '', $t);

            return $query->where('phone', 'like', '%'.$digits.'%');   // the list is small: any part of the number matches
        }

        return $query->where('name', 'like', '%'.FullTextSearch::like($t).'%');
    }
}
