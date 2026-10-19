<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrderItem extends Model
{
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(BatchReservation::class);
    }

    use HasFactory;

    protected $fillable = ['sales_order_id', 'product_id', 'cases_ordered', 'price_per_case', 'line_total'];

    protected function casts(): array
    {
        return ['price_per_case' => 'decimal:2', 'line_total' => 'decimal:2'];
    }
}
