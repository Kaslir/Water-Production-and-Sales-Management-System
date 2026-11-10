<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FailedDeliveryLine extends Model
{
    protected $fillable = ['dispatch_line_id', 'cases', 'status', 'failure_reason', 'warehouse_reference', 'quality_notes', 'recorded_by', 'inspected_by', 'returned_on', 'inspected_on'];

    protected function casts(): array
    {
        return ['returned_on' => 'date', 'inspected_on' => 'date'];
    }

    public function dispatchLine(): BelongsTo
    {
        return $this->belongsTo(DispatchLine::class);
    }
}
