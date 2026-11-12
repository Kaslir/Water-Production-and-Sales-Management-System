<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductionBatch extends Model
{
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function manufacturingPolicy(): BelongsTo
    {
        return $this->belongsTo(ProductManufacturingPolicy::class, 'manufacturing_policy_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(BatchReservation::class);
    }

    public function actualCost(): HasOne
    {
        return $this->hasOne(BatchActualCost::class);
    }

    public function stockCounts(): HasMany
    {
        return $this->hasMany(StockCount::class);
    }

    use HasFactory;

    protected $fillable = ['product_id', 'manufacturing_policy_id', 'water_supply_rate_id', 'production_forecast_decision_id', 'batch_code', 'produced_on', 'expires_on', 'cases_produced', 'cases_on_hand', 'quality_rejected_cases', 'cases_reserved', 'status', 'water_source_reference', 'water_input_litres', 'treatment_log_reference', 'unplanned_reason', 'unplanned_authorized_by', 'quality_tested_on', 'quality_evidence_reference', 'quality_ph', 'quality_turbidity_ntu', 'microbiological_result', 'water_supply_cost', 'water_treatment_cost', 'bottle_cost', 'inbound_transport_cost', 'cap_label_cost', 'labour_cost', 'electricity_cost', 'other_production_cost', 'total_cost', 'cost_per_case', 'quality_notes', 'produced_by', 'released_by', 'released_at'];

    protected function casts(): array
    {
        return ['produced_on' => 'date', 'expires_on' => 'date', 'quality_tested_on' => 'date', 'released_at' => 'datetime', 'water_supply_cost' => 'decimal:2', 'total_cost' => 'decimal:2', 'cost_per_case' => 'decimal:4', 'quality_ph' => 'decimal:2', 'quality_turbidity_ntu' => 'decimal:3', 'water_input_litres' => 'decimal:2'];
    }
}
