<?php

namespace App\Domain\Reward\Models;

use Illuminate\Database\Eloquent\Model;

/** One row of the append-only coin ledger. `earn` / `reverse` / `adjust` rows are spendable batches; `redeem` rows record a spend. */
class RewardTransaction extends Model
{
    public const EARN = 'earn';
    public const REDEEM = 'redeem';
    public const REVERSE = 'reverse';     // coins given back (the redeemed coins of a cancelled order)
    public const ADJUST = 'adjust';       // admin gift / correction

    public const AVAILABLE = 'available';
    public const CONSUMED = 'consumed';
    public const EXPIRED = 'expired';

    protected $fillable = [
        'user_id', 'type', 'status', 'coins', 'remaining', 'source_type', 'source_id', 'expires_at', 'credited_at', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'credited_at' => 'datetime', 'coins' => 'integer', 'remaining' => 'integer'];
    }
}
