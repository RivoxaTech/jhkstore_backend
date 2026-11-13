<?php

namespace App\Domain\Wholesale\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer's request for a bulk price: one product line (or free text) and a quantity. Personal data: only admins with `wholesale.view`
 * see it. Statuses: new → contacted → quoted → closed (any order; the admin decides).
 */
class WholesaleRequest extends Model
{
    public const STATUSES = ['new', 'contacted', 'quoted', 'closed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'handled_at' => 'datetime'];
    }

    /** Search by reference, name, phone, e-mail, business or product name. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(fn ($q) => $q->where('reference', 'like', $like)->orWhere('name', 'like', $like)->orWhere('phone', 'like', $like)
            ->orWhere('email', 'like', $like)->orWhere('business_name', 'like', $like)->orWhere('brn', 'like', $like)->orWhere('product_name', 'like', $like));
    }
}
