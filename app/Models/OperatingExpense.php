<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperatingExpense extends Model
{
    use HasFactory;

    protected $fillable = ['expense_date', 'category', 'amount', 'notes', 'recorded_by'];

    protected function casts(): array
    {
        return ['expense_date' => 'date', 'amount' => 'decimal:2'];
    }
}
