<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = ['production_batch_id', 'signed_cases', 'reason', 'reference_type', 'reference_id', 'performed_by', 'notes'];
}
