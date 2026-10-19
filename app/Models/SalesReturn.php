<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturn extends Model
{
    protected $fillable = ['invoice_id', 'dispatch_line_id', 'cases', 'credit_amount', 'returned_on', 'disposition', 'reason', 'recorded_by'];

    protected function casts(): array
    {
        return ['returned_on' => 'date', 'credit_amount' => 'decimal:2'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(DispatchLine::class, 'dispatch_line_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
