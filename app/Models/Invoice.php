<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    use HasFactory;

    protected $fillable = ['sales_order_id', 'invoice_number', 'issued_on', 'amount', 'status'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'amount' => 'decimal:2'];
    }
}
