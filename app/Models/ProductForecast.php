<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductForecast extends Model
{
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    use HasFactory;

    protected $fillable = ['product_id', 'origin_date', 'horizon_days', 'method', 'predicted_cases', 'mae_cases', 'bias_cases', 'evaluation'];

    protected function casts(): array
    {
        return ['origin_date' => 'date', 'predicted_cases' => 'decimal:2', 'mae_cases' => 'decimal:2', 'bias_cases' => 'decimal:2', 'evaluation' => 'array'];
    }
}
