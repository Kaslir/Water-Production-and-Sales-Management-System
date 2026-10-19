<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchDisposal extends Model
{
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }

    use HasFactory;

    protected $fillable = ['production_batch_id', 'cases', 'reason', 'disposed_on', 'recorded_by'];

    protected function casts(): array
    {
        return ['disposed_on' => 'date'];
    }
}
