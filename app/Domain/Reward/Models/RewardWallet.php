<?php

namespace App\Domain\Reward\Models;

use Illuminate\Database\Eloquent\Model;

/** Cached coin balance of a customer (the ledger is `reward_transactions`). */
class RewardWallet extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'available', 'pending', 'lifetime_earned', 'lifetime_redeemed'];
}
