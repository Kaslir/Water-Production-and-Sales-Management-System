<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchLine extends Model
{
    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }

    use HasFactory;

    protected $fillable = ['dispatch_id', 'sales_order_item_id', 'production_batch_id', 'cases', 'cost_per_case'];

    protected function casts(): array
    {
        return ['cost_per_case' => 'decimal:4'];
    }
}
