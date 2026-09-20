<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class InventoryTransaction extends Model { protected $primaryKey='transaction_id'; protected $fillable=['transaction_type','quantity','reference_type','reference_id','transaction_date','user_id','notes']; protected function casts():array{return ['quantity'=>'decimal:2','transaction_date'=>'date'];} public function user(): BelongsTo{return $this->belongsTo(User::class,'user_id');} }
