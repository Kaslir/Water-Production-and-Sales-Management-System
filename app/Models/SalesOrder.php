<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesOrder extends Model
{
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function dispatch(): HasOne
    {
        return $this->hasOne(Dispatch::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    use HasFactory;

    protected $fillable = ['order_number', 'customer_id', 'ordered_on', 'status', 'delivery_address', 'discount', 'delivery_cost', 'total_amount', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['ordered_on' => 'date', 'discount' => 'decimal:2', 'delivery_cost' => 'decimal:2', 'total_amount' => 'decimal:2'];
    }
}
