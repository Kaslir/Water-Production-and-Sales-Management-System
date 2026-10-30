<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductManufacturingPolicy extends Model
{
    protected $fillable = [
        'product_id', 'effective_from', 'shelf_life_days', 'shelf_life_evidence',
        'water_treatment_per_case', 'bottle_per_case', 'inbound_transport_per_case',
        'cap_label_per_case', 'labour_per_case', 'electricity_per_case',
        'other_production_per_case', 'approved_by',
    ];

    protected function casts(): array
    {
        return ['effective_from' => 'date'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
