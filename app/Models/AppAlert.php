<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppAlert extends Model
{
    protected $fillable = ['user_id', 'alert_key', 'category', 'severity', 'title', 'message', 'target_type', 'target_id', 'read_at', 'resolved_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function destination(User $user): string
    {
        return match ($this->target_type) {
            'batch' => $user->hasRole('administrator', 'manager', 'production_staff', 'inventory_staff')
                ? route('packaged.batches.show', $this->target_id) : route('notifications.index'),
            'order' => $user->hasRole('administrator', 'manager', 'sales_staff', 'distribution_staff')
                ? route('packaged.orders.show', $this->target_id) : route('notifications.index'),
            'plan' => $user->hasRole('administrator', 'manager')
                ? route('packaged.forecasts.index') : route('production.index'),
            'stock' => $user->hasRole('administrator', 'manager', 'inventory_staff')
                ? route('inventory.index') : route('production.index'),
            default => route('notifications.index'),
        };
    }
}
