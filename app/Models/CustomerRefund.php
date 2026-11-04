<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerRefund extends Model
{
    protected $fillable = ['invoice_id', 'amount', 'refunded_on', 'method', 'reference', 'reason', 'recorded_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'refunded_on' => 'date'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
