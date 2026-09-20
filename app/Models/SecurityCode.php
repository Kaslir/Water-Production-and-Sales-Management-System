<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model; class SecurityCode extends Model { protected $fillable=['user_id','code_hash','expires_at','used_at']; protected function casts():array{return ['expires_at'=>'datetime','used_at'=>'datetime'];} }
