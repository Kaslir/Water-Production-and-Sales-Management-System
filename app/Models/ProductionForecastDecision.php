<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionForecastDecision extends Model
{
    protected $fillable = ['product_forecast_id', 'recommended_cases', 'approved_cases', 'reason', 'decided_by'];

    public function forecast(): BelongsTo
    {
        return $this->belongsTo(ProductForecast::class, 'product_forecast_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by', 'user_id');
    }
}
