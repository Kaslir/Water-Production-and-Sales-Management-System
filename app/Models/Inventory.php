<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Inventory extends Model { protected $primaryKey='inventory_id'; protected $fillable=['quantity_available','last_updated']; protected function casts(): array { return ['quantity_available'=>'decimal:2','last_updated'=>'datetime']; } }
