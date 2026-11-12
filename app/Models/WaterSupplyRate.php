<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaterSupplyRate extends Model
{
    protected $fillable = ['effective_from', 'price_per_litre', 'bill_or_tariff_reference', 'approved_by'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'price_per_litre' => 'decimal:4'];
    }
}
