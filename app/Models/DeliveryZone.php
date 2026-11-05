<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryZone extends Model
{
    protected $fillable = ['code', 'county', 'fixed_fee', 'is_active'];

    protected function casts(): array
    {
        return ['fixed_fee' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function areas(): HasMany
    {
        return $this->hasMany(DeliveryArea::class)->orderBy('name');
    }

    public function feeChanges(): HasMany
    {
        return $this->hasMany(DeliveryZoneFeeChange::class)->latest('id');
    }
}
