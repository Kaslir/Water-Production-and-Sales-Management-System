<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Production extends Model { protected $primaryKey='production_id'; protected $fillable=['production_date','quantity_produced','notes','user_id']; protected function casts():array{return ['production_date'=>'date','quantity_produced'=>'decimal:2'];} public function user():BelongsTo{return $this->belongsTo(User::class,'user_id');} }
