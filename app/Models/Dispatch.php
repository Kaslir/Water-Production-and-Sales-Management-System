<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dispatch extends Model
{
    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DispatchLine::class);
    }

    use HasFactory;

    protected $fillable = ['sales_order_id', 'dispatched_on', 'delivered_on', 'status', 'delivery_reference', 'dispatched_by'];

    protected function casts(): array
    {
        return ['dispatched_on' => 'date', 'delivered_on' => 'date'];
    }
}
