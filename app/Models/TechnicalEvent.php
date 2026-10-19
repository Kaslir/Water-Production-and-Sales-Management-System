<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TechnicalEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['event_type', 'source', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
