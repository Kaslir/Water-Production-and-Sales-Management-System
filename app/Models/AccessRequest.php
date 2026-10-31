<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessRequest extends Model
{
    protected $fillable = ['name', 'email', 'phone_number', 'password', 'verification_nonce', 'status', 'email_verified_at', 'reviewed_by', 'reviewed_at'];

    protected $hidden = ['password', 'verification_nonce'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'email_verified_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }
}
