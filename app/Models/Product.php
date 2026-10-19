<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    public function batches(): HasMany
    {
        return $this->hasMany(ProductionBatch::class);
    }

    use HasFactory;

    protected $fillable = ['sku', 'name', 'volume_ml', 'bottles_per_case', 'default_price_per_case', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'default_price_per_case' => 'decimal:2'];
    }
}
