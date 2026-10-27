<?php

namespace App\Support;

use App\Domain\Support\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/** Writes an audit trail row for important admin/system actions. */
class Audit
{
    public static function log(?Model $actor, string $action, ?Model $subject = null, array $changes = []): void
    {
        AuditLog::create([
            'actor_type' => $actor ? strtolower(class_basename($actor)) : 'system',
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'changes' => $changes ?: null,
            'ip' => request()?->ip(),
        ]);
    }
}
