<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Customer extends Model { protected $primaryKey='customer_id'; protected $fillable=['name','phone_number']; public function sales(): HasMany { return $this->hasMany(Sale::class, 'customer_id'); } }
