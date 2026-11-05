<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    public function deliveryArea(): BelongsTo
    {
        return $this->belongsTo(DeliveryArea::class, 'delivery_area_id');
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    public function deliveryTrips(): BelongsToMany
    {
        return $this->belongsToMany(DeliveryTrip::class, 'delivery_trip_orders')->withTimestamps();
    }

    use HasFactory;

    protected $fillable = ['order_number', 'customer_id', 'ordered_on', 'requested_delivery_on', 'status', 'delivery_address', 'delivery_county', 'delivery_area_id', 'delivery_zone_id', 'delivery_fee', 'discount', 'delivery_cost', 'total_amount', 'price_override_reason', 'data_origin', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['ordered_on' => 'date', 'requested_delivery_on' => 'date', 'discount' => 'decimal:2', 'delivery_cost' => 'decimal:2', 'delivery_fee' => 'decimal:2', 'total_amount' => 'decimal:2'];
    }
}
