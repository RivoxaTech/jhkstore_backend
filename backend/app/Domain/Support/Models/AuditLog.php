<?php

namespace App\Domain\Support\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = ['actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }
}
