<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DeliveryTrip extends Model
{
    protected $fillable = ['trip_reference', 'status', 'active_dispatch_key', 'truck_identifier', 'driver_name', 'delivery_on', 'fuel_expense', 'other_expense', 'expense_reference', 'created_by', 'expense_recorded_by'];

    protected function casts(): array
    {
        return ['delivery_on' => 'date', 'fuel_expense' => 'decimal:2', 'other_expense' => 'decimal:2'];
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(SalesOrder::class, 'delivery_trip_orders')->withTimestamps();
    }

    public function totalExpense(): float
    {
        return round((float) $this->fuel_expense + (float) $this->other_expense, 2);
    }
}
