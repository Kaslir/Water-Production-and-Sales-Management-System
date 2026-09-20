<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Distribution extends Model { protected $primaryKey='distribution_id'; protected $fillable=['distribution_date','quantity_distributed','destination','notes','user_id']; protected function casts():array{return ['distribution_date'=>'date','quantity_distributed'=>'decimal:2'];} public function user():BelongsTo{return $this->belongsTo(User::class,'user_id');} }
