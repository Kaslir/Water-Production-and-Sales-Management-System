<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditTrail
{
    public function record(string $event, ?Model $subject = null, ?int $actorId = null, array $before = [], array $after = [], ?string $reason = null): void
    {
        AuditLog::create([
            'actor_id' => $actorId,
            'event' => $event,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'before_values' => $before ?: null,
            'after_values' => $after ?: null,
            'ip_address' => request()?->ip(),
            'reason' => $reason,
        ]);
    }
}
