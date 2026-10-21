<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id', 'user_id');
    }

    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['actor_id', 'event', 'subject_type', 'subject_id', 'before_values', 'after_values', 'ip_address', 'reason'];

    protected function casts(): array
    {
        return ['before_values' => 'array', 'after_values' => 'array', 'created_at' => 'datetime'];
    }
}
