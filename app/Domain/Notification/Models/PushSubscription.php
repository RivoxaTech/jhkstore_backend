<?php

namespace App\Domain\Notification\Models;

use App\Domain\Auth\Models\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One browser of one admin that agreed to receive push notifications. The endpoint is unique: a browser belongs to one admin at a time. */
class PushSubscription extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
