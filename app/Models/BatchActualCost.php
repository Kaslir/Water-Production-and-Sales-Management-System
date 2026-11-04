<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchActualCost extends Model
{
    protected $fillable = ['production_batch_id', 'actual_treatment', 'actual_bottles', 'actual_inbound', 'actual_caps_labels', 'actual_labour', 'actual_electricity', 'actual_other', 'actual_total', 'evidence_reference', 'recorded_by'];

    protected function casts(): array
    {
        return ['actual_treatment' => 'decimal:2', 'actual_bottles' => 'decimal:2', 'actual_inbound' => 'decimal:2', 'actual_caps_labels' => 'decimal:2', 'actual_labour' => 'decimal:2', 'actual_electricity' => 'decimal:2', 'actual_other' => 'decimal:2', 'actual_total' => 'decimal:2'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }
}
