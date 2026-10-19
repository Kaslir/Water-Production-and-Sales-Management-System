<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionBatch extends Model
{
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(BatchReservation::class);
    }

    use HasFactory;

    protected $fillable = ['product_id', 'batch_code', 'produced_on', 'expires_on', 'cases_produced', 'cases_on_hand', 'quality_rejected_cases', 'cases_reserved', 'status', 'water_treatment_cost', 'bottle_cost', 'inbound_transport_cost', 'cap_label_cost', 'labour_cost', 'electricity_cost', 'other_production_cost', 'total_cost', 'cost_per_case', 'quality_notes', 'produced_by', 'released_by', 'released_at'];

    protected function casts(): array
    {
        return ['produced_on' => 'date', 'expires_on' => 'date', 'released_at' => 'datetime', 'total_cost' => 'decimal:2', 'cost_per_case' => 'decimal:4'];
    }
}
