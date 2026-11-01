<?php

namespace App\Domain\Cart\Models;

use App\Domain\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cart belongs to one customer (`user_id`) or to one guest (`guest_token`, sent as the X-Cart-Token header).
 */
class Cart extends Model
{
    public const ACTIVE = 'active';
    public const MAX_LINES = 50;
    public const GUEST_DAYS = 30;

    protected $fillable = ['user_id', 'guest_token', 'coins_to_redeem', 'status', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'coins_to_redeem' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
