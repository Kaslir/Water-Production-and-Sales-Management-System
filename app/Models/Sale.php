<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Sale extends Model { protected $primaryKey='sale_id'; protected $fillable=['sale_date','quantity_sold','unit_price','total_amount','consumes_inventory','customer_id','user_id']; protected function casts():array{return ['sale_date'=>'date','quantity_sold'=>'decimal:2','unit_price'=>'decimal:2','total_amount'=>'decimal:2','consumes_inventory'=>'boolean'];} public function customer():BelongsTo{return $this->belongsTo(Customer::class,'customer_id');} public function user():BelongsTo{return $this->belongsTo(User::class,'user_id');} }
