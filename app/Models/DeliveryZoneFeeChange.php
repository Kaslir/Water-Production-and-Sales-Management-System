<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryZoneFeeChange extends Model
{
    public $timestamps = false;

    protected $fillable = ['delivery_zone_id', 'previous_fee', 'new_fee', 'reason', 'changed_by'];

    protected function casts(): array
    {
        return ['previous_fee' => 'decimal:2', 'new_fee' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by', 'user_id');
    }
}
