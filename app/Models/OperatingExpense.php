<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperatingExpense extends Model
{
    use HasFactory;

    protected $fillable = ['expense_date', 'category', 'amount', 'notes', 'recorded_by', 'reversed_at', 'reversed_by', 'reversal_reason'];

    protected function casts(): array
    {
        return ['expense_date' => 'date', 'amount' => 'decimal:2', 'reversed_at' => 'datetime'];
    }
}
